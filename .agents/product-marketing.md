# Product Marketing Context

*Last updated: 2026-09-29 (pricing synced to config/product/subscription.php). V2: drafted from the codebase, then owner answers (ICP, main rival, top differentiator, stage, goal, objections, anti-persona, voice). Stage: **pre-launch**, so there are no customers, quotes or metrics yet. Items marked *(inferred)* are guesses, not customer evidence.*

## Product Overview
**One-liner:** Push a repo. Get the whole app running.
**What it does:** dply edge deploys static sites, server-rendered apps, and PHP, Rails or Node servers from a Git push onto a global edge. The managed Postgres, MySQL, MongoDB, Valkey and queue workers those apps need run right beside them. No servers to patch, and one bill for all of it.
**Product category:** Git-push app hosting / PaaS: "Netlify/Vercel for the whole app," not just the frontend.
**Product type:** Self-serve SaaS (web dashboard + `dply` CLI + REST API).
**Business model:** Three monthly plans, each with usage credit included, plus metered usage past it. No free plan and no annual billing.
- **Starter $5/mo:** 1 seat, $5 of usage included. **Pro $20/mo:** 3 seats, $20 included. **Team $49/mo:** 10 seats (+$5 per seat past 10), $49 included.
- Sites are unlimited on every plan: no per-site fees and no per-meter allowances. A hidden fair-use cap on apps (Starter 25, Pro 250, Team 1,000) stops abuse.
- Usage past the included credit bills at provider cost + 30%. Apps, workers, databases and Valkey bill per second while awake; bandwidth ($0.06/GB), build time and requests by the unit. Nothing is throttled. Monthly only.
- **5-day trial of the chosen plan, card required at signup.** It bills on day 6 unless canceled. Trial usage is capped at $2. If it lapses unpaid, the org is paused and its data is kept for 30 days (warning emails 7 days and 1 day before), then deleted.

## Target Audience
**Primary ICP (owner, 2026-09-26):** **Laravel and Rails developers** leaving Forge, Laravel Cloud, Heroku or a VPS. Solo devs and small teams (1–5 seats) running a real app with a database and queue workers.
**Secondary:** Frontend devs on Vercel/Netlify and agencies. They're served by the same product but not the lead message.
**Decision-makers:** The developer who deploys is also the buyer: an indie dev, tech lead or small-team CTO.
**Primary use case:** Hosting a full web app (frontend + backend + database + queue workers) from one Git repo without running servers.
**Jobs to be done:**
- Get my app live from a repo without assembling a deploy pipeline.
- Stop babysitting servers (patching, scaling, queue supervisors) for a Laravel/Rails/Node app.
- Keep the marketing site, API, DB and workers in one place with one invoice.
**Use cases:**
- A marketing or docs site (Astro, Next.js, Hugo…) with previews per branch.
- A Laravel/Rails/Express app in a container that scales out on traffic and sleeps when idle.
- An app with a Postgres/MySQL + Valkey backend and autoscaling queue workers with the scheduler built in.
- Migrating off Forge, Heroku, Vercel, Netlify or Cloudflare Pages (the importer keeps build settings).

## Personas
| Persona | Cares about | Challenge | Value we promise |
|---------|-------------|-----------|------------------|
| Laravel/Rails dev leaving Forge/Heroku *(inferred)* | Not being a sysadmin; queues that just work | Servers to patch, supervisor configs, separate DB hosting | Framework runs unchanged in a container; workers autoscale; DB attached with credentials injected |
| Frontend/JAMstack dev on Vercel/Netlify *(inferred)* | Previews, speed, DX | Backend + DB live somewhere else, second bill | Same repo and dashboard add the API, DB and workers when needed |
| Agency / small team lead *(inferred)* | Predictable cost, many client sites | Per-seat and per-project costs stacking up | Flat plan with unlimited sites, previews don't take a slot, access rules for client staging |

**Lead persona:** the Laravel/Rails dev leaving Forge/Heroku (owner-confirmed). The other two are secondary.

