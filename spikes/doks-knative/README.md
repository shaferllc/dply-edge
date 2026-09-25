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

## Destroy

```sh
kubectl delete -f app.example.yaml   # before destroy, so the LB is released cleanly
terraform destroy
```
