# Report: Organizations & access, Observability, Tools

Pages: organizations, teams, roles-and-permissions, app-members, account-security, activity-log, traffic, logs, alerts, notifications, deploy-badge, cli, api, api/reference, mcp.

## 1. Mismatches

### Organizations & access

- **You can't remove a member or change their role.** `app/Livewire/Organizations/Members.php` only supports invite and cancel-invitation. Nothing in `app/` detaches an org user or updates the pivot role, apart from org deletion and a local dev seeder. The docs tell customers to contact support. The "Roles & limits" copy in `resources/views/livewire/organizations/members.blade.php:128-131` and old `docs/ORG_MEMBERS.md` both say admins can do this.
- **The Deployer role is not "reduced scope".** `members.blade.php:131` says "Reduced scope: deploy and read, no destructive changes". In fact `ServerPolicy::update` (`app/Policies/ServerPolicy.php:34-44`) returns true for every org member, and `SitePolicy::update` (`app/Policies/SitePolicy.php:48-70`) falls through to it for Edge apps (`workspace_id` null). So deployers can change every setting, env var, domain and firewall rule. Only three things stop them:
  - notification subscriptions (`app/Livewire/Concerns/Edge/ManagesEdgeAlertsNotifications.php:55`)
  - Credentials (`app/Policies/ProviderCredentialPolicy.php:10-18`)
  - the API/CLI ability caps

  `app/Support/Sites/SiteSettingsViewData.php:121-127` has "settings are read-only for the Deployer role" copy that never renders, because `can('update')` is true for deployers.
- **Member and Deployer are nearly the same in the UI.** The only other differences are status-page create and workspace create (`StatusPagePolicy`, `WorkspacePolicy`).
- **App roles Viewer and Deployer do nothing.** `EdgeSiteMember` grants only elevate (`app/Policies/SitePolicy.php:20-29,59-69`), and every org member already has view and update on every app. Only the app **Admin** role changes anything (`manageMembers`). The in-app copy "Deploy-oriented roles cannot change notification subscriptions" (`resources/views/livewire/sites/edge/workspace/members.blade.php:13`) is wrong: that check reads the *org* role.
- **Teams "scope servers, sites" but don't.**
  - `resources/views/livewire/organizations/teams.blade.php:33` says teams "scope servers, sites, and notifications".
  - The create-team modal (`:363`) says "scope notifications and access".
  - Teams only own notification channels. The page's own explainer (`:126-129`, "Grouping, not permissions") and footer (`:330`) contradict the hero.
  - The page's explainer also still says "the server" (`:120`, `:157`).
- **Team admins can't be made.** Only the org creator on "General" is a team admin (`app/Models/Concerns/ManagesOrganizationMembership.php:44`). Add and invite always attach `role => member` (`app/Livewire/Organizations/Teams.php:245,304`, `app/Livewire/Invitations/Accept.php:141`). No UI promotes someone, so `Team::userCanManageNotificationChannels` for non-admins is mostly unreachable.
- **Most members can't create API tokens.** `app/Livewire/Settings/ApiKeys.php:106` requires `authorize('update', $org)`, which is org admin, and the page lists only admin orgs (`:263,278`). So:
  - Members and deployers can't create tokens, or revoke their own, from the dashboard. They get tokens only via `dply login`.
  - `applyDeployerAbilityCap` (`:236-245`) is dead code.
  - `docs/ORG_GENERAL.md` and the Settings page copy imply every user creates their own.
