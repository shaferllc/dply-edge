---
title: "Troubleshooting errors & 5xx"
description: "Diagnose 5xx responses, paused pages, slow first requests and broken assets on a deployed dply app, from what the visitor sees to the fix."
---

A deployed app can fail in a few distinct ways: the platform refuses the request (a paused or gated app), the edge cannot reach what renders the page (an origin or Worker), or your own code errors. The response body usually tells you which. Match it below.

## Where to look

- **Traffic & analytics** shows requests by status code, so you can see when errors started and which paths return them. See [Traffic & analytics](/docs/traffic).
- **Build & deploy logs**, then **What your app is printing**, shows what a container app printed to stdout and stderr: stack traces, boot errors, framework logs.
- **Deploys** shows whether the error started with a particular deployment. Roll back from there if it did. See [Deployments](/docs/deployments).

## "This site is paused" (503)

The organization has no active plan: the trial ended unpaid, the subscription lapsed, or trial usage reached its $5 cap. Every site in the organization serves this page, container apps sleep, and queue workers stop.

How you resume depends on why the organization was paused; [Paused accounts](/docs/paused-accounts) has the steps for each case. Sites come back within the hour after payment, usually right away.

## "This app is paused. The workspace usage credit is used up." (503)

A container app on a trial reached the trial's $5 usage cap, so dply stops starting its container. Within the hour the whole organization is paused and its sites show "This site is paused" instead. The app resumes when the trial converts to a paid plan. See [Spending caps & alerts](/docs/spending-alerts).

## "We'll be right back." (503)

Maintenance mode is on for the site. Turn off **Maintenance mode** under **Error pages**, or remove `maintenance` from `dply.yaml` and redeploy. See [Error pages](/docs/error-pages).

## "Service temporarily unavailable — The site origin did not respond." (503)

A hybrid site proxied a request to its origin and the origin failed or timed out, twice. The response carries `X-Dply-Edge-Failover: 1`.

- Check the origin is up and answers at the **Origin URL** on **Delivery**. **Test origin** probes it.
- If the origin sits behind an access gateway or checks a shared secret, confirm **Origin access token** or **Origin auth secret** match.
- Replace the default page with your own under **Failover HTML**.

## "Service temporarily unavailable — SSR worker not reachable." (503)

The edge could not hand the request to the site's Worker SSR script. Redeploy; if it persists, contact [Support](/docs/support) with the site's link, since this is a platform-side fault.

## 500 or 502 from a container app

Your app answered with an error, or crashed. Open the container logs.

| What you see in the logs | Fix |
|---|---|
| A stack trace for a missing environment variable or config | Add it under **Environment** and redeploy. |
| `SQLSTATE` connection errors | Check a database is attached under **Add resource**, **Database**, and that you redeployed after attaching it. A sleeping database wakes on the next connection; the first query waits for it. |
| Missing table errors | Migrations have not run. Turn on **Run migrations when a container starts** in **Overview** → **App** card → **Sleep, scaling, region**, or run **Migrate** from the database's settings. |
| Out of memory, or the container restarting | Choose a larger size on the **App** card on **Overview**. |
| Laravel: "No application encryption key" | Restore `APP_KEY` under **Environment**; dply generates one only when none is set. |

After each container deploy dply requests the app's URL and marks the deploy failed if it answers with a 5xx. See [Troubleshooting builds](/docs/guides/troubleshooting-builds).

## The first request is slow

Container apps sleep after the **Sleep after idle** period (5 minutes by default). The next request starts a container and waits for it to boot, which is the cold start. It is longer when **Run migrations when a container starts** is on, because every start runs migrations first.

- Set **Always awake** to 1 in **Overview** → **App** card → **Sleep, scaling, region** to keep one instance warm. It bills compute all month.
- Use a longer **Sleep after idle** for fewer cold starts.
- Use **Scaling windows** to keep instances warm only during business hours.

See [Scaling & sleep](/docs/scaling-and-sleep).

## Assets load over HTTP, or styles are missing

- **Laravel: mixed content.** Asset links built for `http://` are blocked in the browser. dply sets `ASSET_URL` to the app's address on the first deploy; after adding a custom domain, update `APP_URL` and `ASSET_URL` under **Environment** and redeploy.
- **Rails: no styles.** `assets:precompile` failed during the build without stopping it. Look for its output in the build log.
- **Static sites: 404 on client-side routes.** Turn on **SPA fallback** under **Build**, **Advanced**.

## Users are logged out, or data disappears

- **Sessions.** Laravel apps default to `SESSION_DRIVER=cookie`, which works across instances. File sessions do not: each container has its own disk.
- **Uploaded files.** Files written inside the container are lost when it sleeps or is replaced. Store them in [Object storage](/docs/resources/object-storage).
- **SQLite.** An app without a database runs on SQLite inside the container. Attach a database for data you need to keep.

## WebSockets

| Symptom | Fix |
|---|---|
| Echo never connects | Redeploy after adding Realtime: the `VITE_*` keys are baked in at build time. Check **Allowed origins** on the Realtime resource includes your site. |
| Broadcasts are refused after a secret rotation | Redeploy so the app signs with the new secret. |
| Connections refused at a steady number | You reached **Max connections**. Raise it within your plan's cap. |
| "WebSocket upgrade failed at origin." (502) | A hybrid origin did not accept the WebSocket upgrade. Use a Realtime resource, or check the origin's WebSocket support. |

See [Laravel broadcasting with Realtime](/docs/guides/laravel-broadcasting).

## Related

- [Logs](/docs/logs)
- [Alerts](/docs/alerts)
- [Status pages & uptime](/docs/status-pages)
- [Troubleshooting builds](/docs/guides/troubleshooting-builds)
