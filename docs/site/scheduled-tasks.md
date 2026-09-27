---
title: "Scheduled tasks"
description: "Run code on a cron schedule: a scheduled() handler for static, hybrid and Worker SSR apps, and the Laravel scheduler or artisan and rake commands for container apps."
---

Scheduled tasks run your code on a cron schedule without a request coming in. How you write them depends on the app's delivery mode: apps that run on the edge network call a `scheduled()` handler in your Worker code, and container apps run the Laravel scheduler or a command you name.

## Schedules and where they come from

Schedules come from two places, merged on each deploy:

- The **Crons** section in the sidebar (under **Extend**). Rows you add there are stored on the app.
- A `crons` list in `dply.yaml`, committed with your code. These appear in the **Crons** section as read-only.

```yaml
crons:
  - schedule: "*/5 * * * *"
  - schedule: "0 3 * * *"
    handler: "reports:send --daily"
```

A schedule is a standard 5-field cron expression in **UTC**. Changes take effect on the next deploy. The **Crons** section appears once the app has a Worker to schedule: a container app, a Worker SSR app, or a static or hybrid app with [edge middleware](/docs/edge-middleware).

> [!IMPORTANT]
> An app can have at most 5 distinct schedules. For container apps the Laravel scheduler uses one of them (`* * * * *`). Schedules past the fifth are dropped at deploy without an error, so keep the list short.

[Preview deployments](/docs/preview-deployments) never run scheduled tasks; only production does.

## Static and hybrid apps

A static or hybrid app runs schedules through its edge middleware. Export a `scheduled` handler from `src/middleware.ts` (or `middleware.ts` at the repository root):

```ts
// src/middleware.ts
export default {
  async fetch(request, env) {
    // Let the request continue to your static files or origin.
    return new Response(null, {
      status: 204,
      headers: { "X-Dply-Middleware": "continue" },
    });
  },

  async scheduled(controller, env, ctx) {
    // controller.cron is the expression that fired
    console.log("cron", controller.cron);
  },
};
```

Every schedule calls the same `scheduled` export; the **Handler** field is not used for these apps. Your environment variables are available on `env`. Without middleware there is nothing to schedule.

## Worker SSR apps

Each schedule calls the `scheduled` export of your app's Worker entry. Next.js (OpenNext), Astro, SvelteKit and Remix builds do not export one by default, so a schedule does nothing until your Worker entry exports `scheduled` alongside `fetch`.

## Container apps

### The Laravel scheduler

To run `schedule:run` every minute, open **Overview**, select **Add resource**, then **Scheduler**, and redeploy. You can also turn on **Run the Laravel scheduler every minute** in **Sleep, region, scheduler** or in the **Container** section. dply adds the `dply/laravel` package to the image if your app does not require it (add it yourself if you ship your own `Dockerfile`).

Where the scheduler runs depends on whether the app has [queue workers](/docs/queue-workers):

| App has | The scheduler runs | Effect on sleep |
|---|---|---|
| At least one always-on queue worker | `php artisan schedule:work` inside `worker-0` | The web instances can sleep. Pausing the workers pauses the scheduler. |
| No always-on worker | A trigger every minute calls `schedule:run` in the app | The app is woken only when one of your tasks is due. |

After each run, the app reports its tasks' cron expressions and time zones, and dply wakes the app only in minutes when one of them is due. An app with only a nightly task sleeps the rest of the day. dply wakes the app every minute when it cannot tell: right after a deploy, when the report is more than a day old, or when the app has sub-minute tasks.

Your tasks use the time zones set in your schedule (`->timezone()` or `schedule_timezone`), not UTC.

Select the **Scheduler** card on **Overview** (or the **Queue workers** card when the app has workers) to see which mode is active. **Run now** runs `schedule:run` once in the live app and shows its output. **Remove** turns the scheduler off on the next deploy.

### Commands on a schedule

For anything other than the Laravel scheduler, add a schedule in the **Crons** section (or `dply.yaml`) with a **Handler**:

- Laravel: an artisan command, for example `reports:send --daily`. A blank handler runs `schedule:run`.
- Rails: a rake task, for example `reports:daily`.

Each run wakes the app if it is asleep. With **Run queued jobs and scheduled tasks on their own instance** on in the **Container** section, scheduled runs go to a separate jobs instance instead of a web instance.

## When a run does not happen

- Scheduled tasks stop while the organization is paused. See [Paused accounts](/docs/paused-accounts).
- A schedule added in the dashboard does nothing until the next deploy.
- A static or hybrid app needs middleware that exports `scheduled`.
- Check that the schedule is within the first five.

## Related

- [Queue workers](/docs/queue-workers)
- [Edge middleware](/docs/edge-middleware)
- [Configuration files](/docs/configuration-files)
- [Scaling & sleep](/docs/scaling-and-sleep)
