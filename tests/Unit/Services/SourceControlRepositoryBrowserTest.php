<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SourceControlRepositoryBrowserTest;

use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use Illuminate\Support\Facades\Http;

test('it lists github repositories for a linked account', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'acme/hello-functions',
                'clone_url' => 'https://github.com/acme/hello-functions.git',
                'default_branch' => 'main',
            ],
        ], 200),
    ]);

    $browser = new SourceControlRepositoryBrowser;
    $account = new SocialAccount([
        'provider' => 'github',
        'access_token' => 'gho_test',
    ]);

    $repositories = $browser->repositoriesForAccount($account);

    expect($repositories)->toHaveCount(1);
    expect($repositories[0]['label'])->toBe('acme/hello-functions');
    expect($repositories[0]['url'])->toBe('https://github.com/acme/hello-functions.git');
    expect($repositories[0]['branch'])->toBe('main');
});
test('it builds an authenticated clone url for github', function () {
    $browser = new SourceControlRepositoryBrowser;
    $account = new SocialAccount([
        'provider' => 'github',
        'access_token' => 'gho_test',
    ]);

    $url = $browser->authenticatedCloneUrl($account, 'https://github.com/acme/hello-functions.git');

    expect($url)->toBe('https://x-access-token:gho_test@github.com/acme/hello-functions.git');
});

test('it lists github repositories using a personal access token', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'acme/secret-service',
                'clone_url' => 'https://github.com/acme/secret-service.git',
                'default_branch' => 'main',
            ],
        ], 200),
    ]);

    $browser = new SourceControlRepositoryBrowser;
    $pat = new GitProviderToken([
        'provider' => 'github',
        'access_token' => 'ghp_personal',
    ]);

    $repositories = $browser->repositoriesForAccount($pat);

    expect($repositories)->toHaveCount(1);
    expect($repositories[0]['url'])->toBe('https://github.com/acme/secret-service.git');
});

test('it routes gitlab API calls to the PAT base URL for self-hosted hosts', function () {
    Http::fake([
        'https://gitlab.acme.com/api/v4/projects*' => Http::response([], 200),
        '*' => Http::response('unexpected host', 500),
    ]);

    $browser = new SourceControlRepositoryBrowser;
    $pat = new GitProviderToken([
        'provider' => 'gitlab',
        'access_token' => 'glpat-secret',
        'api_base_url' => 'https://gitlab.acme.com',
    ]);

    $browser->repositoriesForAccount($pat);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://gitlab.acme.com/api/v4/projects'));
});

test('it pages past the first 100 github repositories', function () {
    // GitHub caps per_page at 100, so an account with more repos than that only
    // ever showed its first page in the picker. Two full pages + a short third
    // proves the loop follows through and stops on the short page.
    $page = fn (int $start, int $count): array => collect(range($start, $start + $count - 1))
        ->map(fn (int $i): array => [
            'full_name' => 'tshafer/repo-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'clone_url' => 'https://github.com/tshafer/repo-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'.git',
            'default_branch' => 'main',
        ])
        ->all();

    Http::fakeSequence()
        ->push($page(1, 100), 200)
        ->push($page(101, 100), 200)
        ->push($page(201, 7), 200);

    $browser = new SourceControlRepositoryBrowser;
    $account = new SocialAccount(['provider' => 'github', 'access_token' => 'gho_test']);

    $repositories = $browser->repositoriesForAccount($account);

    expect($repositories)->toHaveCount(207);
    expect(collect($repositories)->pluck('label'))
        ->toContain('tshafer/repo-207')
        ->toContain('tshafer/repo-150');

    Http::assertSentCount(3);
});

test('it stops paging github at the page cap', function () {
    // A never-ending full page must not turn one picker render into an
    // unbounded fan of API calls.
    $full = collect(range(1, 100))
        ->map(fn (int $i): array => [
            'full_name' => 'tshafer/repo-'.$i,
            'clone_url' => 'https://github.com/tshafer/repo-'.$i.'.git',
            'default_branch' => 'main',
        ])
        ->all();

    Http::fake(['https://api.github.com/user/repos*' => Http::response($full, 200)]);

    $browser = new SourceControlRepositoryBrowser;
    $account = new SocialAccount(['provider' => 'github', 'access_token' => 'gho_test']);

    $browser->repositoriesForAccount($account);

    Http::assertSentCount(10);
});

test('it reports a provider rejection instead of an empty repository list', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $browser = new SourceControlRepositoryBrowser;
    $pat = new GitProviderToken([
        'provider' => 'github',
        'access_token' => 'github_pat_dead',
    ]);

    expect($browser->repositoriesForAccount($pat))->toBe([])
        ->and($browser->repositoryError())->toContain('401')
        ->and($browser->repositoryError())->toContain('Bad credentials');
});
