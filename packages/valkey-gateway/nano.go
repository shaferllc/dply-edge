package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"log"
	"strings"
	"sync"
	"time"

	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
)

// Nano (T-028): many small Valkey tenants in one always-on Valkey, over REST
// only, so a call after a quiet hour never waits for a wake. A nano tenant
// has a record like any other (Shared: true) but no pod. The gateway runs
// its commands on the shared pool tenant (nanoPoolID, a flex pod that never
// sleeps and snapshots to R2 like the rest), with every key rewritten under
// the tenant's prefix. Only commands whose key positions are known are
// allowed: no scripts, KEYS/SCAN, FLUSH*, INFO, DBSIZE, pub/sub or anything
// that could see or touch another tenant's keys. TCP connections to a nano
// tenant are refused.
//
// Memory can't be limited per tenant inside one process, so it is sampled:
// every nanoSampleEvery the active gateway adds up MEMORY USAGE over each
// tenant's keys, and a tenant over its quota (memory_mb) gets an OOM error
// on commands that grow data until it is back under.

const (
	nanoPoolID      = "nano-pool"
	nanoPoolMB      = 1024
	nanoSampleEvery = 5 * time.Minute
	nanoSampleKeys  = 20000 // past this many keys a tenant counts as over quota
)

func nanoPrefix(id string) string { return "n:" + id + ":" }

// Key positions per command: where in the arguments the keys are.
type keySpec int

const (
	keyNone  keySpec = iota // no keys (PING)
	keyFirst                // the first argument
	keyTwo                  // the first two arguments
	keyAll                  // every argument
	keyPairs                // arguments 1, 3, 5, … (MSET k v k v)
)

var nanoCommands = map[string]keySpec{
	"PING": keyNone, "ECHO": keyNone, "TIME": keyNone,
	// strings
	"GET": keyFirst, "SET": keyFirst, "SETNX": keyFirst, "SETEX": keyFirst, "PSETEX": keyFirst, "GETSET": keyFirst,
	"GETDEL": keyFirst, "GETEX": keyFirst, "APPEND": keyFirst, "STRLEN": keyFirst, "GETRANGE": keyFirst, "SETRANGE": keyFirst,
	"INCR": keyFirst, "INCRBY": keyFirst, "INCRBYFLOAT": keyFirst, "DECR": keyFirst, "DECRBY": keyFirst,
	"MGET": keyAll, "MSET": keyPairs, "MSETNX": keyPairs,
	// keys
	"DEL": keyAll, "UNLINK": keyAll, "EXISTS": keyAll, "TOUCH": keyAll, "TYPE": keyFirst,
	"EXPIRE": keyFirst, "PEXPIRE": keyFirst, "EXPIREAT": keyFirst, "PEXPIREAT": keyFirst, "TTL": keyFirst, "PTTL": keyFirst,
	"PERSIST": keyFirst, "RENAME": keyTwo, "RENAMENX": keyTwo,
	// hashes
	"HGET": keyFirst, "HSET": keyFirst, "HSETNX": keyFirst, "HMSET": keyFirst, "HMGET": keyFirst, "HDEL": keyFirst,
	"HGETALL": keyFirst, "HKEYS": keyFirst, "HVALS": keyFirst, "HLEN": keyFirst, "HEXISTS": keyFirst, "HSTRLEN": keyFirst,
	"HINCRBY": keyFirst, "HINCRBYFLOAT": keyFirst,
	// lists
	"LPUSH": keyFirst, "RPUSH": keyFirst, "LPUSHX": keyFirst, "RPUSHX": keyFirst, "LPOP": keyFirst, "RPOP": keyFirst,
	"LRANGE": keyFirst, "LLEN": keyFirst, "LINDEX": keyFirst, "LSET": keyFirst, "LREM": keyFirst, "LTRIM": keyFirst,
	"LMOVE": keyTwo, "RPOPLPUSH": keyTwo,
	// sets
	"SADD": keyFirst, "SREM": keyFirst, "SMEMBERS": keyFirst, "SISMEMBER": keyFirst, "SMISMEMBER": keyFirst, "SCARD": keyFirst,
	"SPOP": keyFirst, "SRANDMEMBER": keyFirst, "SMOVE": keyTwo,
	"SINTER": keyAll, "SUNION": keyAll, "SDIFF": keyAll, "SINTERSTORE": keyAll, "SUNIONSTORE": keyAll, "SDIFFSTORE": keyAll,
	// sorted sets
	"ZADD": keyFirst, "ZREM": keyFirst, "ZSCORE": keyFirst, "ZMSCORE": keyFirst, "ZINCRBY": keyFirst, "ZCARD": keyFirst,
	"ZCOUNT": keyFirst, "ZRANGE": keyFirst, "ZRANGEBYSCORE": keyFirst, "ZREVRANGE": keyFirst, "ZREVRANGEBYSCORE": keyFirst,
	"ZRANK": keyFirst, "ZREVRANK": keyFirst, "ZREMRANGEBYRANK": keyFirst, "ZREMRANGEBYSCORE": keyFirst,
	// HyperLogLog and streams
	"PFADD": keyFirst, "PFCOUNT": keyAll,
	"XADD": keyFirst, "XLEN": keyFirst, "XRANGE": keyFirst, "XREVRANGE": keyFirst, "XDEL": keyFirst, "XTRIM": keyFirst,
}

