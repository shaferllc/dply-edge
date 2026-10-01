# Battlecard: Laravel Cloud

**Threat: highest.** Same audience, same price points since June 2026, backed by the Laravel team.

## Their pitch
Laravel's own managed platform: push to deploy, no servers, hibernation by default, autoscaling queue clusters,
preview environments per pull request, spending caps.

## Current facts (public, 2026)
- Starter $5, Growth $20, Business $200 (restructured June 2026).
- Hibernation on by default; they state wake "in under 500ms".
- Queue clusters (autoscale on CPU, memory, throughput, backlog) and preview environments: Growth and up.
- Free trial with $5 credit, **no card required**.

## Where they're strong (don't pretend otherwise)
First-party Laravel integration, brand trust, a no-card trial, a published wake time.

## Where dply differs
- Runs **Rails and Node apps and static/SSR frontends** in the same project. Laravel Cloud is Laravel-only.
- MongoDB available alongside Postgres and MySQL.
- Pro $20 includes 3 seats and $20 of usage (compare their Growth seat terms before quoting).

## Trap questions
- "Is everything you run Laravel? What about the Node service or the Next.js frontend?"
- "Where does your non-Laravel code live today, and what does that second host cost?"

## Don't
- Don't compete on wake time. Measured 2026-09-30: dply takes ~3–5 s to wake vs their stated <500ms.
  If it comes up, say it plainly and point to Min instances (keep one warm).
- Don't claim to be cheaper. At $5/$20 the plans are level; the difference is usage and stack coverage.

## Evidence
No deals yet. Sources: [Laravel blog: More Features, Smarter Pricing](https://laravel.com/blog/laravel-cloud-more-features-smarter-pricing) ·
[Cloud pricing docs](https://cloud.laravel.com/docs/pricing) · [Cloud pricing page](https://marketing.cloud.laravel.com/pricing)
