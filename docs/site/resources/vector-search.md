---
title: "Vector search"
description: "Store embeddings in a vector index and find the closest matches: semantic search, recommendations and retrieval for AI answers. Billed by use, sleeps like any resource."
---

Vector search stores **embeddings**, lists of numbers that describe what a piece of text or an image means, and finds the stored ones closest to one you send. Two sentences that mean the same thing get embeddings that sit close together, even when they share no words. "How do I change my password?" finds "Reset your password from the sign-in page", which a keyword search would miss.

Use it for:

- **Semantic search.** Search help articles, products or docs by meaning, not exact words.
- **Recommendations.** "More like this": find the items closest to one a visitor is looking at.
- **Retrieval for AI answers (RAG).** Find the few passages that answer a question, then hand them to a model with [Workers AI](/docs/resources/ai) so it answers from your content.
- **Duplicate detection.** Flag a new support ticket or listing that is nearly the same as an existing one.

> [!NOTE]
> Available on paid plans. Not included in the trial.

## How it works

1. **Embed your content.** Run each document through an embedding model, such as `@cf/baai/bge-base-en-v1.5` on [Workers AI](/docs/resources/ai). You get a vector of numbers back.
2. **Store it.** Save each vector in the index with an `id` and optional `metadata`, such as the title or the text itself.
3. **Search.** When someone asks something, embed the question with the **same model** and send that vector. The index returns the closest stored vectors, closest first, each with a `score`.

The index only compares numbers. Use the same model for storing and searching, or the scores mean nothing.

## Create an index

1. In your app, open **Overview**.
2. Choose **Add resource**, then **Vector search** (under **AI & media**).
3. Leave **Create new** selected and enter a **Name**, such as `Search`. Use at most 32 letters, numbers and dashes. An app can have several indexes, each with its own name.
4. Pick **Dimensions**: how many numbers each vector has. It must match your embedding model (see the table below).
5. Pick **Distance**: how closeness is measured. `cosine` suits most text embeddings.
6. Choose **Create**.

The index's sheet opens right away, showing its address, size and a demo you can run. The app gets the index on its **next deploy**.

To reuse an index this organization already created, for example one shared by two apps, choose **Attach existing** and pick it from **Existing Index**.

> [!IMPORTANT]
> **Dimensions** and **Distance** cannot change after the index is created. To change them, create a new index and store your vectors again.

### Choose dimensions

| Dimensions | Embedding models | Good for |
|---|---|---|
| `384` | `@cf/baai/bge-small-en-v1.5` | Fast, cheap search over short English text. |
| `768` | `@cf/baai/bge-base-en-v1.5` | The default. A good balance for most English search. |
| `1024` | `@cf/baai/bge-large-en-v1.5`, `@cf/baai/bge-m3` (multilingual) | Best quality, or text in many languages. |
| `1536` | OpenAI `text-embedding-3-small`, `text-embedding-ada-002` | Embeddings you create outside dply. |

Bigger vectors cost more to store and to search (billing counts dimensions), so pick the smallest that searches well for your content.

### Choose distance

| Distance | Use when |
|---|---|
| `cosine` | Most text embeddings, including every model above. Scores run up to `1` for identical meaning. |
| `euclidean` | Your model's docs say to use L2 distance. Lower means closer. |
| `dot-product` | Your model outputs normalized vectors and its docs recommend dot product. |

## The Vector search box and sheet

Each index shows as a **Vector search** box on the **Overview** map, with its name, where the app reaches it (`env.NAME` or its private address), **On** or **Asleep**, and what it has cost this month. Select the box to open its sheet:

- **Address** or **Binding**, with **Copy**.
- **Index**: its **Dimensions**, **Distance** and **Vectors** count, and **Refresh**. New vectors show up a few seconds after they are written.
- **Try it**: a live demo (below).
- **Use it from your app**: code to copy for your app type.
- Usage this period against your organization's limit.
- **Sleep**, **Detach** and **Delete**.

## Try it

