package main

import "testing"

func TestParseMemoryStat(t *testing.T) {
	anon, file := parseMemoryStat("anon 734003200\nfile 268435456\nkernel 1048576\nfile_mapped 4096\n")
	if anon != 734003200 || file != 268435456 {
		t.Fatalf("got anon=%d file=%d", anon, file)
	}
	if a, f := parseMemoryStat(""); a != 0 || f != 0 {
		t.Fatalf("empty stat: got %d %d", a, f)
	}
}
