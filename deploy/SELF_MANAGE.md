# dply self-manages dply — operational runbook

> **Superseded for deploys (2026-09-27).** dply now runs as a container app on
> its own platform. Deploy, rollback, database restore, secret rotation and
> gateway recovery live in [`docs/self-hosting-runbook.md`](../docs/self-hosting-runbook.md)
> (`dply:self:register`, `dply:self:deploy`). W2–W4 below describe the VM
> control plane and name commands deleted in the Edge-only cut
> (`dply:self:adopt`, `dply:self:sync-supervisor`, `dply:db:restore`). W1
> (escrow) and W5 (APP_KEY rotation) still apply.

dply manages its own prod control-plane: off-box secret escrow, DB backups, and
(eventually) self-deploy run through dply's own features. See the plan:
`~/.claude/plans/we-need-a-env-iridescent-marble.md`.

> **Break-glass note:** `deploy.sh` used to double as a self-contained recovery
> deployer. It has been reduced to `commit.sh` (commit + push only), so there is
> **no longer a one-command break-glass deploy**. If a self-deploy bricks the box,
> recovery is the manual on-box atomic-release procedure in W4 below (or restoring
> `shared/.env` + the DB from the W1 escrow). Reintroducing a minimal standalone
> recovery deployer is an open item — see the gap called out in W4.

> Why this exists: the box's SSH keys, DB admin creds, and every secret are
> encrypted in the Postgres **on that box**, under `APP_KEY` which lives in
> `shared/.env` **on that box**. Lose the box → it's all locked inside. The W1
> off-box escrow below is what breaks that loop. Do W1 first.

---

## W1 — Off-box break-glass (do this first)

### One-time: age key custody (asymmetric)
1. `age-keygen -o identity.txt` on an **offline** machine. Store `identity.txt`
   (the private key) in 1Password **and** print it; never put it on a prod box.
2. Put the **public** recipient (`age1…`) in `shared/secrets/age-recipients.txt`
   on each box and set `SECRET_VAULT_RECIPIENTS_PATH` to it. Install `age` and set
   `SECRET_VAULT_AGE_BIN`.

### One-time: stores (set in shared/.env)
- Object (primary, **separate cloud account**, versioned + object-locked, box IAM
  = PutObject+ListBucket only): `SECRET_VAULT_OBJECT_*`.
- Git ops repo (ciphertext only): `SECRET_VAULT_GIT_*`.
- 1Password: `SECRET_VAULT_OP_*`.
- Fast break-glass bundle: `SECRET_VAULT_CRITICAL_SSH_KEY_PATH`,
  `SECRET_VAULT_CRITICAL_PG_PASSWORD` (+ `_PG_SUPERUSER`).
- Dead-man's-switch: `SECRET_VAULT_DMS_*`; failures → `SECRET_VAULT_ALERT_WEBHOOK`.

### Scheduled automatically (DplySchedule)
- `secrets:escrow --source=platform-env` (daily) — .env → APP_KEY off-box.
- `secrets:escrow --source=db-dump` (daily) — independent age-encrypted pg_dump.
- `secrets:escrow --source=critical-keys` (daily) — recovery SSH key + pg superuser.
- `secrets:restore-drill` (daily, **drill host only**: `SECRET_VAULT_DRILL_ENABLED=1`).

### Restore (break-glass)
```
# On a machine WITH the offline age identity (SECRET_VAULT_IDENTITY_PATH):
php artisan secrets:restore --source=platform-env --revision=latest --to=/path/.env
php artisan secrets:restore --source=critical-keys --revision=latest --to=/tmp/keys.json
# DB: restore the latest escrowed dump (see W3 dply:db:restore, or psql the decrypted dump).
```

---

## W2 — Adopt prod as a managed Server
```
php artisan dply:self:adopt   # idempotent; reads prod box details from config/env
```
Creates/syncs the `Server` (ip, ssh user, operational + recovery keys), the
`ServerDatabaseAdminCredential` (postgres superuser), and a `ServerDatabase` row
for the control-plane Postgres. Record the resulting server IDs into
`secret_vault.drift.targets` (web + worker) so drift-check covers them.

---

