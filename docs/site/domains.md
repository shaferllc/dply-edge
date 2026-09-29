---
title: "Domains"
description: "Use your app's default dply hostname, attach your own custom domains, and get HTTPS certificates issued automatically."
---

Every app gets a free dply hostname when its first deploy goes live. When you want visitors to reach the app at your own address, such as `www.example.com`, you attach a custom domain, point DNS at dply, and dply issues and renews the TLS certificate for you.

## Default hostname

Each app has a default hostname on `on-dply.live`, such as `my-app-a1b2c3.on-dply.live`. It stays available when you add custom domains, so you can always reach the app on it.

The hostname appears once the first deploy succeeds. Until then, **Routing** shows **Pending first deploy** under **Default hostname**.

## Custom domains

Custom domains are managed per app. Open your app and choose **Routing**. The **Domains** tab opens with a sentence saying where visitors reach the app, then lists each address as a row, such as "www.example.com is live over HTTPS". Click a row to see its DNS records, check DNS, make it the primary address, or remove it.

> [!IMPORTANT]
> Deploy the app at least once before you attach a domain. Without a live deploy, the domain is saved but shows an error until the first deploy finishes.

### Add a custom domain

Choose **Use your own domain**, enter the hostname (for example, `www.example.com`), and pick how to connect it. The dialog tells you where the domain's DNS is hosted today, such as GoDaddy or Cloudflare.

- **Let dply run DNS** (easiest): you change the domain's nameservers once, and dply answers for the whole domain after that. See [Let dply run DNS](#let-dply-run-dns).
- **Point it at dply myself**: keep your DNS where it is and add the records dply shows. If the domain is an active zone in your organization's Cloudflare account, dply adds the record for you. See [Automatic DNS with Cloudflare](#automatic-dns-with-cloudflare).

When you point it yourself, the domain's dialog shows the records to create:

- **CNAME**: the hostname to point your domain at.
- **TXT** `_dply-verify…`: proof that you own the hostname, shown until DNS is ready.
- An ownership TXT record from the certificate provider, for some hostnames until TLS is active.

Add them at your DNS provider (see [Domain verification](/docs/domain-verification) for examples), then choose **Check DNS now**. When the records resolve, the row changes to "is live over HTTPS" once the certificate is issued.

dply also checks domains on its own every 15 minutes:

- A domain waiting for DNS is checked on every run.
- A domain that failed keeps being re-checked for 72 hours after you attached it: every 15 minutes for the first hour, then hourly for the rest of the first day, then every 6 hours. If your records go live in that window, the domain goes live by itself.

After 72 hours a failed domain waits for you to choose **Check DNS now**. If you subscribe to domain notifications, you hear about a domain when it turns ready or starts failing, not on every repeat check.

### Let dply run DNS

With this option dply hosts DNS for the whole domain, so any app in your organization can use the domain or a subdomain of it without copying records.

1. Choose **Use your own domain**, enter a hostname, pick **Let dply run DNS**, and choose **Continue**. The domain appears under **DNS dply runs**, and its dialog opens.
2. **Keep your existing records.** dply looks up the records your domain has today, such as MX and TXT records for email. Check the ones you use and choose **Keep the checked records**. Add any it missed under **Records**. Do this before you switch, or email to the domain stops arriving.
3. **Turn off DNSSEC** at your registrar. A domain with DNSSEC on stops resolving when its nameservers change.
4. **Change the nameservers** at your registrar to the two the dialog shows, then choose **I’ve changed them — check now**.

Registrars can take up to a day to publish the change. dply checks every few minutes. When the domain turns active, every hostname under it that you attached goes live by itself, and new ones go live as soon as you add them.

You manage the domain's records from its dialog under **DNS dply runs**: add A, AAAA, CNAME, MX and TXT records, or delete them. Records for your app hostnames are added and removed for you.

A domain can be run by one organization only. A domain whose nameservers never change is dropped after 14 days, so nobody can hold a domain they don't control. To move a domain away, point its nameservers back at your registrar, then choose **Stop using dply DNS**, which deletes its records at dply.

### Add a domain from your repository

You can also declare domains in `dply.yaml`. dply attaches every listed hostname on each deploy:

```yaml
domains:
  - "www.example.com"
  - "example.com"
```

Removing a hostname from the file does not detach it. To detach one, remove it in the dashboard. The **Domains** tab lists repo-declared hostnames under **From dply.yaml**.

