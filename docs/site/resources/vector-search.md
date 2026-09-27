---
title: "Vector search"
description: "Store embeddings in a vector index and find the closest matches for semantic search, recommendations, and retrieval."
---

A vector index stores embeddings, lists of numbers that describe a piece of text or an image, and finds the stored vectors closest to one you send. Use it for semantic search, recommendations, and retrieval-augmented generation: store the embedding of each document, then query with the embedding of a question. Pair it with [Workers AI](/docs/resources/ai) to create the embeddings.

> [!NOTE]
> Available on paid plans. Not included in the trial.

## Create an index

1. In your app, open **Resources**.
2. Choose **Add resource**, then **Vector search**.
3. Leave **Create new** selected and enter a **Name**, such as `Search`. Use at most 32 letters, numbers, and dashes.
4. Pick **Dimensions**: how many numbers each vector has. Match your embedding model, such as `768` for `bge-base` or `1536` for `text-embedding-3-small`.
5. Pick **Distance**: how closeness is measured. `cosine` suits most text embeddings.
6. Choose **Create**, then deploy the app.

| Setting | Options |
|---|---|
| Dimensions | `384`, `768`, `1024`, `1536` |
| Distance | `cosine`, `euclidean`, `dot-product` |

Dimensions and distance cannot change after the index is created. To reuse an index this organization already created, choose **Attach existing** and pick it from **Existing Index**.

## Use the index

### SSR and hybrid apps (Workers)

The next deploy binds the index as `env.<NAME>`, where `<NAME>` is the name you gave it in capitals.

```ts
// Store a document's embedding
await env.SEARCH.upsert([
  { id: 'doc-1', values: embedding, metadata: { title: 'Hello' } },
]);

// Find the five closest
const { matches } = await env.SEARCH.query(embedding, { topK: 5, returnMetadata: 'all' });
```

With Workers AI on the same app:

```ts
const { data } = await env.AI.run('@cf/baai/bge-base-en-v1.5', { text: [question] });
const { matches } = await env.SEARCH.query(data[0], { topK: 5 });
```

New vectors show up in queries a few seconds after they are written.

### Container apps

A container app sends a vector to the index's private host, shown on the **Connect** tab, and gets the closest matches with their scores.

```php
$matches = Http::post('http://dply.<app>.search.internal/query', [
    'vector' => $embedding,
    'topK' => 5,
])->json('matches');
```

```js
const res = await fetch('http://dply.<app>.search.internal/query', {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ vector: embedding, topK: 5 }),
});
const { matches } = await res.json();
```

> [!IMPORTANT]
> From a container app the index can only be queried. Writing vectors from a container app is not supported yet. Write them from an SSR or hybrid app that binds the same index.

## Query from the dashboard

Open the index card and go to **Query**. Paste a **Vector (JSON)** with the index's number of dimensions, set **Results**, and choose **Query**. This reads the index directly and does not call your app. The **Overview** tab shows dimensions, distance, and the vector count.

## Pricing

Vector search is billed in dimensions, at the invoice line **AI, browser rendering and vector search**, less your plan's included usage credit.

<!-- generated: php artisan dply:billing:price-table rates --group="Vector search" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Queried dimensions | $0.013 | per million |
| Stored dimensions | $0.065 | per 100 million, per month |

- **Queried dimensions:** each query counts the query vector's dimensions (768 for a 768-dimension index). Each month, every index's stored vectors also count once as queried, as Cloudflare bills them.
- **Stored dimensions:** vectors × dimensions, at each index's largest day in the period.

An index of 100,000 vectors at 768 dimensions stores 76.8 million dimensions. With 1 million queries a month it is about 845 million queried dimensions.

### Monthly limit

AI, browser rendering and vector search share one monthly limit per organization, **$25** unless an owner changes it on the **Billing** page. It counts every app in the organization, and the **Try it** and **Query** tabs in the dashboard. Owners are emailed at 80% and 100%. At the limit, calls are refused until the next billing period or until an owner raises it:

- A Worker app's call rejects with an error whose message says the limit is reached (`error.status` is `429`).
- A container app gets HTTP `429` with `{"error": "..."}`.

Setting the limit to `0` removes it, up to a platform maximum. dply can also turn one of these services off for everyone for a while; calls then fail with status `503` and say so. The AI, browser and vector search cards show this period's usage and the limit.

On a trial, or if the organization leaves its paid plan, the index is left out of the app's next deploy. The index and its vectors are kept.

> [!NOTE]
> In a Worker app, only your fetch, scheduled and queue handlers (and a `WorkerEntrypoint` class entry) see `env.NAME`. Your own Durable Object classes do not: call it from a handler instead.

## Delete an index

On the index's **Settings** tab, choose **Delete**, then **Delete resource**. The index and every vector in it are deleted if this organization created it; otherwise it is only detached. This cannot be undone. If a live deploy still uses the index, the delete waits until the next deploy drops it. **Detach** on the card removes it from this app and keeps the index.

## Related

- [Workers AI](/docs/resources/ai)
- [Server rendering (SSR)](/docs/server-rendering)
- [Plans & pricing](/docs/pricing)
