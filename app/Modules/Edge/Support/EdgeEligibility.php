<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Modules\Edge\Services\Frameworks\EdgeFrameworkPresetRegistry;

/**
 * Decides whether a runtime-detection plan belongs on dply Edge
 * (JS/static/SSG + hybrid JS SSR) vs Cloud / BYO for long-running apps.
 *
 * Unknown / empty plans stay eligible so operators can still deploy with
 * manual build settings; we only hard-block when detection clearly says
 * the repo is a non-Edge workload.
 */
final class EdgeEligibility
{
    /**
     * Framework slugs Edge can build/serve (presets + common aliases).
     *
     * @var list<string>
     */
    private const EXTRA_ALLOWED_FRAMEWORKS = [
        'nextjs',
        'node_generic',
        'node',
        'vitepress',
        'docusaurus',
        'react',
        'vue',
        'svelte',
    ];

    /**
     * Frameworks that are never Edge workloads.
     *
     * @var list<string>
     */
    private const BLOCKED_FRAMEWORKS = [
        'laravel',
        'symfony',
        'wordpress',
        'rails',
        'sinatra',
        'django',
        'flask',
        'fastapi',
        'nest',
        'spring',
        'express',
    ];

    /**
     * Language runtimes that imply a long-running server (unless an
     * Edge-allowed SSG framework was also detected, e.g. jekyll/hugo
     * via the static detector).
     *
     * @var list<string>
     */
    private const BLOCKED_RUNTIMES = [
        'php',
        'ruby',
        'python',
        'go',
        'java',
        'dotnet',
        'rust',
    ];

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function isEligible(array $plan): bool
    {
        return self::evaluate($plan)['eligible'];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{
     *     eligible: bool,
     *     message: ?string,
     *     alternative_route: ?string,
     *     alternative_label: ?string,
     * }
     */
    public static function evaluate(array $plan): array
    {
        $allow = [
            'eligible' => true,
            'message' => null,
            'alternative_route' => null,
            'alternative_label' => null,
        ];

        if ($plan === [] || ! empty($plan['error']) || ! empty($plan['no_match'])) {
            return $allow;
        }

        // Framework / tooling monorepo roots (withastro/astro, next.js, …)
        // often look like Node/Astro plans but never emit a site dist/.
        if (! empty($plan['not_a_site'])) {
            return [
                'eligible' => false,
                'message' => __(
                    'This repository looks like a framework or monorepo package root, not a single Edge site. Pick an app package directory (for example examples/basics or apps/web), or point Edge at a project that produces a static build output.',
                ),
                'alternative_route' => null,
                'alternative_label' => null,
            ];
        }

        $framework = self::normalizeFramework((string) ($plan['framework'] ?? ''));
        $runtime = strtolower(trim((string) ($plan['runtime'] ?? '')));

        if ($framework !== '' && self::isAllowedFramework($framework)) {
            return $allow;
        }

        if ($framework !== '' && in_array($framework, self::BLOCKED_FRAMEWORKS, true)) {
            return self::reject($framework, $runtime);
        }

        if (in_array($runtime, ['node', 'static'], true)) {
            return $allow;
        }

        if ($runtime !== '' && in_array($runtime, self::BLOCKED_RUNTIMES, true)) {
            return self::reject($framework !== '' ? $framework : $runtime, $runtime);
        }

        if ($runtime !== '' && ! in_array($runtime, ['node', 'static'], true)) {
            return self::reject($framework !== '' ? $framework : $runtime, $runtime);
        }

        return $allow;
    }

    private static function normalizeFramework(string $framework): string
    {
        $framework = strtolower(trim($framework));

        return match ($framework) {
            'nextjs' => 'next',
            default => $framework,
        };
    }

    private static function isAllowedFramework(string $framework): bool
    {
        if (EdgeFrameworkPresetRegistry::find($framework) !== null) {
            return true;
        }

        return in_array($framework, self::EXTRA_ALLOWED_FRAMEWORKS, true);
    }

    /**
     * @return array{
     *     eligible: bool,
     *     message: string,
     *     alternative_route: string,
     *     alternative_label: string,
     * }
     */
    private static function reject(string $label, string $runtime): array
    {
        $display = $label !== '' ? $label : ($runtime !== '' ? $runtime : 'this');

        return [
            'eligible' => false,
            'message' => __(
                'This repository looks like a :stack app. Edge is for JavaScript static/SSG sites (and hybrid JS SSR). Use a BYO server for this workload.',
                ['stack' => $display],
            ),
            'alternative_route' => 'servers.create',
            'alternative_label' => __('Create a server'),
        ];
    }
}
