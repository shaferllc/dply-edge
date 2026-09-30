# Fri Oct 9 · X + Bluesky

Status: ready to post · Calendar: [content-calendar.md](../content-calendar.md)

What "zero config" means on dply. You run:

$ git push origin main

and dply:
→ spots Laravel from composer.json (or Rails from the Gemfile, Node from package.json)
→ picks the PHP or Ruby version and build commands
→ builds an image, no Dockerfile needed
→ starts it next to its database and queue workers
→ gives the branch its own preview URL

No dply.yaml, no Dockerfile, no pipeline to write first. Detection covers Laravel, Symfony, Rails, Node and most static frameworks, and every setting is yours to override.

edge.dply.io/features
