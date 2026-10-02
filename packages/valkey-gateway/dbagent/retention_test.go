package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestKeepFrom(t *testing.T) {
	day := func(n int) time.Time { return time.Date(2026, 10, n, 4, 0, 0, 0, time.UTC) }
	cutoff := day(10)
	cases := []struct {
		name  string
		times []time.Time
		want  int
	}{
		{"none", nil, 0},
		{"all inside the window", []time.Time{day(11), day(12)}, 0},
		{"keeps the base covering the window start", []time.Time{day(1), day(5), day(9), day(11), day(12)}, 2},
		{"asleep past the window keeps its last backup", []time.Time{day(1), day(2)}, 1},
		{"one exactly at the cutoff is the base", []time.Time{day(8), day(10), day(11)}, 1},
	}
	for _, c := range cases {
		if got := keepFrom(c.times, cutoff); got != c.want {
			t.Errorf("%s: keepFrom = %d, want %d", c.name, got, c.want)
		}
	}
}

func TestRetentionDaysPrefersTheFileTheGatewaySent(t *testing.T) {
	retentionFile = filepath.Join(t.TempDir(), "days")
	t.Setenv("BACKUP_RETENTION_DAYS", "14")
	if got := retentionDays(); got != 14 {
		t.Fatalf("env: got %d", got)
	}
	_ = os.WriteFile(retentionFile, []byte("30\n"), 0o600)
	if got := retentionDays(); got != 30 {
		t.Fatalf("file: got %d", got)
	}
}
