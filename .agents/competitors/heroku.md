# Battlecard: Heroku

**Threat: medium.** Legacy git-push incumbent; source of Rails switchers.

## Current facts (public, 2026)
- Eco $5/mo for 1,000 shared dyno-hours, sleeps after 30 minutes; Basic $7 per dyno, always on; Standard $25–50 per dyno.
- Postgres and Redis are paid add-ons; no free tier since Nov 2022.

## Where they're strong
Maturity, add-on marketplace, Pipelines, review apps, familiarity.

## Where dply differs
- Per-second billing for awake time; an idle app costs nothing for compute.
- Database, Valkey and workers are built-in resources, not separately priced add-ons.
- Rails detected from the Gemfile; no Procfile.

## Trap questions
- "Add up the dynos, the worker dyno, Postgres and Redis. What's the monthly total?"
- "How much of that runs while nobody's using the app?"

## Don't
- Don't fight the add-on marketplace or Pipelines. The /vs/heroku page concedes both.

## Evidence
No deals yet. Sources: [Aptible: Heroku pricing](https://www.aptible.com/heroku-alternatives/heroku-pricing) ·
[Heroku: removal of free plans FAQ](https://help.heroku.com/RSBRUH58/removal-of-heroku-free-product-plans-faq)