// Commands that add data: refused while a tenant is over its quota.
var nanoGrows = map[string]bool{
	"SET": true, "SETNX": true, "SETEX": true, "PSETEX": true, "GETSET": true, "APPEND": true, "SETRANGE": true,
	"INCR": true, "INCRBY": true, "INCRBYFLOAT": true, "DECR": true, "DECRBY": true, "MSET": true, "MSETNX": true,
	"HSET": true, "HSETNX": true, "HMSET": true, "HINCRBY": true, "HINCRBYFLOAT": true,
	"LPUSH": true, "RPUSH": true, "LPUSHX": true, "RPUSHX": true, "LSET": true, "LMOVE": true, "RPOPLPUSH": true,
	"SADD": true, "SMOVE": true, "SINTERSTORE": true, "SUNIONSTORE": true, "SDIFFSTORE": true,
	"ZADD": true, "ZINCRBY": true, "PFADD": true, "XADD": true,
}

// nanoRewrite puts prefix on every key in cmd, or refuses the command.
func nanoRewrite(cmd []string, prefix string, over bool) ([]string, error) {
	name := strings.ToUpper(cmd[0])
	spec, ok := nanoCommands[name]
	if !ok {
		return nil, restCommandError("ERR " + name + " is not available on a nano database")
	}
	if over && nanoGrows[name] {
		return nil, restCommandError("OOM this nano database is over its memory quota; delete keys or move to a larger size")
	}
	out := append([]string{cmd[0]}, cmd[1:]...)
	for i := 1; i < len(out); i++ {
		if spec == keyAll || (spec == keyFirst && i == 1) || (spec == keyTwo && i <= 2) || (spec == keyPairs && i%2 == 1) {
			out[i] = prefix + out[i]
		}
	}
	if spec != keyNone && len(out) < 2 {
		return nil, restCommandError("ERR wrong number of arguments for '" + strings.ToLower(name) + "'")
	}
	return out, nil
}

type nanoState struct {
	mu    sync.Mutex
	usage map[string]int64 // tenant id -> sampled bytes
	over  map[string]bool
}

func (n *nanoState) isOver(id string) bool {
	n.mu.Lock()
	defer n.mu.Unlock()
	return n.over[id]
}

// nanoPool is the shared pool's tenant record, made on first use. Created
// with Create (never Update), so two gateways can't each give it a password.
func (g *gateway) nanoPool(ctx context.Context) (*tenant, error) {
	if t, err := g.getTenantRecord(ctx, nanoPoolID); err == nil {
		return t, nil
	} else if err != errNotFound {
		return nil, err
	}
	buf := make([]byte, 24)
	_, _ = rand.Read(buf)
	t := tenant{ID: nanoPoolID, Password: hex.EncodeToString(buf), MemoryMB: nanoPoolMB, Engine: "valkey"}
	secret := &corev1.Secret{
		ObjectMeta: metav1.ObjectMeta{Name: objectName(t.ID), Labels: map[string]string{"app": "dply-valkey", "tenant": t.ID}},
		StringData: map[string]string{"id": t.ID, "password": t.Password, "memory_mb": itoa(t.MemoryMB), "sleep_after": "0", "persistent": "false", "engine": "valkey", "disk_gb": "0"},
	}
	if _, err := g.kube.CoreV1().Secrets(g.cfg.namespace).Create(ctx, secret, metav1.CreateOptions{}); err != nil && !apierrors.IsAlreadyExists(err) {
		return nil, err
	}
	g.records.drop(nanoPoolID)
	return g.getTenantRecord(ctx, nanoPoolID)
}

// execNano runs a nano tenant's commands on the shared pool, keys rewritten.
func (g *gateway) execNano(ctx context.Context, t tenant, cmds [][]string, transaction, b64 bool) ([]map[string]any, bool, error) {
	pool, err := g.nanoPool(ctx)
	if err != nil {
		return nil, false, err
	}
	prefix := nanoPrefix(t.ID)
	over := g.nano.isOver(t.ID)
	rewritten := make([][]string, len(cmds))
	for i, cmd := range cmds {
		out, err := nanoRewrite(cmd, prefix, over)
		if err != nil {
			return []map[string]any{{"error": err.Error()}}, true, nil
		}
		rewritten[i] = out
	}
	return g.execREST(ctx, *pool, rewritten, transaction, b64)
}

