# Report — Apps & deployments section

Pages written: `apps`, `source-control`, `builds`, `configuration-files`, `deployments`, `deploy-triggers`, `preview-deployments`, `environment-variables`, `secrets`, `monorepos`.

Paths are relative to the repo root. Items are ordered by customer impact within each section.

## 1. Mismatches

### Blocking / high impact

1. **Private repositories cannot build.** `BuildEdgeSiteJob.php:198` builds `https://github.com/{repo}.git` (or the stored URL) and `EdgeRepoCloner` never adds credentials. `SourceControlRepositoryBrowser::authenticatedCloneUrl` is used only by `DefaultBranchResolver`; `git log -S` shows the Edge build path never had clone auth. Meanwhile the create wizard lists private repos and detection reads them via the authenticated API (`ManagesEdgeRepoDetection.php:578`), so a user can pick a private repo and fail at clone. Docs carry an `[!IMPORTANT]` on apps and source-control.
2. **Previews get none of the parent's env vars or linked secrets.** `CreateEdgePreviewSite.php:69-147` copies build/routing/origin meta only; `BuildEdgeSiteJob.php:204` → `EdgeProductionEnv::forSite($preview)` reads the child's own (empty) rows. There is no way to set preview env (the preview Environment page says "Environment variables are managed on the parent Edge site."; `SCOPE_PREVIEW` is never written). Contradicted by the UI tip `resources/views/livewire/sites/edge/workspace/environment.blade.php:29` ("Preview children inherit env from this parent") and `docs/EDGE_ENVIRONMENT.md:14`.
3. **Promote ships a build made without production env.** `PromoteEdgePreview.php:84-128` copies preview artifacts; combined with #2, anything inlined at build time (`VITE_*`, `NEXT_PUBLIC_*`) comes from an env-less build. Containers rebuild instead (`:69-77`).
4. **Promote is blocked by default until a shadow replay scores ≥99%.** No `config/deploy_contract.php` exists, so defaults apply: `require_run_before_promote=true` (`DeployContractState.php:67,114`), `require_replay_when_enabled=true`, `min_replay_pass_rate=99.0` (`DeployContractPolicy.php:83,92`), and `EdgeDeployReplayPassCheck.php:56-60` fails when no replay exists. Replay needs production GET/HEAD traffic from the last 60 minutes (`QueueEdgeDeployReplay.php:21`), so a new or quiet app can only promote by waiver or a repo `dply-contract.yaml`. Nothing in the Previews feature guide says this.
5. **"Deploy on push" checkbox is inert.** `GithubEdgeWebhookController::handlePush` (`:118-160`) never reads `source.deploy_on_push`; only Enable/Disable webhook changes behaviour (and they write the flag, `EdgeGithubWebhookProvisioner.php:101,144`). Unchecking it while the webhook is connected still deploys on push. The Build page and the Deploy triggers guide ("Keep Deploy on push enabled under Build", `deploy-triggers.blade.php:12`) imply otherwise.
6. **Push-to-deploy is not enabled on create**, although `deploy_on_push=true` is saved (`Create.php:201`, `CreateEdgeSite.php:108`). New apps show "Deploy on push ✓" on Build but never deploy on push until Deploy triggers → Enable. (The form default comment says "Off by default — operators have to opt in", `EdgeCreateForm.php:35-38`, which `mount()` overrides.)
7. **Delete leaves things behind that the UI says it removes.** `danger.blade.php:13,42` says teardown removes "preview child sites"; `TeardownEdgeSiteJob` never touches previews (linked only via `meta.edge.preview_parent_site_id`). Also left: Cloudflare custom hostnames (`EdgeCustomDomainProvisioner::remove` never called; non-ready domains keep KV entries), the Edge GitHub webhook (stored at `meta.edge.webhook` but the delete hook reads `meta.repository.provider_hook`, `RepositoryWebhookProvisioner.php:31-45`), the default per-site KV namespace, and org-scoped D1/queues/buckets. Docs list these in a "What deleting keeps" table.
8. **Preview protection gates production too.** The rule is saved on the parent and published into the parent's host map (`EdgeAccessGate.php:41,94-124`; `EdgeHostMapPublisher.php:261`). UI copy "Locks preview URLs and the live site" is correct, but the section name, toast ("Preview protection updated.") and old doc say "preview protection". Radio label is **Password**, not "Shared password".
9. **Env merge order: site env wins over linked secrets** (`EdgeProductionEnv.php:25-43`), but the Override tooltip says "this secret wins over the same key in the site .env." (`linked-organization-secrets.blade.php:76`).
10. **`dply edge env pull` → `push` wipes every value.** `pull` prints `KEY=` with no values; `push --file` does a PUT bulk replace (`packages/dply-cli/src/commands.mjs:942-1016`). The API `PUT /env` also deletes platform-written rows (`DB_*`, `DATABASE_URL`, generated `APP_KEY`) absent from the body.
11. **Residency tab has no effect on Edge deploys.** No customer UI moves a secret to the org key; `SecretResidencyResolver` has no callers; `EdgeProductionEnv` never reads residency or external stores. Copy is VM-era ("Access key (omit to use the box IAM)", "Server fetches (dply never sees values)" in `residency.blade.php`). Docs say it doesn't affect Edge apps.
12. **`auto_create` cannot be set.** `EdgeBindingsAutoResolver.php:54` reads `repo_config.bindings.auto_create`, but `EdgeRepoConfigLoader::normalizeBindings` (`:150-208`) drops the key and the wrangler extractor never emits it, so auto-create is always on. The only test writes `repo_config` directly (`tests/Feature/EdgeResourceGatesTest.php:173-182`). Docs say there is no way to turn it off.
13. **Existing wrangler projects silently get new empty R2 buckets / queues.** A `bucket_name` or `queue` is treated as a name → `dply-<org>-<name>` is created (`EdgeContainerConnections.php:1019-1043`). KV/D1 ids from the user's own Cloudflare account are refused. Binding errors surface at publish (`PublishEdgeDeploymentJob::markFailed`), not in the build log, and every message says "wrangler.toml binding …" even for dply.yaml bindings (`EdgeBindingsAutoResolver.php:107`).
14. **Non-admins can create org vault secrets** via Environment → "Paste or link secrets" → Bulk import (`ManagesLinkedOrganizationSecrets.php:106` → `OrganizationSecretManager::create` under site `update` only), contradicting "Admins create / rotate / delete" (`docs/ORG_SHARED_SECRETS.md:26`).

