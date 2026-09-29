---
title: "Forms"
description: "Accept form POSTs on a path of your app at the edge, with honeypot and Turnstile spam checks, and no backend of your own."
---

Forms turns a path on your app, such as `/contact`, into a form endpoint handled at the edge. Visitors submit an HTML form or a JSON request to that path. dply screens out bots with a honeypot field and, optionally, a [Turnstile](/docs/bot-protection) check, then emails the fields to the endpoint's inbox and lists them on the **Forms** page. It's intended for static sites that need a contact or signup form without running a server.

> [!NOTE]
> Forms need dply-hosted delivery, which is the default.

## Add a form endpoint

1. Open your app and choose **Forms**.
2. Choose **Add a form**, then pick a starter below or **Blank form**.
3. Fill in the endpoint:
   - **Path**: the path that accepts `POST`, such as `/contact`.
   - **Email to**: the inbox for submissions.
   - **Honeypot field**: the name of a hidden input that real visitors leave empty.
   - **Require bot check**: require a valid Turnstile token.
4. Choose **Save**.

The endpoint is live within about a minute, with no redeploy. Adding a form turns form submissions on. The page lists each endpoint as a sentence, such as "POSTs to /contact go to you@example.com". Click one to edit or remove it. Its **HTML for this form** section shows form markup built from that endpoint's path and honeypot and your app's live hostname, ready to copy into your site. **Accept form submissions on this site** turns every endpoint off or back on, and takes effect right away.

| Starter | Path | Honeypot | Bot check |
|---------|------|----------|-----------|
| **Contact** | `/contact` | `company` | On |
| **Newsletter** | `/newsletter` | `website` | On |
| **Support** | `/api/support` | `fax` | On |
| **Simple (no bot check)** | `/feedback` | `company` | Off |

## Where submissions go

After a submission passes the honeypot and bot checks, the edge forwards its fields to dply over a signed request. dply then:

- stores the submission and shows the 20 most recent under **Recent submissions** on the app's **Forms** page, where clicking one shows every field and when it arrived
- emails the fields to the endpoint's **Email to** address, with the subject `[dply Edge] Form: <app> (<path>)`

The honeypot field and the Turnstile token are removed before the fields are forwarded. The inbox always comes from your saved endpoint settings, never from the request. Each field value is capped at 10,000 characters, a submission at 50 fields, and the forwarded body at 64 KB.

The visitor gets a success response once dply has stored the submission. Email is sent in the background, so a slow or failing mail server doesn't fail the form. Submissions are deleted when the app is deleted.

## HTML form

Post to the endpoint's path on your app's hostname. Match the honeypot input's `name` to **Honeypot field**, and hide it from people:

```html
<form method="POST" action="/contact">
  <label>Name <input type="text" name="name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Message <textarea name="message" required></textarea></label>

  <!-- Honeypot: must stay empty -->
  <input type="text" name="company" tabindex="-1" autocomplete="off"
         style="position:absolute;left:-9999px" aria-hidden="true">

  <!-- Only when Require bot check is on -->
  <div class="cf-turnstile" data-sitekey="YOUR_SITE_KEY"></div>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

  <button type="submit">Send</button>
</form>
```

If [Bot protection](/docs/bot-protection) is on, dply adds the Turnstile widget to forms in your static HTML for you, so you can leave out the last two elements.

## JSON requests

```bash
curl -X POST "https://www.example.com/contact" \
  -H "Content-Type: application/json" \
  -d '{"name":"Ada","email":"ada@example.com","message":"Hello","company":"","cf-turnstile-response":"<token>"}'
```

Send the Turnstile token as `cf-turnstile-response` or `turnstile_token` when **Require bot check** is on.

## Responses

| Situation | Status | Body |
|-----------|--------|------|
| Accepted, request `Accept` includes `text/html` | 200 | A short "Thanks — we received your message." page |
| Accepted, other clients | 200 | `{"ok":true}` |
| Honeypot filled in | 200 | `{"ok":true}`. The bot isn't told it was caught. |
| Missing or invalid Turnstile token | 403 | `{"ok":false,"error":"Bot check failed"}` |
| Body can't be parsed | 400 | `{"ok":false,"error":"Invalid form body"}` |
| dply refused or couldn't be reached | 502 | `{"ok":false,"error":"Could not deliver form"}` |
| The app's edge config predates delivery. Save any form on **Forms**, or redeploy. | 503 | `{"ok":false,"error":"Form delivery is not configured"}` |

Only `POST` requests are handled. Other methods on the same path go to your app as normal. Endpoints accept `application/x-www-form-urlencoded`, `multipart/form-data` and `application/json`. File uploads are ignored.

> [!IMPORTANT]
> **Require bot check** needs [Bot protection](/docs/bot-protection) keys. With the check on and no keys configured, every submission is rejected with 403.

## Forms in `dply.yaml`

```yaml
forms:
  enabled: true
  endpoints:
    - path: /contact
      to_email: you@example.com
      honeypot: company
      require_turnstile: true
```

Up to 20 endpoints are read from the file. Once you save forms in the dashboard, the dashboard settings replace the repository's `forms` section.

## Tips

- Use one endpoint per form.
- Add a [rate limit](/docs/rate-limits) on busy form paths, such as 5 requests per 60 seconds on `/contact`.
- Honeypots and Turnstile reduce spam but don't eliminate it.

## Related

- [Bot protection](/docs/bot-protection)
- [Rate limits](/docs/rate-limits)
- [Snippets & tags](/docs/snippets)
