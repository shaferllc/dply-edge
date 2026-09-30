# Battlecard: Laravel Forge

**Threat: high (largest source of switchers).** Owner-named main rival.

## Their pitch
Provision and manage your own servers for Laravel: deploy scripts, daemons, SSL, databases on the box.

## Current facts (public, 2026)
- Hobby ~$12/mo, Growth ~$19, Business ~$39 per account (annual discounts), **plus** the cloud provider's server bill.
- Servers are always on and yours to size and patch.

## Where they're strong
Full control: SSH, persistent disk, anything installable on the box. Predictable fixed server bill. Mature, trusted.

## Where dply differs
- No server to size, patch or pay for while idle. Queue workers, scheduler and migrations are built in.
- Managed database and Valkey instead of running them on the same box.
- Mapping and trade-offs: /vs/forge and the Forge migration guide.

## Trap questions
- "When did you last patch that server, and who gets paged if it runs out of disk?"
- "How many hours a day is the app actually busy? You pay for all 24."
- "What happens to Horizon and the scheduler if the box reboots?"

## Don't
- Don't hide: no SSH, no persistent disk, cold starts. Forge wins for apps that need those; the /vs page says so.

## Evidence
No deals yet. Sources: [Benjamin Crozat: Laravel Forge](https://benjamincrozat.com/laravel-forge) ·
[Capterra: Laravel Forge](https://www.capterra.com/p/234049/Laravel-Forge/)
