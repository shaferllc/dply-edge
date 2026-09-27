package main

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"syscall"
)

// memoryLimit is the pod's memory limit in bytes (cgroup v2). The limit does
// not change when the database sleeps (only the request shrinks), so it is
// the awake size. fallback when it cannot be read or is "max".
func memoryLimit(fallback int64) int64 {
	b, err := os.ReadFile("/sys/fs/cgroup/memory.max")
	if err != nil {
		return fallback
	}
	n, err := strconv.ParseInt(strings.TrimSpace(string(b)), 10, 64)
	if err != nil || n <= 0 {
		return fallback
	}
	return n
}

// diskUsage is the data volume's size and bytes in use.
func diskUsage(path string) (total, used int64) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, 0
	}
	return int64(st.Blocks) * int64(st.Bsize), int64(st.Blocks-st.Bfree) * int64(st.Bsize)
}

func diskTotal(path string) int64 { total, _ := diskUsage(path); return total }

// pgTuning sizes Postgres to its pod instead of the stock defaults (128 MB
// shared_buffers whatever the size, and 1 GB of WAL, which alone can fill
// the smallest disk). Passed on the command line at every start, so a
// resize applies on the next wake and existing databases pick it up.
func pgTuning(memBytes, diskBytes int64, maxConnections int) []string {
	mb := max(memBytes>>20, 128)
	conns := int64(max(maxConnections, 1))
	walMax := int64(1024)
	if diskBytes > 0 {
		// A quarter of the disk at most: WAL is recycled only after a
		// checkpoint, and a full disk stops the database.
		walMax = min(walMax, max(diskBytes>>20/4, 96))
	}
	return []string{
		fmt.Sprintf("shared_buffers=%dMB", max(mb/4, 32)),
		fmt.Sprintf("effective_cache_size=%dMB", mb*3/4),
		// Per sort/hash node, and a query can use several: a quarter of
		// what is left per connection keeps a busy database inside its limit.
		fmt.Sprintf("work_mem=%dkB", max(mb*3/4*1024/conns/4, 4096)),
		fmt.Sprintf("maintenance_work_mem=%dMB", max(mb/16, 16)),
		fmt.Sprintf("max_wal_size=%dMB", walMax),
		fmt.Sprintf("min_wal_size=%dMB", min(80, walMax/2)),
		// Network SSD volumes: random reads cost about the same as sequential.
		"random_page_cost=1.1",
		"effective_io_concurrency=200",
		// App queries are short; JIT compiling them costs more than it saves.
		"jit=off",
		"wal_compression=lz4",
		"track_io_timing=on",
		// Top queries (the Queries tab). Loaded here, not in auto.conf, so
		// databases created before it get it on their next wake.
		"shared_preload_libraries=pg_prewarm,pg_stat_statements",
		"pg_stat_statements.track=top",
		"pg_stat_statements.max=1000",
	}
}

// startOptions are every -c setting a start passes (normal and restore).
func (p *postgres) startOptions() string {
	conns, _ := strconv.Atoi(envOr("MAX_CONNECTIONS", "50"))
	var b strings.Builder
	b.WriteString(archiveOptions())
	for _, o := range pgTuning(memoryLimit(512<<20), diskTotal(p.data), conns) {
		b.WriteString(" -c " + o)
	}
	return b.String()
}
