---
title: "Workers AI"
description: "Run text-generation and embedding models from your app without managing model servers or API keys."
---

Workers AI lets your app run open models, such as Llama and Mistral for text and BGE for embeddings, on GPUs near your users. There is nothing to create and no API key: turn AI on for the app, then name a model on each call. Use it for summaries, classification, chat, and creating embeddings for [vector search](/docs/resources/vector-search).

> [!NOTE]
> Available on paid plans. Not included in the trial.

## Turn on AI

1. In your app, open **Overview**.
2. Choose **Add resource**, then **AI**.
3. Deploy the app.

AI is added as soon as you choose it; there is no form. It starts working after the next deploy. An SSR or hybrid app gets it as `env.AI`. A container app calls a private host shown on the AI card.

## Call a model

### SSR and hybrid apps (Workers)

```ts
const { response } = await env.AI.run('@cf/meta/llama-3.1-8b-instruct', {
  prompt: 'Say hello',
});

const { data } = await env.AI.run('@cf/baai/bge-base-en-v1.5', {
  text: ['a sentence to embed'],
});
```

### Container apps

`POST` to `http://<host>/run` with `{"model", "input"}`. The reply is the model's JSON.

```php
$answer = Http::post('http://dply.<app>.ai.internal/run', [
    'model' => '@cf/meta/llama-3.1-8b-instruct',
    'input' => ['prompt' => 'Say hello'],
])->json('response');
```

```js
const res = await fetch('http://dply.<app>.ai.internal/run', {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({
    model: '@cf/meta/llama-3.1-8b-instruct',
    input: { prompt: 'Say hello' },
  }),
});
const { response } = await res.json();
```

Use the exact host shown at the top of the AI card and on its **Connect** tab.

### Inputs and outputs

| Model type | Input | Output |
|---|---|---|
| Text | `{"prompt": "..."}` or `{"messages": [...]}` | `{"response": "..."}` |
| Embeddings | `{"text": ["...", "..."]}` | `{"data": [[...], [...]]}`, one vector per text |

Any Workers AI model id works. The **Try it** tab lists a few examples and runs one prompt from the dashboard:

| Model | Type |
|---|---|
| `@cf/meta/llama-3.1-8b-instruct` | Text |
| `@cf/meta/llama-3.2-3b-instruct` | Text |
| `@cf/mistral/mistral-7b-instruct-v0.1` | Text |
| `@cf/baai/bge-base-en-v1.5` | Embeddings (768 dimensions) |

## Pricing

Workers AI is billed in neurons, Cloudflare's unit for GPU work, at the invoice line **AI, browser rendering and vector search**, less your plan's included usage credit.

<!-- generated: php artisan dply:billing:price-table rates --group="AI" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Neurons | $0.0143 | per 1,000 |

Each model has its own rate in neurons per million input and output tokens (the [Workers AI pricing](https://developers.cloudflare.com/workers-ai/platform/pricing/) table). dply counts the tokens the model reports for each call and applies that model's rate. When a model reports no token counts, dply estimates them from the length of the input and the answer (about four characters per token). A model that is not in the table is billed at a high text-model rate with a minimum of 100 neurons per call.

### Monthly limit

AI, browser rendering and vector search share one monthly limit per organization, **$25** unless an owner changes it on the **Billing** page. It counts every app in the organization, and the **Try it** and **Query** tabs in the dashboard. Owners are emailed at 80% and 100%. At the limit, calls are refused until the next billing period or until an owner raises it:

- A Worker app's call rejects with an error whose message says the limit is reached (`error.status` is `429`).
- A container app gets HTTP `429` with `{"error": "..."}`.

Setting the limit to `0` removes it, up to a platform maximum. dply can also turn one of these services off for everyone for a while; calls then fail with status `503` and say so. The AI, browser and vector search cards show this period's usage and the limit.

On a trial, or if the organization leaves its paid plan, AI is left out of the app's next deploy and calls to it fail.

> [!NOTE]
> In a Worker app, only your fetch, scheduled and queue handlers (and a `WorkerEntrypoint` class entry) see `env.AI`. Your own Durable Object classes do not: call it from a handler instead.

## Turn off AI

Open the AI card and choose **Remove from this app**, then **Remove**. AI is removed on the next deploy, and code that calls it gets errors. Nothing is stored, so nothing is lost.

## Related

- [Vector search](/docs/resources/vector-search)
- [Browser rendering](/docs/resources/browser-rendering)
- [Plans & pricing](/docs/pricing)
