---
title: "Snippets & tags"
description: "Inject HTML snippets and third-party scripts such as analytics into your app's pages at the edge, with optional consent gating."
---

Snippets and tags add code to your HTML pages as they're served, without changing your repository or redeploying. **Snippets** insert HTML you write, such as meta tags, structured data or a banner. **Tags** load third-party tools such as Google Analytics or a chat widget from an ID or script URL, and can wait for visitor consent.

> [!NOTE]
> Snippets and tags need dply-hosted delivery, which is the default. They're added to static HTML served from your deploy. They aren't added to responses from SSR apps, container apps, or a hybrid app's origin. Add those in your app's templates instead.

## Snippets

### Add a snippet

1. Open your app and choose **Snippets**. The page shows your page's `<head>` and the end of its `<body>`, with each snippet in the slot where it lands and the pages it's on.
2. Choose **Add to head** or **Add to end of body**. A new snippet offers starter examples you can fill it from.
3. Fill in the snippet:
   - **Name**: a label for you.
   - **Add it**: **In the head** (before `</head>`) or **At the end of the body** (before `</body>`).
   - **On pages matching**: which pages get it. `/*` means every page.
   - **HTML**: the markup to insert, up to 8,000 characters.
4. Choose **Save**.

The snippet appears on the next page load, with no redeploy. Saving your first snippet turns snippets on. To edit or remove a snippet, click it. The **Snippets on** checkbox at the bottom turns every snippet off or back on, and takes effect right away. Snippets are inserted in the order listed, and a page without the matching closing tag is left unchanged.

| Example | Added | Adds |
|---------|-------|------|
| **Basic SEO meta** | head | A description and Open Graph tags |
| **Noindex** | head | `<meta name="robots" content="noindex, nofollow">` |
| **Announcement banner** | body | A simple notice |
| **JSON-LD Organization** | head | Organization structured data |

> [!WARNING]
> Snippet HTML runs on your pages exactly as written, including any `<script>`. Only add code you trust. Anyone who can edit the app can change snippets.

## Tags

### Add a tag

1. Open your app and choose **Tags**.
2. Choose **Add a tool**, then pick a vendor, or **Custom script** for any script URL.
3. Paste the vendor's ID, or for a custom script enter a **Script URL (https)**.
4. Optionally set **Fire on path** (for example `/checkout/*`) and **Consent purpose**.
5. Choose **Save**.

The page lists each tool with the pages it fires on and its consent purpose. Click one to edit or remove it. Adding a vendor tool turns tags on. **Load tags on this site** turns every tool off or back on, and takes effect right away.

| Tool | ID format | Default purpose |
|------|-----------|-----------------|
| Google Analytics | `G-XXXXXXXXXX` | Analytics |
| Google Tag Manager | `GTM-XXXXXXX` | Analytics |
| Meta Pixel | `123456789012345` | Marketing |
| Microsoft Clarity | `abcd12345` | Analytics |
| Hotjar | `1234567` | Analytics |
| Plausible | your domain, such as `example.com` | Analytics |
| Custom script | an `https://` URL, up to 500 characters | Analytics |

dply adds each vendor's standard loader and setup code before `</head>`. Custom scripts load `async` unless you turn off **Load async** for that tool. Up to 20 tools are used.

### Consent

Turn on **Wait for consent before loading analytics and marketing tools** to hold back every tool whose purpose isn't **Necessary** until the visitor agrees. Your consent banner then calls:

```js
// Load every held tool
window.__dplyTags.grant();

// Or only some purposes
window.__dplyTags.grant(['analytics']);

// Withdraw consent (stops loading on later pages)
window.__dplyTags.revoke();
```

The choice is remembered in `localStorage` under `dply_tag_consent`, so returning visitors aren't asked again. `window.__dplyTags.consent` and `window.__dplyTags.purposes` tell you the current state. Turning consent on also turns tags on. dply doesn't provide the consent banner itself.

### Custom events

`window.__dplyTags.track(name, props)` sends one event to every loaded tool that supports events (Google Analytics, Tag Manager, Meta Pixel, Plausible, Clarity and Hotjar):

```js
window.__dplyTags.track('signup', { plan: 'pro' });
```

## Path patterns

| Pattern | Matches |
|---------|---------|
| `/*` | every page |
| `/blog/*` | `/blog` and every page under it |
| `/pricing` | that exact path |

Paths are matched after directory URLs resolve to their index file: the home page `/` is `/index.html`, and `/docs/` is `/docs/index.html`. Use `/docs/*` for a section.

> [!NOTE]
> On apps with SPA fallback on, every route without its own file is served from `/index.html`, and snippets and tags see it as `/index.html`. On those apps, only `/*` targets pages reliably.

## In `dply.yaml`

```yaml
snippets:
  enabled: true
  items:
    - name: Meta
      phase: head          # head or body
      path: /*
      html: '<meta name="description" content="…">'

tags:
  enabled: true
  consent_required: true
  tools:
    - vendor: ga4          # ga4, gtm, meta, clarity, hotjar, plausible, or omit for custom
      id: G-XXXXXXXXXX
      purpose: analytics   # necessary, analytics or marketing
      path: /*
    - name: Chat
      src: https://widget.example.com/chat.js
```

Up to 50 snippets and 20 tools are read from the file. Once you save snippets or tags in the dashboard, the dashboard settings replace that section of the repository config. The **Advanced** section of each page shows what the repository declares.

## Related

- [Error pages](/docs/error-pages)
- [Bot protection](/docs/bot-protection)
- [Edge middleware](/docs/edge-middleware): for logic beyond injecting HTML
- [Caching](/docs/caching)
