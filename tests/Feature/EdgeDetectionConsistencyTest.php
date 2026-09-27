<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDetectionConsistencyTest;

use App\Jobs\DetectRepositoryRuntimeJob;
use App\Models\User;
use App\Modules\Edge\Livewire\Create;
use App\Modules\Edge\Services\RuntimeDetection\GitCloner;
use App\Modules\Edge\Support\EdgeDeliveryRecommender;
use App\Modules\Edge\Support\EdgeEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The GitHub API fast path and the clone path must reach the same plan for
 * the same files.
 *
 * @param  array<string, string>  $files
 * @return array{fast: array<string, mixed>, clone: array<string, mixed>}
 */
function detectBothWays(array $files): array
{
    $user = User::factory()->create();

    Http::fake(function ($request) use ($files) {
        $prefix = 'https://raw.githubusercontent.com/acme/app/main/';
        $path = str_starts_with($request->url(), $prefix) ? substr($request->url(), strlen($prefix)) : null;

        return $path !== null && isset($files[$path]) ? Http::response($files[$path]) : Http::response('', 404);
    });
    $fast = Livewire::actingAs($user)->test(Create::class)
        ->call('runDetection', 'https://github.com/acme/app.git', 'main')
        ->get('detectedPlan');

    app()->instance(GitCloner::class, new class($files) implements GitCloner
    {
        public function __construct(private array $files) {}

        public function shallowClone(string $url, string $branch, string $destination, array $env = []): void
        {
            foreach ($this->files as $path => $contents) {
                File::ensureDirectoryExists(dirname($destination.'/'.$path));
                File::put($destination.'/'.$path, $contents);
            }
        }
    });
    app()->call([new DetectRepositoryRuntimeJob('clone-key', 'https://github.com/acme/app.git', 'main'), 'handle']);

    return ['fast' => $fast, 'clone' => Cache::get('clone-key')['plan']];
}

test('both detection paths agree', function (array $files, string $mode, ?string $build, ?string $output) {
    ['fast' => $fast, 'clone' => $clone] = detectBothWays($files);

    foreach (['fast' => $fast, 'clone' => $clone] as $path => $plan) {
        expect(EdgeDeliveryRecommender::for($plan)['mode'] ?? null)->toBe($mode, "{$path} path mode")
            ->and($plan['build_command'])->toBe($build, "{$path} path build")
            ->and($plan['output_dir'])->toBe($output, "{$path} path output");
    }
    expect($fast['framework'])->toBe($clone['framework']);
})->with([
    // Worker SSR is available under the fake edge, so a Next server app is an "App".
    'next without export renders on the server' => [[
        'package.json' => json_encode(['dependencies' => ['next' => '15.0.0', 'react' => '19.0.0'], 'scripts' => ['build' => 'next build']]),
        'next.config.js' => 'module.exports = { reactStrictMode: true }',
    ], 'ssr', 'npm run build', 'out'],
    'next with output export is a static site' => [[
        'package.json' => json_encode(['dependencies' => ['next' => '15.0.0'], 'scripts' => ['build' => 'next build', 'start' => 'next start']]),
        'next.config.mjs' => "export default { output: 'export' }",
    ], 'static', 'npm run build', 'out'],
    'nuxt builds with generate to match its static output' => [[
        'package.json' => json_encode(['dependencies' => ['nuxt' => '3.12.0'], 'scripts' => ['build' => 'nuxt build', 'generate' => 'nuxt generate']]),
    ], 'static', 'npm run generate', '.output/public'],
    'remix is known to both paths' => [[
        'package.json' => json_encode(['dependencies' => ['@remix-run/node' => '2.0.0', '@remix-run/react' => '2.0.0'], 'scripts' => ['build' => 'remix vite:build', 'start' => 'remix-serve ./build/server/index.js']]),
    ], 'hybrid', 'npm run build', 'build/client'],
]);

test('a Jekyll site with a Gemfile is not a Ruby container on either path, and says it cannot build', function () {
    ['fast' => $fast, 'clone' => $clone] = detectBothWays([
        'Gemfile' => "source 'https://rubygems.org'\ngem 'jekyll', '~> 4.3'\n",
        '_config.yml' => "title: Blog\n",
        'index.html' => '<h1>hi</h1>',
    ]);

    foreach ([$fast, $clone] as $plan) {
        expect($plan['framework'])->toBe('jekyll')
            ->and(EdgeEligibility::needsContainer($plan))->toBeFalse()
            ->and(EdgeEligibility::evaluate($plan)['eligible'])->toBeFalse()
            ->and(EdgeEligibility::evaluate($plan)['message'])->toContain('can’t build Jekyll sites yet');
    }
});
