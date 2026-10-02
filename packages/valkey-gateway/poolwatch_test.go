package main

import (
	"testing"
	"time"
)

func TestPoolFollowsDemandBetweenFloorAndCeiling(t *testing.T) {
	var d poolDemand
	now := time.Now()
	if got := d.want(250, 2, now); got != 2 {
		t.Fatalf("no demand: got %d, want the floor", got)
	}
	for i := 0; i < 5; i++ {
		d.recordAdoption(250, now.Add(-time.Duration(i)*time.Minute))
	}
	if got := d.want(250, 2, now); got != 5 {
		t.Fatalf("5 recent adoptions: got %d", got)
	}
	for i := 0; i < 20; i++ {
		d.recordAdoption(250, now)
	}
	if got := d.want(250, 2, now); got != 2*poolCeiling {
		t.Fatalf("capped: got %d", got)
	}
	if got := d.want(250, 2, now.Add(poolWindow+time.Minute)); got != 2 {
		t.Fatalf("after the window: got %d", got)
	}
	d.recordAdoption(1024, now)
	if got := d.want(1024, 0, now); got != 1 {
		t.Fatalf("a floor of 0 follows demand: got %d", got)
	}
}
