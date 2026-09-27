# dply docs — writing guide

Public documentation, rendered in the app at `/docs/<slug>` from
`docs/site/<slug>.md`. The sidebar comes from `docs/site/nav.json`. Model:
Laravel Cloud's docs (https://laravel.com/cloud/docs/intro) — complete,
task-oriented, calm, precise.

## Every page

```markdown
---
title: "Queue workers"
description: "One sentence for search results and the page subtitle."
---

Opening paragraph: what this is and when you'd use it (2–4 sentences).

## First task-oriented heading
...
```

- `title` must match nav.json. `description` is one plain sentence.
- H2 for major sections, H3 below that. No H1 (the renderer adds it).
- Sentence-case headings. Second person ("you"). Present tense.
- Name UI exactly as the product shows it: bold for UI labels (**Deploy**,
  **Add resource**), backticks for values, env vars, commands, file names.
- Show the path to a setting: "In your app, open **Resources**, choose
  **Add resource**, then **Key-value**."
- Code blocks always have a language (`bash`, `php`, `toml`, `yaml`, `json`,
  `js`, `ts`, `env`, `http`).
- Callouts use GitHub alert syntax:
  - `> [!NOTE]` extra context
  - `> [!TIP]` a better way
  - `> [!WARNING]` data loss, downtime, cost, or security
  - `> [!IMPORTANT]` required for it to work
- Plan availability: if a feature needs a plan, say so near the top with a
  `> [!NOTE]` ("Available on Pro and Team. Not included in the trial.").
- Prices and limits: always take them from config
  (`config/product/subscription.php`, `config/product/dply.php`,
  `config/product/edge.php`) — never invent numbers. Show as tables.
- Link other pages as `/docs/<slug>`. Link only to slugs in nav.json.
- End with `## Next steps` or `## Related` (2–4 links) where useful.
- No marketing fluff, no "simply", "just", "easily". No emoji.

## Accuracy rule (most important)

Every statement must match the product as the code is today. Verify in the
code (Livewire components, views, config, routes, services) before writing.
If the product does something awkward, missing, or inconsistent — don't paper
over it in the docs. Write the doc for what exists, and record the issue in
your report file (below).

## Report file

Each writer also writes `docs/site/_reports/<your-section>.md` with:

1. **Mismatches** — where the UI, copy, pricing page, or config disagree
   with each other or with reality (file:line).
2. **Gaps vs Laravel Cloud** — features Cloud documents that dply lacks or
   does worse, that a beta customer would expect.
3. **Pricing / limits questions** — anything confusing, uncompetitive, or
   unenforced.
4. **Suggested fixes** — smallest change for each, marked S/M/L.
