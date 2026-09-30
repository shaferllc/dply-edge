# Wed Oct 21 · r/rails

Status: ready to post · Calendar: [content-calendar.md](../content-calendar.md)

Check the subreddit's self-promotion rules first, and post from your personal account.

**Title:** Heroku-style git push for Rails, but the app sleeps when idle and bills per second. Feedback wanted.

I'm building dply. You push a Rails repo and it's detected without a Procfile, runs in containers that scale out on traffic, and sleeps when nobody's using it. You pay for the seconds it's awake. Postgres or MySQL, Valkey and background workers are built-in resources, not add-ons, and every branch gets a preview URL.

The honest limits: no SSH into the running app, no persistent disk, and a cold start after 5 idle minutes unless you keep one instance warm. If your stack leans on Heroku marketplace add-ons or Pipelines, Heroku is probably still the better fit. The comparison says so.

edge.dply.io/vs/heroku

What would you need to see before moving a Rails app?
