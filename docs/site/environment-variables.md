---
title: "Environment variables"
description: "Set environment variables for your app, understand when they reach the build and the runtime, and see the variables dply adds for you."
---

Environment variables hold configuration that changes between environments and secrets that must stay out of your repository: API keys, database URLs, feature flags. You set them for each app on the **Environment** page, and dply passes them to your build and, depending on the app type, to your running code. Changes take effect on the next deploy.

## Set variables

1. In your app, open **Environment**.
2. Edit the **Environment** field. It holds one `KEY=value` per line, like a `.env` file.
3. Choose **Save and redeploy** in the bar at the bottom of the page.

```env
APP_ENV=production
STRIPE_SECRET="sk_live_..."
# Comments are allowed while you edit
export FEATURE_NEW_CHECKOUT=true
```

The field works like a whole file, not a list of separate entries:

- Saving replaces the full set. A line you delete removes that variable.
- Wrap values containing spaces, `#`, or quotes in double quotes. An `export ` prefix is accepted and removed.
- Comments are not stored. After saving, the field shows only your variables.
- Keys are converted to uppercase, must start with a letter, and may contain only `A–Z`, `0–9`, and `_`, up to 128 characters.
- The discard control in the bottom bar restores the last saved values.

Members who can update the app see the field with values in plain text. Other members see only the list of keys. Values are encrypted at rest.

> [!IMPORTANT]
> Saving does not change the running app. Variables apply on the next deploy. **Save and redeploy** does both. If you save without deploying, open **Deploys** and choose **Redeploy now**. Rolling back or deploying an earlier commit that was already built does not pick up new values, because it reuses that build as it was.

### Reserved names

These names are used by the platform and cannot be set: `HOST_MAP`, `ASSETS`, `ARTIFACTS`, `DEPLOYMENT_ID`, `SITE_ID`, `STORAGE_PREFIX`, `EDGE_ANALYTICS`, `LOG_INGEST_BASE_URL`, `LOG_INGEST_KEY`, `ENVIRONMENT`.

## Where variables are available

When a variable reaches your code depends on how the app runs:

| App type | Build | Runtime |
|----------|-------|---------|
| Static | Yes | No server code runs. Only [edge middleware](/docs/edge-middleware), if you have it, receives the variables. |
| Server-rendered (Worker SSR) | Yes | Yes, as secret bindings on the Worker, read from `env` in your handler. |
| Hybrid | Yes | Only [edge middleware](/docs/edge-middleware) receives them. Server-rendered routes run on your own origin, which dply sends nothing: configure its variables where it runs. |
| Container | Only `VITE_*` variables reach the image's asset build. | Yes, in the container's process environment. |

In a Worker, read variables from the `env` argument, not `process.env`:

```js
export default {
  async fetch(request, env) {
    const stripe = new Stripe(env.STRIPE_SECRET);
    // ...
  },
};
```

### Variables inlined at build time

Frameworks such as Vite, Next.js, and Astro copy some variables into the JavaScript they ship to browsers, for example `VITE_*`, `NEXT_PUBLIC_*`, and `PUBLIC_*`. For static, server-rendered, and hybrid apps, dply passes every variable to the build, so these work as they do locally. Container apps pass only `VITE_*` variables to the asset build.

> [!WARNING]
> Anything your framework inlines into client code is public: anyone can read it in the browser. Only give browser-exposed prefixes to values that are safe to publish, and keep secrets on unprefixed names that only server code reads.

## Variables dply adds for you

Some resources add variables to the next deploy. They appear under **From resources** on the **Environment** page, with secret values masked.

