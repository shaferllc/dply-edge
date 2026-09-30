# Mon Oct 5 · X + Bluesky thread

Status: ready to post · Calendar: [content-calendar.md](../content-calendar.md)

1/ Most Laravel and Rails apps are spread over four places: a frontend host, a server, a database provider and a Redis box. Four dashboards, four bills, and glue in between.

I'm building dply to put the whole app in one place. 🧵

2/ Push a repo. dply works out it's Laravel, Rails or Node, builds it, and runs it in containers that scale out on traffic and sleep when it's quiet.

3/ Postgres, MySQL or MongoDB, Valkey, object storage and queue workers sit right next to the app. The credentials show up as environment variables. Nothing to copy between providers.

4/ Queue workers autoscale, the Laravel scheduler runs every minute, and migrations can run when a container starts. The day-two chores are handled.

5/ Honest trade-offs: no SSH, no persistent disk, and an idle app takes a moment to wake. They're all on the features page.

We're pre-launch. Waitlist → edge.dply.io/coming-soon