- **The compliance export README lists files that aren't in the ZIP.** `app/Http/Controllers/OrganizationComplianceExportController.php:73-76` lists `deploys.csv` and `certificates.csv`, "BYO + Cloud + Serverless". The ZIP holds only `audit_log.csv` and `edge_access_rules.csv` (`:44-46`). The README also says "full history", but rows are pruned at 365 days (`app/Console/Commands/PruneAuditLogsCommand.php`, `config/product/audit.php:14`).
- **The Activity filter shows families from removed products.** `app/Support/AuditActionMeta.php:30-44` lists Servers, Projects, Backups, Insights and Imports. Background (`queue_worker.*`) has no writers either. The org page's own hero says it "filter[s] by family or search action / subject". The search placeholder "Search action or subject…" undersells it: search also matches the recorded values (`app/Livewire/Organizations/Activity.php:108-117`).
- **Audit log gating is inconsistent.** The per-app **Audit log** (`resources/views/livewire/sites/partials/edge/audit-log.blade.php`) shows the last 100 events on every plan, to anyone who can view the app. The org **Activity** page is Team-only and admin-only (`Activity.php:48,221-224`). Only the per-app export is Team-gated (`app/Modules/Edge/Http/Controllers/EdgeAuditLogExportController.php:31`). Also:
  - The CSV/JSON buttons show on Pro and return a bare 403.
  - The feature-guide text "Export CSV or JSON when you need a copy" doesn't mention the plan.
- **Account events are classified oddly.** `user.*` events (password, 2FA, passkeys) are written to whichever org is *current* at the time (`app/Livewire/Settings/Security.php:88,113,139`). They land in "Other", not "Security". Credential events (`credential.*`) also fall into "Other".
- **The org Settings component has dead code.** `app/Livewire/Organizations/Settings.php:220-270` (`saveAlertDestinations`, a Slack webhook plus extra emails) is still present but has no UI. It is a DigitalOcean App Platform leftover, and `docs/ORG_GENERAL.md` still documents it.
- **The org delete copy has VM-era wording.** It says "Remove all servers and sites" (`resources/views/livewire/organizations/settings.blade.php:315,365`, `app/Actions/Organizations/DeleteOrganizationAction.php:74`). `TeardownEdgeSiteJob::deleteOrphanedEdgeServer` does remove each app's placeholder `Server` row, so the guard works. Only the wording is VM-era.
- **Stale docs slugs.** `x-docs-link` in the Members, Teams and Overview pages points to `org-roles-and-limits` and `org-overview`. Neither is in `docs/site/nav.json`. They should point to `roles-and-permissions` and `organizations`.
- **The Credentials page describes the VM product.** `resources/views/livewire/credentials/index.blade.php:8` mentions "buckets and remotes your backups ship to". The org Overview (`show.blade.php:77`) has a commented-out Webserver templates tile.
- **OAuth sign-in skips 2FA (security).** `app/Http/Controllers/Auth/OAuthController.php:90` calls `Auth::login` directly, with no two-factor check. Only password login enforces 2FA (`app/Livewire/Auth/Login.php:70-74`). A user with 2FA enabled plus a linked GitHub account can be signed in with the GitHub account alone. The docs disclose this in a NOTE. **Fix before public beta.**
- **No transfer of ownership, and account deletion ignores ownership.** Deleting the account (`app/Livewire/Profile/DeleteAccount.php`) doesn't check whether the user is an org's only owner. That can orphan an org with live apps and a subscription.

### Tools (from the CLI/MCP and API writers)

- **The Profile → CLI GitHub Actions snippet is broken** (`resources/views/livewire/settings/cli-authentications.blade.php`):
  - `--no-shell` (`:119`) makes `install.sh:85` exit 2.
  - `dply deploy --sync --idempotency-key` (`:121`) aren't CLI flags.
  - `:123` says `sites.deploy`; the deploy route needs `edge.read` and `edge.deploy`.
  - `:101` mentions `commands.run` and `network.read`, both VM-era.
- **Member CLI scopes don't match member rights** (`config/product/cli.php:71`):
  - The member cap has no `edge.deploy`, but members deploy in the UI.
  - It has `billing.read`, but `BillingApiController.php:51` returns 403 to non-admins.
  - `:94-138` still labels about 40 VM abilities.
- **CLI oddities:**
  - `packages/dply-cli/src/billing-commands.mjs:64` prints "(Free plan)".
  - `--prod` (`commands.mjs:506`) only prints the URL.
  - `resolveContext` (`config.mjs:320`) ignores `DPLY_TOKEN`/`DPLY_API_TOKEN`, so CI deploys can't use env tokens.
  - README and `docs/ACCOUNT_CLI.md` disagree on the `promote` argument, list `--no-prompt`/`DPLY_NO_PROMPT` which don't exist, and mention a `dply lint` command that doesn't exist.
