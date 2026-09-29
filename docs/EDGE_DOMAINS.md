---
title: "Edge domains"
slug: edge-domains
category: "Edge"
order: 60
description: "How to use an Edge site's default hostname, attach custom domains by CNAME or by letting dply run DNS, manage SSL, and handle BYO Cloudflare and previews."
group: edge
---

# Edge domains

**Routing → Domains** controls how visitors reach an Edge site. It opens with one sentence ("Visitors reach this app at …") and lists each address as a row. The old `edge-domains` section redirects here.

## Default hostname

Every Edge site gets a dply URL (e.g. `{slug}.on-dply.live`) once the first deploy succeeds. Its row reads "… always works"; until the first deploy it says the address appears then. It is the primary URL unless a custom domain is made primary.

## Use your own domain

**Use your own domain** opens a dialog: enter the hostname, see where its DNS is hosted today (from an NS lookup, cached 10 minutes), and pick a way:

| Way | What happens |
|-----|--------------|
| **Let dply run DNS** | Only when `DPLY_EDGE_DNS_ENABLED=true`. Creates a zone for the registrable domain in dply's Cloudflare account (`edge_dns_zones`), then attaches the hostname. |
| **Point it at dply myself** | Attaches the hostname. The domain's dialog shows the CNAME target and TXT records to add, and **Check DNS now**. If the org's Cloudflare credential holds the zone as active, the record is created automatically (`mode: auto`). |

Each domain row opens a dialog with its records, errors, **Make primary**, **Check DNS now**, and **Remove**.

## dply-run DNS

Off by default. To turn it on (and for branded nameservers), follow [EDGE_DNS_GO_LIVE.md](EDGE_DNS_GO_LIVE.md).

- **Zone.** `EdgeDnsZones::add()` creates a full-setup zone, stores its `name_servers` and `original_name_servers`, and starts Cloudflare's record scan. One zone per domain across all of dply; another org can't claim it.
- **Branded nameservers.** Set `DPLY_EDGE_DNS_NS_SET` to a Cloudflare *account custom nameserver* set (e.g. `ns1/ns2.dply.io`) and new zones are switched to it (`PATCH /zones/{id}/dns_settings`, `custom.account`). That feature needs Cloudflare Business (via support) or Enterprise, a set created in the account, and glue records for the names. Without it customers see Cloudflare's assigned pair.
- **Existing records.** The zone dialog lists scanned records to keep (MX/TXT for email) and accepts or rejects them via `/dns_records/scan/review`, plus a small editor for A, AAAA, CNAME, MX and TXT.
- **Activation.** `CheckEdgeDnsZonesJob` (every 5 min) polls pending zones. When one turns active it provisions every non-ready hostname under it (`mode: managed`, proxied CNAME to the CNAME target, then the same Custom Hostname step as other modes). **Check now** does the same on demand and nudges Cloudflare's activation check.
- **Ownership.** Only an active zone proves ownership; nothing is provisioned from a pending one, and pending zones are deleted after `DPLY_EDGE_DNS_PENDING_DAYS` (14). Previews never write records.
- **Removal.** **Stop using dply DNS** refuses while an app still has a hostname under the zone, then deletes the Cloudflare zone.

## Status

| Row says | Meaning |
|----------|---------|
| … is live over HTTPS | DNS ready, certificate active |
| … is getting its certificate | DNS ready, certificate issuing |
| … goes live once example.com's nameservers point at dply | Zone pending |
| … is waiting for its DNS records | Pending DNS |
| … couldn't be verified yet | Failed; re-checked with backoff for 72 hours |

## SSL

| Delivery | How TLS works |
|----------|---------------|
| **Managed (`dply_edge`)** | Custom Hostnames (SSL for SaaS) on the dply Edge zone. Some hostnames also need a one-time ownership TXT record, shown in the domain's dialog until TLS is active. |
| **BYO Cloudflare** | Terminate TLS on your zone with an orange-cloud (proxied) record. |

`VerifyEdgeCustomDomainsJob` re-checks pending DNS and certificates every 15 minutes.

## Preview sites

Previews get their own hostname and never serve or repoint custom domains.
