# Getting started: writer report

Pages: `introduction`, `quickstart`, `frameworks`, `how-dply-works`, `local-development`, `private-beta`.

## 1. Mismatches

1. **The ineligible-repo message points to a product that no longer exists.** `app/Modules/Edge/Support/EdgeEligibility.php:200` says "Use a BYO server for this workload." BYO servers left with the Edge-only cut, so Python, Go and WordPress users hit a dead end. The class docblock at line 12 has the same wording.
2. **The register page carries VM-era beta copy.** `resources/views/livewire/auth/register.blade.php:11` says "Connect your own cloud servers free during the beta, plus one dply-managed server on us." Neither is true: beta orgs get the standard card-up-front trial (ruling r-f17p5zgeh120cm5t).
3. **The `BetaProgram` docblock contradicts the ruling.** `app/Support/Beta/BetaProgram.php:17` says beta orgs "pay $0, trial/pause is suppressed". Code and ruling say the opposite: `betaFeeWaived()` returns false. The `BetaInvitation` and `Register` docblocks also say signups are closed, and nothing in `Register::submit()` enforces that.
4. **The coming-soon gate lets OAuth sign-ups through but blocks invite links.**
   - With `COMING_SOON=true`, `RedirectGuestsToComingSoon` sends `/register` (and so `/register?invite=…`) to the teaser.
   - It allows `auth/*/redirect` and `auth/*/callback` (`app/Http/Middleware/RedirectGuestsToComingSoon.php:78`), so anyone can sign up with GitHub.
   - An invited email user can't redeem their invite unless their IP is allow-listed.
5. **Hugo and Jekyll are detected, but their builds can't run.**
   - Every non-container build runs in `node:<major>-bookworm` (`EdgeBuildRunner.php:504-518`).
   - The presets `hugo --minify` and `bundle install && bundle exec jekyll build` (`EdgeFrameworkPresetRegistry.php:146,156`) need binaries that image lacks.
   - On the GitHub fast path, a Jekyll repo with a `Gemfile` is detected as a Ruby container (`ManagesEdgeRepoDetection.php` `synthesizeContainerPlan`).
   - Documented as a warning.
6. **The same repo is detected differently depending on the path.**
   - The GitHub fast path (`ManagesEdgeRepoDetection::synthesizeNodePlan`, line 649) never sets `start_command`, so `EdgeSsrDetection::planLooksLikeSsr` is false. Next.js defaults to **Static** with output `out`, which only works with `output: 'export'`.
   - The clone path (`NodeRuntimeDetector`) sets `next start`, so the same repo becomes **Hybrid** and needs an origin URL.
   - Remix isn't in the fast-path map and lands as `node_generic`, with output `dist` and mode Static.
   - The clone path doesn't know Vite, Gatsby, Hono, VitePress or Docusaurus. A Hono repo on GitLab gets output `.` from the static preset fallback.
   - Documented as "Static or Hybrid", with a note.
7. **Node version sources disagree.**
   - `NodeRuntimeDetector` reads `.tool-versions` first, then `.nvmrc`, then `engines`.
   - The build image comes from `NodeVersionDetector`: `engines`, then `.nvmrc`, `.node-version` and `packageManager`. It never reads `.tool-versions`.
   - The detected version shown in the plan can differ from the one actually used.
   - Ruby is the same: the detector reads `.tool-versions` and the `Gemfile`, but the container reads only `.ruby-version` (`EdgeContainerDockerfile.php` `ruby()`).
