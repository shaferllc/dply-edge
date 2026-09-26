package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"os/exec"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
)

// mysqlInsightsSQL is one fixed query returning one JSON document. Top
// queries come from performance_schema's statement digests (on by default).
const mysqlInsightsSQL = `SELECT JSON_OBJECT(
 'engine', 'mysql', 'version', CONCAT('MySQL ', VERSION()),
 'uptime_seconds', (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Uptime') + 0,
 'size_bytes', (SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = 'app') + 0,
 'tables', (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'app'),
 'rows', (SELECT COALESCE(SUM(table_rows), 0) FROM information_schema.tables WHERE table_schema = 'app') + 0,
 'connections', (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Threads_connected') + 0,
 'max_connections', @@max_connections,
 'cache_hit_ratio', (SELECT ROUND((r.v - d.v) / r.v * 100, 1)
   FROM (SELECT VARIABLE_VALUE + 0 AS v FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Innodb_buffer_pool_read_requests') r,
        (SELECT VARIABLE_VALUE + 0 AS v FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Innodb_buffer_pool_reads') d WHERE r.v > 0),
 'commits', (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Handler_commit') + 0,
 'rollbacks', (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Handler_rollback') + 0,
 'largest', (SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT('name', n, 'rows', r, 'bytes', b)), JSON_ARRAY()) FROM (
   SELECT table_name AS n, COALESCE(table_rows, 0) AS r, data_length + index_length AS b FROM information_schema.tables
   WHERE table_schema = 'app' ORDER BY b DESC LIMIT 5) x),
 'queries_since', (SELECT MIN(FIRST_SEEN) FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = 'app'),
 'queries', (SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT('query', q, 'calls', c, 'total_ms', t, 'mean_ms', m, 'rows', r)), JSON_ARRAY()) FROM (
   SELECT LEFT(DIGEST_TEXT, 2000) AS q, COUNT_STAR AS c, ROUND(SUM_TIMER_WAIT / 1e9, 1) AS t,
     ROUND(AVG_TIMER_WAIT / 1e9, 2) AS m, SUM_ROWS_SENT AS r
   FROM performance_schema.events_statements_summary_by_digest
   WHERE SCHEMA_NAME = 'app' AND DIGEST_TEXT IS NOT NULL ORDER BY SUM_TIMER_WAIT DESC LIMIT 25) x),
 'running', (SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT('pid', i, 'state', s, 'seconds', t, 'query', q)), JSON_ARRAY()) FROM (
   SELECT ID AS i, COALESCE(NULLIF(STATE, ''), COMMAND) AS s, TIME AS t, LEFT(INFO, 500) AS q
   FROM information_schema.PROCESSLIST WHERE USER = 'app' AND COMMAND <> 'Sleep' ORDER BY TIME DESC) x),
 'by_state', JSON_OBJECT(
   'active', (SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER = 'app' AND COMMAND <> 'Sleep'),
   'idle', (SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER = 'app' AND COMMAND = 'Sleep'),
   'idle in transaction', (SELECT COUNT(*) FROM information_schema.innodb_trx t
     JOIN information_schema.PROCESSLIST p ON p.ID = t.trx_mysql_thread_id WHERE p.USER = 'app' AND p.COMMAND = 'Sleep')),
 'unused_indexes', (SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT('table', tb, 'index', ix)), JSON_ARRAY()) FROM (
   SELECT DISTINCT u.OBJECT_NAME AS tb, u.INDEX_NAME AS ix FROM performance_schema.table_io_waits_summary_by_index_usage u
   JOIN information_schema.STATISTICS s ON s.TABLE_SCHEMA = u.OBJECT_SCHEMA AND s.TABLE_NAME = u.OBJECT_NAME AND s.INDEX_NAME = u.INDEX_NAME
   WHERE u.OBJECT_SCHEMA = 'app' AND u.INDEX_NAME IS NOT NULL AND u.INDEX_NAME <> 'PRIMARY' AND s.NON_UNIQUE = 1 AND u.COUNT_STAR = 0
   LIMIT 20) x),
 'full_scans', (SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT('table', tb, 'rows_scanned', n)), JSON_ARRAY()) FROM (
   SELECT object_name AS tb, rows_full_scanned AS n FROM sys.schema_tables_with_full_table_scans
   WHERE object_schema = 'app' ORDER BY rows_full_scanned DESC LIMIT 10) x)
)`

