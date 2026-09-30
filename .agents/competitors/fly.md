# Battlecard: Fly.io

**Threat: low-medium.** Cheapest raw compute, most DIY.

## Current facts (public, 2026)
- Per-second machines (~$2.02/mo for shared-cpu-1x 256MB running 24/7); volumes $0.15/GB-month;
  dedicated IPv4 $2/mo per app; volume snapshots billed since January 2026; support $29 or $99/mo.

## Where they're strong
Price floor, global regions, full control of the machine.

## Where dply differs
- No Dockerfile, fly.toml or CLI required; the framework is detected.
- Managed databases, Valkey and workers attached, not assembled.

## Trap questions
- "Who on the team maintains the Dockerfile and fly.toml?"
- "Count the IPv4, volumes and snapshots on the last invoice."

## Evidence
No deals yet. Sources: [Orb: Fly.io pricing](https://www.withorb.com/blog/flyio-pricing) ·
[costbench: Fly.io hidden costs](https://costbench.com/software/developer-tools/flyio/hidden-costs/)