### Medium

15. `build.node` (dply.yaml) and `env.public.NODE_VERSION` are parsed but never choose the Node image (`NodeVersionDetector` only reads engines/.nvmrc/.node-version/packageManager). Both are advertised: loader docblock `EdgeRepoConfigLoader.php:21,822-823` and the CLI page `resources/views/livewire/settings/cli-authentications.blade.php:162,176,183,191`.
16. `build.root` probably builds in the wrong directory: it moves `$checkout` (install detection, Node, output lookup, `EdgeBuildRunner.php:349-358,635`) but Docker `-w` stays `/src/<repo_root>` (`:560-571`) and `composeBuildScript` only `cd`s for repo_root. No tests.
17. `--if-present` is appended to every `run` script (`EdgeBuildRunner.php:1015-1027`), so a missing `build` script passes silently and fails later as "Build output directory not found" — confusing.
18. Unreachable/incorrect copy: "This month’s :minutes build minutes are used up. Upgrade to Pro…" (`BuildEdgeSiteJob.php:98`) can't fire on Pro/Team (both have overage prices) and says "Upgrade to Pro" to Pro users.
19. Push-driven previews are dead: `EVENT_PUSH` is never passed to `EdgePreviewPolicy::shouldCreatePreview`; push to non-production branches returns early (`GithubEdgeWebhookController.php:118-131`). The Previews page still renders "PRs + branches" for `pr_only: false` (`workspace/previews.blade.php:31-50`). Also the `previews:` policy is read from the parent's last **live** deployment, so a PR changing it has no effect until production deploys.
20. `dply-contract.yaml` is loaded only inside `if ($repoConfig !== null)` (`EdgeBuildRunner.php:214,232`) — ignored without a dply.yaml.
21. `dply.toml` is in `EdgeRepoRoot::CONFIG_FILENAMES` and `docs/EDGE_BUILD.md` but the loader reads only yaml/yml/json.
22. Generated dply.yaml header says "Commit at the repo root." (`EdgeRepoConfigYamlGenerator.php` ~L183) — wrong in monorepos (file is read from repo_root). The generator also emits `spa_fallback`, which the loader never parses, and omits `build`/`bindings`.
23. Empty or comments-only dply.yaml fails the build ("Config file could not be parsed.", fatal per `EdgeRepoConfigLinter.php:99-104`); the 64 KB message says "was ignored" but is fatal.
24. Wrangler parsing: JSONC comment stripping is not string-aware (`WranglerBindingsExtractor.php:62`) and breaks on `"https://…"` or `"example.com/*"`, silently falling through with no log; TOML inline arrays and trailing comments aren't handled. Log lines always say "wrangler.toml".
25. Code comments contradict behaviour: `EdgeBuildRunner.php:227-228` says dply.yaml wins over wrangler (code: wrangler wins, `:250`); `EdgeRepoConfig.php:13-17` and `EdgeRepoBindingTranslator.php:84-86` say dply.yaml `bindings:` isn't parsed (it is).
26. SPA fallback toast "Changes apply on the next deploy." but the change is republished immediately (`ManagesEdgeBuildSettings.php:121-133`).
27. Deploys tip "Rollback republishes a previous artifact; it does not re-run npm." (`deploys.blade.php`) is wrong for container apps (rebuild). Rollback error text says "Deploy a specific commit" but the UI label is **Deploy ref** (`RollbackEdgeDeployment.php`).
28. Deploy ref on an already-built SHA silently rolls back instead of rebuilding (`DeployEdgeCommit.php:35-50`) — with stale env. The helper text says "Re-flips KV if we already built that commit", which users won't parse.
29. Env textarea shows decrypted values to anyone with site `update` (incl. deployer-rank members) (`Environment.php:53-55`); the API/CLI and old doc say values are write-only. `docs/EDGE_ENVIRONMENT.md` describes a Key/Value/"Set value" UI that no longer exists.
30. Env "Missing" badges for dply.yaml `env.secret` ignore linked org secrets (`Environment.php:270-275`) while the build log counts them.
31. UI marks `DPLY_APP_URL` / `DPLY_MIGRATE_ON_BOOT` as "replaced above" when set, but the platform value wins (`EdgeContainerDeployer.php:395-400`).
32. Org secrets allow a leading underscore (`OrganizationSecretManager.php:21`) but `EdgeProductionEnv` drops keys failing `^[A-Z]…` and reserved names — silently never injected.
33. API: `branch_tip` is validated but never used (`EdgeDeploymentApiController.php:71`).
34. Create wizard: errors say *Click "Detect runtime" to retry* (`ManagesEdgeRepoDetection.php:782,797`) but no such button exists. `?runtime_mode=container` is dropped (`Create.php:371-375`). PHP/Rails error says 'Choose "Container" delivery' but there's no runtime picker. Template links pass `framework`/`template` params that are ignored. Cost sidebar (`partials/create-sidebar.blade.php`) is computed but never rendered.
35. Dashboard empty state says "Your first live site is free; add a card when you ship a second." and "not containers" (`edge-index-page.blade.php`) — contradicts the no-free-plan trial ruling and Container support.
36. Only the dashboard delete path writes an org audit entry; Danger zone and "Cancel build" (which deletes a never-live app with only `update` permission, `ManagesEdgeSiteProvisioning.php:60-111`) don't. Scheduled deletion has no cancel UI.
37. Tip "Same commit SHA reuses the preview; a new SHA gets its own URL" is true only for ad-hoc previews; PR previews keep one URL per PR.
38. In-app feature-guide `docSlug`s point at slugs not in `nav.json`: `edge-deploys` (`deploys.blade.php:4`), `edge-deploy-triggers` (`deploy-triggers.blade.php:4`), `edge-environment` (`environment.blade.php:16`), and likely the other workspace sections — they will 404 on the new docs.
39. Hostnames are `*.on-dply.live` (`config/product/testing_domains.php`), while `docs/EDGE_CREATE.md` says `{slug}.on-dply.site`.
40. `BuildLogStream` is registered but unused; `edge-deployment-detail.blade.php:69-72` has an empty failure div; deployment statuses render as raw enum values.

