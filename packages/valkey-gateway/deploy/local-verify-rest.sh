#!/usr/bin/env bash
# End-to-end check of Redis over REST (rest.go) on the local gateway
# (run deploy/local-up.sh first): Upstash's REST shapes, Lua with the
# Upstash SDK's script flag, refusals, a bad token, a REST request that
# wakes a sleeping tenant, and the command count in /usage.
set -uo pipefail
cd "$(dirname "$0")/.."
TOKEN=$(cat deploy/.local/token)
API=http://localhost:8080
ID=t-rest
PW=$(openssl rand -hex 16)
URL=https://$ID.cache.dply.local:8443
fail=0

api() { curl -sS -H "Authorization: Bearer $TOKEN" "$@"; }
rest() { curl -sS --cacert deploy/.local/tls.crt --resolve "$ID.cache.dply.local:8443:127.0.0.1" -H "Authorization: Bearer $PW" "$@"; }
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: expected [$3] got [$2]"; fail=1; fi; }
for i in $(seq 1 30); do [ "$(curl -s $API/healthz)" = ok ] && break; sleep 1; done

api -X DELETE $API/tenants/$ID >/dev/null; sleep 2
api -X PUT $API/tenants/$ID -d "{\"password\":\"$PW\",\"memory_mb\":50,\"sleep_after\":15}" >/dev/null

check "path-style SET wakes and writes" "$(rest $URL/set/greeting/hello)" '{"result":"OK"}'
check "GET" "$(rest $URL/get/greeting)" '{"result":"hello"}'
check "base64 encoding" "$(rest -H 'Upstash-Encoding: base64' $URL/get/greeting)" '{"result":"aGVsbG8="}'
check "POST body is the last argument" "$(rest -X POST "$URL/set/note?EX=100" --data-binary 'two words')" '{"result":"OK"}'
check "JSON command" "$(rest -X POST $URL/ -d '["GET","note"]')" '{"result":"two words"}'
check "pipeline" "$(rest -X POST $URL/pipeline -d '[["INCR","n"],["INCR","n"],["GET","n"]]')" '[{"result":1},{"result":2},{"result":"2"}]'
check "multi-exec" "$(rest -X POST $URL/multi-exec -d '[["SET","t","1"],["INCRBY","t","41"]]')" '[{"result":"OK"},{"result":42}]'
check "Lua with the Upstash flag" "$(rest -X POST $URL/ -d '["EVAL","#!lua flags=allow-key-locking\nreturn redis.call(\"GET\", KEYS[1])","1","greeting"]')" '{"result":"hello"}'
sha=$(rest -X POST $URL/ -d '["SCRIPT","LOAD","#!lua flags=allow-key-locking\nreturn 7"]' | python3 -c 'import sys,json; print(json.load(sys.stdin)["result"])')
want=$(printf '#!lua flags=allow-key-locking\nreturn 7' | shasum | cut -d' ' -f1)
check "SCRIPT LOAD answers with the client's SHA" "$sha" "$want"
check "EVALSHA with the client's SHA" "$(rest -X POST $URL/ -d "[\"EVALSHA\",\"$sha\",\"0\"]")" '{"result":7}'
check "blocking commands are refused" "$(rest -o /dev/null -w '%{http_code}' -X POST $URL/ -d '["BLPOP","q","1"]')" "400"
check "a bad token is refused" "$(curl -sS -o /dev/null -w '%{http_code}' --cacert deploy/.local/tls.crt --resolve "$ID.cache.dply.local:8443:127.0.0.1" -H 'Authorization: Bearer nope' $URL/get/greeting)" "401"

echo "info waiting for the idle sleep (15s idle + up to 10s reaper tick)..."
for i in $(seq 1 40); do
  awake=$(api $API/tenants/$ID | python3 -c 'import sys,json; print(json.load(sys.stdin)["awake"])')
  [ "$awake" = "False" ] && break
  sleep 1
done
check "idle tenant is asleep" "$awake" "False"
check "a REST request wakes it with the data" "$(rest $URL/get/greeting)" '{"result":"hello"}'

echo "info waiting for the command count to flush (every 30s)..."
for i in $(seq 1 40); do
  n=$(api $API/usage | python3 -c "import sys,json; print(json.load(sys.stdin).get('rest_commands', {}).get('$ID', 0))")
  [ "$n" -ge 14 ] && break
  sleep 1
done
check "REST commands are counted in /usage" "$([ "$n" -ge 14 ] && echo yes)" "yes"
echo "info $ID REST commands: $n"

api -X DELETE $API/tenants/$ID >/dev/null
exit $fail
