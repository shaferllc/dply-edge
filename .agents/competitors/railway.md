# Battlecard: Railway

**Threat: medium-high.** Same pricing shape, generic and polished; surfaces in AI answers for "Heroku alternative".

## Current facts (public, 2026)
- Hobby $5/mo including $5 usage; Pro $20 per user including $20 usage. Per-second RAM, vCPU and egress (~$0.05/GB).

## Where they're strong
Excellent DX, any language, fast setup, templates.

## Where dply differs
- Framework-aware: Laravel scheduler, queue workers, migrations on start detected, not wired by hand.
- Static and SSR frontends served from the edge in the same project.
- Pro seats: dply Pro is $20 for 3 seats; Railway Pro is $20 **per user**.

## Trap questions
- "Who set up your queue worker and scheduler services, and what happens when you add a queue?"
- "How many people on the team? Price it per seat."

## Evidence
No deals yet. Sources: [livemy.app: Railway pricing](https://livemy.app/blog/railway-pricing) · [Railway: deploy Laravel](https://railway.com/deploy/laravel--laravel)
