---
title: "Bot protection"
description: "Add a Cloudflare Turnstile challenge to your forms, verify tokens at the edge, and use it as the challenge for rate limits."
---

Bot protection uses [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/), a privacy-friendly challenge that usually passes real visitors without a puzzle. dply can create the Turnstile keys for your app, add the widget to your HTML forms, and check tokens at the edge for [Forms](/docs/forms) and [Rate limits](/docs/rate-limits).

> [!NOTE]
> Bot protection needs dply-hosted delivery, which is the default.

## Turn on bot protection

1. Open your app and choose **Bot protection**.
2. Tick **Bot protection is on**. If the app has no keys yet, dply creates a Turnstile widget for your app's hostnames, saves its keys, and turns protection on.
3. Click **Check visitors on form posts only** to choose where to check: **Form posts only** (recommended) or **Every page**. Choose **Save** in the dialog.

The page opens with a sentence such as "Visitors are checked on your forms. Your /contact form and /login rate limit rely on it." Under **Also uses it**, each form that requires a bot check and each rate-limit rule that challenges is listed, with a link to its page.

The widget appears on matching pages within about a minute.

### Use your own keys

To use keys from your own Cloudflare account, create a Turnstile widget in the Cloudflare dashboard, then open **Keys**, paste its **Site key (public)** and **Secret key**, choose **Save**, and tick **Bot protection is on**.

The site key is public and safe in HTML. The secret key is used only at the edge to verify tokens. Don't put it in your frontend code. Only members who can edit the app can see the secret key on this page.

### Regenerate keys

Open **Keys** and choose **Regenerate**. You're asked to confirm, then dply creates a new widget and replaces both keys.

> [!IMPORTANT]
> Generated keys are tied to the hostnames and domains your app had when you generated them. If you later attach a [custom domain](/docs/domains) on a domain the app didn't use before, open **Keys** and choose **Regenerate** so the widget accepts it.

## What each mode does

When the mode applies, dply adds the Turnstile script to your static HTML pages, and inserts the widget immediately before the first `</form>` on any page that doesn't already have one.

| Mode | When it applies |
|------|-----------------|
| **Form posts only** | Only while [Forms](/docs/forms) is enabled for the app. |
| **Every page** | Always, whether or not Forms is enabled. |

Neither mode blocks page views on its own. A token is only checked where something asks for it:

- **Forms** endpoints with **Require bot check** reject submissions without a valid token.
- **Rate limits** with **Challenge (bot check)** show a Turnstile page to visitors over the limit.
- Your own server code can verify the token with Turnstile's API.

> [!NOTE]
> The widget is added to static HTML served from your deploy. For SSR and container apps, and for hybrid origin responses, add the widget to your templates yourself, using the site key from this page:
>
> ```html
> <div class="cf-turnstile" data-sitekey="YOUR_SITE_KEY"></div>
> <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
> ```

## Checking tokens

When the widget completes, it adds a hidden `cf-turnstile-response` field to the form. dply's edge checks that token against Turnstile, along with the visitor's IP:

- **Forms** read `cf-turnstile-response` or `turnstile_token` from the form body or JSON.
- **Rate limit challenges** read the `cf-turnstile-response` header or query parameter.

## Related

- [Forms](/docs/forms)
- [Rate limits](/docs/rate-limits)
- [Firewall](/docs/firewall)
