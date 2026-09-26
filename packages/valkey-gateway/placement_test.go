package main

import (
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