- **MCP describes tools that don't exist.** The server instructions (`app/Mcp/Servers/DplyServer.php:33-38`) mention `deploy_site`, `list_deployments` and `get_deployment`.
  - `ListServers` (`app/Mcp/Tools/Sites/ListServers.php:19,33`) claims IPs and "machines only". It returns placeholder `edge-<slug>` hosts with provider `digitalocean` (the DB default).
  - `GetSite`, `ListSites` and `SiteConfigResource` return null legacy fields for Edge apps: runtime, document_root, git repo and branch, and ssl_status. `type` is always `static`.
  - `GetOperationStatus` (`:21`) tracks operations nothing starts.
  - MCP resources skip ability checks.
- **The edge 600/min API limit never applies.** `routes/api.php:36` wraps everything in `throttle:api` (60/min, `AppServiceProvider.php:269-273`). The `edge-api` 600/min (`:281-285`) stacks on top, so the effective limit is 60/min. The comment at `routes/api.php:62-64` and `docs/HTTP_API.md` both say otherwise.
- **The API returns HTML and redirects where JSON is expected:**
  - An unknown `Site` on model-bound routes (env, site notifications) returns a 302 to the dashboard (`bootstrap/app.php` ~151).
  - There is no `shouldRenderJsonWhen` for `api/*`, so without `Accept: application/json`, validation failures redirect and `abort(403)` renders HTML.
- **`{site}` doesn't accept a slug on `/edge/sites/{site}/*`.** Those routes use `->find($id)` (`EdgeApiController.php` ~45). Model-bound routes do accept a slug.
- **Raw exception messages leak in 422 responses.** Controllers return `$e->getMessage()` in `EdgeDeploymentApiController`, `EdgePreviewApiController`, `EdgeDomainApiController` and `EdgeDataApiController`.
- **VM-era API payloads:**
  - `/notifications/events` returns server, ssh_key and backup groups (`config/notifications/events.php`).
  - `/account` returns `projects_count`, which is always 0.
  - `/billing` returns `yearly_total_cents` although plans are monthly-only.
  - Billing returns Tailwind classes in `color`.
  - `public/openapi/edge.json` is missing about 10 routes.
- **The API keys copy is out of date.** It says "Token creation needs an active Pro subscription" (`api-keys.blade.php:96-98`). The gate is env `DPLY_API_TOKENS_REQUIRE_PAID_PLAN`, which defaults to off and checks `onAnyPaidPlan()`.

### Observability (from the observability writer)

- **Webhook payloads all carry the same `event`.** Every event delivered to an HTTP webhook channel has `"event": "server.insights_alerts"`, hardcoded in `deliverWebhookInsight` (`app/Models/NotificationChannel.php:1294`). Receivers can't branch on the event. The docs flag this in a NOTE.
- **Wrong error text for notification webhook URLs.** A private URL is refused with origin-specific wording, "must be reachable from the public internet" (`app/Rules/PubliclyRoutableUrl.php:48,55`). That text was written for Edge hybrid origins.
- **Analytics pruning is never scheduled.** `dply:edge:prune-analytics` has config (`config/product/edge.php:111-118`: 7 days / 500 rows per site of access logs, 30 days of vitals, 45 days of hourly data), but `app/Console/Scheduling/DplySchedule.php` never runs it. Request-log retention is therefore unbounded, and the docs don't state a number.
- **The notification event catalog is VM-era.** `config/notifications/events.php:12` has 31 categories, including server, ssh_key, patches, webserver and backups. Bulk assign (`app/Livewire/Settings/BulkNotificationAssignments.php:191,285,590`) offers them all, plus a servers picker.
- **Stale copy.**
  - `resources/views/livewire/settings/partials/notification-channels-explainer.blade.php:43` says "From a server or site Notifications tab".
  - `resources/views/livewire/sites/edge/workspace/alerts.blade.php:22,38` says "BYO sites".
