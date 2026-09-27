---
title: "Workflows"
description: "Workflows are not available on dply yet; this page explains why and how to remove an existing Workflow resource."
---

Workflows (durable, multi-step jobs on Cloudflare Workflows) are not supported on dply yet. They do not appear under **Add resource**, on container apps or on Worker apps.

## Why they are not available

A workflow needs its code, a `WorkflowEntrypoint` class, inside the app's Worker. dply generates the Worker for a container app and does not build that class into it, so a deploy with a workflow binding fails. SSR and hybrid apps run on Workers for Platforms, where Cloudflare does not support workflows.

For multi-step background work today, use a [queue](/docs/resources/queues) or [queue workers](/docs/queue-workers), and keep progress in a database or [State](/docs/resources/state).

## Remove an existing Workflow resource

An app that was given a Workflow resource before it was hidden still shows it on the resource map. Its sheet says "Workflows are not supported yet. A workflow needs its code inside this app's worker, and dply does not build that, so a deploy with this resource fails. Remove it to deploy again."

1. Open your app. On **Overview**, choose **Open** on the Workflow card.
2. Choose **Remove**, then **Remove** again to confirm.
3. Redeploy the app.

Removing it deletes nothing.

## Related

- [Resources overview](/docs/resources)
- [Queues](/docs/resources/queues)
- [Queue workers](/docs/queue-workers)