**Try it** shows the whole flow on your own index with real embeddings. You need permission to edit the app.

1. **Add sample documents.** dply embeds six short help-center answers with the model that matches your index's size and stores them with ids starting `dply-demo-`.
2. **Ask a question.** Type something like "How do I change my password?" and choose **Search**. dply embeds the question with the same model and searches the index.
3. Read the results: the closest documents, best first, each with its score and a bar. The line above them says how the search ran, how long it took and which model made the vector.

On a **container app**, the search goes **through your live app**: the same path your code's calls to the index take. If it works here, your app is wired up. The app needs a deploy made after the index was added; before that the sheet says to redeploy. On an **SSR or hybrid app**, dply searches the index directly.

> [!WARNING]
> The sample documents are real vectors in your index, so your app's own searches can return them. Choose **Remove sample documents** when you are done. They are billed as stored vectors until you remove them.

Try it is billed like your app's own usage: the embeddings as AI, each search as queried dimensions, and the samples as stored dimensions. It counts toward the organization's monthly limit, and you can run it about ten times a minute.

A `1536`-dimension index has no built-in embedding model, so it has no question demo. Use **Search with a vector** instead: paste a JSON array of numbers the size of the index, set how many results you want (up to 50), and choose **Search**. This reads the index directly, shows each match's id, score and metadata, and does not call the app.

## Use the index from your app

### SSR and hybrid apps (Workers)

The next deploy binds the index as `env.<NAME>`, where `<NAME>` is the name you gave it in capitals, such as `env.SEARCH`. Worker apps can store and search.

**Store vectors:**

```ts
await env.SEARCH.upsert([
  { id: 'doc-1', values: embedding, metadata: { title: 'Reset your password', url: '/help/password' } },
]);
```

`upsert` adds a vector or replaces the one with the same `id`. Keep ids stable, such as your database row id, so updating a document replaces its vector instead of adding a second one.

**Search:**

```ts
const { matches } = await env.SEARCH.query(embedding, { topK: 5, returnMetadata: 'all' });
// [{ id: 'doc-1', score: 0.83, metadata: { title: 'Reset your password', … } }, …]
```

**Delete:**

```ts
await env.SEARCH.deleteByIds(['doc-1', 'doc-2']);
```

**End to end with Workers AI** (add [AI](/docs/resources/ai) to the same app):

```ts
const MODEL = '@cf/baai/bge-base-en-v1.5'; // 768 dimensions

// Index your documents (for example when they are created or edited)
const { data } = await env.AI.run(MODEL, { text: docs.map((d) => d.text) });
await env.SEARCH.upsert(docs.map((d, i) => ({ id: d.id, values: data[i], metadata: { text: d.text } })));

// Answer a search
const { data: [question] } = await env.AI.run(MODEL, { text: [query] });
const { matches } = await env.SEARCH.query(question, { topK: 5, returnMetadata: 'all' });
```

**Retrieval for an AI answer:**

```ts
const context = matches.map((m) => m.metadata.text).join('\n\n');
const { response } = await env.AI.run('@cf/meta/llama-3.1-8b-instruct', {
  messages: [
    { role: 'system', content: `Answer using only this context:\n\n${context}` },
    { role: 'user', content: query },
  ],
});
```

> [!NOTE]
> In a Worker app, only your fetch, scheduled and queue handlers (and a `WorkerEntrypoint` class entry) see `env.NAME`. Your own Durable Object classes do not: call it from a handler instead.

### Container apps

A container app searches the index at its private address, shown in the sheet and on the box, such as `http://dply.my-app.search.internal`. Only this app can reach it. POST a vector to `/query`:

```php
use Illuminate\Support\Facades\Http;

$matches = Http::post('http://dply.my-app.search.internal/query', [
    'vector' => $embedding,
    'topK' => 5,
])->json('matches');
```

```js
const res = await fetch('http://dply.my-app.search.internal/query', {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ vector: embedding, topK: 5 }),
});
const { matches } = await res.json();
```

