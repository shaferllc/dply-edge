package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
)

// pgExtensions are the extensions the panel offers. Each must be in the
// image (contrib ships with Postgres; vector is added in Dockerfile.postgres).
var pgExtensions = []string{
	"btree_gin", "btree_gist", "citext", "cube", "earthdistance", "fuzzystrmatch", "hstore",
	"intarray", "ltree", "pg_trgm", "pgcrypto", "tablefunc", "unaccent", "uuid-ossp", "vector",
}

func (p *postgres) running() bool { return run("pg_ctl", "-D", p.data, "status") == nil }

// pgJSON runs one fixed query as the admin over the pod's socket and returns
// the single JSON value it selects.
func (p *postgres) pgJSON(db, sql string) (map[string]any, error) {
	cmd := exec.Command("psql", "-h", p.run, "-U", "dply_admin", "-d", db, "-v", "ON_ERROR_STOP=1", "-qAtX", "-c", sql)
	var stderr strings.Builder
	cmd.Stderr = &stderr
	b, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("psql: %v: %s", err, lastLines(stderr.String(), 2))
	}
	out := map[string]any{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(string(b))), &out); err != nil {
		return nil, fmt.Errorf("psql: unexpected output: %v", err)
	}
	return out, nil
}

func (p *postgres) insights() (map[string]any, error) {
	allowed := "ARRAY['" + strings.Join(pgExtensions, "','") + "']"
	out, err := p.pgJSON("app", `WITH st AS (SELECT * FROM pg_stat_database WHERE datname = current_database()),
t AS (SELECT count(*) AS n, coalesce(sum(n_live_tup), 0) AS r FROM pg_stat_user_tables)
SELECT json_build_object(
 'engine', 'postgres',
 'version', 'PostgreSQL ' || current_setting('server_version'),
 'uptime_seconds', extract(epoch FROM now() - pg_postmaster_start_time())::bigint,
 'size_bytes', pg_database_size(current_database()),
 'tables', (SELECT n FROM t), 'rows', (SELECT r FROM t),
 'connections', (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()),
 'max_connections', current_setting('max_connections')::int,
 'cache_hit_ratio', (SELECT CASE WHEN blks_hit + blks_read > 0 THEN round(blks_hit * 100.0 / (blks_hit + blks_read), 1) END FROM st),
 'commits', (SELECT xact_commit FROM st), 'rollbacks', (SELECT xact_rollback FROM st),
 'stats_since', (SELECT stats_reset FROM st),
 'largest', (SELECT coalesce(json_agg(x), '[]') FROM (SELECT relname AS name, n_live_tup AS rows, pg_total_relation_size(relid) AS bytes FROM pg_stat_user_tables ORDER BY 3 DESC LIMIT 5) x),
 'by_state', (SELECT coalesce(json_object_agg(s, n), '{}') FROM (SELECT coalesce(state, 'other') AS s, count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND usename = 'app' GROUP BY 1) x),
 'running', (SELECT coalesce(json_agg(x ORDER BY x.seconds DESC), '[]') FROM (
   SELECT pid, state, extract(epoch FROM now() - coalesce(xact_start, query_start))::int AS seconds,
     wait_event_type AS wait, pg_blocking_pids(pid) AS blocked_by, left(query, 500) AS query
   FROM pg_stat_activity WHERE datname = current_database() AND usename = 'app' AND state <> 'idle') x),
 'unused_indexes', (SELECT coalesce(json_agg(x), '[]') FROM (
   SELECT s.relname AS "table", s.indexrelname AS "index", pg_relation_size(s.indexrelid) AS bytes
   FROM pg_stat_user_indexes s JOIN pg_index i ON i.indexrelid = s.indexrelid
   WHERE s.idx_scan = 0 AND NOT i.indisunique AND NOT i.indisprimary ORDER BY 3 DESC LIMIT 20) x),
 'full_scans', (SELECT coalesce(json_agg(x), '[]') FROM (
   SELECT relname AS "table", seq_scan AS scans, coalesce(idx_scan, 0) AS index_scans, n_live_tup AS rows
   FROM pg_stat_user_tables WHERE n_live_tup >= 1000 AND seq_scan > coalesce(idx_scan, 0)
   ORDER BY seq_scan * n_live_tup DESC LIMIT 10) x),
 'vacuum', (SELECT coalesce(json_agg(x), '[]') FROM (
   SELECT relname AS "table", n_dead_tup AS dead_rows, n_live_tup AS rows, greatest(last_autovacuum, last_vacuum) AS last_vacuum
   FROM pg_stat_user_tables WHERE n_dead_tup >= 1000 ORDER BY n_dead_tup DESC LIMIT 10) x),
 'extensions', (SELECT coalesce(json_agg(x ORDER BY x.name), '[]') FROM (
   SELECT name, installed_version AS installed, comment FROM pg_available_extensions WHERE name = ANY(`+allowed+`)) x)
)`)
	if err != nil {
		return nil, err
	}
	// pg_stat_statements lives in the admin's database (see start).
	if q, err := p.pgJSON("postgres", `SELECT json_build_object(
 'queries_since', (SELECT stats_reset FROM pg_stat_statements_info),
 'queries', (SELECT coalesce(json_agg(x), '[]') FROM (
   SELECT left(query, 2000) AS query, calls, round(total_exec_time::numeric, 1) AS total_ms,
     round(mean_exec_time::numeric, 2) AS mean_ms, rows
   FROM pg_stat_statements
   WHERE dbid = (SELECT oid FROM pg_database WHERE datname = 'app') AND userid = (SELECT oid FROM pg_roles WHERE rolname = 'app')
   ORDER BY total_exec_time DESC LIMIT 25) x))`); err == nil {
		out["queries"], out["queries_since"] = q["queries"], q["queries_since"]
	} else {
		out["queries_error"] = err.Error()
	}
	return out, nil
}

