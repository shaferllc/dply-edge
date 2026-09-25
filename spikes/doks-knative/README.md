# Spike: dedicated compute on DOKS + Knative (T-023)

Can we run dply container sites on our own Kubernetes with scale to zero and
concurrency autoscaling, and what does it cost? See T-022 for why; the
Cloudflare path already covers autoscaling, min instances and scaling windows.

Nothing here is applied automatically. **`apply` creates billable resources**
(a DOKS cluster, 1–3 droplets, a load balancer): roughly $50–150/month while
it exists. `destroy` when done.

## What it creates

| Piece | Why |
|---|---|
| DOKS cluster, autoscaling pool (`s-2vcpu-4gb`, 1–3 nodes) | The compute |
| Registry (only if `create_registry = true`; DO allows one per account) | Images from `EdgeContainerDockerfile` |
| Knative Operator + `KnativeServing` with Kourier | Scale to zero, holds requests while a pod starts, concurrency autoscaling |
| `apps` namespace, NetworkPolicy, ResourceQuota | The per-org isolation shape |
| `gvisor` RuntimeClass (only if `enable_gvisor = true`) | Untrusted code. DOKS nodes have no `runsc`; see open questions |

## Run it

```sh
export TF_VAR_do_token=dop_v1_...
terraform init
terraform apply                        # add -var create_registry=true if the account has no registry
$(terraform output -raw kubeconfig_command)
kubectl -n knative-serving get pods    # wait for Running
```

Deploy one app: build any Laravel repo with the generated Dockerfile, push it,
edit the image and `APP_KEY` in `app.example.yaml`, then:

```sh
kubectl apply -f app.example.yaml
IP=$(eval "$(terraform output -raw ingress_ip_command)")
curl -H "Host: laravel-spike.apps.example.com" "http://$IP/"
```

## What to measure (write the numbers into docs/EDGE_PLATFORM_STATUS.md)

1. **Cold wake**: wait past `scale-to-zero-grace-period` (30s) until
   `kubectl -n apps get pods` is empty, then time the first `curl`. p50/p95 over 10 tries.
   Also time it with the image not yet on the node (scale the pool, or a new tag).
2. **Autoscaling**: `hey -z 60s -c 40` against the app; watch
   `kubectl -n apps get pods -w` grow to `max-scale` and shrink back to zero.
3. **Idle cost**: the cluster with no traffic for a day (DO billing page).
4. **gVisor**: can `runsc` get onto DOKS nodes without a custom image
   (a privileged installer DaemonSet that edits containerd config)? If not, the
   options are Kata on self-managed nodes, or not running untrusted code on shared nodes.

## Local results (OrbStack, 2026-09-25)

`./local.sh install`, then the generated image for a fresh
`laravel/vue-starter-kit` app (php-fpm, Inertia SSR) at containerConcurrency 2,
max-scale 3. Knative Serving 1.18, Kubernetes 1.35, arm64.

| | Result |
|---|---|
| Cold wake from zero pods | 5/5 HTTP 200, p50 3.7s, max 6.6s |
| Warm request | ~20ms (`/whoami`), 620ms home page with SSR |
| Scale to zero | ~62s after the last request (30s grace + stable window) |
| 6 concurrent 4s requests | all 200, but 2 pods split 4/2, 9.1s total: the third pod came up after the requests were already queued |
| gVisor | not tested (OrbStack's node is not DOKS) |

What it found:

- **Every cold start 502'd** until this was fixed: the generated fpm
  image started nginx before php-fpm listened, and readiness only checks
  port 8080. Fixed in `EdgeContainerDockerfile` (nginx waits for :9000); the
  Cloudflare Worker's `waitForPort` had the same blind spot.
- Knative's autoscaler reacts to observed concurrency, so a burst waits in the
  queue. The Cloudflare Worker gives out slots immediately and put the same six
  requests on three instances, 2/2/2, in ~4s.
- Cold wakes are about the same as Cloudflare Containers locally (3.2s),
  well off Laravel Cloud's claimed <500ms. Getting there needs the warm-pool
  or snapshot work in T-026 on either platform.

## Destroy

```sh
kubectl delete -f app.example.yaml   # before destroy, so the LB is released cleanly
terraform destroy
```
