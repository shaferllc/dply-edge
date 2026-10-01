# Competitive landscape

Researched 2026-09-29 from public sources (web). **No win/loss data yet**: dply is pre-launch, with no CRM,
call recordings or deals, so every "where we win" below is a hypothesis to test, not a record.
Prices are as reported by the cited pages; check the vendor's own pricing page before quoting one publicly.

## What changed that matters

1. **Laravel Cloud moved onto dply's price points (June 2026).** Starter $5, Growth $20, Business $200, with
   hibernation on by default (wake "under 500ms"), and a **free trial with $5 credit, no card required**.
   dply requires a card up front. See [laravel-cloud.md](laravel-cloud.md).
2. **Railway's model is the same shape as dply's:** Hobby $5 with $5 of usage, Pro $20 with $20 of usage
   (per user), billed per second. Price alone doesn't differentiate. See [railway.md](railway.md).
3. **Render went flat-fee (April 2026):** Pro $25/month with unlimited seats, free Hobby tier that spins down.

## Price points at a glance

| | Entry | Next tier | Sleeps when idle | Card to try |
|---|---|---|---|---|
| **dply** | Starter $5 (1 seat, $5 usage incl.) | Pro $20 (3 seats, $20 usage incl.) | Yes, after 5 min | Required |
| Laravel Cloud | Starter $5 | Growth $20 (queue clusters, previews) | Yes, default | Not required |
| Laravel Forge | Hobby $12 + your VPS | Growth $19 + VPS | No (always-on server) | n/a |
| Railway | Hobby $5 ($5 usage incl.) | Pro $20/user ($20 usage incl.) | Configurable | Hobby trial |
| Heroku | Eco $5 (1,000 dyno-hrs, sleeps after 30 min) | Basic $7/dyno, always on | Eco only | Required |
| Render | Hobby free (spins down after 15 min) | Pro $25 flat, unlimited seats | Free tier only | No |
| Fly.io | Pay per second (~$2/mo smallest machine) | Support plans $29 / $99 | Configurable | Required |

## dply's defensible wedges (to prove in real deals)

- **One project for a mixed stack:** Laravel *and* Rails *and* Node apps, plus static/SSR frontends on the edge,
  one bill. Laravel Cloud is Laravel-only; Forge is PHP servers.
- **Framework-aware without config:** Laravel scheduler, queue workers, migrations on start, detected from the repo.
  Railway, Fly and Render are generic container hosts; you wire those yourself.
- **Honest trade-offs published:** the /vs pages say when the rival fits better.

## Where dply is weaker today (say so, fix or accept)

- **Card-required trial** vs Laravel Cloud and Render trying for free. Owner ruling r-f17p5zgeh120cm5t chose this;
  worth revisiting if waitlist→trial conversion is low.
- **Starter doesn't scale out:** one app instance, one worker. Laravel Cloud and Railway entry tiers compete here.
- **Wake time is ~3–5 seconds** (2026-09-30, after the wake speed-ups; was 5–6 s). Measured from real cold starts
  (`php artisan dply:edge:wake-time {site} --recent`): a php-fpm app ~3.2–3.7 s from request to reply, an Octane app
  ~4.8–5.2 s. Laravel Cloud publishes "under 500ms". Don't mention wake speed in any copy; the honest line is
  "an idle app takes a few seconds to wake; keep one instance warm if that matters". Re-measure after any
  container or Worker change.
- **No track record** vs incumbents. Use product proof (docs, traces, transparent pricing), never invented logos.

## Files

- [laravel-cloud.md](laravel-cloud.md) · main rival
- [forge.md](forge.md) · main rival
- [railway.md](railway.md) · [heroku.md](heroku.md) · [render.md](render.md) · [fly.md](fly.md)

## Refresh

Re-run monthly ("competitive intelligence"). Once trials start, record the competitor each signup left and
each loss reason, so these cards can carry real win/loss numbers instead of hypotheses.
