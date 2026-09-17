<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

/**
 * Curated list of "Deploy with dply" starter templates. Backed by a
 * static registry — adding a template is a one-line PR, no migration
 * needed. When the gallery grows past ~20 entries we'll promote this
 * to a DB-backed model so non-engineers can add/remove without a deploy.
 *
 * Each entry needs:
 *   - slug          stable identifier (used in URL + analytics)
 *   - name          human label
 *   - description   one-liner shown in the gallery card
 *   - repo          owner/name on GitHub (must be public)
 *   - branch        defaults to main; override only when the upstream
 *                   template lives on a different branch
 *   - framework     matches an EdgeFrameworkPreset slug — drives the
 *                   default build/output/runtime when the user lands
 *                   in the Create flow
 *   - runtime_mode  override the preset default (e.g. force a static
 *                   variant of Next.js template for a fully-static demo)
 *   - tags          free-form labels — used for the gallery filter
 *   - hero_emoji    quick visual marker until we ship real screenshots
 */
class EdgeTemplateRegistry
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'keel-workers',
                'name' => 'Keel on Edge',
                'description' => 'House framework for Node.js — service container, routing, JSX views. Hybrid Edge + Cloud by default; Worker SSR optional.',
                'repo' => 'shaferllc/keel-site',
                'clone_repo' => 'shaferllc/keel-site',
                'branch' => 'main',
                'framework' => 'keel',
                'runtime_mode' => 'hybrid',
                'tags' => ['keel', 'hybrid', 'fullstack'],
                'hero_emoji' => 'K',
                'hero_url' => '/edge-templates/keel-workers.svg',
            ],
            [
                'slug' => 'astro-blog',
                'name' => 'Astro Blog',
                'description' => 'Markdown blog with RSS feed, dark mode, and sitemap. Zero JS by default.',
                'repo' => 'withastro/astro/tree/main/examples/blog',
                'clone_repo' => 'withastro/astro',
                'branch' => 'main',
                'framework' => 'astro',
                'runtime_mode' => 'static',
                'tags' => ['blog', 'astro', 'static'],
                'hero_emoji' => 'A',
                'hero_url' => '/edge-templates/astro-blog.svg',
            ],
            [
                'slug' => 'nextjs-docs',
                'name' => 'Next.js + Nextra Docs',
                'description' => 'Documentation site with sidebar, search, and dark mode powered by Nextra.',
                'repo' => 'shuding/nextra-docs-template',
                'clone_repo' => 'shuding/nextra-docs-template',
                'branch' => 'main',
                'framework' => 'next',
                'runtime_mode' => 'ssr',
                'tags' => ['docs', 'next', 'ssr'],
                'hero_emoji' => 'N',
                'hero_url' => '/edge-templates/nextjs-nextra-docs.svg',
            ],
            [
                'slug' => 'sveltekit-saas-starter',
                'name' => 'SvelteKit SaaS Starter',
                'description' => 'Marketing page + auth-ready dashboard scaffold. SQLite via Cloudflare D1.',
                'repo' => 'CriticalMoments/CMSaasStarter',
                'clone_repo' => 'CriticalMoments/CMSaasStarter',
                'branch' => 'main',
                'framework' => 'sveltekit',
                'runtime_mode' => 'hybrid',
                'tags' => ['saas', 'sveltekit', 'hybrid'],
                'hero_emoji' => 'S',
                'hero_url' => '/edge-templates/sveltekit-saas-starter.svg',
            ],
            [
                'slug' => 'eleventy-portfolio',
                'name' => 'Eleventy Portfolio',
                'description' => 'Personal portfolio template with project gallery and contact page.',
                'repo' => '11ty/eleventy-base-blog',
                'clone_repo' => '11ty/eleventy-base-blog',
                'branch' => 'main',
                'framework' => 'eleventy',
                'runtime_mode' => 'static',
                'tags' => ['portfolio', 'eleventy', 'static'],
                'hero_emoji' => '11',
                'hero_url' => '/edge-templates/eleventy-portfolio.svg',
            ],
            [
                'slug' => 'hono-api-starter',
                'name' => 'Hono Edge API',
                'description' => 'Typed REST API on Workers with Zod validation and OpenAPI docs.',
                'repo' => 'honojs/starter',
                'clone_repo' => 'honojs/starter',
                'branch' => 'main',
                'framework' => 'hono',
                'runtime_mode' => 'hybrid',
                'tags' => ['api', 'hono', 'edge'],
                'hero_emoji' => 'H',
                'hero_url' => '/edge-templates/hono-edge-api.svg',
            ],
            [
                'slug' => 'remix-shop',
                'name' => 'Remix Indie Stack',
                'description' => 'Full-stack starter with auth, Prisma, and Tailwind. Production-ready scaffold.',
                'repo' => 'remix-run/indie-stack',
                'clone_repo' => 'remix-run/indie-stack',
                'branch' => 'main',
                'framework' => 'remix',
                'runtime_mode' => 'hybrid',
                'tags' => ['fullstack', 'remix', 'auth'],
                'hero_emoji' => 'R',
                'hero_url' => '/edge-templates/remix-indie-stack.svg',
            ],
            [
                'slug' => 'static-html',
                'name' => 'Plain HTML Landing Page',
                'description' => 'Single-page hand-written HTML/CSS template — no build step, ships in seconds.',
                'repo' => 'pages-themes/minimal',
                'clone_repo' => 'pages-themes/minimal',
                'branch' => 'master',
                'framework' => 'static',
                'runtime_mode' => 'static',
                'tags' => ['landing', 'html', 'static'],
                'hero_emoji' => '~',
                'hero_url' => '/edge-templates/plain-html.svg',
            ],
            [
                'slug' => 'hugo-docs',
                'name' => 'Hugo Documentation',
                'description' => 'Docs theme with versioned sidebars, search, and code highlighting.',
                'repo' => 'gohugoio/hugoDocs',
                'clone_repo' => 'gohugoio/hugoDocs',
                'branch' => 'master',
                'framework' => 'hugo',
                'runtime_mode' => 'static',
                'tags' => ['docs', 'hugo', 'static'],
                'hero_emoji' => 'H',
                'hero_url' => '/edge-templates/hugo-docs.svg',
            ],
            [
                'slug' => 'nextjs-landing',
                'name' => 'Next.js Marketing Landing',
                'description' => 'High-conversion marketing page with sections, CTA, and Tailwind styling.',
                'repo' => 'vercel/next.js/tree/canary/examples/with-tailwindcss',
                'clone_repo' => 'vercel/next.js',
                'branch' => 'canary',
                'framework' => 'next',
                'runtime_mode' => 'ssr',
                'tags' => ['marketing', 'next', 'tailwind'],
                'hero_emoji' => 'L',
                'hero_url' => '/edge-templates/nextjs-landing.svg',
            ],
            [
                'slug' => 'sveltekit-dashboard',
                'name' => 'SvelteKit Dashboard',
                'description' => 'Admin dashboard scaffold with sidebar nav, data tables, and charts.',
                'repo' => 'sveltejs/kit/tree/main/examples/realworld',
                'clone_repo' => 'sveltejs/kit',
                'branch' => 'main',
                'framework' => 'sveltekit',
                'runtime_mode' => 'hybrid',
                'tags' => ['dashboard', 'sveltekit', 'hybrid'],
                'hero_emoji' => 'D',
                'hero_url' => '/edge-templates/sveltekit-dashboard.svg',
            ],
            [
                'slug' => 'nuxt-portfolio',
                'name' => 'Nuxt Portfolio',
                'description' => 'Vue/Nuxt personal portfolio with project case studies and blog.',
                'repo' => 'nuxt/nuxt/tree/main/examples/essentials/auto-imports',
                'clone_repo' => 'nuxt/nuxt',
                'branch' => 'main',
                'framework' => 'nuxt',
                'runtime_mode' => 'hybrid',
                'tags' => ['portfolio', 'nuxt', 'vue'],
                'hero_emoji' => 'V',
                'hero_url' => '/edge-templates/nuxt-portfolio.svg',
            ],
            [
                'slug' => 'remix-shop-storefront',
                'name' => 'Remix Storefront',
                'description' => 'Headless e-commerce storefront with product pages, cart, and checkout stubs.',
                'repo' => 'remix-run/remix/tree/main/examples/shopify',
                'clone_repo' => 'remix-run/remix',
                'branch' => 'main',
                'framework' => 'remix',
                'runtime_mode' => 'ssr',
                'tags' => ['shop', 'remix', 'ssr'],
                'hero_emoji' => 'C',
                'hero_url' => '/edge-templates/remix-shop-storefront.svg',
            ],
            // Container apps (PHP / Rails / Node servers on Cloudflare Containers).
            [
                'slug' => 'laravel-starter',
                'name' => 'Laravel',
                'description' => 'The official Laravel skeleton as a container app — FrankenPHP, queues and the scheduler wired through dply/laravel.',
                'repo' => 'laravel/laravel',
                'clone_repo' => 'laravel/laravel',
                'branch' => '12.x',
                'framework' => 'laravel',
                'runtime_mode' => 'container',
                'tags' => ['laravel', 'php', 'container'],
                'hero_emoji' => 'L',
                'hero_url' => '/edge-templates/laravel-starter.svg',
            ],
            [
                'slug' => 'rails-getting-started',
                'name' => 'Ruby on Rails',
                'description' => 'A Rails app on Puma as a container. Add DATABASE_URL for Postgres before the first deploy.',
                'repo' => 'heroku/ruby-getting-started',
                'clone_repo' => 'heroku/ruby-getting-started',
                'branch' => 'main',
                'framework' => 'rails',
                'runtime_mode' => 'container',
                'tags' => ['rails', 'ruby', 'container'],
                'hero_emoji' => 'R',
                'hero_url' => '/edge-templates/rails-getting-started.svg',
            ],
            [
                'slug' => 'express-getting-started',
                'name' => 'Express server',
                'description' => 'A Node.js Express app listening on $PORT, run as a container.',
                'repo' => 'heroku/node-js-getting-started',
                'clone_repo' => 'heroku/node-js-getting-started',
                'branch' => 'main',
                'framework' => 'express',
                'runtime_mode' => 'container',
                'tags' => ['node', 'express', 'container'],
                'hero_emoji' => 'E',
                'hero_url' => '/edge-templates/express-getting-started.svg',
            ],
        ];
    }

    /**
     * Shortlist shown inline on Edge create (Connect Git). Prefer
     * standalone public repos — skip monorepo tree paths that need a
     * {@code repo_root} before detection can succeed.
     *
     * @return list<array<string, mixed>>
     */
    public static function featuredForCreate(): array
    {
        $featured = [];
        foreach (['keel-workers', 'laravel-starter', 'nextjs-docs', 'rails-getting-started'] as $slug) {
            $template = self::find($slug);
            if ($template !== null) {
                $featured[] = $template;
            }
        }

        return $featured;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $template) {
            if (($template['slug'] ?? null) === $slug) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function allTags(): array
    {
        $tags = [];
        foreach (self::all() as $template) {
            foreach ((array) ($template['tags'] ?? []) as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        return array_values(array_unique($tags));
    }
}
