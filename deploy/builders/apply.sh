#!/usr/bin/env bash
# Deploy (or update) the dply build servers on the DOKS cluster from
# deploy/valkey/terraform (pool `builders`). Idempotent. Nothing secret is in
# the repo; it comes from:
#   ../valkey/.secrets/do.env   DIGITALOCEAN_TOKEN (kubeconfig), as for the gateway
#   .secrets/builder.env        the builder's env (see docs/self-hosting-runbook.md
#                               "Builders" for the exact, least-privilege key list)
#
#   ./apply.sh <image> [max_replicas]
#   ./apply.sh --dry-run <image>        render (+ kubeconform) only; no cluster
#
# Installs KEDA (pinned release manifest) the first time; the repo has no
# Helm provider, so it goes on with kubectl like cert-manager does.
set -euo pipefail
cd "$(dirname "$0")"
DRY=""
if [ "${1:-}" = "--dry-run" ]; then DRY="--dry-run=client"; shift; fi
IMAGE=${1:?usage: ./apply.sh [--dry-run] registry.digitalocean.com/dply-cloud/dply-builder:<tag> [max_replicas]}
MAX_REPLICAS=${2:-4}
KEDA=v2.17.2
umask 077

# KEY='value' / KEY="value" → KEY=value: kubectl --from-env-file keeps quotes literally.
unquote() { sed -E -e "s/^([A-Za-z0-9_]+)='(.*)'$/\\1=\\2/" -e 's/^([A-Za-z0-9_]+)="(.*)"$/\1=\2/'; }
env_get() { { grep -hE "^$1=" ../../.env.production .secrets/builder.env || true; } | tail -1 | unquote | cut -d= -f2-; }

if [ -z "$DRY" ]; then
  # CI passes DIGITALOCEAN_TOKEN and DOKS_CLUSTER_ID; a laptop reads its secrets and terraform state.
  # shellcheck source=/dev/null
  [ -n "${DIGITALOCEAN_TOKEN:-}" ] || source ../valkey/.secrets/do.env
  cluster_id=${DOKS_CLUSTER_ID:-$(cd ../valkey/terraform && terraform output -raw cluster_id)}
  node -e "fetch('https://api.digitalocean.com/v2/kubernetes/clusters/$cluster_id/kubeconfig',{headers:{Authorization:'Bearer '+process.env.DIGITALOCEAN_TOKEN}}).then(r=>{if(!r.ok)throw new Error('kubeconfig '+r.status);return r.text()}).then(t=>require('fs').writeFileSync('kubeconfig',t))"
  export KUBECONFIG=$PWD/kubeconfig
  kubectl apply --server-side -f "https://github.com/kedacore/keda/releases/download/$KEDA/keda-${KEDA#v}.yaml" >/dev/null
  kubectl -n keda wait --for=condition=Available deploy --all --timeout=300s
fi

[ -s .secrets/builder.env ] || { echo "missing deploy/builders/.secrets/builder.env" >&2; exit 1; }
for key in APP_KEY REDIS_URL DB_HOST DPLY_EDGE_R2_BUCKET; do
  [ -n "$(env_get "$key")" ] || { echo "builder.env has no $key" >&2; exit 1; }
done
if grep -qE '^(SECRET_VAULT_IDENTITY_PATH|DB_ADMIN_|SECRET_VAULT_CRITICAL_PG_PASSWORD)=' .secrets/builder.env; then
  echo "builder.env holds admin/escrow-identity material; builders must not (runbook: Builders)." >&2; exit 1
fi

# The KEDA scaler reads the same Valkey the queue uses.
redis_url=$(env_get REDIS_URL)
node -e '
  const u = new URL(process.argv[1]);
  const out = { host: u.hostname, port: u.port || "6380", username: decodeURIComponent(u.username || "default"), password: decodeURIComponent(u.password) };
  process.stdout.write(Object.entries(out).map(([k, v]) => `${k}=${v}`).join("\n") + "\n");
' "$redis_url" > .secrets/keda.env
prefix=$(env_get REDIS_PREFIX); prefix=${prefix:-$(env_get APP_NAME | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-|-$//g')-database-}

render() { sed -e "s#__IMAGE__#$IMAGE#g" -e "s#__MAX_REPLICAS__#$MAX_REPLICAS#g" -e "s#__QUEUE_LIST__#${prefix}queues:dply-provision#g" k8s/builder.yaml; }

if [ -n "$DRY" ]; then
  # Offline: render, and schema-check with kubeconform when installed
  # (KEDA kinds via the CRDs catalog). kubectl --dry-run=client still needs
  # an API server for discovery.
  render > .secrets/rendered.yaml
  if command -v kubeconform >/dev/null; then
    kubeconform -strict -summary -schema-location default \
      -schema-location 'https://raw.githubusercontent.com/datreeio/CRDs-catalog/main/{{.Group}}/{{.ResourceKind}}_{{.ResourceAPIVersion}}.json' .secrets/rendered.yaml
  fi
  echo "Rendered: deploy/builders/.secrets/rendered.yaml"
  exit 0
fi

kubectl create namespace dply-builders --dry-run=client -o yaml | kubectl apply -f - >/dev/null
grep -E '^[A-Za-z0-9_]+=' .secrets/builder.env | unquote > .secrets/builder.clean.env
kubectl -n dply-builders create secret generic dply-builder-env --from-env-file=.secrets/builder.clean.env --dry-run=client -o yaml | kubectl apply -f - >/dev/null
rm -f .secrets/builder.clean.env
kubectl -n dply-builders create secret generic dply-builder-keda --from-env-file=.secrets/keda.env --dry-run=client -o yaml | kubectl apply -f - >/dev/null
render | kubectl apply -f -
# A changed Secret does not restart pods; a rollout does, draining each one.
kubectl -n dply-builders rollout restart deploy/dply-builder
kubectl -n dply-builders rollout status deploy/dply-builder --timeout=9000s
