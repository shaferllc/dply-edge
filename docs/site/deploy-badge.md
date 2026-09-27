---
title: "Deploy badge"
description: "Add a Deploy to dply button to a README so visitors can create an app from your repository with the form already filled in."
---

The **Deploy to dply** badge is a button for template authors and demo repositories. A visitor clicks it, signs in to dply, and lands on the new-app form with your repository and settings already filled in. They review the form and deploy.

## Add the badge

Paste this into your `README.md` and replace `OWNER/REPO` with your repository:

```markdown
[![Deploy to dply](https://edge.dply.io/images/deploy-to-dply.svg)](https://edge.dply.io/deploy?repo=OWNER/REPO)
```

`repo` accepts either `owner/name` or a full clone URL, such as `https://github.com/owner/repo`.

For an HTML page:

```html
<a href="https://edge.dply.io/deploy?repo=OWNER/REPO">
  <img src="https://edge.dply.io/images/deploy-to-dply.svg" alt="Deploy to dply">
</a>
```

## Pre-fill more of the form

The `/deploy` link passes these query parameters to the new-app form. Anything else is dropped.

| Parameter | Fills in | Notes |
|-----------|----------|-------|
| `repo` | Repository | `owner/name` or a clone URL |
| `branch` | Branch | |
| `name` | App name | When omitted, dply suggests a name from the repository |
| `runtime_mode` | Runtime | `static`, `hybrid`, or `ssr`; other values are ignored |
| `build_command` | Build command | |
| `output_dir` | Output directory | |

For example:

```text
https://edge.dply.io/deploy?repo=acme/astro-starter&branch=main&runtime_mode=static&build_command=npm%20run%20build&output_dir=dist
```

URL-encode values that contain spaces or other special characters. When you leave out `runtime_mode`, dply inspects the repository and recommends one, as it does for any new app.

## What visitors see

- **Signed in**: the new-app form opens directly, pre-filled.
- **Signed out**: dply asks them to sign in, then returns them to the pre-filled form.
- **No account yet**: they create an account and start the [free trial](/docs/free-trial) first. Afterward they may need to click the badge again to reach the pre-filled form.

The visitor reviews every field before anything is created. The badge never deploys on its own.

## The badge image

The badge is a static SVG at `https://edge.dply.io/images/deploy-to-dply.svg`. You can link it directly or copy it into your own repository or CDN. The same image works for every link, so you don't need a new badge when you add parameters.

## Related

- [Quickstart](/docs/quickstart)
- [Apps](/docs/apps)
- [Frameworks & runtimes](/docs/frameworks)
- [Source control](/docs/source-control)
