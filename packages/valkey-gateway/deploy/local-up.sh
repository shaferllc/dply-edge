#!/usr/bin/env bash
# Build the gateway and run the local stack on OrbStack Kubernetes.
# Writes the API token and CA cert to deploy/.local/ (gitignored).
set -euo pipefail
cd "$(dirname "$0")/.."
ctx=(--context orbstack)
mkdir -p deploy/.local

docker build -q -t dply/valkey-gateway:local . >/dev/null
docker build -q -f dbagent/Dockerfile.postgres -t dply/postgres:17 . >/dev/null

if [ ! -f deploy/.local/tls.crt ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=*.cache.dply.local" \
    -addext "subjectAltName=DNS:*.cache.dply.local" \
    -keyout deploy/.local/tls.key -out deploy/.local/tls.crt 2>/dev/null
fi
[ -f deploy/.local/token ] || openssl rand -hex 24 | tr -d '\n' > deploy/.local/token
[ -f deploy/.local/admin ] || openssl rand -hex 24 | tr -d '\n' > deploy/.local/admin

kubectl "${ctx[@]}" apply -f deploy/local.yaml >/dev/null
kubectl "${ctx[@]}" -n dply-valkey create secret tls valkey-gateway-tls \
  --cert=deploy/.local/tls.crt --key=deploy/.local/tls.key --dry-run=client -o yaml | kubectl "${ctx[@]}" apply -f - >/dev/null
kubectl "${ctx[@]}" -n dply-valkey create secret generic valkey-gateway-api \
  --from-file=token=deploy/.local/token --dry-run=client -o yaml | kubectl "${ctx[@]}" apply -f - >/dev/null
kubectl "${ctx[@]}" -n dply-valkey create secret generic valkey-gateway-admin \
  --from-file=password=deploy/.local/admin --dry-run=client -o yaml | kubectl "${ctx[@]}" apply -f - >/dev/null
kubectl "${ctx[@]}" -n dply-valkey rollout restart deployment/valkey-gateway >/dev/null
kubectl "${ctx[@]}" -n dply-valkey rollout status deployment/s3 --timeout=120s >/dev/null
kubectl "${ctx[@]}" -n dply-valkey rollout status deployment/valkey-gateway --timeout=120s >/dev/null
# Pods from before the warm pool used app=dply-valkey; they are not managed any more.
kubectl "${ctx[@]}" -n dply-valkey delete pods -l app=dply-valkey --now >/dev/null 2>&1 || true
# Give the reaper a tick to fill the warm pool.
sleep 15
echo "gateway up: TLS proxy localhost:6380, API http://localhost:8080"
