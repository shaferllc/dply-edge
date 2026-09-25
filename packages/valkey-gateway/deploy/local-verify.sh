#!/usr/bin/env bash
# End-to-end check of the local gateway (run deploy/local-up.sh first):
# create, first wake, sleep with snapshot, wake with restore, memory limit,
# tenant isolation, and a persistent (pro) tenant.
set -uo pipefail
cd "$(dirname "$0")/.."
TOKEN=$(cat deploy/.local/token)
API=http://localhost:8080
PW1=$(openssl rand -hex 16)
PW2=$(openssl rand -hex 16)
fail=0

api() { curl -sS -H "Authorization: Bearer $TOKEN" "$@"; }
# A TLS-capable valkey-cli, kept running so each call is a quick exec.
docker rm -f dply-vk-client >/dev/null 2>&1
docker run -d --name dply-vk-client --entrypoint sleep valkey/valkey:8-alpine 3600 >/dev/null
trap 'docker rm -f dply-vk-client >/dev/null 2>&1' EXIT
vk() { local id=$1 pw=$2; shift 2; docker exec dply-vk-client valkey-cli --tls --insecure --sni "$id.cache.dply.local" -h host.docker.internal -p 6380 --user default --pass "$pw" --no-auth-warning "$@"; }
for i in $(seq 1 30); do [ "$(curl -s $API/healthz)" = ok ] && break; sleep 1; done
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: expected [$3] got [$2]"; fail=1; fi; }
ms() { python3 -c 'import time; print(int(time.time()*1000))'; }

api -X DELETE $API/tenants/t-flex >/dev/null; api -X DELETE $API/tenants/t-other >/dev/null; api -X DELETE $API/tenants/t-pro >/dev/null
sleep 3

echo "info put: $(api -X PUT $API/tenants/t-flex -d "{\"password\":\"$PW1\",\"memory_mb\":50,\"sleep_after\":15}")"
api -X PUT $API/tenants/t-other -d "{\"password\":\"$PW2\",\"memory_mb\":50,\"sleep_after\":0}" >/dev/null

t0=$(ms); out=$(vk t-flex "$PW1" SET greeting hello); t1=$(ms)
check "first connection starts the tenant" "$out" "OK"
echo "info cold start (no snapshot): $((t1 - t0)) ms"
check "reads back" "$(vk t-flex "$PW1" GET greeting)" "hello"
vk t-flex "$PW1" SET short-lived x PX 600000 >/dev/null
check "tenant cannot run CONFIG" "$(vk t-flex "$PW1" CONFIG GET maxmemory 2>&1 | grep -c NOPERM)" "1"

check "another tenant cannot see it" "$(vk t-other "$PW2" GET greeting)" ""
denied=$(vk t-flex "$PW2" GET greeting 2>&1)
check "wrong password is refused" "$(case "$denied" in *WRONGPASS*) echo refused;; esac)" "refused"
check "unknown host is dropped" "$(docker exec dply-vk-client valkey-cli --tls --insecure --sni nope.example.com -h host.docker.internal -p 6380 PING 2>&1 | grep -c PONG)" "0"

oom=""
for i in $(seq 1 80); do r=$(vk t-flex "$PW1" EVAL "redis.call('SET', KEYS[1], string.rep('x', 1048576)) return 'OK'" 1 "big:$i" 2>&1); case "$r" in *OOM*) oom=yes; break;; esac; done
check "maxmemory is enforced" "$oom" "yes"
vk t-flex "$PW1" EVAL "for _,k in ipairs(redis.call('KEYS','big:*')) do redis.call('DEL',k) end return 1" 0 >/dev/null

echo "info waiting for the idle sleep (15s idle + up to 10s reaper tick)..."
for i in $(seq 1 40); do
  awake=$(api $API/tenants/t-flex | python3 -c 'import sys,json; print(json.load(sys.stdin)["awake"])')
  [ "$awake" = "False" ] && break
  sleep 1
done
check "idle tenant is asleep" "$awake" "False"
check "a snapshot was stored" "$(api $API/tenants/t-flex | python3 -c 'import sys,json; print(json.load(sys.stdin)["has_snapshot"])')" "True"

t0=$(ms); out=$(vk t-flex "$PW1" GET greeting); t1=$(ms)
check "waking restores the data" "$out" "hello"
wake_ms=$((t1 - t0))
check "a key's expiry survives the sleep" "$([ "$(vk t-flex "$PW1" PTTL short-lived)" -gt 0 ] && echo yes)" "yes"
check "the wake adopted a warm pod" "$(kubectl --context orbstack -n dply-valkey get pods -l tenant=t-flex -o name | grep -c vkp-)" "1"
check "wake is under a second" "$([ "$wake_ms" -lt 1000 ] && echo yes)" "yes"
secs=$(api $API/usage | python3 -c 'import sys,json; print(json.load(sys.stdin)["awake_seconds"].get("t-flex", 0))')
check "awake time was counted" "$([ "$secs" -ge 15 ] && echo yes)" "yes"
echo "info t-flex awake seconds so far: $secs"
echo "info wake with restore: $((t1 - t0)) ms"

api -X PUT $API/tenants/t-pro -d "{\"password\":\"$PW1\",\"memory_mb\":50,\"persistent\":true}" >/dev/null
check "pro tenant writes" "$(vk t-pro "$PW1" SET k v)" "OK"
kubectl --context orbstack -n dply-valkey delete pod vk-t-pro --now >/dev/null 2>&1
check "pro tenant keeps data across a pod restart" "$(vk t-pro "$PW1" GET k)" "v"

exit $fail
