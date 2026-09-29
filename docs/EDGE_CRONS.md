---
title: "Edge crons"
slug: edge-crons
category: "Edge"
order: 116
description: "UTC cron schedules for Edge apps: commands in container apps, scheduled() in Worker apps. Dashboard rows merge with dply.yaml on deploy."
group: edge
---

# Edge crons

**Scheduled tasks** (crons) run work on a UTC schedule. They live on **Overview**: add one from **Add resource** → **Scheduled task** (as many as you need), and they show as a **Scheduled tasks** box under the app on the map. The old Crons page is gone; `…/crons` redirects to Overview. Every schedule becomes a Cloudflare Cron Trigger on the app's Worker, so it works for every app that ships one:

- **Container apps** (Laravel, Rails, Node): each schedule runs a command in the live container.
- **SSR and middleware apps**: each schedule calls the Worker's `scheduled()` export.

Schedules apply on the **next deploy**. The map box lists the first three schedules and how many slots are used. Selecting it opens the **Scheduled tasks** sheet, which lists each schedule in plain English ("Every day at 06:30 UTC, run reports:daily") with its raw expression and source.

## The 5-schedule limit

**Limits depend on the runtime.** Container apps register one `* * * * *` Cron Trigger and the Worker's `scheduled()` runs each task whose schedule is due (`cronDue(…, strict)`), so they get up to `EdgeCronExpression::MAX_TASKS` (50) tasks in the grammar `EdgeCronExpression::supported()` checks (no `L`/`W`/`#`/`?`). SSR / middleware Workers still get one Cron Trigger per distinct schedule because their own `scheduled()` branches on `controller.cron`, so Cloudflare's 5 apply (`EdgeEffectiveCrons::MAX_WORKER_SCHEDULES`). Either way, extras and unreadable expressions are dropped at deploy and marked **Won't run**.

On a Laravel container app, the scheduler (`schedule:run` every minute) takes one slot, unless it runs inside a queue worker. Turn it on from **Add resource** → **Scheduled task** → **Run Laravel’s scheduler**, and off from the **Scheduler** / **Queue workers** box.

## Dashboard vs repo

| Source | Editable here? | Notes |
|--------|----------------|-------|
| **Dashboard** | Yes | Stored on the site; merged at deploy time |
| **Repo (`dply.yaml`)** | Read-only, marked **Repo** | Commit + redeploy to change |

Dashboard entries add to the repo's. Prefer the repo for anything you want reproducible in git.

## Adding a schedule

**Add resource** → **Scheduled task**, **Add** in the sheet, or a row's **Edit** opens the edit sheet. On a Laravel container app with the scheduler off, a new task first offers **Run Laravel’s scheduler**, which is usually all a Laravel app needs. The fields are:

- **When (UTC)**: a preset (every minute, every 5 minutes, hourly, daily at 06:00, Monday at 06:00, first of the month) or a custom 5-field expression, read back in plain English as you type.
- **Command** (container apps only), labelled **Artisan command**, **Rake task** or **Command** for Node.
- **Remove** deletes the schedule.

## Container apps

The Cron Trigger POSTs the command to `/_dply/schedule` in the live container:

- **Laravel** (`dply/laravel`): runs it as an artisan command, e.g. `reports:send --daily`.
- **Rails** (`dply-rails`): runs it as a rake task, e.g. `reports:daily`.
- **Node**: your app must handle `POST /_dply/schedule` itself.

### Node handler

The request body is JSON `{cron, handler}`. Check that the `x-dply-queue-token` header equals `DPLY_QUEUE_TOKEN` (in the app's environment), run the task, and answer JSON `{output}`:

```js
app.post("/_dply/schedule", express.json(), async (req, res) => {
  if (req.get("x-dply-queue-token") !== process.env.DPLY_QUEUE_TOKEN) {
    return res.status(403).json({ error: "Forbidden" });
  }
  const { handler, cron } = req.body; // e.g. "reports:daily", "0 6 * * *"
  // run the task for `handler` here
  res.json({ output: `ran ${handler}` });
});
```

### Run now

Every container app (Laravel, Rails, Node) gets **Run now** on each listed command, and on `schedule:run` when the Laravel scheduler is on. It runs the command in the live app and shows the output. Only commands already on the list can run, and it needs edit rights. Rails tasks print no output. A Node app without the route gets a message saying to handle it.

## SSR and middleware apps

Only the schedule is pushed; there is no command field. The Worker's `scheduled(controller, env, ctx)` runs on every schedule, and `controller.cron` tells them apart.

```ts
// src/middleware.ts
export default {
  async fetch(request, env) {
    // Pass through to static assets / hybrid origin
    return new Response(null, {
      status: 204,
      headers: { "X-Dply-Middleware": "continue" },
    });
  },

  async scheduled(controller, env, ctx) {
    if (controller.cron === "0 6 * * *") {
      // daily work, e.g. await env.JOBS.send({ type: "cleanup" });
    }
  },
};
```

Return `204` + `X-Dply-Middleware: continue` on `fetch` so normal page traffic keeps working. Without a `scheduled` export, a schedule does nothing.

### Schedules in `dply.yaml`

```yaml
crons:
  - schedule: "*/5 * * * *"
  - schedule: "0 3 * * *"
    handler: "reports:send --daily"   # container apps only
```

## Tips

- Expressions are **UTC**, not the browser's timezone.
- Pair with **Jobs** / **Bindings** when a cron should enqueue queue work.

## Related sections

- **Jobs** — the Projects queues this app runs or sends to, its Laravel workers, and queue bindings for Worker code
- **Bindings** — KV, R2, D1, queues available to cron handlers
- **Deploy triggers** — git/webhook deploys (not cron)
