---
title: "Edge forms"
slug: edge-forms
category: "Edge"
order: 112
description: "Accept HTML form POSTs on an Edge path and email the fields — no app server required. Optional honeypot and bot check."
group: edge
---

# Edge forms

**Forms** turns a path on your Edge site into a mail-backed endpoint. Visitors POST; Dply emails you the fields. No app server or serverless function is required.

Requires **Dply-hosted Edge delivery** and a working **outbound mail** config for the organization.

## How it works

1. You add a form and save its endpoint (path + inbox + spam defenses).
2. Save republishes the Edge host map — the Worker starts accepting `POST` on that path.
3. A visitor submits a form (or JSON) to `https://{your-edge-host}{path}`.
4. The Worker:
   - Drops the request quietly if the **honeypot** field is filled (bots).
   - Optionally verifies a **Turnstile** token when **Require bot check** is on.
   - HMAC-signs the remaining fields and POSTs them to dply’s form ingest URL.
5. dply verifies the signature and sends mail via the org’s outbound mailer.

Successful HTML submissions get a simple “Thanks” page. JSON clients get `{"ok":true}`.

## Endpoint fields

| Field | Purpose |
|-------|---------|
| **Path** | URL path that accepts `POST` (e.g. `/contact`, `/api/support`) |
| **Email to** | Inbox that receives the submission |
| **Honeypot field** | Hidden input name; bots that fill it are discarded |
| **Require bot check** | When on, submissions need a valid bot-protection token |

## Dashboard starters

**+ Add a form** offers starter endpoints, or **Blank form**:

| Starter | Path | Notes |
|---------|------|--------|
| Contact | `/contact` | Honeypot `company` + bot check |
| Newsletter | `/newsletter` | Honeypot `website` + bot check |
| Support | `/api/support` | Good pair with Rate limits |
| Simple | `/feedback` | Honeypot only (no Turnstile) |

The page lists endpoints as sentences ("POSTs to /contact go to you@example.com", tagged **Bot check** or **Honeypot only**). Click one to edit it; the modal's collapsible **HTML for this form** is built from that endpoint's path, honeypot and bot check plus the live hostname, with a Copy button. **Accept form submissions on this site** saves on click. Click a recent submission to see every field and when it arrived.

## HTML example

```html
<form method="POST" action="https://your-site.on-dply.site/contact">
  <label>Name <input type="text" name="name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Message <textarea name="message" required></textarea></label>
  <!-- Honeypot: leave empty; hide from humans -->
  <input type="text" name="company" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
  <!-- If Require bot check is on: -->
  <div class="cf-turnstile" data-sitekey="YOUR_TURNSTILE_SITE_KEY"></div>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <button type="submit">Send</button>
</form>
```

Match the honeypot `name` to the dashboard field. Turnstile site key comes from **Bot protection** ([Cloudflare Turnstile](https://dash.cloudflare.com/?to=/:account/turnstile)).

## JSON example

```bash
curl -X POST "https://your-site.on-dply.site/contact" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Ada","email":"ada@example.com","message":"Hello","company":""}'
```

Include `cf-turnstile-response` or `turnstile_token` when bot check is required.

## `dply.yaml`

```yaml
forms:
  enabled: true
  endpoints:
    - path: /contact
      to_email: you@example.com
      honeypot: company
      require_turnstile: true
```

Saving forms in the dashboard overrides the repo for the whole `forms` section. Ingest URL / HMAC key are injected by the platform at publish time (not in the file).

## How to use it

1. Open **Forms**, choose **+ Add a form**, and pick a starter or **Blank form** (adding one turns forms on).
2. Set **Email to**, match honeypot / bot check to your HTML.
3. Optional: configure **Bot protection**, then enable **Require bot check**.
4. Optional: add a **Rate limit** on the same path.
5. **Save** in the modal, then test a real POST from the live hostname.

## Tips

- Use one endpoint per form. Remove unused endpoints so they stop accepting mail.
- Paths apply after Save republishes delivery — no full rebuild required.
- Spam still happens; honeypot + bot check is defense in depth, not a guarantee.
- Put the form markup in your app, or inject it with **Snippets** while you prototype.

## Related sections

- **Bot protection** — Turnstile site + secret keys
- **Rate limits** — cap abuse on form paths (e.g. `/contact`)
- **Snippets** — inject sample HTML while testing
