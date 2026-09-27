---
title: "Domain verification"
description: "The DNS records dply asks for when you attach a custom domain, how verification works, and how to fix a domain that won't verify."
---

When you attach a custom domain, dply checks that the hostname points at your app, and, where needed, that you own it. After that, it issues a TLS certificate. This page explains each record, shows examples, and covers the common reasons a domain stays in **Pending DNS** or **Failed**.

## What dply checks

A custom domain goes through three stages:

1. **Routing.** The hostname has a CNAME record pointing at the **CNAME target** shown on the domain row. An apex domain instead answers with the same addresses as the CNAME target, through ALIAS, ANAME or CNAME flattening.
2. **Ownership.** If the domain row shows a **Verification TXT record**, that record must also exist.
3. **Certificate.** Once DNS is **Ready**, dply requests a certificate. The badge moves from **Issuing certificate** to **TLS active**.

Copy each value exactly as the domain row shows it. Every row has its own values. The examples below use placeholders.

## Records you may be asked for

### CNAME record

Points your hostname at dply. It's required for every subdomain. For an apex, see [Apex domains](#apex-domains).

| Type | Name | Value |
|------|------|-------|
| `CNAME` | `www` | the **CNAME target** from the domain row |

### Verification TXT record

Proves that you, and not another dply customer, control the hostname. It appears under **Verification TXT record** while the domain isn't **Ready** yet. It's always required for an apex domain.

| Type | Name | Value |
|------|------|-------|
| `TXT` | `_dply-verify.www` | `dply-verify=…` (copy from the domain row) |

The full record name is `_dply-verify.<your hostname>`, for example `_dply-verify.www.example.com`. Many DNS providers append your zone automatically, so you enter only `_dply-verify.www`.

The token is created once per hostname and doesn't change if you verify again. You can delete the record after the domain is **Ready**.

### Ownership TXT record

Some hostnames also need a record from the certificate provider before the certificate can be issued. When one is needed, the row shows it as **Ownership TXT record**, usually named `_cf-custom-hostname.<your hostname>`. It disappears from the row once TLS is active.

## Example: `www.example.com`

For `www.example.com` in a zone `example.com`, the records usually look like this:

```text
www.example.com.                    CNAME  <CNAME target from the row>
_dply-verify.www.example.com.       TXT    "dply-verify=<token from the row>"
```

Then choose **Verify DNS**. When it succeeds, you see "DNS verified — www.example.com is live on Edge."

## Apex domains

For `example.com` itself, create one of these at the root (`@`), pointing at the **CNAME target** from the row:

- an `ALIAS` or `ANAME` record, or
- a `CNAME` record, if your provider flattens CNAMEs at the root (Cloudflare does this automatically).

Keep it DNS-only, and add the verification TXT record:

```text
example.com.                 ALIAS  <CNAME target from the row>
_dply-verify.example.com.    TXT    "dply-verify=<token from the row>"
```

A flattened apex answers with IP addresses, not a CNAME. dply accepts it when those addresses match the CNAME target's addresses and the TXT record holds your token. The addresses are shared by every app behind the target, so the TXT record is what proves ownership.

If `example.com` is an active zone in your organization's Cloudflare account, dply creates the root record for you and you don't need the TXT record. See [Domains](/docs/domains#apex-domains).

## If your DNS is on Cloudflare

- If your organization has a Cloudflare credential that can see the zone, and the zone is **active**, dply creates the CNAME for you. There's nothing to add by hand. See [Domains](/docs/domains).
- If you add the CNAME yourself, set it to **DNS only** (gray cloud). A proxied record answers with Cloudflare IP addresses instead of the CNAME, so **Verify DNS** can't see it.
- TXT records are never proxied. Add them as normal TXT records.

## Check your records from a terminal

Replace `www.example.com` with your hostname.

```bash
# Who serves DNS for the domain?
dig NS example.com +short

# Does the hostname CNAME to the target shown in dply?
dig CNAME www.example.com +short

# Apex: do its addresses match the target's?
dig A example.com +short
dig A <CNAME target from the row> +short

# Is the verification token visible?
dig TXT _dply-verify.www.example.com +short
```

No output means the record is missing, or hasn't propagated yet. Propagation usually takes a few minutes, but can take up to your record's TTL (and longer for a freshly delegated domain).

## Troubleshooting

### "No DNS records found for …"

dply didn't find a CNAME, A or AAAA record for the hostname. Check the record name. A common mistake is entering `www.example.com` in a field that already appends `example.com`, which creates `www.example.com.example.com`. Wait for propagation, then choose **Verify DNS**.

### "Hostname resolves to …, expected …"

The hostname points somewhere else, such as an old host or a parking page. Update the CNAME value to the **CNAME target** on the row. Remove any A or AAAA records for the same name, because they conflict with the CNAME.

### "Add a TXT record … to prove you own this hostname"

The CNAME is correct but the verification TXT record is missing or has a different value. Add `_dply-verify.<hostname>` with the exact value from the row, then verify again.

### "… is already attached to another site" or "… is already live on another site"

A hostname can belong to only one app across all dply organizations. Remove it from the other app first. If you own the domain but can't access the app that has it, contact [support](/docs/support).

### The domain shows Failed after I fixed DNS

dply keeps re-checking a **Failed** domain for 72 hours after you attach it: every 15 minutes for the first hour, hourly for the rest of the first day, then every 6 hours. It turns **Ready** on its own once your records resolve. After 72 hours, or to skip the wait, choose **Verify DNS**.

### Apex domain won't verify

- Check that the root record is an `ALIAS`, `ANAME` or flattened `CNAME` to the **CNAME target**, and that it's DNS-only. A proxied record answers with your own Cloudflare zone's addresses, which don't match.
- Check the verification TXT record at `_dply-verify.example.com`. An apex always needs it.
- Remove any other A or AAAA records at the root, such as an old host's, so every address the root returns belongs to the target.

### TLS failed

The domain row shows the reason from the certificate provider. The most common causes are a CAA record that doesn't allow Cloudflare's certificate authorities, and a CNAME removed while issuance was in progress. Fix the cause. dply retries certificates every 15 minutes.

## Related

- [Domains](/docs/domains)
- [Edge network](/docs/edge-network)
- [Notification channels](/docs/notifications): get alerted when a domain verifies or starts failing