- **Alerts links non-admins to a page they can't open.** It always shows **Organization channels** (`alerts.blade.php:65-74`). That page is admin-only (`app/Providers/AppServiceProvider.php:202-208`), so members and deployers get a 403.
- **Stale sidebar comment.** `app/Support/SiteSettingsSidebar.php:273` says Logs sit under Traffic. In fact the sidebar item is **Build & deploy logs**, and request logs are the Live requests panel on Traffic.
- **The deploy badge loses the pre-filled form for new sign-ups.** Registration redirects to `billing.show#plans` (`app/Livewire/Auth/Register.php:173`), not the intended `/projects/create?...`. `docs/DEPLOY_BADGE.md` says otherwise.
- **Stale internal notes.**
  - `docs/EDGE_ALERTS.md:42` mentions "Forms-only bot protection".
  - `docs/EDGE_TRAFFIC.md` mentions a time-range selector that doesn't exist.
  - `docs/EDGE_LOGS.md` mentions "Linked Cloud app" origin logs.
- **Channel types switched off by default.** Pushover, Rocket.Chat, Google Chat and Mobile app exist in `NotificationChannel::types()` but are off in `config/notifications/channels.php`, so the docs don't list them.

## 2. Gaps vs Laravel Cloud

- **Member management.** Cloud lets admins change a role, remove a member and revoke invitations from **Settings > Access**. dply only has invite and cancel.
- **Roles.** Cloud has six fixed roles, including Viewer (read-only, no env or credentials), Finance (billing only) and Manager, plus custom roles and app- and environment-scoped access. dply has no read-only org role: a "Member" or "Deployer" can reconfigure any app. The per-app roles can't restrict anyone. Beta customers with contractors or finance staff will expect Viewer and Finance.
- **No ownership transfer and no "leave organization".**
- **No org-wide 2FA requirement.** Cloud doesn't list one either, but enterprise buyers ask for it. There is also no way to regenerate recovery codes without disabling 2FA.
- **Audit log.** There is no API for it and no streaming to a SIEM. Retention is a fixed 365 days, and the org log is admin-only and Team-only.
- **Teams** don't scope access to apps, which is the usual expectation.
- **CLI:**
  - There is no `dply create` and no create-app API.
  - `deploy --wait` doesn't stream build logs.
  - It installs via curl-to-bash only, needs Node 18 or later, and isn't on npm or Homebrew.
- **MCP** is read-only: no deploy, rollback, logs, domains or env tools, although REST has them.
- **API:**
  - no cursor pagination (hard caps instead)
  - no idempotency keys
  - no member, team, secret or firewall endpoints
  - no create or delete app
  - no matching OpenAPI spec
  - no webhooks or event stream
  - no structured error codes

- **Logs.** There is no searchable runtime log history and no log drain, only:
  - a live request tail (200 rows)
  - 15 minutes of container logs
  - 1 hour of queue worker logs

  There is also no SSR worker `console.log` tail.
- **Webhooks.** There is no HMAC signing and no retries: a delivery is sent once and a failure is only logged (`NotificationChannel.php:1025`). `webhookHeaders` (`:1341`) has no UI.
- **Subscriptions.** There are no org-wide subscriptions in the UI, although `NotificationRoutingResolver.php:75` supports them. Each app is wired individually or through Bulk assign.
- **Traffic.** There is no time-range selector (month-to-date only), no per-route or per-status breakdown beyond today, and no latency percentiles by path.
- **Alerts.** There are three fixed metrics, checked hourly, with a 6-hour cooldown. There is no p95 alert and no "recovered" notification.

## 3. Pricing / limits questions

- **Seats.**
  - Pro is a hard 3 seats, and pending invitations count (`ManagesOrganizationSubscription.php:243-270`).
  - Team includes 5 and bills extra seats at $5, but only members count toward billing (`OrganizationBillingStateComputer.php:122`). Pending invitations don't count there.
  - The trial is Pro, so trial orgs are capped at 3 seats.
  - The UI error says "Upgrade on the billing page" but doesn't name Team as the fix.