## Problems & Pain Points
**Core problem:** A modern app is spread across a frontend host, a server host, a database provider, a Redis provider and a queue setup. That means several dashboards, several bills and glue to hold it together.
**Why alternatives fall short:**
- Frontend hosts (Vercel/Netlify) stop at the frontend and serverless functions. Your PHP/Rails server and database live elsewhere.
- Server tools (Forge, VPS) still leave you patching and scaling boxes, and you pay while they sit idle.
- Classic PaaS (Heroku) is expensive and always-on.
**What it costs them:** Time spent building pipelines and running servers, idle compute bills, and connection strings copied between providers.
**Emotional tension:** *(inferred)* Fear of a 3am outage on a box you're responsible for; worry about surprise bills; "I just want to ship."

## Competitive Landscape
**Main rival (owner): Laravel Forge / Laravel Cloud.** Forge still leaves you owning, patching and paying for an always-on server, and wiring supervisor for queues. Laravel Cloud is Laravel-only. dply runs Laravel *and* Rails/Node, detects everything from the repo, and serves static and SSR sites from the edge in the same project.
**Direct (frontend):** Vercel, Netlify, Cloudflare Pages: they own static/SSR, but the backend server, database and workers aren't first-class.
**Direct (general PaaS):** Heroku, Render, Railway, Fly.io: they host servers, but static edge delivery, previews and edge access rules are secondary, or pricing is always-on.
**Secondary:** Laravel Forge / Ploi / a DIY VPS: you still run the server.
**Indirect:** Hand-rolled Docker + GitHub Actions on AWS/DO: full control, lots of glue.

## Differentiation
**#1 differentiator (owner): zero-config deploys.** Point at a repo. dply detects the framework, runtime, build command and monorepo packages, and imports existing build settings. No pipeline, no `dply.yaml`, no server setup before the first deploy. Lead with this against Forge.

**Supporting differentiators:**
- Static, SSR, containerized PHP/Rails/Node, databases, cache and queue workers in **one project, one dashboard, one invoice**.
- **Scale to zero:** containers, Flex databases and Flex Valkey sleep when idle, so the meter stops too.
- **Queue workers as a product:** autoscaling on backlog, worker groups, the scheduler inside the worker, and one-click failed-job retry.
- **Zero-config start:** repo detection (framework, build command, runtime, monorepo packages); `dply.yaml` is optional.
- **Nothing is throttled:** going past the plan meters instead of breaking the site.
- Day-two features built in: preview-per-branch with pinned review comments, instant rollback without a rebuild, access rules, real request logs + Core Web Vitals, point-in-time DB restore, alerts to Slack/email/PagerDuty.
**How we do it differently:** Apps run on a global edge network, with the data services attached in the same project and credentials injected into the environment.
**Why that's better:** Fewer moving parts, fewer bills, and you pay for seconds used, not boxes left running.
**Why customers choose us:** Pre-launch, so there's no evidence yet. The hypothesis is that Laravel devs pick us because the first deploy takes minutes with nothing to configure, and the day-two chores (queues, scaling, backups) are already handled.

## Objections
| Objection | Response |
|-----------|----------|
| "No free plan? Forge + a $6 droplet is cheaper." | 5-day trial with a $2 usage cap, so a busy trial can't run up a bill. Cancel anytime. Compare the whole stack: server + managed DB + Redis + your time patching it. Starter is $5 with $5 of usage included, and idle apps sleep. |
| "Metered billing is unpredictable." | Every plan includes usage credit equal to its price. The billing page shows usage accrued so far this month and a cost forecast before the invoice lands. Valkey's monthly price is a ceiling. Idle containers and Flex databases sleep, so quiet months cost less, not more. |
| "You're new. Can I trust you with prod data?" | Daily backups and point-in-time restore on managed databases, database export/import, and instant rollback of any deploy. Your code stays in your Git repo. *(Pre-launch: this objection weighs most; add proof points as they appear.)* |
| "No SSH? And what about lock-in?" | You don't need a shell for the usual jobs: live log tail, a query console, failed-job retry, and env vars in the dashboard. Your framework runs unchanged in a standard container, config lives in your repo (`dply.yaml` is optional), and databases export. Leaving is `git push` somewhere else. |
| "Migration sounds painful." | Point us at the repo; we detect the framework and keep your build settings. There's an importer for Vercel, Netlify and Cloudflare Pages. |
| "Can it run my Laravel/Rails app unchanged?" | Yes. It runs in a container, migrations run on deploy, and the scheduler runs inside the worker. |