var extensionName = regexp.MustCompile(`^[a-z0-9_-]+$`)

func (p *postgres) action(name string, body []byte) (any, error) {
	switch name {
	case "queries-reset":
		_, err := p.psqlValue("SELECT pg_stat_statements_reset()")
		return map[string]bool{"ok": err == nil}, err
	case "cancel":
		var in struct{ PID int }
		if err := json.Unmarshal(body, &in); err != nil || in.PID <= 0 {
			return nil, errors.New("pid required")
		}
		// Only the app's own sessions.
		out, err := p.psqlValue(fmt.Sprintf("SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE pid = %d AND usename = 'app'", in.PID))
		return map[string]bool{"ok": out == "t"}, err
	case "extension":
		var in struct{ Name string }
		if err := json.Unmarshal(body, &in); err != nil || !extensionName.MatchString(in.Name) || !contains(pgExtensions, in.Name) {
			return nil, errors.New("that extension is not offered")
		}
		err := run("psql", "-h", p.run, "-U", "dply_admin", "-d", "app", "-v", "ON_ERROR_STOP=1", "-qAtc",
			`CREATE EXTENSION IF NOT EXISTS "`+in.Name+`"`)
		return map[string]bool{"ok": err == nil}, err
	case "readonly":
		// The read-only login for BI tools: a password turns network login
		// on, an empty one turns it off (the console uses the socket).
		var in struct{ Password string }
		if err := json.Unmarshal(body, &in); err != nil || (in.Password != "" && len(in.Password) < 16) {
			return nil, errors.New("password of 16+ characters, or empty to turn it off")
		}
		sql := "ALTER ROLE app_ro PASSWORD NULL"
		if in.Password != "" {
			sql = "ALTER ROLE app_ro PASSWORD '" + strings.ReplaceAll(in.Password, "'", "''") + "'"
		}
		err := run("psql", "-h", p.run, "-U", "dply_admin", "-d", "postgres", "-v", "ON_ERROR_STOP=1", "-qAtc", sql)
		return map[string]bool{"ok": err == nil}, err
	case "query":
		in, err := parseConsole(body)
		if err != nil {
			return nil, err
		}
		return p.console(in.SQL)
	}
	return nil, errUnknownAction
}

// console runs one statement as app_ro (SELECT only, read-only transaction)
// with the extended protocol, which refuses more than one statement.
func (p *postgres) console(sql string) (*consoleResult, error) {
	if strings.TrimSpace(sql) == "" {
		return nil, errors.New("type a query")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	conn, err := pgx.Connect(ctx, "host="+p.run+" user=app_ro dbname=app")
	if err != nil {
		return nil, err
	}
	defer conn.Close(context.Background())
	tx, err := conn.BeginTx(ctx, pgx.TxOptions{AccessMode: pgx.ReadOnly})
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback(context.Background()) }()
	if _, err := tx.Exec(ctx, "SET LOCAL statement_timeout = '15s'"); err != nil {
		return nil, err
	}
	started := time.Now()
	rows, err := tx.Query(ctx, sql, pgx.QueryResultFormats{pgx.TextFormatCode})
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	res := &consoleResult{Rows: [][]any{}}
	for _, f := range rows.FieldDescriptions() {
		res.Columns = append(res.Columns, f.Name)
	}
	for rows.Next() {
		if len(res.Rows) == consoleRows {
			res.Truncated = true
			break
		}
		raw := rows.RawValues()
		row := make([]any, len(raw))
		for i, v := range raw {
			row[i] = cell(v, v == nil)
		}
		res.Rows = append(res.Rows, row)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	res.Millis = float64(time.Since(started).Microseconds()) / 1000
	return res, nil
}

// readOnlyRole keeps app_ro able to read everything app owns, including
// tables later migrations create. Run on every wake (after setTenant).
func (p *postgres) readOnlyRole() error {
	if err := run("psql", "-h", p.run, "-U", "dply_admin", "-d", "postgres", "-v", "ON_ERROR_STOP=1", "-qAtc", `DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_ro') THEN CREATE ROLE app_ro LOGIN; END IF;
END $$; ALTER ROLE app_ro SET default_transaction_read_only = on; ALTER ROLE app_ro SET statement_timeout = '30s'`); err != nil {
		return err
	}
	return run("psql", "-h", p.run, "-U", "dply_admin", "-d", "app", "-v", "ON_ERROR_STOP=1", "-qAtc",
		`GRANT CONNECT ON DATABASE app TO app_ro; GRANT USAGE ON SCHEMA public TO app_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO app_ro;
ALTER DEFAULT PRIVILEGES FOR ROLE app IN SCHEMA public GRANT SELECT ON TABLES TO app_ro`)
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}
