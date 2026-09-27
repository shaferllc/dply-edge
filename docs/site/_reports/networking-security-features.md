# Report: Networking, Security, Site features

Writer pages: `domains`, `domain-verification`, `routing`, `caching`, `edge-network`,
`firewall`, `rate-limits`, `bot-protection`, `access-control`, `waiting-room`,
`platform-security`, `forms`, `error-pages`, `snippets`, `status-pages`.

Verified against code on 2026-09-26 (branch `edge/queue-workers`). The worker
source is `packages/edge-worker/src/{handler,addons,auth}.ts`.

## 1. Mismatches

### Critical: broken or misleading behavior

1. **Forms never deliver submissions.** The Worker validates the honeypot and
   Turnstile, then returns `{"ok":true}` or a "Thanks" page
   (`packages/edge-worker/src/addons.ts:236-252`). It does not forward the
   fields anywhere. Both the ingest route `POST /hooks/edge/{site}/forms`
   (`routes/web.php`) and the Worker's signed forward were deleted in commit
   `e9e36802` ("Redis", 2026-09-24). `EdgeFormIngestController` is now dead
   code. `FormsConfig.ingest_url` / `ingest_key` (`addons.ts:36-37`) are never
   set. The Forms page still says "Visitors POST; Dply emails you the fields",
   and the `to_email` field implies email delivery. This looks like an
   accidental regression. The docs page carries a WARNING until it is fixed.
2. **Rate-limit and waiting-room counters are not scoped per app.** The keys
   are `rl:${ip}:${rule.path}:${rule.window_seconds}` (`addons.ts:153`) and
   `wr:admit:<minute>` / `wr:active` (`addons.ts:287-288`), stored in the
   shared `caches.default`. None of them include `site_id`, so two apps with a
   `/api/*` rule share one per-IP counter, and every app with a waiting room
   shares the same admit and active counters in a data center. This is a
   cross-tenant interference bug: one busy app's traffic can queue or throttle
   visitors of another app.
