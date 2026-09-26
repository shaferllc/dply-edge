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
	if sel, tol := anywhere.databasePlacement(); sel != nil || len(tol) != 2 {
		t.Fatalf("no DB_NODE_POOL: got selector %v and %d tolerations, want none and the 2 fast-eviction ones", sel, len(tol))
	}
	pooled := &gateway{cfg: config{dbNodePool: "db"}}
	sel, tol := pooled.databasePlacement()
	if sel[nodePoolKey] != "db" || len(tol) != 3 || tol[2].Key != dbTaintKey || *tol[0].TolerationSeconds != 30 {
		t.Fatalf("DB_NODE_POOL=db: got selector %v, tolerations %+v", sel, tol)
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
