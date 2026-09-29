---
title: "Turning on dply-run DNS"
slug: edge-dns-go-live
category: "Edge"
description: "Owner checklist for switching on 'Let dply run DNS' and branded ns1/ns2.dply.io nameservers, in the order to do it."
group: edge
---

# Turning on dply-run DNS

The code for **Let dply run DNS** shipped on 2026-09-28 and is **off** (`DPLY_EDGE_DNS_ENABLED=false`). Until it's on, customers only see **Point it at dply myself** (CNAME + TXT). This is the list of what to do, in order, when it's worth paying for. How the feature works: [Edge domains](EDGE_DOMAINS.md#dply-run-dns).

There are two stages. Stage 1 costs nothing extra and already lets customers change nameservers instead of copying records. Stage 2 is only about the nameservers carrying the dply name.

## Stage 1 — turn it on with Cloudflare's nameservers (no plan upgrade)

Customers are shown the pair Cloudflare assigns to each zone (e.g. `ada.ns.cloudflare.com`). Works on the current account.

1. **Widen the platform API token** (`DPLY_EDGE_CF_API_TOKEN`), account-scoped, adding:
   - Zone → Zone: Edit (create/delete zones; must cover *all zones in the account*, not just the worker zone)
   - Zone → DNS: Edit
   - Zone → Zone Settings: Edit
   Check it: `curl -H "Authorization: Bearer $TOKEN" https://api.cloudflare.com/client/v4/zones?per_page=1` succeeds.
2. **Prove same-account serving on one real domain you own** (the one unverified piece). A managed zone lives in dply's own account, and provisioning adds a proxied CNAME to the site's CNAME target plus a Custom Hostname on the worker zone. Cloudflare only documents that pairing across *different* accounts.
   - Set `DPLY_EDGE_DNS_ENABLED=true` on staging (or locally without `DPLY_FAKE_EDGE`).
   - Routing → Domains → Use your own domain → your test domain → Let dply run DNS.
   - Keep the scanned records, switch the nameservers at the registrar, wait for "dply runs DNS for …".
   - Open `https://www.<test-domain>` and confirm it serves the app over HTTPS.
   - **If it fails:** change only `EdgeCustomDomainProvisioner::provisionManaged` to route the zone straight to the edge worker (Worker route or Workers custom domain on that zone) instead of CNAME + Custom Hostname.
3. **Check the record scan** on that test domain: the dialog lists the domain's MX/TXT records, and after **Keep the checked records** they appear under **Records** (confirms the `/dns_records/scan/review` body shape).
4. **Turn it on in production:** `DPLY_EDGE_DNS_ENABLED=true`, `php artisan config:cache`, `php artisan queue:restart`. The scheduler already runs `CheckEdgeDnsZonesJob` every 5 minutes.
5. **Tick it off** in [EDGE_PLATFORM_STATUS.md](EDGE_PLATFORM_STATUS.md) (move "dply-run DNS" from "Not verified" to "Verified").

## Stage 2 — branded nameservers `ns1.dply.io` / `ns2.dply.io`

Uses Cloudflare **account custom nameservers**, available on Business (after contacting Cloudflare support) or Enterprise. Check current pricing and whether Business-via-support is enough for your account before committing.

1. **Upgrade / ask support** to enable account custom nameservers on the dply Cloudflare account.
2. **Create the set:** Cloudflare dashboard → account → DNS Settings → Configure custom nameservers → add `ns1.dply.io` and `ns2.dply.io` as set **1** (or via the API's create-account-custom-nameserver endpoint).
3. **Glue records:** if `dply.io`'s DNS is on Cloudflare in the same account, they're created automatically. Otherwise add A/AAAA records for `ns1`/`ns2.dply.io` (the IPs Cloudflare gives for the set) at dply.io's DNS host, and glue at dply.io's registrar.
4. **Point dply at the set:** `DPLY_EDGE_DNS_NS_SET=1`, then `config:cache` + `queue:restart`. New domains are switched to the set when created and customers see `ns1/ns2.dply.io`.
5. **Existing domains** created before this keep Cloudflare's pair. Either leave them, or switch each with `PATCH /zones/{id}/dns_settings` `{"nameservers":{"type":"custom.account","ns_set":1}}` and ask those customers to update their registrar.
6. **Test** with a fresh domain: the dialog shows `ns1.dply.io` / `ns2.dply.io`, and the zone activates after the switch.

## Later improvements (not needed to launch)

- **One-click setup at registrars (Domain Connect).** GoDaddy, IONOS and others let customers approve DNS changes in one click, but each provider must approve a dply template first (weeks). The DNS host is already detected (`ManagesEdgeDnsZones::dnsProviderFor`), so the button would go next to "Point it at dply myself".
- **Multi-part suffixes.** Zone names use the last two labels, so `example.co.uk` becomes `co.uk` and Cloudflare rejects it. Swap `EdgeDnsZones::zoneNameFor` to a public-suffix list.
- **Fuller record editor.** Today: add/delete A, AAAA, CNAME, MX, TXT. Missing: edit in place, SRV/CAA, proxied toggle, TTL.
- **Org-wide domains page.** Domains dply runs are shared by the whole organization but only shown from an app's Routing page.
- **Cleanup when a customer leaves.** Org deletion cascades the `edge_dns_zones` rows but doesn't delete the Cloudflare zones; add that to `DeleteOrganizationAction` / the billing purge.