8. **`build.node` in `dply.yaml` is parsed but ignored.** It is in the loader's docblock and its key list (`EdgeRepoConfigLoader.php:241`), but `EdgeBuildRunner` never reads it. Not documented.
9. **Two parsers read `dply.yaml`.** `DplyManifestParser` (runtime/version/build list/`processes.web`, used only by detection's clone path) and `EdgeRepoConfigLoader` (`build.command`/`output`/`root`, used at build time) have different schemas. I documented only the loader's keys.
10. **The create page never renders its sidebar.** `resources/views/livewire/edge/partials/create-sidebar.blade.php:1` (Deploy summary: build, output, mode, cost) is not included anywhere. Users can't see or change the build command, output directory or mode before **Deploy**.
11. **There is no UI to choose Worker SSR.**
    - The create page has no mode picker. SSR is reachable only through templates or `?runtime_mode=ssr` (`Create.php:372`), and that query allowlist omits `container`.
    - The Resources hint says "Switch to SSR or a container app" (`resources.blade.php:182`), but no control does that.
    - **Delivery** offers only **Convert to hybrid**.
12. **Resources are allowed on Hybrid apps** (`Resources.php:1707`, `WORKER_KINDS`), but the hint names only SSR and containers. It is unclear what code on a hybrid app consumes those bindings.
13. **`dply edge env push` silently deletes keys.**
    - It calls `PUT /env`, which deletes every production key not in the file (`EdgeEnvController::bulkUpdate`).
    - The CLI prints only "Pushed N key(s)" (`packages/dply-cli/src/commands.mjs:1010`), and the README calls it "bulk replace" without saying keys are deleted.
    - Documented as a warning.
14. **The packaged CLI default points at a dev host.** `packages/dply-cli/src/instance-defaults.json:2` has `https://dplyi.test`. The README uses `your-dply.example` placeholders, and `instance-commands.mjs` hard-codes `https://dply.io` as the hosted instance. I used `https://dply.io` in the docs; please confirm it's the public origin.
15. **Environment values are visible in the dashboard.** `Environment::mount()` (`app/Livewire/Sites/Edge/Workspace/Environment.php:54`) loads decrypted values into `edgeEnvText` for editors, while the API and CLI are keys-only. That is fine, but "write-only" wording elsewhere (CLI help, API docblock) overstates it.
16. **Hardcoded price in the create flow.** In `Create.php` `planCostSummary()`, the no-plan copy says "then $20/mo" instead of reading `subscription.standard.tiers.pro.price_cents`.
17. **The no-plan quota message is stale.** `ManagesOrganizationQuotas::quotaLimitMessage()` still says "Choose Pro … for 10 sites, plus $2 for each site" (hardcoded). It is also reachable only by orgs with no plan, which can't create apps at all.

18. **Deploy on push works only for GitHub.**
    - The create form turns it on by default (`app/Modules/Edge/Livewire/Create.php:201`), and the **Build** tab shows it for every app.
    - Only GitHub webhooks exist (`EdgeGithubWebhookProvisioner`, `GithubEdgeWebhookController`). GitLab, Bitbucket and pasted repositories never auto-deploy, and the UI doesn't say so.
19. **A failed container deploy can leave the new image live.** `wrangler deploy` replaces the running container during upload, before publish (see `CloudflareEdgeDelivery.php:29-32` and `docs/EDGE_PLATFORM_STATUS.md`). A post-deploy failure can leave the new image serving while the deployment is marked failed. Documented in how-dply-works.
20. **Nuxt defaults to a server build in Static mode.**
    - The fast path uses `npm run build` whenever a `build` script exists (`ManagesEdgeRepoDetection.php:666`), which is true of almost every Nuxt repo.
    - `npm run build` produces a server bundle, but the output is set to `.output/public` and the mode to Static. The preset's `npm run generate` is used only when there is no `build` script.
    - The same applies to Next.js (`npm run build` → `out` only with `output: 'export'`).

## 2. Gaps vs Laravel Cloud

- **Runtime versions page.** Cloud publishes supported PHP/Node/Bun/Deno/Go/Python versions. dply's versions live only in code constants. I built the tables from `EdgeContainerDockerfile::PHP_VERSIONS`, `RUBY_VERSIONS` and `NodeVersionDetector::SUPPORTED_MAJORS`. Consider a config file that both the docs and the code read.
- **Language coverage.** Cloud supports Python (Django/Flask/FastAPI), Go, Bun and Deno. dply detects Python and Go and then blocks them. Container apps could build them from a user `Dockerfile` today; the eligibility gate is the only blocker.
- **Choosing and editing before the first deploy.** Cloud shows and lets you edit build settings when you create an app. dply deploys blind (see mismatch 10).
- **No CLI app creation.** The README states "There is no create-site API".
- **No real `env pull`.** Cloud and Vercel let you pull values for local development; dply returns keys only.
- **Support channel.** Cloud documents support tiers. dply has no support email, chat widget or support route anywhere in the app. The private-beta page marks this "Owner to confirm".
- **Preview environments with their own resources.** Cloud gives each preview an isolated environment. I couldn't confirm whether dply previews get their own databases or share production's, so I didn't claim either. The owner of the preview-deployments page should check.

## 3. Pricing / limits questions

- **The beta envelope is probably dead code.**
  - `subscription.standard.beta` caps (25 edge apps and so on) apply only when `isBeta()` and not `onAnyPaidPlan()`.
  - Beta orgs now need a plan to deploy, so the caps apply only if a trialing Stripe subscription doesn't count as `onAnyPaidPlan()`.
  - `cutover_at` is unset.
  - Decide whether to delete the envelope or document it.
- **Is the $5 trial cap enough for containers?** Pro includes $5/mo of container compute, and the trial's spending cap is also $5. One Laravel container with dply Postgres and Valkey could reach the cap within the 5 days, and the trial would then pause. Worth modeling.
- **Worker SSR costs extra on every plan.** Every Worker SSR app costs $7/mo and none are included, while Hybrid (which needs your own server) is included. The create page defaults server-rendered frameworks to Hybrid, which silently requires an external host. For a Next.js user, that looks worse than Vercel or Netlify.
- **Custom domain allowance.** Pro gives 20 custom domains, and `custom_domains_per_site` is 100. The per-site cap is looser than the org cap, which reads oddly.

## 4. Suggested fixes

| # | Fix | Size |
|---|---|---|
| 1 | Reword the `EdgeEligibility` rejection: "dply doesn't run :stack apps yet." Drop the BYO server reference. | S |
| 2 | Replace the register beta copy with trial wording from config (`trial.days`, `trial.tier`). | S |
| 3 | Fix the `BetaProgram`, `BetaInvitation` and `Register` docblocks to match the ruling. | S |
| 4 | Allow `register` through `RedirectGuestsToComingSoon` when a valid `invite` or `org_invite` token is present. Block OAuth *registration* (not login) for unknown emails while the gate is on. | M |
| 5 | Either install Hugo in the build image (or switch to a Hugo image when the preset is `hugo`) and drop Jekyll's Ruby dependency, or remove both from detection and presets. Make the fast path check `_config.yml` before treating a `Gemfile` as Ruby. | M |
| 6 | Make the fast path emit `start_command` from `scripts.start`, and add `remix`/`@remix-run/*` to its map, so both detection paths agree. Add Vite/Hono/Gatsby/VitePress/Docusaurus to `NodeRuntimeDetector`. | M |
| 7 | Have `NodeVersionDetector` read `.tool-versions`, and the Ruby container read `.tool-versions`/the `Gemfile`, matching the detectors. | S |
| 8 | Wire `build.node` into the runner (it is one line: pass it to `NodeVersionDetector`), or remove it from the loader. | S |
| 10 | Render `create-sidebar.blade.php` on the create page, with editable **Build command**, **Output directory** and a mode selector (Static / Hybrid / Worker SSR / Container, gated by plan and availability). | M |
| 11 | Add **Convert to Worker SSR** next to **Convert to hybrid** in **Delivery**, or change the Resources hint to name what actually exists. | S (copy) / M (feature) |
| 13 | Make `dply edge env push` print the keys it will delete and require `--yes` (or add `--merge`, implemented as a PATCH per key). | S |
| 14 | Set `instance-defaults.json` to the public origin at pack time, or drop it in favor of the installer-injected origin. | S |
| 18 | Label **Deploy on push** as GitHub-only, or add GitLab/Bitbucket webhooks. | S / L |
| 20 | For Nuxt and Next.js, prefer `generate`/export commands when the mode is Static, or pick Hybrid/SSR when a server build is detected. | M |
| 16–17 | Read prices from config in `planCostSummary()` and `quotaLimitMessage()`. | S |
| — | Publish runtime versions from one config file that the docs and the code both read. | M |
| — | Add a support contact (email or chat) to the app footer and to **Help**. | S |