3. **Counters are per Cloudflare location and approximate.** The Cache API is
   per colo, so a limit of 60/min is really 60/min per IP per data center. It
   is also not atomic (read, then put) and fails open (`addons.ts:190`). The
   docs say "approximate, per location". The UI copy ("Counting is per IP +
   path rule + window, enforced at the Edge") implies a global counter.
4. **The waiting room "active visitors" count only increments.** Each
   admit re-puts `wr:active` with a fresh TTL of one session length. It never
   decrements when a visitor leaves. Once it reaches `total_active_users`,
   admissions stop, and the key expires one session length after the last
   admit, when the room reopens all at once. So **Max active visitors** is
   "admits per session window" rather than concurrent visitors. It's
   approximate, and shared across apps (item 2).
5. **The waiting room admit cookie is only set on static R2 responses**
   (`handler.ts:920`). SSR, container, redirect, rewrite-proxy, hybrid-origin
   and 404 responses never carry `dply_wr=1`. On SSR and container apps every
   request re-enters the room logic and burns an admit slot.
6. **Status pages mostly do not work for Edge apps.**
   - `config/site_uptime.php` does not exist, so
     `EnsuresDefaultUptimeMonitors` (`app/Services/Sites/EnsuresDefaultUptimeMonitors.php:30`)
     seeds nothing. There is also no per-app UI to create uptime monitors. The
     **Site uptime check** picker on a status page is therefore always empty.
     The dispatch command still runs every 5 minutes against zero rows.
   - **Site** monitors resolve through `Server.health_status`
     (`app/Services/Status/MonitorOperationalState.php:~100-120`). Nothing sets
     that field for Edge owner servers, so they read **Unknown**. An Unknown
     component turns the public banner into "Partial service degradation"
     (`app/Livewire/Status/PublicPage.php`, `$worstMonitor` → `degraded`).
     Adding your app to a status page makes your public page say you are
     degraded.
   - **Server** is still offered as a monitor type
     (`resources/views/livewire/status-pages/manage.blade.php:154`), which
     exposes the vestigial owner row as a customer-facing concept.
   - The index copy still says "for your servers and sites—similar to other
     hosting panels".
7. **Redirects, rewrites, headers, snippets and tags match the index-resolved
   path, not the URL.** `normalizeRequestPath` turns `/` into `index.html` and
   `/blog/` into `blog/index.html` before `matchRepoRule` runs
   (`handler.ts:344`, `:491`, `:702`, `:900`). So a redirect `from: /` never
   fires, and `from: /blog` does not match `/blog/`. That contradicts the
   comment on `Routing::isAlreadyMatched` ("matches the path and that path
   plus one trailing slash"). Rate limits, forms and the waiting room use the
   raw `url.pathname`, so the two families match differently. There are no
   tests for `matchRepoRule`.

### Behavior the UI misdescribes

8. **"First matching path wins"** (`rate-limits.blade.php:87`) is wrong. Every
   matching rule increments its own counter, and the request is stopped by
   the first rule that is over its limit (`addons.ts:151-171`).
9. **Bot protection "All HTML pages" does not challenge page views.** It only
   injects the widget and script into HTML that contains a `</form>`
   (`addons.ts:injectTurnstileWidget`). Nothing blocks a visitor without a
   token. The stored `turnstile.paths` is published but never read by the
   Worker. The "Where to challenge" copy ("every document response") and the
   rate-limit "Challenge" action (a real interstitial) describe two different
   things.
10. **HTML add-ons only run on static HTML from storage.** Snippets, tags and
    Turnstile injection do not run on SSR, container or hybrid-proxied
    responses (`handler.ts:885-905` versus the SSR branch at `:680-712`). None
    of the four pages says so.
11. **Edge TTL options above 1 day are silently clamped to 24h.** The Worker
    caps freshness at `EDGE_CACHE_MAX_TTL_SECONDS` (`handler.ts:300`). The
    **Edge TTL** select offers 7 days, 30 days and 1 year.
12. **The Cache page shows "Static assets" before anything is saved, but the
    Worker treats that state as Off.** `Cache::mount` defaults the mode to
    `assets` (`Cache.php`), while `EdgeHostMapAddons` publishes no `cache` key
    until meta exists, and the Worker's `cacheMode` then returns `off`.
13. **Hybrid origin routes are read from and written to the KV cache whatever
    the cache mode** (`handler.ts:~770-800`), so choosing **Off** does not stop
    it.
14. **"Purge a path" misses query-string variants.** It deletes exactly
    `edge_cache:{site}:{path}` (`EdgeCachePurger.php:149-190`). With
    **Include in the cache key**, the `?a=b` variants stay cached.
15. **"Purge a tag" drops only the latest entry for the tag.** The tag index
    maps each tag to one key (`handler.ts` `writeEdgeCache`). The UI copy does
    say "drops the latest copy stored under the tag", but a tag purge is
    usually expected to be complete.
16. **Rewrites do not run for SSR or container apps.** Redirects and headers
    do (`handler.ts:680-729`). The Routing page does not say so.
17. **The default security headers are added only to static and redirect
    responses.** SSR and container responses get only header rules.
18. **Maintenance mode cannot be turned off from the dashboard when the repo
    enables it.** The merge is `dashMaintOn || repoMaintOn`
    (`EdgeEffectiveErrorPages.php:57`). The UI says "Dashboard values override
    the repo".
19. **The firewall mode cannot be turned off from the dashboard when the repo
    sets it.** Dashboard **Off** falls through to the repo mode, and country
    lists are unioned (`EdgeEffectiveFirewall.php`). A repo-listed country
    cannot be removed in the dashboard.
20. **Without a custom 500 page, the Worker leaks internal error detail.**
    `internalServerError` returns `Internal Server Error\n<exception message>`
    in plain text (`handler.ts:2302`).
21. **The custom 404 page rarely shows on static sites.** `spa_fallback`
    defaults to `true` (`EdgeHostMapPublisher.php:224`), so a missing path
    serves `index.html` with status 200. The 404 page only appears when SPA
    fallback is off or `index.html` is missing. The Error pages UI does not say
    so.
22. **The domain-limit error message still mentions a Free tier:** "Upgrade to
    Pro for up to 100" (`EdgeCustomDomainProvisioner.php:57`, `{1}` branch).
    No tier has a per-site limit of 1 any more.
23. **The per-site domain cap is larger than the org cap on Pro.** Pro allows
    `custom_domains_per_site` = 100 but `custom_domains` = 20
    (`config/product/subscription.php:107`), so the per-site value has no
    effect on Pro.
24. **Failed domains are never re-checked automatically.**
    `VerifyEdgeCustomDomainsJob` only re-verifies `dns_status === 'pending'`
    (`VerifyEdgeCustomDomainsJob.php:44`). A customer who attaches a domain
    and then takes more than 15 minutes to add DNS finds it stuck in
    **Failed** until they click **Verify DNS**.
25. **Apex domains are unsupported on every path.** Manual:
    `verify()` compares `dns_get_record(DNS_CNAME|DNS_A)` against a hostname,
    and a flattened apex returns only A records. Auto: the zone search loops in
    `findCloudflareCredentialForZone` / `findOwnedCloudflareZone`
    (`for ($i = 1; $i <= count($labels) - 2; ...)`) never try the hostname
    itself, so for `example.com` the loop runs zero times and the `'@'` record
    branch in `provision()` is unreachable. The docs say apex is not supported
    yet.
26. **Access gate comments disagree.** `auth.ts:3` says "Production hostnames
    skip the gate entirely", but `gateApplies` does not check
    `is_production`, and the publisher and UI say the gate covers the live
    site. The UI is right. Fix the comment.
27. **Rate limits save with no validation** (`RateLimits.php:57`). A limit of
    0 or a 99999s window is clamped silently at publish (1–10,000 requests,
    1–3,600 s in `EdgeHostMapAddons`), and the form keeps showing the raw
    value.
28. **The Remove button on `partials/edge/domains.blade.php` has no confirm
    modal.** It is the legacy `Domains` component, no longer reachable because
    `edge-domains` redirects to Routing → Domains. The live `routing-domains`
    partial does confirm. The old component and partial can be deleted.
29. **`dply.yaml` top-level keys `forms`, `snippets` and `tags` are missing
    from `DplyManifest::KNOWN_TOP_LEVEL_KEYS`** (`DplyManifest.php:45-60`),
    even though `EdgeRepoConfigLoader` reads them. `dply edge lint` or the
    manifest parser probably emits an "unknown key" warning for valid config.
    Not verified end to end.
30. **The Routing redirect status select offers 301/302/307/308.** The repo
    loader also accepts 303. Minor.
31. **The CLI usage string says `edge purge --tag`, and purge by path is not
    exposed in the CLI** (API only).
32. **Stale internal docs.** `docs/EDGE_ROUTING.md` (says routing is
    read-only), `EDGE_FORMS.md` (describes the deleted ingest), and
    `EDGE_DOMAINS.md` (`.on-dply.site` apex, no TXT token) are out of date.

33. **SPA fallback rewrites the path that header rules, snippets, tags and
    Turnstile see.** After fallback, `requestPath = 'index.html'`
    (`handler.ts:~820`). On an SPA (the default, `spa_fallback: true`), a
    snippet or tag on `/pricing` never fires, and a rule on `/index.html`
    fires on every client route. Redirects and rewrites are unaffected. The
    docs say only `/*` is reliable on SPA apps.
34. **Forms, rate limits and the waiting room run before the access gate**
    (`handler.ts:593-614`, where `runEarlyAddons` runs before
    `handleAccessGate`), so a protected app's form endpoints accept anonymous
    POSTs. Nothing documents it.
35. **Proxied manual CNAMEs can't verify.** A proxied Cloudflare record
    answers with Cloudflare IPs, so `verify()` never sees the CNAME. The docs
    tell users to set it to DNS only. Auto mode creates a proxied record but
    skips `verify()`.
36. **The status page public URL is `/status/{ulid}` on the dply app domain.**
    There is no slug and no custom domain.

## 2. Gaps vs Laravel Cloud

- **Domains.** Missing:
  - wildcard domains;
  - a www ↔ apex redirect option at attach time;
  - a "primary domain" setting;
  - moving a domain between apps;
  - the pre-verification path (prove ownership and issue TLS before switching
    traffic);
  - an A-record option for apex domains on non-Cloudflare DNS;
  - a timed retry window with clear per-step status (hostname / SSL / origin).
  Cloud also sets `X-Robots-Tag: noindex` on its default domains. dply does
  not set it on `*.on-dply.live`, so default hostnames can be indexed.
- **Custom-domain overage.** Cloud bills $0.25 per domain past the allowance.
  dply hard-stops.
- **Access control.** There is no visitor SSO (Google or GitHub org, SAML), no
  Cloudflare Access gate for visitors, and no per-path protection.
  **Password** and **Dply account** protect the whole hostname.
- **Firewall.** Country rules only. There is no IP or CIDR allow/deny list, no
  ASN or user-agent rules, and no branded block page.
- **Caching.** No purge of static assets (not needed, since deploys are
  immutable). No wildcard or prefix purge. No stale-if-error. The cache page
  cannot purge browser copies (expected), and does not say so.
- **Observability of security events.** Security shows 7-day 403/429 counts.
  There is no per-rule hit count and no firewall event log with the rule
  name.
- **Status pages.** No custom domain for the status page, no subscriber
  emails or RSS, no scheduled maintenance, no working automatic component
  health for Edge apps (item 6).
- **Forms.** Nothing is delivered (item 1). Even when fixed, there is no
  submissions inbox, CSV export, or webhook target.
- **Edge network.** No customer-visible TLS settings (minimum version, HSTS
  toggle, HTTP/3) and no statement of what the zone enforces.

## 3. Pricing / limits questions

- **The `addons` tier allowance is never read.** Every paid tier and the trial
  get Firewall, Bot protection, Rate limits, Waiting room, Forms, Snippets and
  Tags. Decide whether `addons` is meant to gate anything, or remove the key.
- **Custom domains:** trial and Pro 20 per org, Team 100, Enterprise
  unlimited. Pro's `custom_domains_per_site` = 100 is meaningless (item 23).
  Laravel Cloud's Starter tier includes 10 plus paid overage, so dply is
  competitive, but a hard stop at 20 hurts agencies. Consider overage billing
  as Cloud does.
- **Unpublished caps.**
  - Rate limits: 1–10,000 requests, 1–3,600 s windows.
  - Waiting room: max active 100,000, admits/min 10,000, session 1,440 min.
  - Snippets: 8,000 chars each, 50 from the repo.
  - Tags: 20 tools.
  - Forms: 20 endpoints from the repo.
  - Error pages: 200,000 chars.
  - Edge cache: 8 MB per object, 24h max TTL, 5s min.
  Documented where relevant. None is shown in the UI except through
  validation errors.
- **Edge cache costs.** The cache stores bodies in KV as base64 JSON, so every
  cached response costs KV writes and storage. It is not metered or billed,
  and there is no ceiling per app beyond TTL expiry.

## 4. Suggested fixes

| # | Fix | Size |
|---|-----|------|
| 1 | Restore form ingest: the route plus a signed Worker POST. Put a per-site ingest key on the host entry, not the platform `log_ingest.key`, which would give every tenant the platform ingest secret. Add a Worker test. | M |
| 2 | Prefix rate-limit and waiting-room cache keys with `site_id`. | S |
| 3 | Move rate-limit and waiting-room counting to a Durable Object (or the Workers Rate Limiting binding) for per-app global counts. Until then, say "per location, approximate" in the UI. | L (S for copy) |
| 4 | Waiting room: track active sessions properly (for example, a DO with expiring slots), and stamp the admit cookie on every response path, SSR included. | M |
| 5 | Status pages: add `config/site_uptime.php` with a probe region, or build Edge-native health from `edge_access_logs` 5xx rate. Drop **Server** from the monitor type list. Stop mapping Unknown to "degraded" on the public banner. | M |
| 6 | Match routing rules against the raw pathname (keep `index.html` resolution for R2 lookups only). Add Worker tests for `/`, `/blog`, `/blog/`, `/blog/*`. | S |
| 7 | Rate-limit copy: replace "First matching path wins" with "Every matching rule counts". | S |
| 8 | Bot protection: rename **All HTML pages** to "Widget on every form" or implement a real interstitial. Drop or implement `turnstile.paths`. | S / M |
| 9 | Run HTML add-ons (snippets, tags, Turnstile) through `HTMLRewriter` on SSR, container and hybrid HTML too. | M |
| 10 | Clamp the Edge TTL select to 24h, or raise `EDGE_CACHE_MAX_TTL_SECONDS`. | S |
| 11 | Cache mode default: publish `assets` when meta is empty, or show **Off** until saved. | S |
| 12 | Purge by path: also delete query-string variants (list by prefix `edge_cache:{site}:{path}?`). | S |
| 13 | Tag index: store a set of keys per tag, not only the latest. | M |
| 14 | Maintenance and firewall merge: let an explicit dashboard **Off** override the repo, or state in the UI that the repo wins. | S |
| 15 | `internalServerError`: never echo the exception to visitors. Use `DEFAULT_APP_500_HTML`. | S |
| 16 | Error pages UI: note that SPA fallback suppresses 404s. | S |
| 17 | Domain limit message: drop the "Upgrade to Pro for up to 100" branch. Set Pro `custom_domains_per_site` to ≤ 20 or remove the per-site cap. | S |
| 18 | `VerifyEdgeCustomDomainsJob`: also retry `failed` rows (with backoff, for example 48h), so late DNS completes on its own. | S |
| 19 | Apex support: start the zone loop at `$i = 0` so auto mode can find an apex zone (S). Also accept A/AAAA records that match the resolved IPs of the CNAME target (M). | S / M |
| 20 | Add `forms`, `snippets`, `tags` to `KNOWN_TOP_LEVEL_KEYS`. | S |
| 21 | Delete the unreachable `Workspace/Domains.php` and `partials/edge/domains.blade.php`. | S |
| 22 | Fix the `auth.ts` header comment about production skipping the gate. | S |
| 23 | Validate rate-limit rules in `RateLimits::save()` (1–10,000, 1–3,600 s, path starts with `/`). | S |
| 24 | Add `X-Robots-Tag: noindex` on default `on-dply.live` hostnames. | S |
| 25 | Bot protection: update the generated Turnstile widget's domains when a custom domain in a new zone is attached. The widget includes each attached domain's zone at generation time, so only a new zone needs **Generate keys** again today. | S |
| 26 | SPA fallback: pass the original pathname to header rules and HTML add-ons. | S |
| 27 | Accept proxied CNAMEs by checking the Cloudflare custom-hostname status instead of DNS. | M |
| 28 | Document, or reorder, the access gate relative to form, rate-limit and waiting-room handling. | S |

## Open questions (not verifiable in code)

- **Zone-level TLS settings for the worker zone.** Minimum TLS version,
  HTTP/3, HSTS, Always Use HTTPS. None is set in code. The `edge-network` page
  says only that Cloudflare terminates TLS and that HTTP requests are handled
  by Cloudflare's zone settings. Confirm the production zone settings and
  document them.
- **The build host firewall rules** from `docs/edge-build-isolation.md`
  (metadata and private-range drop) are an operator step the app does not
  enforce. `platform-security` does not claim them. Confirm they are applied
  on production build workers, then the page can say so.
- **Preview-protection password verifier.** The Worker gets a salted SHA-256
  verifier in KV (`EdgeAccessGate::kvPayloadForSite`). It is fast to
  brute-force if KV contents leak. Low risk, but worth PBKDF2 when next
  touched.

