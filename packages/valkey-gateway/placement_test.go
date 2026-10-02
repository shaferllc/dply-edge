package main

import (
	"strings"
	"testing"

	corev1 "k8s.io/api/core/v1"
)

func TestProPlacement(t *testing.T) {
	if sel, tol := proPlacement(12288, false); sel != nil || tol != nil {
		t.Fatalf("flex must stay on the shared pool, got %v %v", sel, tol)
	}
	for mb, want := range map[int]string{5120: proSmallPool, 12288: proSmallPool, 25600: proLargePool, 51200: proLargePool} {
		sel, tol := proPlacement(mb, true)
		if sel[nodePoolKey] != want || len(tol) != 1 || tol[0].Key != proTaintKey {
			t.Fatalf("%d MB: got %v %v, want pool %s", mb, sel, tol, want)
		}
		if pod := (&gateway{cfg: config{}}).podSpec("vk-t", mb, true); pod.Spec.NodeSelector[nodePoolKey] != want {
			t.Fatalf("%d MB pod lands on %v", mb, pod.Spec.NodeSelector)
		}
	}
}

func TestCPURequest(t *testing.T) {
	for _, c := range []struct {
		mb         int
		persistent bool
		want       string
	}{{250, false, "25m"}, {2560, false, "25m"}, {5120, true, "500m"}, {12288, true, "1200m"}, {25600, true, "2500m"}, {51200, true, "5000m"}} {
		if got := cpuRequest(c.mb, c.persistent); got != c.want {
			t.Fatalf("%d MB persistent=%v: got %s, want %s", c.mb, c.persistent, got, c.want)
		}
	}
}

func TestTenantFromName(t *testing.T) {
	for name, want := range map[string]string{
		"pg-abc123.db.dply.io":     "pg-abc123",
		"pg-abc123.cache.dply.io":  "", // a database name on the cache domain is not a database
		"db.dply.io":               "",
		"evil.db.dply.io.attacker": "",
	} {
		got, ok := tenantFromName(name, "db.dply.io")
		if (want == "") == ok || got != want && want != "" {
			t.Fatalf("%s: got %q ok=%v, want %q", name, got, ok, want)
		}
	}
}

func TestDatabasePlacement(t *testing.T) {
	anywhere := &gateway{cfg: config{}}
	if sel, tol := anywhere.databasePlacement(16384); sel != nil || len(tol) != 2 {
		t.Fatalf("no DB_NODE_POOL: got selector %v and %d tolerations, want none and the 2 fast-eviction ones", sel, len(tol))
	}
	pooled := &gateway{cfg: config{dbNodePool: "db"}}
	// 0.25 and 0.5 CU on the db pool; 1 and 2 CU on db-large; 4 CU on db-xl.
	for mb, want := range map[int]string{1024: "db", 2048: "db", 4096: dbLargePool, 8192: dbLargePool, 16384: dbXLPool} {
		sel, tol := pooled.databasePlacement(mb)
		if sel[nodePoolKey] != want || len(tol) != 3 || tol[2].Key != dbTaintKey || *tol[0].TolerationSeconds != 30 {
			t.Fatalf("DB_NODE_POOL=db, %d MB: got selector %v, tolerations %+v, want pool %s", mb, sel, tol, want)
		}
		if pod := pooled.databasePodSpec(tenant{ID: "pg-x", Engine: "postgres", MemoryMB: mb}); pod.Spec.NodeSelector[nodePoolKey] != want {
			t.Fatalf("%d MB pod lands on %v, want %s", mb, pod.Spec.NodeSelector, want)
		}
	}
}

func TestDatabaseResizedAcrossPoolsIsRecreated(t *testing.T) {
	onDB := &corev1.Pod{Spec: corev1.PodSpec{NodeSelector: map[string]string{nodePoolKey: "db"}}}
	if wrongDatabasePool(onDB, databasePool("db", 2048)) {
		t.Fatal("a 0.5 CU database on the db pool must stay")
	}
	if !wrongDatabasePool(onDB, databasePool("db", 4096)) {
		t.Fatal("a database grown to 1 CU must leave the db pool")
	}
	onLarge := &corev1.Pod{Spec: corev1.PodSpec{NodeSelector: map[string]string{nodePoolKey: dbLargePool}}}
	if !wrongDatabasePool(onLarge, databasePool("db", 1024)) || !wrongDatabasePool(onLarge, databasePool("db", 16384)) {
		t.Fatal("a database shrunk to 0.25 CU or grown to 4 CU must leave db-large")
	}
	if wrongDatabasePool(&corev1.Pod{}, databasePool("", 16384)) {
		t.Fatal("without DB_NODE_POOL no pod is ever on the wrong pool")
	}
}

