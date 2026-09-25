#!/usr/bin/env bash
# Deploy the dply Valkey gateway to the DOKS cluster made by terraform/.
# Idempotent. Secrets come from .secrets/ and the app's .env, never the repo:
#   .secrets/do.env        DIGITALOCEAN_TOKEN (kubeconfig)
#   .secrets/api-token     gateway control API bearer token (made on first run)
#   .secrets/admin-password gateway admin password (made on first run)
#   ../../.env             DPLY_EDGE_R2_* (sleep snapshots), DPLY_EDGE_CF_API_TOKEN
#                          (cert-manager's DNS check: needs Zone:DNS:Edit on $DOMAIN)
#
#   ./apply.sh [image]     default: the last pushed gateway image
set -euo pipefail
cd "$(dirname "$0")"
IMAGE=${1:-registry.digitalocean.com/dply-cloud/valkey-gateway:202609250651}
DOMAIN=${DOMAIN:-dply.io}
CERT_MANAGER=v1.21.2
# shellcheck source=/dev/null
source .secrets/do.env
umask 077

cluster_id=$(cd terraform && terraform output -raw cluster_id)
node -e "fetch('https://api.digitalocean.com/v2/kubernetes/clusters/$cluster_id/kubeconfig',{headers:{Authorization:'Bearer '+process.env.DIGITALOCEAN_TOKEN}}).then(r=>{if(!r.ok)throw new Error('kubeconfig '+r.status);return r.text()}).then(t=>require('fs').writeFileSync('kubeconfig',t))"
export KUBECONFIG=$PWD/kubeconfig

kubectl apply -f "https://github.com/cert-manager/cert-manager/releases/download/$CERT_MANAGER/cert-manager.yaml" >/dev/null
kubectl -n cert-manager wait --for=condition=Available deploy --all --timeout=300s

[ -s .secrets/api-token ] || openssl rand -hex 32 > .secrets/api-token
[ -s .secrets/admin-password ] || openssl rand -hex 32 > .secrets/admin-password
app_env() { grep -E "^$1=" ../../.env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
r2() { app_env "DPLY_EDGE_R2_$1"; }

kubectl create namespace dply-valkey --dry-run=client -o yaml | kubectl apply -f - >/dev/null
secret() { kubectl -n dply-valkey create secret generic "$1" "${@:2}" --dry-run=client -o yaml | kubectl apply -f - >/dev/null; }
secret cloudflare-dns --from-literal=api-token="$(app_env DPLY_EDGE_CF_API_TOKEN)"
# --from-literal, not --from-file: the files end in a newline, which Valkey
# keeps as part of the password while the gateway trims it (WRONGPASS).
secret valkey-gateway-api --from-literal=token="$(tr -d '[:space:]' < .secrets/api-token)"
secret valkey-gateway-admin --from-literal=password="$(tr -d '[:space:]' < .secrets/admin-password)"
secret valkey-gateway-r2 --from-literal=bucket="$(r2 BUCKET)" --from-literal=endpoint="$(r2 ENDPOINT)" --from-literal=access-key="$(r2 ACCESS_KEY)" --from-literal=secret-key="$(r2 SECRET)"

sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__IMAGE__#$IMAGE#g" gateway.yaml | kubectl apply -f -

echo "Waiting for the wildcard certificate (DNS check, 1-3 min)..."
kubectl -n dply-valkey wait certificate/valkey-gateway-tls --for=condition=Ready --timeout=600s
kubectl -n dply-valkey rollout status deploy/valkey-gateway --timeout=300s
ip=$(kubectl -n dply-valkey get svc valkey-gateway -o jsonpath='{.status.loadBalancer.ingress[0].ip}')
echo "Gateway load balancer: ${ip:-pending}"