func (m *mysqlEngine) insights() (map[string]any, error) {
	// --raw: JSON_OBJECT already escapes; batch mode would escape again.
	cmd := exec.Command("mysql", "--socket="+m.socket(), "-uroot", "-p"+m.admin, "-N", "-B", "--raw", "-e", mysqlInsightsSQL)
	var stderr strings.Builder
	cmd.Stderr = &stderr
	b, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("mysql: %v: %s", err, lastLines(stderr.String(), 2))
	}
	out := map[string]any{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(string(b))), &out); err != nil {
		return nil, fmt.Errorf("mysql: unexpected output: %v", err)
	}
	return out, nil
}

func (m *mysqlEngine) action(name string, body []byte) (any, error) {
	switch name {
	case "queries-reset":
		_, err := m.query("TRUNCATE TABLE performance_schema.events_statements_summary_by_digest")
		return map[string]bool{"ok": err == nil}, err
	case "cancel":
		var in struct{ PID int }
		if err := json.Unmarshal(body, &in); err != nil || in.PID <= 0 {
			return nil, errors.New("pid required")
		}
		mine, err := m.query(fmt.Sprintf("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d AND USER = 'app'", in.PID))
		if err != nil || mine != "1" {
			return nil, errors.New("that query is not the app's, or has finished")
		}
		_, err = m.query(fmt.Sprintf("KILL QUERY %d", in.PID))
		return map[string]bool{"ok": err == nil}, err
	case "query":
		in, err := parseConsole(body)
		if err != nil {
			return nil, err
		}
		return m.console(in.SQL)
	}
	return nil, errUnknownAction
}

// consoleUser is a local, SELECT-only login the console uses. The app's
// gateway checks MySQL passwords itself, so there is no network read-only
// login for MySQL.
func (m *mysqlEngine) consoleUser() error {
	q := "'" + internalPassword(m.admin, "mysql-console") + "'"
	return m.sql(true, "CREATE USER IF NOT EXISTS 'dply_console'@'localhost' IDENTIFIED BY "+q+"; "+
		"ALTER USER 'dply_console'@'localhost' IDENTIFIED BY "+q+"; "+
		"GRANT SELECT, SHOW VIEW ON app.* TO 'dply_console'@'localhost'")
}

// console runs one statement (the driver sends no multi-statement flag) in a
// read-only transaction as dply_console.
func (m *mysqlEngine) console(query string) (*consoleResult, error) {
	if strings.TrimSpace(query) == "" {
		return nil, errors.New("type a query")
	}
	cfg := mysql.NewConfig()
	cfg.User, cfg.Passwd, cfg.Net, cfg.Addr, cfg.DBName = "dply_console", internalPassword(m.admin, "mysql-console"), "unix", m.socket(), "app"
	cfg.Timeout, cfg.ReadTimeout = 5*time.Second, 20*time.Second
	connector, err := mysql.NewConnector(cfg)
	if err != nil {
		return nil, err
	}
	db := sql.OpenDB(connector)
	defer db.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	tx, err := db.BeginTx(ctx, &sql.TxOptions{ReadOnly: true})
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()
	if _, err := tx.ExecContext(ctx, "SET SESSION max_execution_time = 15000"); err != nil {
		return nil, err
	}
	started := time.Now()
	rows, err := tx.QueryContext(ctx, query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	res := &consoleResult{Rows: [][]any{}}
	if res.Columns, err = rows.Columns(); err != nil {
		return nil, err
	}
	for rows.Next() {
		if len(res.Rows) == consoleRows {
			res.Truncated = true
			break
		}
		raw := make([]sql.RawBytes, len(res.Columns))
		dest := make([]any, len(raw))
		for i := range raw {
			dest[i] = &raw[i]
		}
		if err := rows.Scan(dest...); err != nil {
			return nil, err
		}
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