**Anti-persona (owner-confirmed):**
- Free-hosting seekers: hobbyists who won't put a card down.
- Enterprise / compliance buyers: SOC2, SLAs, annual contracts, invoicing.
- People who want root/SSH: full server control, custom system packages.

*(Pure static-only sites are a fit but not a target, since free tiers elsewhere win on price.)*

## Switching Dynamics
**Push:** Server maintenance, a split frontend/backend/DB stack, idle-compute bills, pipeline glue.
**Pull:** One push deploys everything, scale to zero, queue workers that autoscale, one invoice.
**Habit:** An existing Forge/Vercel setup "works"; the team knows it; DNS and CI are already wired.
**Anxiety:** Surprise usage bills, migration risk, vendor maturity (a newer product), data safety.

## Customer Language
**How they describe the problem:** **TODO**: collect verbatim quotes (support tickets, calls, Reddit/HN threads).
**How they describe us:** **TODO**
**Words to use:** push, deploy, the whole app, project, app, preview, rollback, sleeps when idle, scale to zero, one bill, managed Postgres / MySQL / Redis-compatible cache, queue workers.
**Words to avoid:**
- **Cloudflare / Upstash / any underlying vendor name** in customer copy, and provider jargon (Workers connections, binding types, dash URLs).
- "server" or "site" when you mean the customer's thing: say **project** / **app**.
- Platform margin or markup: prices are presented as the customer's cost, as estimates.
- "Free plan" (it no longer exists).
**Glossary:**
| Term | Meaning |
|------|---------|
| Project / app | The customer's deployed thing (a repo + its services) |
| Usage credit | The part of the plan price that pays for metered usage before anything extra is billed |
| Container app | A PHP/Rails/Node server running in an autoscaling container |
| Preview | A per-branch/PR deploy with its own URL; doesn't take a site slot, but its usage counts |
| Flex vs Pro (DB/Valkey) | Flex sleeps when idle; Pro stays on |
| Bindings / Resources | Attached databases, caches, queues, storage |
| `dply.yaml` | Optional in-repo config for build, redirects, headers, crons |

## Brand Voice
**Tone (owner, 2026-09-26): warmer and friendlier.** Approachable, in the Laravel-community register: a helpful peer, not a terse terminal. Still no hype, still honest about pricing.
**Style:** Concrete and plain-spoken. Show the real thing (a deploy trace with timings) and explain it like you'd explain it to a friend. Contractions and second person ("your app") are fine, and a little warmth in the headlines and empty states. The terminal aesthetic can stay as a *visual* motif, but the copy around it should soften: fewer clipped fragments, more full, friendly sentences.
**Personality:** Friendly, pragmatic, reassuring, honest, quietly confident.
**Note:** the current welcome-v2 copy leans terse and dry, so it's a candidate for a warmth pass.

## Proof Points
**Stage: pre-launch.** There are no customers, testimonials or usage metrics yet. Until there are, use *product* proof: real deploy traces with timings, feature specifics, transparent pricing tables, and an honest founder voice. Never invent logos or quotes.
**Metrics:** None yet. Candidates to start capturing: time to first deploy, build times, trial→paid rate.
**Customers:** None yet.
**Testimonials:** None yet. Collect them from the first trial users.
**Value themes:**
| Theme | Proof |
|-------|-------|
| Whole app, one place | Sites + server apps + databases + workers in one project |
| Pay for what runs | Per-second compute; scale to zero; Valkey price is a cap |
| Zero-config | Framework/runtime/monorepo detection; importer for Vercel/Netlify/CF Pages |
| Safe to operate | Instant rollback, point-in-time restore, failed-job alerts, access rules |

## Goals
**Business goal (next ~90 days, owner):** **Top of funnel: trial signups and a waitlist.** Build an audience of Laravel/Rails devs before optimizing conversion. (Context: the free plan was removed because it was unaffordable, so every signup goes through the card-up-front trial.)
**Conversion action:** Primary: start the 5-day trial. Secondary: join the waitlist (`COMING_SOON` gate) for people not ready to enter a card.
**Current metrics:** None yet (pre-launch).
