#!/usr/bin/env bash
# The same spike on OrbStack's local Kubernetes: free, no DigitalOcean.
#
#   ./local.sh install                 # Knative Serving + Kourier (NodePort, so no host ports are taken)
#   ./local.sh app <image> <env-file>  # deploy a generated image as a Knative Service in `apps`
#   ./local.sh uninstall
#
# Images built with `docker build` are visible to OrbStack's cluster; tag them
# dev.local/<name>:<tag> so Knative skips registry tag resolution.
set -euo pipefail
cd "$(dirname "$0")"
V=knative-v1.18.0
[[ "$(kubectl config current-context)" == orbstack ]] || { echo "kubectl context is not orbstack"; exit 1; }

case "${1:-}" in
  install)
    kubectl apply -f "https://github.com/knative/serving/releases/download/$V/serving-crds.yaml"
    kubectl wait --for=condition=Established --all crd --timeout=60s
    kubectl apply -f "https://github.com/knative/serving/releases/download/$V/serving-core.yaml"
    # Kourier ships as a LoadBalancer; on OrbStack that binds host port 80.
    curl -fsSL "https://github.com/knative/net-kourier/releases/download/$V/kourier.yaml" \
      | sed 's/type: LoadBalancer/type: NodePort/' | kubectl apply -f -
    kubectl patch configmap/config-network -n knative-serving --type merge \
      -p '{"data":{"ingress-class":"kourier.ingress.networking.knative.dev"}}'
    # Without a domain every service is cluster-local and Kourier's external gateway 404s.
    kubectl patch configmap/config-domain -n knative-serving --type merge -p '{"data":{"example.com":""}}'
    kubectl patch configmap/config-autoscaler -n knative-serving --type merge \
      -p '{"data":{"enable-scale-to-zero":"true","scale-to-zero-grace-period":"30s","container-concurrency-target-percentage":"100"}}'
    kubectl wait -n knative-serving --for=condition=Available deploy --all --timeout=240s
    kubectl wait -n kourier-system --for=condition=Available deploy --all --timeout=240s
    echo "Kourier: http://127.0.0.1:$(kubectl -n kourier-system get svc kourier -o jsonpath='{.spec.ports[0].nodePort}') (send Host: <svc>.apps.example.com)"
    ;;
  app)
    image=${2:?image}; envfile=${3:?env file}
    kubectl create namespace apps --dry-run=client -o yaml | kubectl apply -f -
    kubectl -n apps create secret generic laravel-env --from-env-file="$envfile" --dry-run=client -o yaml | kubectl apply -f -
    sed -e "s#registry.digitalocean.com/REPLACE_ME/laravel-spike:latest#$image#" \
        -e '/name: APP_KEY/,+1d' app.example.yaml \
      | sed 's#          env:#          envFrom:\n            - secretRef:\n                name: laravel-env\n          env:#' \
      | kubectl apply -f -
    kubectl -n apps wait ksvc/laravel-spike --for=condition=Ready --timeout=180s
    ;;
  uninstall)
    # Services first, while Knative's controllers can still clear their
    # finalizers; deleting the namespace first leaves it stuck Terminating.
    kubectl delete ksvc --all -n apps --ignore-not-found --wait
    kubectl delete namespace apps --ignore-not-found
    kubectl delete -f "https://github.com/knative/net-kourier/releases/download/$V/kourier.yaml" --ignore-not-found
    kubectl delete -f "https://github.com/knative/serving/releases/download/$V/serving-core.yaml" --ignore-not-found
    kubectl delete -f "https://github.com/knative/serving/releases/download/$V/serving-crds.yaml" --ignore-not-found
    ;;
  *) sed -n '2,9p' "$0"; exit 1 ;;
esac