- **Realtime.** A [Realtime](/docs/resources/realtime) resource adds `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, and `REVERB_SCHEME`, the same set with a `PUSHER_` prefix, `PUSHER_APP_CLUSTER`, and `VITE_REVERB_*` and `VITE_PUSHER_*` for the browser. Laravel apps also get `BROADCAST_CONNECTION=reverb`. The `VITE_*` values reach the build; the rest reach server-rendered and container apps at runtime.
- **Redis.** A Redis connection adds `REDIS_URL`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, and `REDIS_PASSWORD`. dply manages these: they are hidden from the **Environment** field and cannot be overridden there. They are left out while the Redis resource is asleep. See [Valkey (Redis)](/docs/resources/valkey).
- **Databases (container apps).** Attaching a dply Postgres or MySQL database writes `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE`, and `DATABASE_URL` into your variables, where you can see and edit them. A container app with no database gets SQLite at `/tmp/database.sqlite` unless you set `DB_CONNECTION`, `DB_URL`, or `DATABASE_URL`. See [Postgres, MySQL & MongoDB](/docs/resources/databases).
- **Container app defaults.** Container apps get `APP_URL` and `ASSET_URL` set to the app's URL, and generated defaults when first deployed: `APP_KEY` for Laravel, `SECRET_KEY_BASE` for Rails, `NODE_ENV` for Node. Generated defaults are saved into your variables, so you can change them.

A line in the **Environment** field with the same key replaces a value from resources, and the list marks it **replaced above**. Two exceptions: the Redis keys above, and `DPLY_APP_URL` and `DPLY_MIGRATE_ON_BOOT` on container apps, which the platform always sets.

## Linked secrets

Organization secrets let you store a value once and link it onto several apps. Linked secrets appear in the **Linked secrets** panel on the **Environment** page and are added to the next deploy. If the **Environment** field also defines the same key, the value in the field wins. See [Secrets](/docs/secrets).

## Declare variables in `dply.yaml`

You can declare non-secret values and the names of required secrets in your repository:

```yaml
env:
  public:
    APP_ENV: "production"
  secret:
    - "DATABASE_URL"
    - "APP_KEY"
```

- `env.public` values are added to the build. A value set on the **Environment** page wins over the file.
- `env.secret` lists names only. The build log warns about any name with no value set, and the **Environment** page lists them under **From dply.yaml** marked **Missing** or **Set**. A missing secret does not fail the build.
- `build.env_files` loads `.env`-style files from the repository into the build. Values from the dashboard win.

`dply.yaml` values reach the build, and for container apps the container too. Server-rendered Workers do not receive them at runtime. See [Configuration files](/docs/configuration-files).

## Preview deployments

Preview deployments build and run with this app's environment variables and linked secrets, read at each preview build. Edit them here, on the production app: the **Environment** page of a preview shows **Environment variables are managed on the parent Edge site.** To give one preview a different value, set it on the preview through the API; the preview's value wins for that key.

> [!WARNING]
> Previews share production's values except database and Redis connection settings (`DATABASE_URL`, `DB_URL`, `DB_*`, `REDIS_URL`, `REDIS_*`, `MONGODB_URI`, `MONGO_URL`), which previews never inherit. Set them on the preview if it needs a database.

See [Preview deployments](/docs/preview-deployments).

## Manage variables from the CLI or API

The [CLI](/docs/cli) manages the same production variables:

```bash
dply edge env list                          # keys and last update, no values
dply edge env set STRIPE_SECRET=sk_live_... # set one or more
dply edge env rm OLD_KEY                    # remove one or more
dply edge env push --file .env.production   # replace the full set from a file
```

> [!WARNING]
> `dply edge env push` replaces the whole set: any key not in the file is deleted, including database variables dply wrote for you. `dply edge env pull` prints key names with empty values, so pushing its output back erases every value.

The [HTTP API](/docs/api) has the same operations under `/api/v1/edge/sites/{site}/env`. The API never returns values. Reading keys needs a token with `edge.env.read`, and changing them needs `edge.env.write`.

## Related

- [Secrets](/docs/secrets)
- [Builds](/docs/builds)
- [Deployments](/docs/deployments)
- [Configuration files](/docs/configuration-files)
