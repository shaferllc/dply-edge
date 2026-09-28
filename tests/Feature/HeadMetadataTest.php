<?php

declare(strict_types=1);

namespace Tests\Feature\HeadMetadataTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/** The <head> element only: inline SVG <title>s in the body don't count. */
function headOf(TestResponse $response): string
{
    preg_match('#<head>(.*?)</head>#s', (string) $response->getContent(), $m);

    return $m[1] ?? '';
}

function assertHead(TestResponse $response, string $title, bool $indexable): string
{
    $head = headOf($response);

    expect(substr_count($head, '<title'))->toBe(1)
        ->and($head)->toContain('<title>'.e($title).'</title>')
        ->and(substr_count($head, '<meta name="description"'))->toBe(1)
        ->and(substr_count($head, 'rel="canonical"'))->toBe(1)
        ->and(substr_count($head, '<meta name="robots"'))->toBe(1)
        ->and($head)->toContain('property="og:site_name" content="dply"');

    $indexable
        ? expect($head)->not->toContain('noindex')
        : expect($head)->toMatch('#<meta name="robots" content="(noindex[^"]*|none)"#');

    return $head;
}

function signedInOwner(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create(['name' => 'Acme']);
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

test('home has its exact title plus organization and website schemas', function (): void {
    $head = assertHead($this->get('/')->assertOk(), 'dply · Your whole app, deployed from Git', true);

    expect($head)->toContain('"@type":"Organization"')->toContain('"@type":"WebSite"');
});

test('pricing names the entry price and carries product and faq schemas', function (): void {
    $head = assertHead($this->get('/pricing')->assertOk(), 'Pricing · dply', true);

    expect($head)->toContain('Plans from $5/mo')
        ->toContain('"@type":"Product"')
        ->toContain('"@type":"Offer"')
        ->toContain('"@type":"FAQPage"');
});

test('a docs page takes its title, description, canonical and breadcrumbs from the page', function (): void {
    config(['docs.path' => $root = storage_path('framework/testing/head-docs')]);
    File::ensureDirectoryExists($root);
    File::put($root.'/nav.json', json_encode(['sections' => [
        ['title' => 'Start', 'pages' => [['slug' => 'intro', 'title' => 'Intro'], ['slug' => 'later', 'title' => 'Later']]],
    ]]));
    File::put($root.'/intro.md', "---\ntitle: \"Introduction\"\ndescription: \"What dply is.\"\n---\n\nBody.\n");

    try {
        $head = assertHead($this->get('/docs/intro')->assertOk(), 'Introduction · Docs · dply', true);
        expect($head)->toContain('content="What dply is."')
            ->toContain('rel="canonical" href="'.str_replace('http://', 'https://', route('docs.show', 'intro')).'"')
            ->toContain('"@type":"BreadcrumbList"');

        assertHead($this->get('/docs/later')->assertOk(), 'Later · Docs · dply', false);
    } finally {
        File::deleteDirectory($root);
    }
});

test('login is titled and indexable', function (): void {
    assertHead($this->get('/login')->assertOk(), 'Log in · dply', true);
});

test('the dashboard is titled and hidden from robots', function (): void {
    [$user] = signedInOwner();

    assertHead($this->actingAs($user)->get('/dashboard')->assertOk(), 'Dashboard · dply', false);
});

test('an org page names the organization', function (): void {
    [$user, $org] = signedInOwner();

    assertHead($this->actingAs($user)->get(route('organizations.show', $org))->assertOk(), 'Acme · dply', false);
});

test('a workspace page names the site and the section', function (): void {
    [$user, $org] = signedInOwner();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'slug' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['runtime_profile' => 'edge_web', 'edge' => ['source' => ['repo' => 'acme/web', 'branch' => 'main']]],
    ]);

    assertHead(
        $this->actingAs($user)->get(route('sites.show', ['site' => $site, 'section' => 'deploys']))->assertOk(),
        'Edge App · Deploys · dply',
        false,
    );
});

test('a 404 gets the error title and noindex, for full pages and wire:navigate', function (): void {
    assertHead($this->get('/definitely-not-a-page')->assertNotFound(), 'Page not found · dply', false);
    assertHead($this->get('/definitely-not-a-page', ['X-Livewire-Navigate' => '1'])->assertNotFound(), 'Page not found · dply', false);
});

test('no layout writes its own <title>: @head owns it', function (): void {
    // Self-contained pages keep theirs on purpose: billing-paused is served on
    // customers' domains, serverless-not-found on app hostnames, and
    // redis-unreachable renders outside the `web` stack; mail is not a page,
    // and the error-pages tab shows a customer's HTML as an example.
    $selfContained = [
        'livewire/sites/edge/workspace/error-pages.blade.php',
        'edge/billing-paused.blade.php',
        'errors/serverless-not-found.blade.php',
        'errors/redis-unreachable.blade.php',
        'vendor/mail/html/layout.blade.php',
    ];

    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => preg_match('#<head[\s>]#', $file->getContents()) === 1)
        ->reject(fn ($file) => in_array(str_replace('\\', '/', $file->getRelativePathname()), $selfContained, true))
        ->filter(fn ($file) => preg_match('#<title[\s>]#', $file->getContents()) === 1 || ! str_contains($file->getContents(), '@head'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