// nanoSampleLoop measures each nano tenant's memory on the active gateway.
func (g *gateway) nanoSampleLoop(ctx context.Context) {
	tick := time.NewTicker(nanoSampleEvery)
	defer tick.Stop()
	for {
		g.nanoSample(ctx)
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}

func (g *gateway) nanoSample(ctx context.Context) {
	tenants, err := g.listTenants(ctx)
	if err != nil {
		return
	}
	var shared []tenant
	for _, t := range tenants {
		if t.Shared {
			shared = append(shared, t)
		}
	}
	if len(shared) == 0 {
		return
	}
	pool, err := g.nanoPool(ctx)
	if err != nil {
		return
	}
	ip, err := g.wake(ctx, pool.ID)
	if err != nil {
		log.Printf("nano: pool not reachable: %v", err)
		return
	}
	c, err := dialTenant(ip, pool.Password)
	if err != nil {
		return
	}
	defer c.Close()
	for _, t := range shared {
		_ = c.SetDeadline(time.Now().Add(time.Minute))
		bytes, keys, err := nanoUsage(c.conn, nanoPrefix(t.ID))
		if err != nil {
			log.Printf("nano %s: sample: %v", t.ID, err)
			continue
		}
		over := keys > nanoSampleKeys || bytes > int64(t.MemoryMB)<<20
		g.nano.mu.Lock()
		if g.nano.usage == nil {
			g.nano.usage, g.nano.over = map[string]int64{}, map[string]bool{}
		}
		g.nano.usage[t.ID], g.nano.over[t.ID] = bytes, over
		g.nano.mu.Unlock()
	}
}

// nanoUsage adds MEMORY USAGE over the keys under prefix (SCAN, so the
// shared Valkey is never blocked), stopping past nanoSampleKeys.
func nanoUsage(c *conn, prefix string) (bytes int64, keys int, err error) {
	cursor := "0"
	for {
		reply, err := c.do("SCAN", cursor, "MATCH", prefix+"*", "COUNT", "500")
		if err != nil {
			return 0, 0, err
		}
		parts, ok := reply.([]any)
		if !ok || len(parts) != 2 {
			return 0, 0, fmt.Errorf("unexpected SCAN reply")
		}
		cursor = respString(parts[0])
		batch, _ := parts[1].([]any)
		for _, k := range batch {
			keys++
			if n, err := c.do("MEMORY", "USAGE", respString(k)); err == nil {
				if v, ok := n.(int64); ok {
					bytes += v
				}
			}
		}
		if cursor == "0" || keys > nanoSampleKeys {
			return bytes, keys, nil
		}
	}
}

// nanoDelete removes a nano tenant's keys from the shared pool.
func (g *gateway) nanoDelete(ctx context.Context, id string) error {
	pool, err := g.nanoPool(ctx)
	if err != nil {
		return err
	}
	ip, err := g.wake(ctx, pool.ID)
	if err != nil {
		return err
	}
	c, err := dialTenant(ip, pool.Password)
	if err != nil {
		return err
	}
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(5 * time.Minute))
	cursor := "0"
	for {
		reply, err := c.conn.do("SCAN", cursor, "MATCH", nanoPrefix(id)+"*", "COUNT", "500")
		if err != nil {
			return err
		}
		parts, _ := reply.([]any)
		if len(parts) != 2 {
			return fmt.Errorf("unexpected SCAN reply")
		}
		cursor = respString(parts[0])
		if batch, _ := parts[1].([]any); len(batch) > 0 {
			args := []string{"UNLINK"}
			for _, k := range batch {
				args = append(args, respString(k))
			}
			if _, err := c.conn.do(args...); err != nil {
				return err
			}
		}
		if cursor == "0" {
			return nil
		}
	}
}

// respString: a bulk ([]byte), simple string or integer reply as text.
func respString(v any) string {
	switch x := v.(type) {
	case []byte:
		return string(x)
	case string:
		return x
	default:
		return fmt.Sprint(x)
	}
}

// nanoRoom: whether a new nano tenant of quota mb still fits the pool, the
// quotas of existing ones added up against 80% of the pool's memory (the
// pool never evicts, so quotas must not oversubscribe it).
func (g *gateway) nanoRoom(ctx context.Context, mb int) (bool, error) {
	tenants, err := g.listTenants(ctx)
	if err != nil {
		return false, err
	}
	used := mb
	for _, t := range tenants {
		if t.Shared {
			used += t.MemoryMB
		}
	}
	return used <= nanoPoolMB*8/10, nil
}
