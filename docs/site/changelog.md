---
title: "Changelog"
description: "Notable changes to dply, newest first."
---

What changed in dply, newest first. Each entry links to the page that covers the feature in full.

## 2026-09-29

### Outbound traffic and log events are metered

Traffic a container app sends out on its own, like calls to outside APIs or S3, now bills as **App outbound traffic**. Replies to your visitors still bill once, as bandwidth. Log events (one per request, plus each line your app logs) now bill under **Workers**. Outbound counting starts with each app's next deploy. See [Plans & pricing](/docs/pricing).

### Vector search: a live demo and a sheet that explains itself

Creating a **Vector search** index now opens its sheet right away. The **Vector search** box on **Overview** opens it too. The sheet is one page: the address or binding with **Copy**, the index's size and vector count, code for your app type, usage against your limit, and **Sleep**, **Detach** and **Delete**. **Try it** adds six sample documents with real embeddings, then searches them with your question; on a container app the search runs through your live app. Sleeping an index now says plainly that stored vectors keep billing. See [Vector search](/docs/resources/vector-search#try-it).

### Images opens ready to use, and runs a live demo

Adding **Images** now opens its sheet right away. The sheet is one page: the address with **Copy**, the two calls, a call builder that writes curl, Laravel or Rails code, and the options at a glance. **Run it on the app** sends a sample picture through your live app and shows what came back. The **Images** box on **Overview** opens the sheet, and **Images** and **AI** leave **Add resource** once the app has them. See [Images](/docs/resources/images#try-it-on-your-app).

### Min and max instances side by side

The **App** sheet now shows **Max instances** with **Min instances (always awake)** right under it, instead of keeping the minimum under **Sleep, scaling, region**. See [Scaling and sleep](/docs/scaling-and-sleep).

### More than one database per app

An app can now have several Postgres, MySQL and MongoDB databases. Add one from **Add resource** → **Database**, choosing the engine and a name, or attach one your organization already has. The first is the app's primary (`DB_*`, `DATABASE_URL`); others use their name as a prefix, like `ANALYTICS_DATABASE_URL`, and **Make primary** swaps them. Each database has **Detach**, which keeps it in your organization, and **Delete**, which asks you to type its name. The **None** choice is gone. **Projects** → **Databases** lists them all, including ones no app uses, so you can attach them elsewhere or delete them. See [Databases](/docs/resources/databases).

### Container deploys are checked before they take traffic

A container deploy now starts the new version on its own first, runs your migrations there (`php artisan migrate --force` or `rails db:migrate`), and requests it. Only a version that answers moves into production. A failed migration or a server error stops the deploy with the error your app logged, and visitors keep the previous version. See [Deployments](/docs/deployments).

### Suggested database resizes, with your approval

dply now watches each database's memory and cache hit rate and suggests a bigger or smaller size when it would help, with the reason and the price change. Nothing resizes on its own: choose **Resize now**, **Resize tonight** (03:00 in your organization's time zone) or **Dismiss** on the database's sheet. New notifications cover a suggested resize, a finished or failed resize, a database near its memory limit, and a container app that could run on a smaller size. See [Databases](/docs/resources/databases#suggested-resizes).

### Adding queue workers asks what they need

