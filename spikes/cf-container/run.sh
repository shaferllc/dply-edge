#!/usr/bin/env bash
# Spike runner — see README.md. Every step prints PASS/FAIL for one question.
set -uo pipefail
cd "$(dirname "$0")"
NS=dply-container-spike

if [[ "${1:-}" == "cleanup" ]]; then
  (cd dispatcher && npx wrangler delete --force) || true
  (cd container-worker && npx wrangler delete --force --dispatch-namespace "$NS") || true
  npx wrangler dispatch-namespace delete "$NS" || true
  exit 0
fi

: "${CLOUDFLARE_ACCOUNT_ID:?set CLOUDFLARE_ACCOUNT_ID}" "${CLOUDFLARE_API_TOKEN:?set CLOUDFLARE_API_TOKEN}" "${DATABASE_URL:?set DATABASE_URL}"
docker info >/dev/null 2>&1 || { echo "FAIL docker is not running"; exit 1; }

echo "== Q3: docker build from inside node:22-bookworm via the host socket"
if docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$PWD/app:/app" -w /app node:22-bookworm \
    bash -lc 'apt-get update -qq && apt-get install -y -qq docker.io >/dev/null && docker build --platform linux/amd64 -q -t php-spike-probe .' >/dev/null; then
  echo "PASS docker build works inside the build image"
else
  echo "FAIL docker build inside the build image"
fi

echo "== Deploy container Worker into dispatch namespace $NS"
npx wrangler dispatch-namespace create "$NS" 2>/dev/null || true
(cd container-worker && npm install --silent && npx wrangler deploy --dispatch-namespace "$NS" --var "DATABASE_URL:$DATABASE_URL") || { echo "FAIL Q1 deploy into dispatch namespace"; exit 1; }

echo "== Deploy dispatcher"
URL=$(cd dispatcher && npx wrangler deploy 2>&1 | grep -Eo 'https://[a-z0-9.-]+workers\.dev' | head -1)
[[ -n "$URL" ]] || { echo "FAIL dispatcher deploy"; exit 1; }
echo "dispatcher: $URL"

echo "== Q1: request through DISPATCHER.get().fetch() (first call includes image rollout + cold start)"
for i in $(seq 1 30); do
  body=$(curl -s -w ' %{http_code} %{time_total}s' "$URL/") && echo "$body" | grep -q '"php"' && { echo "PASS $body"; break; }
  echo "  waiting ($i): $body"; sleep 10
done

echo "== Cold start after sleep is measured manually: wait >5m, then curl $URL/ and read X-Spike-Container-Ms"
curl -s -D - -o /dev/null "$URL/" | grep -i x-spike || true

echo "== Q2: raw TCP to external Postgres from the container"
body=$(curl -s -w ' %{http_code}' "$URL/db")
echo "$body" | grep -q '"ok":true' && echo "PASS $body" || echo "FAIL $body"
