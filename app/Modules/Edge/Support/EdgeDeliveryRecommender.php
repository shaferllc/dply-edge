<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Modules\Edge\Services\Frameworks\EdgeFrameworkPresetRegistry;
use App\Modules\Edge\Services\Ssr\EdgeSsrFrameworkRegistry;

/**
 * Picks the delivery mode a repo should use from its detection plan, with a
 * one-line reason the create page shows. One place, so the prefill, the
 * "Recommended" badge and the deploy checks can't disagree:
 *
 *   PHP / Ruby / Node HTTP server   → container
 *   server-rendered JS framework    → hybrid (edge + your origin); Worker SSR
 *                                     stays opt-in (needs an adapter + plan)
 *   anything else that builds files → static
 */
final class EdgeDeliveryRecommender
{
    /** Frameworks with a Worker SSR adapter ({@see EdgeSsrFrameworkRegistry}). */
    private const WORKER_SSR_FRAMEWORKS = ['keel', 'next', 'sveltekit', 'astro', 'remix'];

    private const LABELS = [
        'laravel' => 'Laravel', 'symfony' => 'Symfony', 'php' => 'PHP', 'rails' => 'Rails', 'sinatra' => 'Sinatra', 'ruby' => 'Ruby',
        'nest' => 'NestJS', 'express' => 'Express', 'fastify' => 'Fastify', 'koa' => 'Koa', 'next' => 'Next.js', 'nuxt' => 'Nuxt',
        'sveltekit' => 'SvelteKit', 'remix' => 'Remix', 'astro' => 'Astro', 'keel' => 'Keel', 'hono' => 'Hono', 'vite' => 'Vite',
    ];

    /**
     * @param  array<string, mixed>  $plan
     * @return array{mode: string, reason: string}|null null when there is nothing detected to go on
     */
    public static function for(array $plan): ?array
    {
        if ($plan === [] || ! empty($plan['error']) || ! empty($plan['no_match']) || (empty($plan['runtime']) && empty($plan['framework']))) {
            return null;
        }

        $framework = strtolower((string) ($plan['framework'] ?? ''));
        $label = self::LABELS[$framework] ?? ucfirst($framework !== '' ? $framework : (string) ($plan['runtime'] ?? ''));

        if (EdgeEligibility::needsContainer($plan)) {
            return ['mode' => 'container', 'reason' => __(':framework is a server app, so it runs as a container.', ['framework' => $label])];
        }

        $serverRendered = EdgeSsrDetection::planLooksLikeSsr($plan)
            || EdgeFrameworkPresetRegistry::byDetectionPlan($plan)->runtimeMode === 'hybrid';

        // Hybrid, not Worker SSR: Worker SSR needs a Cloudflare adapter in the
        // repo (OpenNext etc.) and a paid plan, so it stays an explicit choice.
        if ($serverRendered) {
            return ['mode' => 'hybrid', 'reason' => in_array($framework, self::WORKER_SSR_FRAMEWORKS, true)
                ? __(':framework renders on the server: static assets from the edge, server routes from your origin. Worker SSR also works if the repo has an edge adapter.', ['framework' => $label])
                : __(':framework renders on the server: static assets from the edge, server routes from your origin.', ['framework' => $label])];
        }

        return ['mode' => 'static', 'reason' => __(':framework builds static files, served straight from the edge.', ['framework' => $label])];
    }
}