41. **`previews.protection` in dply.yaml does nothing.** `CreateEdgePreviewSite::applyDplyPreviewProtection` writes an `EdgeSiteAccessRule` on the preview, but `EdgeAccessGate::ruleForSite` (`:83-89`) always reads the **parent's** rule, so the preview rule is never used (and password mode from the file would have no verifier anyway). The Previews page still shows "Protection" under "From dply.yaml". Docs say the block has no effect.
42. **"Edge env keys" contract check always skips.** `EdgeEnvKeysSubsetCheck` compares `SCOPE_PREVIEW` rows on the parent, which nothing writes, so it returns "Both production and preview env scopes need keys to compare." It never catches the real problem (#2: previews have no env).
43. Build sandbox network doesn't block IP-literal metadata (`169.254.169.254`), host-local services on the bridge gateway, or private ranges without the operator iptables rules in `docs/edge-build-isolation.md`. Whether production build workers have them is an open question for the platform-security page; `builds.md` doesn't claim it.

## 2. Gaps vs Laravel Cloud

- **Private repositories** (see #1) — table stakes; Cloud supports them.
- **Preview environment variables.** Cloud gives each preview automation its own env set with injected overrides; dply previews have none and no way to add any.
- **Push-to-deploy / PR previews on GitLab and Bitbucket.** Only GitHub has a webhook; GitLab/Bitbucket users must wire deploy hooks in CI and get no previews.
- **Deploy hook with `commit_hash`.** Cloud's hook accepts a commit; dply hooks always deploy branch tip (`EdgeDeployHookController`). Hooks can't be regenerated in place (revoke + create).
- **Change repository or branch** after create; **rename** an app. Both impossible (`build-core.blade.php:34`).
- **Staged changes banner.** Cloud batches pending settings and deploys them together; dply relies on per-page "Redeploy to apply" toasts. Only Environment has a Save-and-redeploy bar.
- **Cancel a running deploy** (only "Restart build"; a real Cancel exists only in the create wizard's log view).
- **Build cache controls** (clear cache / deploy without cache) — none.
- **Node version selector / honored `build.node`** — only repo files choose Node; no dashboard override.
- **Preview automation controls** in the UI (branch filters, auto-delete toggle, per-preview overrides) — dply has only `dply.yaml` `previews:` and no cleanup for ad-hoc previews (no expiry, no scheduled prune).
- **Monorepo tooling** — no Turborepo/Nx awareness (no ignored-build step / "only build if changed" beyond path filter on push).
- **Self-hosted GitLab / GitHub Enterprise** — PAT UI offers "API base URL" but the create form rejects non-public hosts.
- **Secrets in external stores** (Residency tab exists but does nothing for Edge).

## 3. Pricing / limits questions

- Pro and Team both have build-minute overage prices, so builds never stop at the allowance; the "used up" stop path (#18) is dead. Is that intended for the trial (Pro tier, $5 cap)? The trial cap is what actually stops builds.
- Failed and superseded (cancelled) builds count toward build minutes (`BuildEdgeSiteJob.php:215-222` records `build_seconds` in `finally`). Rapid pushes cancel each other but each still bills minutes — worth stating in pricing or excluding cancelled builds.
- Preview builds count toward build minutes and org concurrency (`EdgeBuildMinutes::usedBetween` sums every org deployment) though previews aren't billed as sites — fine, but not stated anywhere in-app.
- Build sandbox resources (4 GB / 2 CPU / 2048 pids) are the same on every tier; Team/Enterprise customers may expect bigger builders.
- Concurrency slot TTL = timeout + 15 min; a killed worker can stall an org's slot (mitigated only on cancel path).
- Worker SSR $7/app is never included in the tier's app count — easy to miss; the create wizard doesn't show cost (sidebar not rendered, #34).
- KV creation needs a card on file even during trial (`creationError`), while D1/queues count toward `databases`/`queues` tier caps — the rules differ per kind and only appear as deploy failures.

## 4. Suggested fixes

| # | Fix | Size |
|---|-----|------|
| 1 | Pass the site's linked GitIdentity (`meta.repository.git_source_control_account_id`) through `SourceControlRepositoryBrowser::authenticatedCloneUrl` in `BuildEdgeSiteJob`; scrub the token from logs and mirror remotes. | M |
| 2 | Make `EdgeProductionEnv::forSite` fall back to the parent for preview sites (or add a preview env editor using `SCOPE_PREVIEW`); fix the UI tip. | S (fallback) / M (editor) |
| 3 | Warn in the promote modal when the preview was built without production env, or rebuild on promote like containers. | S / M |
| 4 | Ship `config/deploy_contract.php` with `require_replay_when_enabled=false` (or skip replay when there's no traffic); surface requirements in the Previews guide. | S |
| 5 | Honour `deploy_on_push` in `handlePush`, or remove the checkbox from Build. | S |
| 6 | Enable the GitHub webhook on create when a GitHub identity was used, or default `deploy_on_push=false` until enabled. | S |
| 7 | In `TeardownEdgeSiteJob`: teardown previews, call `EdgeCustomDomainProvisioner::remove`, disable `meta.edge.webhook`, delete the default KV. | M |
| 8 | Rename section to "Protection", clarify production is gated, align label with docs. | S |
| 9 | Fix Override tooltip to "The site's own value wins". | S |
| 10 | CLI: `env pull` should refuse to be piped into `push`, or `push` should skip empty values; API PUT should preserve platform-managed keys. | S |
| 11 | Hide the Residency tab for Edge-only orgs (or label it "not used by Edge apps"). | S |
| 12 | Keep `auto_create` in `normalizeBindings` (and read it from wrangler if desired). | S |
| 13 | Log binding resolution in the build log; name the source file correctly; warn when an R2 `bucket_name`/queue creates a new empty resource. | M |
| 14 | Gate bulk-import creation behind org `update`, or document it as allowed. | S |
| 15 | Read `build.node` in `NodeVersionDetector` (first priority), or remove it from docs/CLI page. | S |
| 16 | Use `build.root` as the Docker workdir (or drop the key). Add a test. | S |
| 17 | Log a clear warning when `--if-present` skips a missing script. | S |
| 18 | Remove/reword the unreachable "used up" message. | S |
| 19 | Remove "PRs + branches" UI or wire push-driven previews. | S / M |
| 20 | Load `dply-contract.yaml` regardless of dply.yaml. | S |
| 21 | Drop `dply.toml` from `CONFIG_FILENAMES`. | S |
| 22 | Fix generator header for repo_root; parse or stop emitting `spa_fallback`. | S |
| 23 | Treat empty dply.yaml as no config; reword the 64 KB message. | S |
| 24 | Use a string-aware JSONC stripper; log parse failures. | S |
| 26–28 | Copy fixes (SPA toast, rollback tip, "Deploy ref" wording, explain reuse-vs-rebuild). | S |
| 29 | Decide: write-only values in UI (match API) or document as visible; update/delete `docs/EDGE_ENVIRONMENT.md`. | S |
| 33 | Remove or implement `branch_tip`. | S |
| 34 | Remove "Detect runtime" wording; accept `runtime_mode=container`; render the cost sidebar. | S |
| 35 | Update dashboard empty-state copy to the trial model. | S |
| 36 | Audit-log all delete paths; add cancel for scheduled deletion; require `delete` for Cancel build that deletes the app. | S |
| 38 | Point feature-guide `docSlug`s at the new slugs (`deployments`, `deploy-triggers`, `environment-variables`, `builds`, `preview-deployments`). | S |
| — | Deploy hook `?commit=` param; build-cache clear button; rename app. | S / S / M |
