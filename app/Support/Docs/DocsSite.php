<?php

namespace App\Support\Docs;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Cache;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\StringContainerHelper;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The public docs at /docs: docs/site/<slug>.md, ordered by docs/site/nav.json.
 *
 * nav.json is the allow-list — a slug is servable only if nav lists it, so
 * STYLE.md, _reports/*.md and anything a path could reach are never served.
 * A listed slug whose file is not written yet is a "coming soon" stub.
 * Scoped so nav.json is read once per request.
 */
#[Scoped]
final class DocsSite
{
    /** Bump when rendering output changes, to drop cached HTML. */
    private const RENDER_VERSION = 3;

    private const SLUG = '#^[a-z0-9-]+(/[a-z0-9-]+)*$#';

    private const ALERTS = ['NOTE' => 'Note', 'TIP' => 'Tip', 'IMPORTANT' => 'Important', 'WARNING' => 'Warning', 'CAUTION' => 'Caution'];

    /** @var list<array{title: string, pages: list<array{slug: string, title: string, section: string, exists: bool}>}>|null */
    private ?array $nav = null;

    public function root(): string
    {
        return rtrim((string) config('docs.path'), '/');
    }

    /**
     * @return list<array{title: string, pages: list<array{slug: string, title: string, section: string, exists: bool}>}>
     */
    public function nav(): array
    {
        if ($this->nav !== null) {
            return $this->nav;
        }

        $file = $this->root().'/nav.json';
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        $sections = [];
        foreach ((array) ($json['sections'] ?? []) as $section) {
            $title = (string) ($section['title'] ?? '');
            $pages = [];
            foreach ((array) ($section['pages'] ?? []) as $page) {
                $slug = (string) ($page['slug'] ?? '');
                if (! preg_match(self::SLUG, $slug)) {
                    continue;
                }
                $pages[] = [
                    'slug' => $slug,
                    'title' => (string) ($page['title'] ?? $slug),
                    'section' => $title,
                    'exists' => is_file($this->file($slug)),
                ];
            }
            $sections[] = ['title' => $title, 'pages' => $pages];
        }

        return $this->nav = $sections;
    }

    /**
     * @return list<array{slug: string, title: string, section: string, exists: bool}>
     */
    public function pages(): array
    {
        return array_merge(...array_column($this->nav(), 'pages') ?: [[]]);
    }