func TestDatabaseCPU(t *testing.T) {
	for mb, want := range map[int]string{256: "100m", 1024: "250m", 2048: "500m", 4096: "1000m", 16384: "1000m"} {
		if got := databaseCPU(mb); got != want {
			t.Fatalf("%d MB: got %s, want %s", mb, got, want)
		}
	}
	awake := databaseResources(tenant{MemoryMB: 1024}, true).Requests[corev1.ResourceCPU]
	parked := databaseResources(tenant{MemoryMB: 1024}, false).Requests[corev1.ResourceCPU]
	if awake.String() != "250m" || parked.String() != "10m" {
		t.Fatalf("awake %s parked %s, want 250m and 10m", awake.String(), parked.String())
	}
}

func TestProPoolsOffPlacesProTenantsAnywhere(t *testing.T) {
	local := &gateway{cfg: config{noProPools: true}}
	if pod := local.podSpec("vk-x", 5120, true); pod.Spec.NodeSelector != nil || pod.Spec.Tolerations != nil {
		t.Fatalf("PRO_NODE_POOLS=off still pins the pod: %v %v", pod.Spec.NodeSelector, pod.Spec.Tolerations)
	}
	cloud := &gateway{cfg: config{}}
	if pod := cloud.podSpec("vk-x", 5120, true); pod.Spec.NodeSelector[nodePoolKey] != proSmallPool {
		t.Fatalf("pro tenant not on %s: %v", proSmallPool, pod.Spec.NodeSelector)
	}
}

func TestValkeyEvictsOnlyExpiringKeys(t *testing.T) {
	pod := (&gateway{cfg: config{}}).podSpec("vk-x", 250, false)
	args := strings.Join(pod.Spec.Containers[0].Args, " ")
	if !strings.Contains(args, "--maxmemory-policy volatile-lru") {
		t.Fatalf("eviction policy not volatile-lru: %s", args)
	}
}

func TestSlowlogEntriesShowCommandAndKeyOnly(t *testing.T) {
	reply := []any{
		[]any{int64(7), int64(1790400000), int64(15230), []any{[]byte("set"), []byte("cache:user:42"), []byte("secret-value")}, []byte("10.0.0.1:5000"), []byte("")},
		[]any{int64(6), int64(1790399990), int64(9000), []any{[]byte("KEYS"), []byte("*")}},
		"junk",
	}
	got := slowlogEntries(reply)
	if len(got) != 2 {
		t.Fatalf("entries = %d", len(got))
	}
	if got[0]["command"] != "SET" || got[0]["key"] != "cache:user:42" || got[0]["micros"] != int64(15230) {
		t.Fatalf("first entry = %v", got[0])
	}
	for _, e := range got {
		for _, v := range e {
			if v == "secret-value" {
				t.Fatal("a value leaked into the slowlog entry")
			}
		}
	}
}

func TestLargeDatabasesAndValkeyProStayOn(t *testing.T) {
	for _, c := range []struct {
		t    tenant
		want bool
	}{
		{tenant{Engine: "postgres", MemoryMB: 2048, Persistent: true}, false},
		{tenant{Engine: "postgres", MemoryMB: 4096, Persistent: true}, true},
		{tenant{Engine: "mysql", MemoryMB: 16384, Persistent: true}, true},
		{tenant{MemoryMB: 1024}, false},
		{tenant{MemoryMB: 5120, Persistent: true}, true},
	} {
		if got := staysOn(c.t); got != c.want {
			t.Fatalf("%+v: staysOn %v, want %v", c.t, got, c.want)
		}
	}
}

func TestTenantSecretKeepsBackupDays(t *testing.T) {
	s := &corev1.Secret{Data: map[string][]byte{"id": []byte("pg-x"), "engine": []byte("postgres"), "backup_days": []byte("30")}}
	if got := tenantFromSecret(s).BackupDays; got != 30 {
		t.Fatalf("backup_days: got %d", got)
	}
}
