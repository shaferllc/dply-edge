---
title: "Data regions"
description: "Where dply stores your files, databases and caches, where container apps run, and which settings pin them."
---

Most of what dply serves is global: your built files and routing are replicated across the edge network and answered from the location nearest each visitor. A few things live in one place, and for those, the distance between your app and its data sets how fast each query is. This page lists what is where and which settings control it.

## At a glance

| What | Where it lives | Can you choose? |
|---|---|---|
| Built files for static, hybrid and Worker SSR apps | Global object storage, cached at the edge | No |
| Routing, redirects and app configuration | Global, replicated to every edge location | No |
| Worker SSR and middleware code | Runs at the edge location nearest the visitor | No |
| Container app instances | A placement region | Yes: **Run only in** and **Regions** |
| dply databases (Postgres, MySQL, MongoDB) | New York | Not yet |
| dply Valkey | New York | Not yet |
| Object storage buckets | A location you pick when creating it, or your organization's default | Yes, when created |
| Edge SQL, key-value stores and queues | Placed by the edge network | Location hint only, for auto-created resources |

## Container app placement

Container apps run in one of the edge network's placement regions. Set placement in **Overview** → **App** card → **Sleep, region, scheduler**:

| **Run only in** | Regions allowed |
|---|---|
| **Anywhere (fastest)** | ENAM, WNAM, EEUR, WEUR, APAC, SAM, ME, OC, AFR |
| **EU only** | EEUR, WEUR |
| **US FedRAMP only** | ENAM, WNAM |

Tick **Regions** to narrow further; leave them all unticked to allow every region inside the choice above. With **Anywhere**, an app wakes in the region nearest the request that woke it, which gives the fastest cold start.

### Apps are placed next to their data

If your container app uses a dply database or dply Valkey and you have not chosen regions or a jurisdiction, dply runs it in the region paired with that data: ENAM (Eastern North America) today, next to New York. Every database query is a network round trip, and from the other side of the continent one takes about ten times as long (roughly 145 ms instead of 13 ms).

After each deploy dply measures the round trip from inside the app to its database. If it is over 40 ms, dply restarts that instance up to twice to be placed again. This is best effort: placement within a region is up to the network, and it can choose the same location again. The **App** card shows where the app is running and its round trip, with a warning when it is far. Redeploy to be placed again.

[Queue workers](/docs/queue-workers) show the same location and round trip for each worker under **Check workers**.

> [!WARNING]
> **EU only** controls where the app's containers run. It does not move dply databases or dply Valkey, which are in New York today. An EU-only app that uses them sends every query across the Atlantic and stores that data in the US. If your data must stay in the EU, keep it in an EU-jurisdiction object storage bucket or a database hosted in the EU, and connect to it with [External Redis](/docs/resources/external-redis) or environment variables.

## Your organization's data region

Organization settings have an **Edge data region**: **Default**, **EU** (strict EU jurisdiction), or a location hint (Western Europe, Eastern Europe, Western North America, Eastern North America, Asia-Pacific, Oceania).

It applies only to resources dply creates for you automatically from bindings declared in your repository's configuration. For those, dply passes the location as a hint, and **EU** also creates object storage buckets in the EU jurisdiction. It does not move existing resources, and it does not affect resources you create under **Add resource** (which ask for their own location), dply databases, dply Valkey or container placement.

## Moving data between regions

dply cannot move a database or Valkey store to another region. Existing data stays where it was created.

## Related

- [Container apps](/docs/containers)
- [Postgres, MySQL & MongoDB](/docs/resources/databases)
- [Object storage](/docs/resources/object-storage)
- [Compliance & security](/docs/compliance)
