package main

import (
	"testing"
	"time"
)

func TestTenantCacheExpiresAndDrops(t *testing.T) {
	var c tenantCache
	if _, ok := c.get("a"); ok {
		t.Fatal("empty cache returned a record")
	}
	c.put(tenant{ID: "a", Engine: "postgres"})
	if got, ok := c.get("a"); !ok || got.Engine != "postgres" {
		t.Fatalf("cached record = %+v, %v", got, ok)
	}
	c.drop("a")
	if _, ok := c.get("a"); ok {
		t.Fatal("dropped record still returned")
	}
	c.put(tenant{ID: "b"})
	c.byID["b"] = cachedTenant{t: c.byID["b"].t, at: time.Now().Add(-recordTTL - time.Second)}
	if _, ok := c.get("b"); ok {
		t.Fatal("expired record still returned")
	}
}
