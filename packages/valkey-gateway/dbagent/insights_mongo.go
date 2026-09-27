package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"strings"
)

// MongoDB 7 has no per-query statistics ($queryStats is 8.0+), so its
// insights are what is running now and indexes nothing has used.
func (m *mongo) insights() (map[string]any, error) {
	base, err := m.stats()
	if err != nil {
		return nil, err
	}
	out := map[string]any{}
	if err := json.Unmarshal(base, &out); err != nil {
		return nil, err
	}
	more, err := m.evalJSON(`
const app = db.getSiblingDB('app');
const running = db.getSiblingDB('admin').aggregate([{$currentOp: {allUsers: true, idleConnections: false}}, {$match: {ns: /^app\./, active: true}}]).toArray()
  .map(o => ({pid: o.opid, state: o.op, seconds: o.secs_running || 0, query: JSON.stringify(o.command || {}).slice(0, 500)}));
const unused = [];
for (const c of app.getCollectionInfos({type: 'collection'})) {
  for (const s of app.getCollection(c.name).aggregate([{$indexStats: {}}]).toArray()) {
    if (s.name !== '_id_' && !(s.spec && s.spec.unique) && Number(s.accesses.ops) === 0) unused.push({table: c.name, index: s.name});
  }
}
print(JSON.stringify({running, unused_indexes: unused.slice(0, 20), queries: null}))`)
	if err != nil {
		return nil, err
	}
	for k, v := range more {
		out[k] = v
	}
	return out, nil
}

func (m *mongo) evalJSON(js string) (map[string]any, error) {
	raw, err := m.eval(js)
	if err != nil {
		return nil, err
	}
	out := map[string]any{}
	if err := json.Unmarshal([]byte(lastLines(raw, 1)), &out); err != nil {
		return nil, fmt.Errorf("mongosh: unexpected output: %s", lastLines(raw, 1))
	}
	return out, nil
}

func (m *mongo) action(name string, body []byte) (any, error) {
	switch name {
	case "cancel":
		var in struct{ PID int64 }
		if err := json.Unmarshal(body, &in); err != nil || in.PID <= 0 {
			return nil, errors.New("pid required")
		}
		out, err := m.evalJSON(fmt.Sprintf(`const op = db.getSiblingDB('admin').aggregate([{$currentOp: {allUsers: true}}, {$match: {opid: %d, ns: /^app\./}}]).toArray();
if (op.length) db.killOp(%d); print(JSON.stringify({ok: op.length > 0}))`, in.PID, in.PID))
		return out, err
	case "readonly":
		var in struct{ Password string }
		if err := json.Unmarshal(body, &in); err != nil || (in.Password != "" && len(in.Password) < 16) {
			return nil, errors.New("password of 16+ characters, or empty to turn it off")
		}
		pw, _ := json.Marshal(in.Password)
		out, err := m.evalJSON(fmt.Sprintf(`const app = db.getSiblingDB('app'), pw = %s;
if (pw === '') { if (app.getUser('app_ro')) app.dropUser('app_ro'); }
else if (app.getUser('app_ro')) app.updateUser('app_ro', {pwd: pw});
else app.createUser({user: 'app_ro', pwd: pw, roles: [{role: 'read', db: 'app'}]});
print(JSON.stringify({ok: true}))`, pw))
		return out, err
	case "query":
		in, err := parseConsole(body)
		if err != nil {
			return nil, err
		}
		return m.console(in.Collection, in.Filter)
	}
	return nil, errUnknownAction
}

// console is a find, not evaluated JavaScript: the collection and filter
// reach mongosh as JSON string literals, parsed there with EJSON.parse.
func (m *mongo) console(collection, filter string) (any, error) {
	if strings.TrimSpace(collection) == "" {
		return nil, errors.New("pick a collection")
	}
	if strings.TrimSpace(filter) == "" {
		filter = "{}"
	}
	// Server-side JavaScript is the one way a filter could run code.
	for _, op := range []string{"$where", "$function", "$accumulator"} {
		if strings.Contains(filter, op) {
			return nil, fmt.Errorf("%s is not allowed here", op)
		}
	}
	c, _ := json.Marshal(collection)
	f, _ := json.Marshal(filter)
	return m.evalJSON(fmt.Sprintf(`const started = Date.now();
const docs = db.getSiblingDB('app').getCollection(%s).find(EJSON.parse(%s)).maxTimeMS(15000).limit(%d).toArray();
const rows = docs.slice(0, %d).map(d => [EJSON.stringify(d, {relaxed: true}).slice(0, 2000)]);
print(JSON.stringify({columns: ["document"], rows, truncated: docs.length > %d, ms: Date.now() - started}))`, c, f, consoleRows+1, consoleRows, consoleRows))
}