## W3 — dply-native DB backup + restore
- Create a `BackupConfiguration` → the separate-account bucket.
- Create a daily `ServerBackupSchedule` for the control-plane `ServerDatabase`.
  Backups run via `RunBackupScheduleCommand` → `ExportServerDatabaseBackupJob`.
- Restore: `php artisan dply:db:restore {backup}` (guarded; imports into a target DB).
- The W1 age dump is the **independent** copy (survives the backup provider/account).

---

## W4 — Self-deploy (no shell-deploy break-glass)
- Onboard dply as a VM **Site** on the prod Server: repo + branch + deploy key,
  `deploy_strategy=atomic`, `env_file_path` = **external** `shared/.env` (never
  per-release), health check `/up` + `deploy_health_auto_rollback=true`.
- Deploy steps cover the parity gaps the old shell deployer handled:
  `composer install`, `npm ci` + `npm run build`, `migrate`,
  `config:cache`/`route:cache`/`event:cache`/`view:cache`, `pennant:clear`; shared
  `storage/` symlinked across releases.
- Pre-deploy `secrets:check-drift` must pass (hard-fail on APP_KEY value drift).
- Workers: their own Site(s); deploy web first, then workers.
- **Always validate on a staging Site/box first**, then cut prod over.

> **Gap:** the engine self-deploy is the *only* deploy path now that `deploy.sh`
> is commit-only. If the control plane itself can't run the engine (it's down /
> the box is bricked), there is no one-command fallback. Until a minimal
> standalone recovery deployer is reintroduced, recovery is the manual procedure
> below. Keep it tested.

### Break-glass: a self-deploy bricked prod (manual atomic-release recovery)
1. SSH in with the recovery key (from `critical-keys` escrow if needed).
2. If `APP_KEY`/.env is the problem, restore `shared/.env` from W1 escrow first —
   an empty/wrong env is the usual cause and nothing else will boot until it's right.
3. If `current` points at a bad release, **roll back** to the previous good one
   (instant, no rebuild):
   ```bash
   cd <ROOT>
   ls -1dt releases/*/                                  # pick the previous good <ts>
   ln -sfn releases/<ts> current.tmp && mv -Tf current.tmp current
   sudo systemctl reload php8.5-fpm; sudo systemctl reload nginx   # web
   sudo systemctl restart dply-site-*-horizon.service             # worker
   ```
4. If you must build a fresh release on the box by hand (engine unavailable),
   reproduce the atomic-release steps manually:
   ```bash
   cd <ROOT>
   TS=$(date +%Y%m%d%H%M%S); NEW="releases/$TS"
   git --git-dir=repo fetch origin main --prune
   mkdir -p "$NEW"
   git --git-dir=repo archive "$(git --git-dir=repo rev-parse origin/main)" | tar -x -C "$NEW"
   ln -sfn "$PWD/shared/.env" "$NEW/.env"; rm -rf "$NEW/storage"; ln -sfn "$PWD/shared/storage" "$NEW/storage"
   ( cd "$NEW" && composer install --no-dev --optimize-autoloader \
       && npm ci && npm run build \
       && for c in config:cache route:cache event:cache view:cache; do php artisan $c; done \
       && php artisan migrate --force )
   ln -sfn "$PWD/$NEW" current.tmp && mv -Tf current.tmp current
   sudo systemctl reload php8.5-fpm; sudo systemctl reload nginx   # web (workers: restart horizon)
   ```

---

## W5 — APP_KEY rotation (routine, online)
```
# 1. Generate new key; on EVERY site's env set APP_KEY=<new>, APP_PREVIOUS_KEYS=<old>.
# 2. Re-encrypt all stored secrets under the new key (resumable):
php artisan secrets:reencrypt --show-plan     # eyeball coverage
php artisan secrets:reencrypt                 # online, chunked
php artisan secrets:reencrypt                 # second pass should report 0
# 3. Gate before retiring the old key — must pass:
php artisan secrets:reencrypt --assert-complete
# 4. Remove APP_PREVIOUS_KEYS everywhere; redeploy config:cache.
```
Coverage is enforced by `tests/Unit/SecretReencryptCoverageTest.php` — if a new
`Crypt::encrypt`/`encrypt()` write site appears unclassified, the test fails until
it's added to `config/secret_vault.php` (`raw_crypt`/`json_crypt`) or the allowlist.
