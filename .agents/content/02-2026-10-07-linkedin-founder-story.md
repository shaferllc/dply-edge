# Wed Oct 7 · LinkedIn

Status: ready to post · Calendar: [content-calendar.md](../content-calendar.md)

The first version of dply was a control panel for servers you own: provision a box, SSH in, deploy from Git, run the database, cron and backups on it. It worked. But every feature I added was another thing you'd still have to look after at 3am.

So this summer I deleted it. All of it: the SSH layer, the provisioning, the server screens. I started again on one question: what if there's no server at all?

That's dply now. You connect a Git repo and it runs the whole app: a Laravel, Rails or Node server in containers, the database, Valkey and queue workers beside it, and your static or server-rendered frontend in the same project. One dashboard, one bill.

It isn't a VPS. There's no server to SSH into, and an app that's been idle for a while takes a moment to wake up. In exchange there's nothing to patch, and quiet apps cost less, not more.

We're not launched yet. If you run a Laravel or Rails app and want to try it early, the waitlist is at edge.dply.io/coming-soon, and I'd love to hear what would stop you from moving.

#Laravel #RubyOnRails #WebDevelopment
