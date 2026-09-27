# Private beta — decisions & fixes (consolidated from the 8 docs reports)

Everything here came out of writing the docs against the code. Section
reports (with file:line and S/M/L sizing) are next to this file. The docs
describe what the product does **today**; each item below is a place where
that is wrong, broken, or a business decision.

## A. Security — fixed already (uncommitted)
- Customer SSR/middleware scripts were bound to the platform's shared
  `HOST_MAP` KV (every site's routing secrets), `EDGE_CACHE` and the shared
  `ASSETS` bucket (every site's build files) → removed, with a test.
- 2FA was skipped for GitHub/OAuth sign-in → now goes through the challenge.
- SSR 503 page showed internal error text to visitors → generic message.
- Removing/switching a dply database deletes it and its backups instantly,
  but the confirm said only "uses it from the next deploy" → red warning.

## B. Security — still open (fix before beta)
1. Rate-limit and waiting-room counters are keyed without the app id
   (`packages/edge-worker/src/addons.ts:153,287`): one app's traffic can
   throttle/queue another's visitors. **S**
2. Deployer role isn't reduced: any org member can edit any app's settings,
   env, domains, firewall (`ServerPolicy::update`). Per-app Viewer/Deployer
   roles don't restrict. **M**
3. Environment textarea shows values in plain text to anyone who can update.
4. Middleware fails open (bundle failure deploys without it). Documented; decide
   whether a failed middleware bundle should fail the deploy. **S**
5. Without a custom 500 page the worker shows internal error text. **S**
6. Webhooks: every payload says `"event": "server.insights_alerts"`, unsigned,
   no retries. **M**

## C. Broken features (a beta user will hit these)
1. **Private repositories can't build** — clone is anonymous. **M** (biggest)
2. **Forms never deliver** — ingest route deleted in `e9e36802`. **M**
3. **Preview deployments get no env vars/secrets**; UI says they inherit.
   Promote reuses that build. Promote also blocked by a 99% shadow-replay gate
   that quiet apps can't pass. **M**
4. **"Deploy on push" checkbox does nothing**; push-deploy isn't on at create. **S**
5. **Paused orgs can't resubscribe themselves** — plan picker one-liner
   (`plan-picker.blade.php:11` → `$this->subscription?->valid()`). **S**
6. **No "end trial early" button** though 3 messages tell users to use it;
   trial-cap pause sends no email. **S**
7. **Status pages / uptime monitors**: report says components always read
   "Unknown" and no monitors are created — but `config/sites/uptime.php` does
   exist (aliased as `site_uptime`), so **re-verify** before fixing. **M**
8. **Apex domains unsupported**; failed domains never re-checked. **M**
9. Waiting room doesn't work on SSR/container apps. **M**
10. Worker SSR can't be chosen on the create page (no mode picker; the
    create sidebar never renders). Next.js non-export apps misdetected as
    static and fail. Hugo/Jekyll detected but fail. **M**
11. Deleting an app leaves previews, custom hostnames, GitHub webhook, default
    KV behind. **M**
12. In-app help links use doc slugs that don't exist (`edge-deploys`,
    `edge-containers`, …) → 404. **S**
13. Leftovers from the VM era: "Use a BYO server" message, register-page beta
    copy about free servers, Teams copy, MCP `list_servers`, activity filters,
    CLI GitHub Actions snippet (`--no-shell` exits 2). **S each**
14. Annual-billing toggle opens a modal that doesn't exist. **S**

## D. Money — unbilled or misbilled
1. Workers AI, Browser Rendering, Vector search, Database pools: not metered
   per org (AI/Browser can't be attributed today). Paid-only gate limits risk.
2. Site storage effectively never billed (`MAX` of one site vs 5 GB × sites).
3. Sleeping a KV store makes the whole month's usage free.
4. `/_dply/image` delivery optimization is free and ungated, while the Images
   resource is paid-only.
5. Deferred deletes dropped when another app binds the resource → keeps billing.
6. Pro Valkey "Sleep" button likely keeps billing (never calls the gateway).
7. Last hour before renewal unbilled (collectors hourly).
8. Pricing page shows rounded rates ($0.06/GB egress bills $0.0625) and omits
   ~10 meters; "Nothing is throttled" is false.
9. Bucket Costs tab shows delivery rates, not bucket rates.
10. `addons` allowance never read — every add-on on every plan.

## E. Pricing & packaging — owner decisions
1. **No low-end plan.** Small static site: dply $20 vs Netlify $9, Vercel Hobby
   $0, Laravel Cloud ~$5. We lose the hobby/entry segment. Options: a $5–9
   "Starter" (1–3 static sites, no SSR/containers), or keep Pro-only for beta.
2. **SSR $7/site on top of Pro** is never covered by the plan. Consider
   including 1–3 SSR sites in Pro.
3. **Pro seat cap 3 (hard)** — pushes small teams to Team ($49). Consider
   3 included + $5 extra like Team.
4. **Container concurrency**: Flex = 2 concurrent PHP requests, Small = 4
   (Laravel Cloud ~34/GiB) → autoscaling early and pricier. Worth tuning
   php-fpm/Octane per size before beta. Container app ~$54/mo vs Cloud ~$36+db.
5. **Pro compute credit $5** ≈ 142 h of one Flex instance — small.
6. **Trial $5 cap** buys ~500 build minutes, not the advertised 1,000.
7. D1/Queues carry +25% markup; KV/R2/DO at list — pick one principle.
8. Site storage priced 2.5× customer buckets ($0.0375 vs $0.015/GB-mo).
9. Pro: 100 domains per app but 20 per org — make them coherent.
10. Container on every app now pays ~$5/mo Durable Object duration (new meter).
11. Where we already win: Next.js SSR team of 3 ($28 vs Vercel $60).

## F. Owner to confirm (docs carry "Owner to confirm" notes)
- Beta: invite-only? how to request? credits/extended trial? end date? how
  price changes are announced.
- Support channel — none exists (pricing mailto defaults to hello@example.com).
- Public origin (`https://dply.io`?) and CLI default host (`dplyi.test` today).
- Compliance page: subprocessor list, "no certifications/DPA/SSO".
- Abuse handling process.
- Data deletion after pause (automatic purge exists, off).
- Changelog "Security" entry — do we disclose the fixed cross-tenant issues?
- Whether to name vendors (Cloudflare, DigitalOcean) in docs consistently.
- Billing details (VAT/tax ID/currency) aren't sent to Stripe; no tax calc.
