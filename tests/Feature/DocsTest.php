<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->docsRoot = sys_get_temp_dir().'/dply-docs-'.uniqid();
    File::ensureDirectoryExists($this->docsRoot.'/guides');
    File::ensureDirectoryExists($this->docsRoot.'/_reports');

    File::put($this->docsRoot.'/nav.json', json_encode(['sections' => [
        ['title' => 'Getting started', 'pages' => [
            ['slug' => 'introduction', 'title' => 'Introduction'],
            ['slug' => 'guides/laravel', 'title' => 'Deploy a Laravel app'],
            ['slug' => 'unwritten', 'title' => 'Unwritten page'],
        ]],
    ]]));

    File::put($this->docsRoot.'/introduction.md', <<<'MD'
---
title: "Introduction"
description: "What dply is and how it deploys."
---

Opening paragraph with a [link](/docs/guides/laravel).

## First steps

> [!NOTE]
> Available on Pro and Team.

### Install the `cli`

```bash
npm i -g @dply/cli
```

| Plan | Price |
|------|-------|
| Pro  | $20   |

> [!WARNING]
>
> Deleting an app is permanent.

> A plain quote.
MD);
    File::put($this->docsRoot.'/guides/laravel.md', "---\ntitle: Deploy Laravel\ndescription: Laravel on dply.\n---\n\n## Configure\n\nText.\n");
    File::put($this->docsRoot.'/STYLE.md', '# style');
    File::put($this->docsRoot.'/_reports/writer.md', '# report');

    config(['docs.path' => $this->docsRoot]);
});

afterEach(function (): void {
    File::deleteDirectory($this->docsRoot);
});

test('docs index redirects to the introduction', function (): void {
    $this->get('/docs')->assertRedirect('/docs/introduction');
});

test('a page renders with title, description, anchors, toc, callouts and code', function (): void {
    $response = $this->get('/docs/introduction')->assertOk();

    $response->assertSee('<title>Introduction', false)
        ->assertSee('What dply is and how it deploys.')
        // laravel/head normalises canonicals to https.
        ->assertSee('<link rel="canonical" href="'.str_replace('http://', 'https://', route('docs.show', 'introduction')).'">', false)
        // Headings get ids + permalinks, and appear in the right-rail TOC.
        ->assertSee('<h2 id="first-steps">', false)
        ->assertSee('href="#first-steps"', false)
        ->assertSee('<h3 id="install-the-cli">', false)
        ->assertSee('href="#install-the-cli"', false)
        // GitHub alerts become callouts; a plain quote stays a blockquote.
        ->assertSee('<div class="docs-callout docs-callout--note" role="note"><p class="docs-callout__title">Note</p><p>Available on Pro and Team.</p></div>', false)
        ->assertSee('<div class="docs-callout docs-callout--warning" role="note"><p class="docs-callout__title">Warning</p><p>Deleting an app is permanent.</p></div>', false)
        ->assertSee('<blockquote>', false)
        ->assertDontSee('[!NOTE]', false)
        // Fenced code keeps its language and gets a copy button; tables scroll.
        ->assertSee('<code class="language-bash">', false)
        ->assertSee('data-docs-copy', false)
        ->assertSee('<div class="docs-table"><table>', false)
        // Internal links stay as written.
        ->assertSee('href="/docs/guides/laravel"', false)
        // Sidebar lists every nav page; prev/next links the neighbour.
        ->assertSee(route('docs.show', 'guides/laravel'), false);
});

test('nested slugs render', function (): void {
    $this->get('/docs/guides/laravel')->assertOk()->assertSee('Deploy Laravel');
});

test('a nav page without a file renders a coming soon stub', function (): void {
    $this->get('/docs/unwritten')
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertSee('<meta name="robots" content="noindex">', false);

    $this->get('/docs/unwritten.md')->assertNotFound();
});

test('unknown, unlisted and traversal slugs 404', function (string $path): void {
    $this->get($path)->assertNotFound();
})->with([
    '/docs/nope',
    '/docs/STYLE',
    '/docs/style',
    '/docs/_reports/writer',
    '/docs/../composer.json',
    '/docs/%2e%2e/composer',
    '/docs/guides/../introduction.md',
]);

test('the md route returns raw markdown', function (): void {
    $response = $this->get('/docs/introduction.md')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/markdown');
    expect($response->getContent())->toContain('> [!NOTE]')->toContain('title: "Introduction"');

    $this->get('/docs/guides/laravel.md')->assertOk()->assertSee('## Configure', false);
});

test('llms.txt lists written pages with urls and descriptions', function (): void {
    $body = $this->get('/docs/llms.txt')->assertOk()->getContent();

    expect($body)
        ->toContain('## Getting started')
        ->toContain('- [Introduction]('.route('docs.markdown', 'introduction').'): What dply is and how it deploys.')
        ->toContain('- [Deploy Laravel]('.route('docs.markdown', 'guides/laravel').'): Laravel on dply.')
        ->not->toContain('Unwritten');
});

test('search index has title, description, headings and text per written page', function (): void {
    $this->get('/docs/search.json')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.slug', 'introduction')
        ->assertJsonPath('0.url', route('docs.show', 'introduction'))
        ->assertJsonPath('0.title', 'Introduction')
        ->assertJsonPath('0.section', 'Getting started')
        ->assertJsonPath('0.description', 'What dply is and how it deploys.')
        ->assertJsonPath('0.headings.0', ['id' => 'first-steps', 'text' => 'First steps'])
        ->assertJsonPath('0.headings.1', ['id' => 'install-the-cli', 'text' => 'Install the cli'])
        ->assertJsonStructure([['slug', 'url', 'title', 'section', 'description', 'headings', 'text']])
        ->assertJsonMissing(['slug' => 'unwritten']);

    expect($this->get('/docs/search.json')->json('0.text'))
        ->toContain('Opening paragraph')
        ->not->toContain('<');
});

test('malformed front matter falls back to the nav title', function (): void {
    File::put($this->docsRoot.'/guides/laravel.md', "---\ntitle: Broken: yaml: here\n---\n\nBody.\n");

    $this->get('/docs/guides/laravel')->assertOk()->assertSee('Deploy a Laravel app');
});

test('sitemap lists written docs pages', function (): void {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertSee(route('docs.show', 'guides/laravel'), false)
        ->assertDontSee(route('docs.show', 'unwritten').'<', false);
});
