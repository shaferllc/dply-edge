# Launch checklist

Owner steps that code cannot do on its own. Tick them off in order.

## Larger dply databases (1, 2, 4 CU)

Ruling r-s56bk4pq4cnv8dt4. Pricing and the idle-node risk: `docs/pricing-review.md` §9 "Larger databases".

1. **Add the node pools.** In `deploy/valkey/terraform`, for each region's workspace (`default` = nyc3, `sfo3`):
   ```
   terraform workspace select default
   TF_VAR_do_token=... terraform plan      # expect 2 new digitalocean_kubernetes_node_pool.db_large, nothing else
   TF_VAR_do_token=... terraform apply
   terraform workspace select sfo3
   TF_VAR_do_token=... terraform apply -var-file=regions/sfo3.tfvars
   ```
   This adds `db-large` (s-8vcpu-16gb) and `db-xl` (m-4vcpu-32gb), both at 0 nodes. They cost nothing until a large database needs one: then $96 or $168 a month per node.
2. **Deploy the gateway** (`packages/valkey-gateway`) in every region. The new build places databases over 0.5 CU on those pools; the old one would leave them Pending on the `db` pool.
3. **Turn the sizes on:** set `DPLY_DATABASE_LARGE_SIZES=true` in the app's env, then `php artisan config:cache` and `php artisan queue:restart`. The size list, the pricing page and the docs size table show 1, 2 and 4 vCPU from then on. They are always on (no sleep choice) and 1 vCPU bills $0.18/CU-h (ruling r-gd2vgb7jd1b4vqtf).
4. **Regenerate the docs tables:** `DPLY_DATABASE_LARGE_SIZES=true php artisan dply:billing:price-table --write-docs`, then remove the "once dply enables the larger database nodes" sentence in `docs/site/resources/databases.md`. Also add `<env name="DPLY_DATABASE_LARGE_SIZES" value="true"/>` to `phpunit.xml`, or `DocsPriceTablesTest` fails against the rewritten tables.
5. **Check:** create a 1 CU database on a test app. The first one takes a few minutes while a node boots (the first connection may fail; retry). Its sheet shows **Stays on** with the sleep choices disabled. `kubectl -n <db namespace> get pod -o wide` shows it on a `db-large` node. Run `kubectl describe node` on it and note allocatable CPU and memory: §9 of `docs/pricing-review.md` estimates the CPU side, so correct `dply.unit_costs` if the real numbers are lower.

Turning the flag off later hides the sizes for new picks; existing large databases keep their size and billing.
