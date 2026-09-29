---
title: "Edge bot protection"
slug: edge-bot-protection
category: "Edge"
order: 111
description: "Challenge automated traffic on forms or every HTML page using a privacy-friendly widget. Requires Dply-hosted Edge delivery."
group: edge
---

# Edge bot protection

**Bot protection** adds a challenge widget on your Edge site so automated scripts can’t submit forms (or browse pages) as easily as real people.

This feature requires **Dply-hosted Edge delivery**. Sites on BYO delivery cannot store challenge keys in the control plane.

## What it does

After keys are saved and the feature is enabled, Edge republishes delivery config. Matching responses include the challenge widget; form submissions can be verified against the secret on Dply-hosted Edge.

| Mode | Behavior |
|------|----------|
| **Form posts only** (recommended) | Challenge on form / POST surfaces |
| **Every page** | Challenge on every HTML document response |

## How to set it up

### Generate keys (recommended)

Tick **Bot protection is on** (or open **Keys** → **Generate keys** / **Regenerate**). Dply creates a challenge widget for this site’s Edge hostnames, saves the site + secret keys, enables protection, and republishes delivery. The page opens with a summary sentence, rows for where to check and keys (each opens a dialog), and an **Also uses it** list of forms requiring a bot check and rate-limit rules that challenge.

If keys already exist, confirm before replacing them.

### Paste keys (optional)

1. Create a widget in your challenge provider console (for Dply’s managed path: [Cloudflare Turnstile](https://dash.cloudflare.com/?to=/:account/turnstile)).
2. Copy the **Site Key** (public) and **Secret Key**.
3. Open **Keys**, paste both keys and **Save**, then tick **Bot protection is on**.

Wait for delivery republish (usually under a minute), then verify a form page in a private window.

Official Turnstile guide: [Turnstile get started](https://developers.cloudflare.com/turnstile/get-started/).

## Platform notes (operators)

Managed key generation calls Cloudflare `POST /accounts/{account_id}/challenges/widgets` with the platform token (`DPLY_EDGE_CF_ACCOUNT_ID` + `DPLY_EDGE_CF_API_TOKEN`). The token needs **Account → Turnstile → Edit**. Local/dev with `DPLY_FAKE_EDGE=true` generates fake keys without calling Cloudflare.

## Tips

- Start with **Form posts only** unless you need a site-wide challenge.
- Pair with **Forms → Require bot check** so Edge form endpoints reject submissions without a valid challenge token.
- Never commit the secret key to your frontend repo — it stays on Dply-hosted Edge only.
- The site key is safe to embed in HTML (it identifies the widget publicly).

## Related sections

- **Forms** — mail-backed POST endpoints that can require the bot check
- **Rate limits** — use **Challenge** action when bot protection is configured
- **Delivery** — must be Dply-hosted (managed) for this feature
