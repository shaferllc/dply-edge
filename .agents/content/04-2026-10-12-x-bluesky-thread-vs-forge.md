# Mon Oct 12 · X + Bluesky thread

Status: ready to post · Calendar: [content-calendar.md](../content-calendar.md)

1/ Moving a Laravel app off Forge? Here's how each piece maps to dply. 🧵

2/ Server + site → a container app, detected from the repo
Deploy script → a generated image (or your own Dockerfile)
php artisan migrate in the script → migrations when a container starts

3/ queue:work / Horizon daemons → queue workers that autoscale on queue depth
Scheduler cron → the Laravel scheduler, every minute
MySQL / Postgres on the box → a managed database
Redis on the box → Valkey

4/ What to plan for: no SSH, no persistent disk (uploads go to object storage), and cold starts after 5 idle minutes unless you keep one instance warm.

5/ Full comparison, including when Forge is still the better choice → edge.dply.io/vs/forge