- **The audit log is Team-only, but the per-app log shows the last 100 events on Pro.** Decide which is intended.
- **API token plan gate.** If `DPLY_API_TOKENS_REQUIRE_PAID_PLAN` is on, `onAnyPaidPlan()` may exclude card-less trial orgs, which would block API and MCP tokens during the trial. Confirm the hosted setting.
- **`dply login` tokens never expire.** Consider a default lifetime.
- **MCP and REST share the 60/min per-token limit.**

- **No observability feature is plan-gated** (traffic, logs, alerts, channels). Confirm that's intended.
- **Retention isn't stated anywhere customer-facing.** Once pruning is scheduled, decide whether it varies by tier.
- **The RUM script is always injected** into HTML responses whenever ingest is configured (`packages/edge-worker/src/rum.ts:3-9`). There is no per-app opt-out, which may matter for privacy and CSP.

## 4. Suggested fixes

- **S** — Fix the `x-docs-link` slugs to `roles-and-permissions` and `organizations`.
- **S** — Fix the Teams hero and modal copy ("group people and route notifications"), and the role blurbs on Members.
- **S** — Fix the compliance README file list, and drop "full history".
- **S** — Hide legacy families in `AuditActionMeta::FAMILIES`. Map `user.*` and `credential.*` to Security.
- **S** — Change the Activity search placeholder to "Search action, subject, or values…".
- **S** — Hide the per-app CSV/JSON export buttons, or label them as Team, when the plan doesn't include them.
- **S** — Enforce 2FA on OAuth login: put `login.id` in the session and redirect to `two-factor.login` when `hasTwoFactorEnabled()`.
- **S** — Fix the Profile → CLI Actions snippet, and the member device-flow cap (add `edge.deploy`, remove `billing.read`).
- **S** — Read `DPLY_TOKEN` in `resolveContext`.
- **S** — Remove the "Free plan" copy and `--prod`.
- **S** — Rewrite the `DplyServer` MCP instructions, and the `ListServers` and `GetOperationStatus` descriptions.
- **S** — Stop the edge API group double-throttling.
- **S** — Add JSON rendering for `api/*`, and a JSON 404 for an unknown Site on the API.
- **S** — Fix the API keys copy ("active plan").
- **S** — Drop `yearly_total_cents`, `projects_count` and the CSS `color` field from the API.
- **S** — Delete the dead `saveAlertDestinations` and `applyDeployerAbilityCap`, or wire them up.
- **M** — Add member role change and removal to **Members**, with an audit log entry and seat recount. Removing a member already invalidates their API tokens (`app/Http/Middleware/AuthenticateApiToken.php:50`).
- **M** — Add ownership transfer. Block account deletion for the sole owner of an org that still has apps or a subscription.
- **M** — Make the org **Deployer** role match its description: gate `SitePolicy::update` on non-deploy settings, or add a separate `deploy` ability. Alternatively, drop the "reduced scope" claim.
- **M** — Let non-admins create capped API tokens and see and revoke their own CLI sessions.
- **M** — Make MCP site tools return Edge fields, and add ability checks to MCP resources.
- **M** — Replace raw exception messages in API 422s. Regenerate the OpenAPI spec.
- **L** — Add a real read-only org role (Viewer) and a Finance role, and make per-app roles restrict as well as elevate. Today the app Viewer and Deployer roles are no-ops.
- **L** — Add cursor pagination, a create-app API with `dply create`, and MCP write tools.

- **S** — Send the real `event_key` (plus severity and app id) in webhook payloads.
- **S** — Schedule `PruneEdgeAnalyticsCommand` daily, then document retention.
- **S** — Give `PubliclyRoutableUrl` a neutral message for notification channels.
- **S** — Limit the Bulk assign catalog to `edge` plus the live `site.*` events, and drop the servers picker.
- **S** — Fix the "server or site Notifications tab" and "BYO sites" copy. Hide **Organization channels** from non-admins.
- **S** — Honor `url.intended` to `edge.create` after registration.
- **M** — Add webhook HMAC signing, retries with backoff, and a custom-headers UI.
- **M** — Add an "All apps in this organization" subscription option.
- **M** — Add a per-app RUM opt-out.
- **L** — Build searchable runtime logs with retention, and an SSR worker log tail.
