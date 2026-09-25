package main

import "testing"

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
