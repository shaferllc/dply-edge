---
title: "Access control"
description: "Put your app and its previews behind a password or dply sign-in, and control who can see them."
---

Access control stops visitors before your app loads. Everyone must enter a shared password or sign in with a dply account to see your app. Use it for staging apps, client reviews, and unreleased work. The gate runs at the edge, so nothing from your app, not even static files, is served to someone who hasn't passed it.

## Protection modes

| Mode | Who gets in |
|------|-------------|
| **Off** | Everyone. The default. |
| **Password** | Anyone who enters the shared password. |
| **Dply account** | People who sign in to dply and can view this app, optionally limited to an email list. |

> [!IMPORTANT]
> Protection covers your production hostname and custom domains, as well as preview and per-deploy URLs. Turning it on puts your live app behind the gate too.

## Turn on protection

1. Open your app and choose **Previews**.
2. Under **Protection**, pick a **Preview protection mode**.
3. For **Password**, enter the password. When changing other settings later, leave **Password** blank to keep the current one.
4. For **Dply account**, optionally list **Allowed emails**. Leave the list empty to allow any signed-in user who can view the app.
5. Choose **Save protection**.

The gate reaches every hostname within about a minute. Protection is set on the main app and applies to all its previews. It can't be configured on a preview by itself.

## What visitors see

A visitor without access gets a **Site protected** page (HTTP 401) on the URL they requested:

- **Password**: a password field. A correct password sets a cookie and sends them to `/`.
- **Dply account**: a link to sign in to dply. After signing in, dply checks that they can view the app (and that their email is on the list, if you set one), then returns them to the app.

Access lasts 24 hours per hostname. After that, visitors pass the gate again. The cookie is `HttpOnly`, `Secure` and `SameSite=Lax`, and it's only valid for the hostname and app it was issued for.

## Who counts as able to view the app

With **Dply account**, a user must be able to view the app in dply: a member of its organization, or someone added in the app's **Members**. See [App members](/docs/app-members) and [Roles & permissions](/docs/roles-and-permissions).

**Allowed emails** narrows this further. Only listed addresses get in, even if other people can view the app in dply.

## Manage protection from the API

The [HTTP API](/docs/api) exposes the current protection settings at `GET /api/v1/edge/sites/{site}/access` and updates them with `PATCH` on the same path.

## Protecting a hybrid origin

Access control protects your dply hostnames. To make sure only dply can reach the origin of a [hybrid app](/docs/static-and-hybrid), use the origin settings on **Delivery**: dply sends a shared secret with each origin request, and can present a Cloudflare Access service token to an origin behind Cloudflare Access.

## Limitations

- Protection applies to the whole hostname. You can't protect only some paths.
- There's no visitor sign-in through Google, GitHub or SAML. **Dply account** requires a dply login.
- The gate page can't be customized.

## Related

- [Preview deployments](/docs/preview-deployments)
- [App members](/docs/app-members)
- [Firewall](/docs/firewall)
- [Platform security & isolation](/docs/platform-security)