The reply is `{"matches": [{"id": "doc-1", "score": 0.83}, …]}`, closest first. Keep what you need to show (title, URL, text) in your database and look it up by `id`.

To make the embedding in the same app, add [AI](/docs/resources/ai) and call its address with an embedding model whose size matches the index.

> [!IMPORTANT]
> From a container app the index can only be **searched**. Storing and deleting vectors from a container app is not supported yet. Store them from an SSR or hybrid app that binds the same index (**Attach existing**).

## Sleep

Vector search sleeps like other resources. Choose **Sleep** in the sheet: on the next deploy the app loses the index, so no searches run and none are billed. A container app's calls get HTTP `503` with "This resource is asleep." **Wake** it and deploy to search again.

Sleeping keeps the index and every vector in it. **Stored vectors are still billed while it sleeps.** To stop that, delete the index.

## Pricing

Vector search is billed by use, in dimensions, less your plan's included usage credit. It is on the invoice line **AI, browser rendering and vector search**. There is no charge for an index just existing.

<!-- generated: php artisan dply:billing:price-table rates --group="Vector search" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Queried dimensions | $0.013 | per million |
| Stored dimensions | $0.065 | per 100 million, per month |

- **Queried dimensions:** each search counts the query vector's dimensions (768 for a 768-dimension index). Each month, every index's stored vectors also count once as queried, as Cloudflare bills them.
- **Stored dimensions:** vectors × dimensions, at each index's largest day in the period.

**Example.** A help center of 100,000 articles at 768 dimensions stores 76.8 million dimensions. With 1 million searches a month it queries about 845 million dimensions (768 million from searches plus 76.8 million for the stored vectors). That is roughly $0.05 stored plus $11 queried, before your plan's credit.

To keep costs down: use the smallest dimensions that search well, delete vectors you no longer need, and sleep or delete indexes you are not using.

### Monthly limit

AI, browser rendering and vector search share one monthly limit per organization, **$25** unless an owner changes it on the **Billing** page. It counts every app in the organization and the demos in the dashboard. Owners are emailed at 80% and 100%. At the limit, calls are refused until the next billing period or until an owner raises it:

- A Worker app's call rejects with an error whose message says the limit is reached (`error.status` is `429`).
- A container app gets HTTP `429` with `{"error": "..."}`.

Setting the limit to `0` removes it, up to a platform maximum. dply can also turn one of these services off for everyone for a while; calls then fail with status `503` and say so. The sheet shows this period's usage and the limit.

On a trial, or if the organization leaves its paid plan, the index is left out of the app's next deploy. The index and its vectors are kept.

## Detach or delete

Both are at the bottom of the index's sheet.

- **Detach** removes the index from this app on the next deploy and keeps it, with its vectors, for other apps or later. Stored vectors are still billed.
- **Delete**, then **Delete resource**, deletes the index and every vector in it if this organization created it; otherwise it is only detached. This cannot be undone. If a live deploy still uses the index, the delete waits until the next deploy drops it.

## Troubleshooting

| What you see | Why, and what to do |
|---|---|
| Search returns nothing | The index is empty, or the vectors were written in the last few seconds. Check the **Vectors** count and try again shortly. |
| "This index takes N numbers" | The vector is the wrong size. Use the embedding model that matches the index's **Dimensions**. |
| Scores are all low or random | Documents and questions were embedded with different models. Re-embed both with the same one. |
| HTTP `503` "This resource is asleep" | The index is asleep. **Wake** it and deploy. |
| HTTP `429` | The organization reached its monthly limit. An owner can raise it on **Billing**. |
| Try it says to redeploy | The live app predates the index. Deploy once, then try again. |
| "This index was not created by this organization" | It was attached by name before dply created indexes. Create a new one to see it in the dashboard. |

## Related

- [Workers AI](/docs/resources/ai)
- [Images](/docs/resources/images)
- [Server rendering (SSR)](/docs/server-rendering)
- [Plans & pricing](/docs/pricing)