### Status badges

Each domain shows a DNS badge and, once DNS is ready, a certificate badge.

| Badge | Meaning |
|-------|---------|
| **Pending DNS** | Attached. Waiting for your DNS records, or for the domain's nameservers to point at dply. |
| **Ready** | DNS verified. dply is routing traffic for this hostname to your app. |
| **Failed** | The last check didn't find the expected records. The row shows what it found. dply keeps re-checking for 72 hours after you attach the domain. |
| **Issuing certificate** | DNS is ready and the TLS certificate is being issued. |
| **TLS active** | The certificate is issued. The domain is fully live over HTTPS. |
| **TLS failed** | Certificate issuance failed. The row shows the reason, and dply retries every 15 minutes. |

## HTTPS certificates

dply issues and renews certificates for custom domains automatically, through Cloudflare. You don't upload or renew certificates yourself. Issuance starts after your domain is **Ready**, and usually finishes within a few minutes. Until it does, HTTPS requests to the domain can fail.

## Apex domains

You can attach an apex (root) domain such as `example.com`. An apex can't hold a normal CNAME record, so you point it at dply one of two ways:

- **Automatic DNS with Cloudflare.** If `example.com` is an active zone in your organization's Cloudflare account, dply creates the record at the zone root for you. Cloudflare flattens it. See below.
- **Manual.** At your DNS provider, create an `ALIAS` or `ANAME` record, or a CNAME with CNAME flattening, at the root (`@`) pointing at the **CNAME target**. Keep it DNS-only (not proxied). Also add the **Verification TXT record**: an apex is always verified with the TXT record, because its addresses alone don't prove you own it.

dply verifies a flattened apex by checking that its addresses match the CNAME target's addresses, and that the TXT record holds your token. See [Domain verification](/docs/domain-verification#apex-domains).

If your DNS provider can't flatten or alias the root, attach `www.example.com` instead, and redirect `example.com` to `https://www.example.com` at your provider or registrar.

## Automatic DNS with Cloudflare

If your organization has a Cloudflare credential, and the zone for the hostname is **active** in that Cloudflare account, dply creates a proxied CNAME record for you when you attach the domain. This works for a subdomain such as `www.example.com` and for the apex `example.com` itself. The domain goes straight to **Ready**. When you remove the domain, dply deletes that record again.

Zones that are pending or not yet active in Cloudflare aren't used. The domain falls back to manual DNS.

## Remove a domain

Choose **Remove** on the domain row and confirm. dply stops serving the hostname and removes its certificate. DNS records you created yourself stay at your DNS provider. Delete them there.

## Limits

Custom domains are counted across your whole organization; there is no separate per-app cap. Preview deployments don't count.

<!-- generated: php artisan dply:billing:price-table limits -->
|  | Starter | Pro | Team |
| --- | --- | --- | --- |
| Sites | Unlimited | Unlimited | Unlimited |
| Concurrent builds | 1 | 2 | 5 |
| Build timeout | 20 min | 45 min | 60 min |
| Custom domains (per organization) | 3 | 20 | 100 |
| Container app instances | 1 per app | Autoscaling | Autoscaling |
| Queue workers per app | 1 | 5, autoscaling | 10, autoscaling |
| SQL databases (D1) | 2 | 10 | 50 |
| Queues | 2 | 10 | 50 |
| Realtime connections per app | 200 | 1,000 | 5,000 |
| Audit log | No | No | Yes |

When you hit a cap, attaching fails with a message that names your plan's limit. Remove a domain or upgrade to add more.

A hostname can be attached to only one app across all of dply. If a hostname is already attached elsewhere, attaching fails with "already attached to another site". Remove it from the other app first, or contact [support](/docs/support) if you own the domain and can't access the app it's attached to.

## Preview deployments

Preview deployments get their own hostnames and don't serve your custom domains. See [Preview deployments](/docs/preview-deployments).

## CLI and API

You can manage domains from the [CLI](/docs/cli):

```bash
dply edge domains list
dply edge domains add www.example.com
dply edge domains verify www.example.com
dply edge domains rm www.example.com
```

The [HTTP API](/docs/api) has matching endpoints under `/sites/{site}/domains`.

## Next steps

- [Domain verification](/docs/domain-verification): DNS record examples and troubleshooting
- [Routing, redirects & headers](/docs/routing)
- [Edge network](/docs/edge-network)