    /**
     * @return array{slug: string, title: string, section: string, exists: bool}|null
     */
    public function find(string $slug): ?array
    {
        foreach ($this->pages() as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }

    public function markdown(string $slug): ?string
    {
        $page = $this->find($slug);

        return $page && $page['exists'] ? (string) file_get_contents($this->file($slug)) : null;
    }

    /**
     * The nav entry plus its rendered content, or null for a slug nav does not
     * list. For a listed-but-unwritten page, `html` is null.
     *
     * @return array{slug: string, title: string, section: string, exists: bool, description: string, html: ?string, toc: list<array{id: string, text: string, level: int}>, text: string}|null
     */
    public function page(string $slug): ?array
    {
        $page = $this->find($slug);
        if ($page === null) {
            return null;
        }

        if (! $page['exists']) {
            return $page + ['description' => '', 'html' => null, 'toc' => [], 'text' => ''];
        }

        $path = $this->file($slug);
        $key = 'docs:'.self::RENDER_VERSION.':'.md5($path).':'.filemtime($path).':'.filesize($path);
        $rendered = Cache::remember($key, now()->addWeek(), fn () => $this->render((string) file_get_contents($path)));

        return array_merge($page, $rendered, ['title' => $rendered['title'] ?: $page['title']]);
    }

    /**
     * Previous and next written pages in nav order.
     *
     * @return array{prev: ?array, next: ?array}
     */
    public function neighbours(string $slug): array
    {
        $written = array_values(array_filter($this->pages(), fn ($p) => $p['exists'] || $p['slug'] === $slug));
        $i = array_search($slug, array_column($written, 'slug'), true);

        return [
            'prev' => $i !== false && $i > 0 ? $written[$i - 1] : null,
            'next' => $i !== false ? ($written[$i + 1] ?? null) : null,
        ];
    }

    /**
     * @return list<array{slug: string, url: string, title: string, section: string, description: string, headings: list<array{id: string, text: string}>, text: string}>
     */
    public function searchIndex(): array
    {
        $index = [];
        foreach ($this->pages() as $entry) {
            if (! $entry['exists']) {
                continue;
            }
            $page = $this->page($entry['slug']);
            $index[] = [
                'slug' => $page['slug'],
                'url' => route('docs.show', $page['slug']),
                'title' => $page['title'],
                'section' => $page['section'],
                'description' => $page['description'],
                'headings' => array_map(fn ($h) => ['id' => $h['id'], 'text' => $h['text']], $page['toc']),
                'text' => mb_substr($page['text'], 0, 4000),
            ];
        }

        return $index;
    }

    public function llmsTxt(): string
    {
        // Plain facts for assistants (served at /llms.txt and /docs/llms.txt). Prices
        // come from config so this cannot drift from the pricing page. The brand is
        // the literal "dply": config('app.name') is "dply-edge" locally.
        $plans = collect(config('subscription.standard.tiers'))
            ->filter(fn ($t) => ($t['price_cents'] ?? 0) > 0)
            ->map(fn ($t) => $t['label'].' $'.intdiv($t['price_cents'], 100).'/mo ('.trans_choice(':count seat|:count seats', $t['seats']).($t['extra_seat_cents'] ?? null ? ', +$'.intdiv($t['extra_seat_cents'], 100).' per extra seat' : '').')')
            ->implode('; ');
        $trialDays = (int) config('subscription.standard.trial.days');

        $out = "# dply\n\n"
            ."> dply deploys Laravel, Symfony, Rails and Node apps, and static or server-rendered sites, from a Git push. Apps run as containers on a global edge network that scale out and sleep when idle. Managed Postgres, MySQL, MongoDB, Valkey, object storage, realtime and autoscaling queue workers run beside them, on one bill.\n\n"
            ."- Plans (monthly only): {$plans}. Each plan includes usage credit equal to its price, and sites are unlimited.\n"
            ."- Usage past the credit bills by the second an app, worker or database is awake; requests and bandwidth by the unit.\n"
            ."- No free plan: a {$trialDays}-day trial of the chosen plan, card required at signup.\n"
            ."- Pricing: ".route('pricing')."\n"
            ."- Features: ".route('features')."\n"
            ."- Compared with Laravel Forge: ".route('compare', 'forge').", Laravel Cloud: ".route('compare', 'laravel-cloud').", Heroku: ".route('compare', 'heroku')."\n"
            ."- Security and subprocessors: ".route('compliance')."\n"
            ."- Contact: ".config('dply.support_email')."\n\n"
            ."Every documentation page below is also available as Markdown.\n";
        foreach ($this->nav() as $section) {
            $lines = [];
            foreach ($section['pages'] as $entry) {
                if (! $entry['exists']) {
                    continue;
                }
                $page = $this->page($entry['slug']);
                $lines[] = '- ['.$page['title'].']('.route('docs.markdown', $page['slug']).')'.($page['description'] !== '' ? ': '.$page['description'] : '');
            }
            if ($lines !== []) {
                $out .= "\n## {$section['title']}\n\n".implode("\n", $lines)."\n";
            }
        }

        return $out;
    }

    private function file(string $slug): string
    {
        return $this->root().'/'.$slug.'.md';
    }

    /**
     * @return array{title: string, description: string, html: string, toc: list<array{id: string, text: string, level: int}>, text: string}
     */
    private function render(string $markdown): array
    {
        $meta = [];
        if (preg_match('/\A---\R(.*?)\R---\R?/s', $markdown, $m)) {
            $markdown = substr($markdown, strlen($m[0]));
            try {
                $meta = (array) Yaml::parse($m[1]);
            } catch (Throwable) {
                // A writer's malformed front matter falls back to nav's title, not a 500.
            }
        }

        $environment = new Environment([
            // Trusted, repo-owned content.
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'min_heading_level' => 2,
                'max_heading_level' => 4,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'apply_id_to_heading' => true,
                'insert' => 'after',
                'symbol' => '#',
                'html_class' => 'docs-anchor',
                'title' => 'Link to this section',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        $result = (new MarkdownConverter($environment))->convert($markdown);

        $toc = [];
        foreach ($result->getDocument()->iterator() as $node) {
            if (! $node instanceof Heading || $node->getLevel() > 3) {
                continue;
            }
            $id = '';
            foreach ($node->children() as $child) {
                if ($child instanceof HeadingPermalink) {
                    $id = $child->getSlug();
                }
            }
            $toc[] = ['id' => $id, 'text' => trim(StringContainerHelper::getChildText($node, [HeadingPermalink::class])), 'level' => $node->getLevel()];
        }

        $html = $this->alerts($result->getContent());
        $html = str_replace(['<table>', '</table>'], ['<div class="docs-table"><table>', '</table></div>'], $html);
        // Fenced code: a bar with the language and a copy button (docs.js).
        $html = (string) preg_replace_callback('#<pre><code(?: class="language-([^"]+)")?>#', fn (array $m) => '<div class="docs-code">'
            .'<div class="docs-code__bar"><span class="docs-code__lang">'.($m[1] ?? '').'</span>'
            .'<button type="button" class="docs-code__copy" data-docs-copy aria-label="Copy code"><span>Copy</span></button></div>'
            .$m[0], $html);
        $html = str_replace('</code></pre>', '</code></pre></div>', $html);

        $text = preg_replace('#<a[^>]*class="docs-anchor"[^>]*>.*?</a>#s', '', $html);
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5)));

        return [
            'title' => (string) ($meta['title'] ?? ''),
            'description' => (string) ($meta['description'] ?? ''),
            'html' => $html,
            'toc' => $toc,
            'text' => $text,
        ];
    }

    /**
     * GitHub alerts: a blockquote opening with [!NOTE] etc. becomes a callout.
     */
    private function alerts(string $html): string
    {
        $types = implode('|', array_keys(self::ALERTS));

        return (string) preg_replace_callback(
            '#<blockquote>\s*<p>\[!('.$types.')\]\s*(.*?)</blockquote>#s',
            function (array $m): string {
                $type = strtolower($m[1]);
                $body = preg_replace('#^(<br\s*/?>)?\s*</p>\s*#', '', $m[2], 1, $emptyFirst);
                $body = $emptyFirst ? $body : '<p>'.$body;

                return '<div class="docs-callout docs-callout--'.$type.'" role="note">'
                    .'<p class="docs-callout__title">'.self::ALERTS[$m[1]].'</p>'
                    .trim((string) $body).'</div>';
            },
            $html,
        );
    }
}
