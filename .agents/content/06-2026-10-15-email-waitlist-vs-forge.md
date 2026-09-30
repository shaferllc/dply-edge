# Thu Oct 15 · Email to the waitlist

Status: ready to post · Calendar: [content-calendar.md](../content-calendar.md)

**Subject:** What moving off Forge looks like
**Preheader:** Every piece of your server, mapped, plus what changes.

Hi [first name],

A lot of you joined the waitlist from a Forge server, so here's what the move looks like in practice.

Your site becomes a container app that dply detects from the repo. Your queue:work daemons become queue workers that scale with the queue. The scheduler cron becomes a toggle. The database on the box becomes a managed Postgres or MySQL database, and Redis becomes Valkey. You bring your .env across, keep your APP_KEY, and import a database dump.

Three things change, and I'd rather you hear them from me: there's no SSH, there's no persistent disk (uploads go to object storage), and an idle app sleeps after five minutes unless you keep one instance warm.

The full side-by-side, including when Forge is still the better choice, is here:
https://edge.dply.io/vs/forge

Reply and tell me what your app runs on today. I read every answer.

Tom
Founder, dply
