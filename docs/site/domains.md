---
title: "Domains"
description: "Use your app's default dply hostname, attach your own custom domains, and get HTTPS certificates issued automatically."
---

Every app gets a free dply hostname when its first deploy goes live. When you want visitors to reach the app at your own address, such as `www.example.com`, you attach a custom domain, point DNS at dply, and dply issues and renews the TLS certificate for you.

## Default hostname

Each app has a default hostname on `on-dply.live`, such as `my-app-a1b2c3.on-dply.live`. It stays available when you add custom domains, so you can always reach the app on it.

The hostname appears once the first deploy succeeds. Until then, **Routing** shows **Pending first deploy** under **Default hostname**.

## Custom domains

Custom domains are managed per app. Open your app, choose **Routing**, then the **Domains** tab.

> [!IMPORTANT]
> Deploy the app at least once before you attach a domain. Without a live deploy, the domain is saved but shows an error until the first deploy finishes.

### Add a custom domain

1. In **Routing**, open **Domains**.
2. Under **Custom domains**, enter the hostname (for example, `www.example.com`) in **Hostname** and choose **Attach domain**.
3. The domain appears with a **Pending DNS** badge and the DNS records to create:
   - **CNAME target**: the hostname to point your domain at.
   - **Verification TXT record**: shown when dply needs proof that you own the hostname.
   - **Ownership TXT record**: shown by the certificate provider for some hostnames until TLS is active.
4. Add those records at your DNS provider. See [Domain verification](/docs/domain-verification) for examples.
5. Choose **Verify DNS** on the domain row. When the records resolve, the badge changes to **Ready** and a certificate is requested.

dply also checks domains on its own every 15 minutes:

- A domain in **Pending DNS** is checked on every run.
- A domain in **Failed** keeps being re-checked for 72 hours after you attached it: every 15 minutes for the first hour, then hourly for the rest of the first day, then every 6 hours. If your records go live in that window, the domain turns **Ready** by itself.

After 72 hours a **Failed** domain waits for you to choose **Verify DNS**. If you subscribe to domain notifications, you hear about a domain when it turns **Ready** or starts failing, not on every repeat check.

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
| **Pending DNS** | Attached. Waiting for your DNS records. |
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