**Add resource** → **Queue workers** now opens a short sheet instead of adding workers with defaults. It prefills the queue names found in your code, lets you choose **Start when jobs arrive** or **Always on** with the cost of each, and sets the number of processes. An app on SQLite gets dply Valkey for the queue in the same step, and **Add and deploy** starts them. Starter plans can now run their one worker as start-when-jobs-arrive. See [Queue workers](/docs/queue-workers#add-queue-workers).

### More than 5 scheduled tasks

Container apps can now have up to 50 scheduled tasks instead of 5. They share one trigger that checks every minute and wakes the app only when a task is due, and the Laravel scheduler no longer uses up a slot. Month and weekday names such as `MON` and `JAN` work. Worker SSR and middleware apps keep Cloudflare's limit of 5 schedules. See [Scheduled tasks](/docs/scheduled-tasks#how-many-you-can-have).

### Deploy progress follows you

A running deploy now shows in a bar at the bottom of every page instead of a card on **Overview** and **Deploys**. It shows the step the deploy is on, including the container rollout percentage, for every deploy in your organization you can see. Open it for the last few log lines, **Full log**, **Open app** and **Cancel**. When it finishes you get a notification if you started it or are on that app's pages. See [Deployments](/docs/deployments#watch-a-deploy).

## 2026-09-28

### App pages that read like a sentence

An app's **Billing & usage**, **Audit log**, **Alerts**, **Tags**, **Forms** and **Error pages** now open with one sentence that says where things stand, for example what the app has used against your plan's credit, or which events reach someone. Each item is a row you click to edit in a dialog, and the page-wide Save buttons are gone. **Snippets** shows where each snippet lands in your page's head or body. Breadcrumbs now sit above the whole workspace. See [Activity log](/docs/activity-log), [Alerts](/docs/alerts), [Forms](/docs/forms) and [Snippets](/docs/snippets).

### Members shows everyone's access

An app's **Members** page now lists everyone in the organization and what they can do on that app, including why ("Org owner", "Deployer on this app"). Click a member or deployer to give them an app role, change it, or return them to their organization role. See [App members](/docs/app-members).

### Traffic in a sentence

**Traffic & analytics** now opens with one sentence about the last seven days: requests, bandwidth, average response time, and today's failures. Rows open dialogs for the daily charts, today's response codes and top paths, response time, page speed in browsers, and live requests. See [Traffic & analytics](/docs/traffic).

### Cache in a sentence

**Cache** now says what the edge keeps and for how long, and how many copies are stored. Click a setting to change it in a dialog; stored copies, purging, and clearing each open from their own row. The page also says when the edge cache hasn't been turned on yet. See [Caching](/docs/caching).

### Let dply run your DNS

**Routing** → **Domains** now starts with **Use your own domain**, which tells you where the domain's DNS is hosted today and offers two ways to connect it. **Let dply run DNS** asks you to change two nameservers once. dply keeps your existing records, and every hostname under the domain goes live with nothing to copy. You can still point a domain yourself with a CNAME. Each address is a row that opens its records and actions in a dialog. See [Domains](/docs/domains#let-dply-run-dns).

### Routing rules in plain words

The **Redirects**, **Rewrites** and **Headers** tabs now sum up their rules in a sentence and list each rule as a row, such as "/old-page moves permanently to /new-page". Click a rule to edit it in place. Bulk import and templates open from their own rows. See [Routing](/docs/routing).

### Logs in a sentence

**Build & deploy logs** now says how your last deploy went, and whether the one before it failed. Each deploy opens its build log in a dialog with find, jump to error and download. Container apps also see what the app printed in the last 15 minutes, with errors counted up front. See [Logs](/docs/logs).

### Deploy triggers in a sentence

**Deploy triggers** now says what starts a deploy, such as pushing to your production branch, pull request previews and deploy hooks, and warns when pushes don't deploy. GitHub, each hook and the manual webhook open in dialogs. A new hook's URL appears once in its dialog with a `curl` command to try it. See [Deploy triggers & hooks](/docs/deploy-triggers).

### Container settings moved to Overview

The separate **Container** page is gone. Its settings were already on the **App** card on **Overview**. The ones that weren't, **Always awake** (min instances), **Scaling windows**, **Worker mode** and **Keep the jobs instance awake**, are now in the card's **Sleep, scaling, region** sheet. The app's output moved to **Build & deploy logs**, and old links to the Container page open **Overview**. See [Scaling & sleep](/docs/scaling-and-sleep).

### Previews in a sentence

**Previews** now says which changes get a preview, how many are live, which one is taking production traffic, and who can open them. Click a preview for its URL, a replay check, one-click traffic shares, **Promote to production** and **Tear down**. Previewing a commit, the auto-deploy rules, protection and review notes each open their own dialog. See [Preview deployments](/docs/preview-deployments).

### Environment in a sentence

**Environment** now says how many variables your app gets on its next deploy and where they come from: the ones you set, org secrets, resources and `dply.yaml`. It names any secret `dply.yaml` declares without a value. Each source is a row. Your own variables open in a `.env` editor with **Save** and **Save and redeploy**, and a missing secret opens it with a line ready to fill in. See [Environment variables](/docs/environment-variables).

### Build in a sentence

**Build** now says what happens on a push, which command runs, what gets published and how many releases are kept. Each setting is a row that opens its own dialog, and settings `dply.yaml` overrides are marked with the file name. The **Advanced** panel is gone. See [Builds](/docs/builds).

### Deploys in a sentence

**Deploys** now says what's live and for how long, whether a later deploy failed, and how many earlier builds you can roll back to. The history reads as rows. Click one for its URL, failure reason and build log, plus **Roll back to this** or **Rebuild from this commit**. Deploying a specific commit, branch or tag opens its own dialog. See [Deployments](/docs/deployments).

### Fixes

- **Open full guide** and other docs buttons were dark text on a dark button in dark mode. They're readable again.
- Sidebar groups such as **Traffic** and **Protect** could stay closed after you moved between sections. They now open every time.
- **Live requests** on **Traffic & analytics** could stay empty with console errors when the tab loaded. It now connects every time.
- The country search on **Firewall** listed its results under the dialog's edge, so some were hidden. The list now opens inside the dialog.

### Security at a glance

An app's **Security** page now sums up its protection in a sentence: HTTPS, which protections are on and off, and how many requests were stopped in the last 7 days. Each row opens the page that controls it, and the stopped requests open in a dialog.

### Firewall rule in one sentence

**Firewall** now says who can reach your site, such as "Only visitors from Canada and United States can reach this site." Click the rule to change it; a country rule now needs at least one country. See [Firewall](/docs/firewall).

### Bot protection shows what relies on it

**Bot protection** now says where visitors are checked and lists the forms and rate limits that rely on it. Ticking **Bot protection is on** generates keys for the app when it has none. See [Bot protection](/docs/bot-protection).

### Rate limits as sentences

**Rate limits** now reads each rule as a sentence ("On /api/*, one visitor gets 60 requests a minute, then block"). Add a rule from a Login, API, Forms or Whole site starter, and the dialog tells you what the limit means for one visitor. See [Rate limits](/docs/rate-limits).

### Waiting room in plain words

The **Waiting room** page now sums itself up in one sentence and lists each setting as a row you click to change. While you set the capacity or the rate, it estimates how long the last person in a rush would wait. See [Waiting room](/docs/waiting-room).

### Error pages go live on save

A new 404, 500 or maintenance page is served on the next request after you save, with no redeploy. The editor shows a live preview next to the HTML. See [Error pages](/docs/error-pages).

### Jobs shows who runs each queue

An app's **Jobs** tab now lists the queues it's attached to and says whether this app runs each one, only sends to it, or whether nothing runs it yet. Each queue has one consumer: the first production app attached to it. The tab also sums up the app's Laravel queue workers. See [Queue workers](/docs/queue-workers).

### Crons for every app, and Run now

Schedules read in plain English and show how many of the 5 per-app schedules are used; any past the fifth are marked as not running. Container apps can **Run now** to test a command in the live app, for Laravel, Rails and Node. Node apps handle `POST /_dply/schedule` themselves. See [Scheduled tasks](/docs/scheduled-tasks).

## 2026-09-27

### Three plans, unlimited sites, pay for what runs

dply now has three plans: Starter ($5/month), Pro ($20/month) and Team ($49/month), each with usage included up to its price. Every plan has unlimited sites, and there are no per-site fees. Apps, workers, databases and Valkey are billed by the second while they're awake; requests, bandwidth and storage by the unit. Invoices show your usage, then the included usage as a credit. Each new organization starts with a 5-day trial of the plan it chooses. View-only members don't use a seat. See [Plans & pricing](/docs/pricing) and [Usage & metering](/docs/usage).

### Private repositories, previews and deploys

Private repositories now build using your connected account. Preview deployments inherit your app's environment variables and secrets, but never its database or Redis connection settings. Deploy on push works as a real switch, and deleting an app cleans up its previews, domains and webhook. See [Source control](/docs/source-control) and [Preview deployments](/docs/preview-deployments).

### Security

Hardened tenant isolation and build sandboxing. Two-factor authentication now applies to every sign-in method.

## 2026-09-26

### One page for your app and its resources

A project's **Overview** now shows the app and every resource it can use in one place, and the separate **Resources** page is gone. Settings open in side sheets that save as you go and prompt you to redeploy when a change needs one. A project's Overview is now the project's own URL; old links redirect. See [Resources overview](/docs/resources).

### Realtime: WebSockets for Laravel Echo and Pusher clients

Add **Realtime** to an app for a Pusher-protocol WebSocket service that works with Laravel Echo, Reverb clients and Pusher SDKs. Each app gets its own host, and the connection settings are added to your app's environment for you. See [Realtime (WebSockets)](/docs/resources/realtime) and [Laravel broadcasting with Realtime](/docs/guides/laravel-broadcasting).

### No free plan: a 5-day trial

New organizations start with a 5-day trial, with a card added at checkout. The trial has a $5 spending cap. An organization whose trial ends without payment is paused, and its data is kept for 30 days. Organizations that were on the free plan received a fresh 5-day trial. See [Free trial](/docs/free-trial) and [Paused accounts](/docs/paused-accounts).

### Usage billed in arrears, once per period

Usage past your plan now appears as a few lines on each renewal invoice, covering exactly the billing period that has ended. Previously, usage changes could produce small prorated invoices during the month. Canceling sends one final usage invoice. Usage alerts email owners at 50%, 80% and 100% of an amount you set. See [Invoices & taxes](/docs/invoices) and [Spending caps & alerts](/docs/spending-alerts).

### New meters

Workers CPU time, Durable Objects, object storage buckets and image transformations are now metered. See [Usage & metering](/docs/usage) for how each is measured and [Plans & pricing](/docs/pricing) for the rates.

### Payment retries

If a renewal payment fails, your organization keeps running while Stripe retries the card. It is paused only if the subscription ends unpaid. A failed first payment after a trial still pauses the organization.

### Faster sites and deploys

- Hashed framework assets (Vite, Next.js, Astro, SvelteKit) are served as immutable, and unchanged files answer with `304 Not Modified`.
- Server-rendered and container HTML streams to the browser.
- Deploys upload files in parallel and copy unchanged files from the live deploy instead of uploading them again. A failed upload now fails the deploy instead of publishing an incomplete site.
- The dashboard's **Traffic**, **Cache** and **Billing** tabs load faster.

See [Deployments](/docs/deployments) and [Caching](/docs/caching).

### Security

Hardened tenant isolation and build sandboxing.

See [Platform security & isolation](/docs/platform-security).

### Databases

The database panel gains **Queries** (running and top queries, with cancel), **Health** (disk, connections, backups, missing and unused indexes) and a read-only **Console**. You can export, download and import backups, and create a read-only login. Opening the panel doesn't wake a sleeping database. See [Postgres, MySQL & MongoDB](/docs/resources/databases).

### Cheaper idle apps

- Queue workers can scale to zero: a worker starts when jobs arrive and stops after 5 quiet minutes.
- The scheduler wakes your app only when a task is due, so an app with one nightly task can sleep the rest of the day.
- Apps suggest a smaller instance size when a week of memory use shows it would fit, with the saving and a one-click switch.
- Valkey shows evicted and expired keys and its slowest recent commands.

See [Scaling & sleep](/docs/scaling-and-sleep) and [Queue workers](/docs/queue-workers).

### Logs by source

Container logs can be filtered by **App**, **Queue workers** or **Routing**, and by worker. See [Logs](/docs/logs).

### Placement visibility

Each queue worker reports where Cloudflare placed it and its round trip to your database and Valkey, and the workers card flags one that is far from its data. See [Data regions](/docs/data-regions).

## 2026-09-25

### Queue worker plan limits

Queue workers per app are now set by plan: Pro allows 5 instances with autoscaling and 2 worker groups; Team allows 10 instances and 4 groups. See [Queue workers](/docs/queue-workers).

### Scheduler as a resource

Add **Scheduler** from **Add resource**, with **Run now** to run it once and see the output. When the app has queue workers, the scheduler runs inside a worker instead of waking the web container every minute. See [Scheduled tasks](/docs/scheduled-tasks).

### Placement

Container apps that land far from their database are restarted once to be placed again, and stop retrying when Cloudflare picks the same location. Placement is best effort. See [Data regions](/docs/data-regions).
