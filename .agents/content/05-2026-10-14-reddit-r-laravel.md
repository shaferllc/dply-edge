# Wed Oct 14 · r/laravel

Status: ready to post · Calendar: [content-calendar.md](../content-calendar.md)

Check the subreddit's self-promotion rules first, and post from your personal account.

**Title:** I'm building a Forge alternative where the app, database and queue workers run without a server. Looking for honest feedback.

Hi r/laravel. I've been building dply, a git-push host for Laravel (plus Rails and Node). It runs the app in containers that scale out and sleep when idle, with managed Postgres/MySQL, Valkey and autoscaling queue workers alongside. The scheduler runs every minute, and migrations can run on container start.

Things it deliberately doesn't do, so you can rule it out quickly:

- No SSH into the running app
- No persistent disk: files written at runtime are gone on restart, so uploads need object storage
- Cold starts: an idle app sleeps after 5 minutes by default (you can keep one instance warm)

I wrote up how Forge concepts map across, and when Forge is still the better fit: edge.dply.io/vs/forge

What would stop you from moving a production app to something like this? Horizon, file uploads, cost predictability, something else? I'd genuinely like to know.
