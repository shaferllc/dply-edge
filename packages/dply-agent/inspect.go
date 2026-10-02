package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"os"
	"sort"
	"strconv"
	"strings"
)

// GET /processes and GET /env (T-040): what is running in this container and
// with what environment, for dply's operators. Values come back as they are;
// dply masks env values unless an operator opened a data-access session.

// clockTicks is USER_HZ, 100 on every Linux dply runs on.
const clockTicks = 100

type process struct {
	PID        int     `json:"pid"`
	PPID       int     `json:"ppid"`
	Name       string  `json:"name"`
	Command    string  `json:"command"`
	State      string  `json:"state"`
	RSSBytes   int64   `json:"rss_bytes"`
	CPUSeconds float64 `json:"cpu_seconds"`
	AgeSeconds float64 `json:"age_seconds"`
}

type memory struct {
	CurrentBytes int64 `json:"current_bytes"`
	MaxBytes     int64 `json:"max_bytes"` // 0: no limit
	AnonBytes    int64 `json:"anon_bytes"`
	FileBytes    int64 `json:"file_bytes"`
	PeakBytes    int64 `json:"peak_bytes"`
}

func processesHandler(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, map[string]any{"memory": readMemory("/sys/fs/cgroup"), "processes": readProcesses("/proc")})
}

func envHandler(w http.ResponseWriter, _ *http.Request) {
	env := map[string]string{}
	for _, kv := range os.Environ() {
		if k, v, ok := strings.Cut(kv, "="); ok {
			env[k] = v
		}
	}
	writeJSON(w, map[string]any{"env": env})
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}

// readProcesses lists every process in root (a /proc), biggest memory first.
func readProcesses(root string) []process {
	uptime := readUptime(root + "/uptime")
	pageSize := int64(os.Getpagesize())
	entries, _ := os.ReadDir(root)
	out := []process{}
	for _, e := range entries {
		pid, err := strconv.Atoi(e.Name())
		if err != nil {
			continue
		}
		stat, err := os.ReadFile(root + "/" + e.Name() + "/stat")
		if err != nil {
			continue // exited while we looked
		}
		p, ok := parseStat(stat, uptime, pageSize)
		if !ok {
			continue
		}
		p.PID = pid
		if cmd, err := os.ReadFile(root + "/" + e.Name() + "/cmdline"); err == nil {
			p.Command = strings.TrimSpace(string(bytes.ReplaceAll(cmd, []byte{0}, []byte{' '})))
		}
		out = append(out, p)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].RSSBytes > out[j].RSSBytes })
	return out
}

// parseStat reads /proc/<pid>/stat. The name is in parentheses and may hold
// spaces or parentheses itself, so fields are counted from the last ')'.
func parseStat(stat []byte, uptime float64, pageSize int64) (process, bool) {
	open, end := bytes.IndexByte(stat, '('), bytes.LastIndexByte(stat, ')')
	if open < 0 || end < open {
		return process{}, false
	}
	f := strings.Fields(string(stat[end+1:]))
	// f[0]=state f[1]=ppid ... f[11]=utime f[12]=stime ... f[19]=starttime f[21]=rss
	if len(f) < 22 {
		return process{}, false
	}
	num := func(i int) int64 { n, _ := strconv.ParseInt(f[i], 10, 64); return n }
	return process{
		Name:       string(stat[open+1 : end]),
		State:      f[0],
		PPID:       int(num(1)),
		CPUSeconds: float64(num(11)+num(12)) / clockTicks,
		AgeSeconds: max(0, uptime-float64(num(19))/clockTicks),
		RSSBytes:   num(21) * pageSize,
	}, true
}

func readUptime(path string) float64 {
	b, err := os.ReadFile(path)
	if err != nil {
		return 0
	}
	n, _ := strconv.ParseFloat(strings.Fields(string(b) + " 0")[0], 64)
	return n
}

// readMemory reads the container's cgroup v2 memory accounting. Zeros when
// a file is missing.
func readMemory(root string) memory {
	value := func(name string) int64 {
		b, err := os.ReadFile(root + "/" + name)
		if err != nil {
			return 0
		}
		n, _ := strconv.ParseInt(strings.TrimSpace(string(b)), 10, 64) // "max" -> 0
		return n
	}
	m := memory{CurrentBytes: value("memory.current"), MaxBytes: value("memory.max"), PeakBytes: value("memory.peak")}
	if b, err := os.ReadFile(root + "/memory.stat"); err == nil {
		for _, line := range strings.Split(string(b), "\n") {
			k, v, _ := strings.Cut(line, " ")
			n, _ := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
			switch k {
			case "anon":
				m.AnonBytes = n
			case "file":
				m.FileBytes = n
			}
		}
	}
	return m
}
