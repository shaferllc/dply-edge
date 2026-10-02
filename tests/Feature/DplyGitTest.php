<?php

declare(strict_types=1);

namespace Tests\Feature\DplyGitTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\DeployTriggers;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Actions\MoveSiteOffDplyGit;
use App\Modules\Edge\Console\PullDplyGitEventsCommand;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Jobs\MoveSiteToDplyGitJob;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Providers\Cloudflare\CloudflareArtifactsClient;
use App\Modules\SourceControl\Services\DplyGit;
use App\Modules\SourceControl\Services\GitCloneAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const ACCOUNT = 'acct123';

beforeEach(function () {
    config([
        'edge.cloudflare.account_id' => ACCOUNT,
        'edge.cloudflare.api_token' => 'cf-token',
        'edge.git.namespace' => 'dply',
        'edge.git.queue_id' => 'q1',
    ]);
});

test('parses dply Git remotes and nothing else', function () {
    expect(DplyGit::parse('https://acct123.artifacts.cloudflare.net/git/dply/01abc.git'))->toBe(['namespace' => 'dply', 'repo' => '01abc'])
        ->and(DplyGit::parse('https://github.com/acme/web.git'))->toBeNull()
        ->and(DplyGit::parse('http://acct123.artifacts.cloudflare.net/git/dply/01abc.git'))->toBeNull()
        ->and(DplyGit::parse('acme/web'))->toBeNull();
});

test('build clones get a read token as Basic auth that the log redaction masks', function () {
    Http::fake(['*/artifacts/namespaces/dply/tokens' => Http::response(['success' => true, 'result' => [
        'id' => 't1', 'plaintext' => 'art_v1_secretsecretsecret?expires=1', 'scope' => 'read', 'expires_at' => null,
    ]])]);
    $site = makeDplyGitSite();
    $remote = $site->edgeMeta()['source']['repo'];

    $env = app(GitCloneAuth::class)->envForSite($site, $remote);

    expect($env['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('x:art_v1_secretsecretsecret?expires=1'))
        ->and(GitCloneAuth::redact('fatal: art_v1_secretsecretsecret?expires=1 rejected', $env))->not->toContain('secretsecret');
    Http::assertSent(fn ($r) => $r['repo'] === strtolower($site->id) && $r['scope'] === 'read' && $r['ttl'] === 3600);
});

test('a push to the production branch deploys', function () {
    Queue::fake();
    $site = makeDplyGitSite();

    $out = app(PullDplyGitEventsCommand::class)->handleEvent(pushEvent($site, 'refs/heads/main'), ACCOUNT);

    expect($out['queued'])->toBe('redeploy');
    Queue::assertPushed(BuildEdgeSiteJob::class);
    expect($site->edgeDeployments()->first()->git_commit)->toBe(str_repeat('b', 40));
});

test('a push to another branch makes its preview, and deleting the branch tears it down', function () {
    Queue::fake();
    $site = makeDplyGitSite();
    $command = app(PullDplyGitEventsCommand::class);

    expect($command->handleEvent(pushEvent($site, 'refs/heads/agent-7'), ACCOUNT)['queued'])->toBe('preview');
    expect($command->handleEvent(pushEvent($site, 'refs/heads/agent-7', str_repeat('0', 40)), ACCOUNT)['queued'])->toBe('teardown');
    Queue::assertPushed(TeardownEdgeSiteJob::class);
});

test('events for another account, an unknown repo or a moved-away site are ignored', function () {
    Queue::fake();
    $site = makeDplyGitSite();
    $command = app(PullDplyGitEventsCommand::class);

    expect($command->handleEvent(pushEvent($site, 'refs/heads/main'), 'someone-else')['reason'])->toBe('wrong_account');

    $other = pushEvent($site, 'refs/heads/main');
    $other['source']['repoName'] = 'not-a-site';
    expect($command->handleEvent($other, ACCOUNT)['reason'])->toBe('unknown_repo');

    $site->mergeEdgeMeta(['source' => ['repo' => 'acme/web', 'branch' => 'main']]);
    $site->save();
    expect($command->handleEvent(pushEvent($site, 'refs/heads/main'), ACCOUNT)['reason'])->toBe('unknown_repo');
    Queue::assertNothingPushed();
});

test('queue messages are pulled, handled and acked', function () {
    Queue::fake();
    $site = makeDplyGitSite();
    Http::fake([
        '*/queues/q1/messages/pull' => Http::response(['success' => true, 'result' => ['messages' => [
            ['lease_id' => 'L1', 'attempts' => 1, 'body' => base64_encode(json_encode(pushEvent($site, 'refs/heads/main')))],
            ['lease_id' => 'L2', 'attempts' => 1, 'body' => 'not json'],
        ]]]),
        '*/queues/q1/messages/ack' => Http::response(['success' => true, 'result' => []]),
    ]);

    $this->artisan('dply:edge:git-events')->assertSuccessful();

    Queue::assertPushed(BuildEdgeSiteJob::class);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ack') && $r['acks'] === [['lease_id' => 'L1'], ['lease_id' => 'L2']]);
});

test('moving an app copies its branches, subscribes pushes and switches the remote', function () {
    $site = makeDplyGitSite(['repo' => 'https://gitlab.com/acme/web.git', 'deploy_on_push' => true]);
    $repo = strtolower($site->id);
    $remote = 'https://'.ACCOUNT.'.artifacts.cloudflare.net/git/dply/'.$repo.'.git';
    Http::fake([
        '*/artifacts/namespaces' => Http::response(['success' => false, 'errors' => [['message' => 'exists']]], 409),
        '*/artifacts/namespaces/dply/repos/'.$repo => Http::response(['success' => false, 'errors' => []], 404),
        '*/artifacts/namespaces/dply/repos' => Http::response(['success' => true, 'result' => ['name' => $repo, 'remote' => $remote, 'token' => 'x']]),
        '*/artifacts/namespaces/dply/tokens' => Http::response(['success' => true, 'result' => ['id' => 'w1', 'plaintext' => 'art_v1_writewritewrite', 'scope' => 'write']]),
        '*/artifacts/namespaces/dply/tokens/w1' => Http::response(['success' => true, 'result' => ['id' => 'w1']]),
        '*/event_subscriptions/subscriptions' => Http::response(['success' => true, 'result' => ['id' => 'sub1']]),
    ]);
    Process::fake();

    (new MoveSiteToDplyGitJob($site->id))->handle(app(DplyGit::class), app(GitCloneAuth::class));

    $site->refresh();
    expect($site->edgeMeta()['source']['repo'])->toBe($remote)
        ->and($site->edgeMeta()['source']['deploy_on_push'])->toBeTrue()
        ->and($site->edgeMeta()['dply_git'])->toMatchArray(['repo' => $repo, 'subscription_id' => 'sub1', 'moved_from' => 'https://gitlab.com/acme/web.git'])
        ->and($site->edgeMeta()['dply_git_move'])->toBeNull();
    Process::assertRan(fn ($p) => $p->command === ['git', 'clone', '--bare', 'https://gitlab.com/acme/web.git', storage_path('app/dply-git-move/'.$site->id)]);
    Process::assertRan(fn ($p) => in_array('refs/heads/*:refs/heads/*', $p->command, true) && in_array($remote, $p->command, true));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/event_subscriptions/subscriptions')
        && $r['source'] === ['type' => 'artifacts.repo', 'namespace' => 'dply', 'repo_name' => $repo] && $r['destination']['queue_id'] === 'q1');
});

test('a failed move leaves the app on its old repo and says why', function () {
    $site = makeDplyGitSite(['repo' => 'acme/web']);
    Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'Authentication error']]], 401)]);
    Process::fake();

    (new MoveSiteToDplyGitJob($site->id))->handle(app(DplyGit::class), app(GitCloneAuth::class));

    $site->refresh();
    expect($site->edgeMeta()['source']['repo'])->toBe('acme/web')
        ->and($site->edgeMeta()['dply_git_move']['status'])->toBe('failed')
        ->and($site->edgeMeta()['dply_git_move']['error'])->toContain('Authentication error');
});

test('Deploy triggers offers the move, then shows the remote and a push token', function () {
    Queue::fake();
    $site = makeDplyGitSite(['repo' => 'acme/web']);
    $this->actingAs($site->user);

    Livewire::test(DeployTriggers::class, ['server' => $site->server, 'site' => $site])
        ->assertSee('Move this app’s code to dply Git')
        ->call('openConfirmActionModal', 'moveToDplyGit', [], 'Move to dply Git', 'Copy?', 'Move')
        ->call('confirmActionModal')
        ->assertSee('Copying every branch and tag');
    Queue::assertPushed(MoveSiteToDplyGitJob::class);

    $site = makeDplyGitSite();
    $this->actingAs($site->user);
    Http::fake(['*/tokens' => Http::response(['success' => true, 'result' => ['id' => 'w', 'plaintext' => 'art_v1_pushpushpush', 'scope' => 'write']])]);
    Livewire::test(DeployTriggers::class, ['server' => $site->server, 'site' => $site])
        ->assertSee($site->edgeMeta()['source']['repo'])
        ->assertDontSee('GitHub isn’t connected')
        ->call('createDplyGitToken')
        ->assertSee('art_v1_pushpushpush')
        ->assertSee('git push dply main');
});

test('revoke all push tokens revokes every active token on the repo', function () {
    $site = makeDplyGitSite();
    $repo = strtolower($site->id);
    Http::fake([
        '*/repos/'.$repo.'/tokens*' => Http::response(['success' => true, 'result' => [['id' => 'a'], ['id' => 'b']]]),
        '*/tokens/*' => Http::response(['success' => true, 'result' => []]),
    ]);

    expect(app(DplyGit::class)->revokeAllForSite($site))->toBe(2);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/tokens/b'));
});

test('moving back points the app at the repo it came from and stops push events', function () {
    Http::fake(['*/event_subscriptions/subscriptions/sub1' => Http::response(['success' => true, 'result' => []])]);
    $site = makeDplyGitSite(['deploy_on_push' => false]);
    $site->mergeEdgeMeta(['dply_git' => ['subscription_id' => 'sub1', 'moved_from' => 'https://gitlab.com/acme/web.git', 'moved_at' => now()->toIso8601String()]]);
    $site->save();

    $result = (new MoveSiteOffDplyGit)->handle($site, $site->user);

    $site->refresh();
    expect($result)->toBe(['repo' => 'https://gitlab.com/acme/web.git', 'webhook' => null])
        ->and($site->edgeMeta()['source']['repo'])->toBe('https://gitlab.com/acme/web.git')
        ->and($site->edgeMeta()['source']['deploy_on_push'])->toBeFalse()
        ->and($site->edgeMeta()['dply_git'])->toBeNull()
        ->and(DplyGit::siteUses($site))->toBeFalse();
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/subscriptions/sub1'));
});

test('the move-back confirm warns when dply Git has newer pushes', function () {
    $site = makeDplyGitSite();
    $site->mergeEdgeMeta(['dply_git' => ['moved_from' => 'acme/web', 'moved_at' => now()->subDay()->toIso8601String()]]);
    $site->save();
    Http::fake(['*/repos/*' => Http::response(['success' => true, 'result' => ['remote' => 'x', 'last_push_at' => now()->toIso8601String()]])]);
    $this->actingAs($site->user);

    Livewire::test(DeployTriggers::class, ['server' => $site->server, 'site' => $site])
        ->assertSee('Move back to acme/web')
        ->call('confirmMoveOffDplyGit')
        ->assertSee('Someone pushed to dply Git after the move');
});

test('queue bodies decode from JSON, base64 JSON or arrays', function () {
    expect(CloudflareArtifactsClient::decodeBody('{"a":1}'))->toBe(['a' => 1])
        ->and(CloudflareArtifactsClient::decodeBody(base64_encode('{"a":1}')))->toBe(['a' => 1])
        ->and(CloudflareArtifactsClient::decodeBody(['a' => 1]))->toBe(['a' => 1]);
});

/**
 * @param  array<string, mixed>  $source  overrides; default is a dply Git remote for the site
 */
function makeDplyGitSite(array $source = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Agents',
        'slug' => 'agents',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['runtime_profile' => 'edge_web', 'edge' => [
            'routing' => ['hostname' => 'agents.dply.host'],
            'live_url' => 'https://agents.dply.host',
        ]],
    ]);
    $site->mergeEdgeMeta(['source' => array_merge([
        'repo' => 'https://'.ACCOUNT.'.artifacts.cloudflare.net/git/dply/'.strtolower($site->id).'.git',
        'branch' => 'main',
        'deploy_on_push' => true,
    ], $source)]);
    $site->save();

    return $site;
}

/**
 * @return array<string, mixed>
 */
function pushEvent(Site $site, string $ref, ?string $after = null): array
{
    return [
        'type' => 'cf.artifacts.repo.pushed',
        'source' => ['type' => 'artifacts.repo', 'namespace' => 'dply', 'repoName' => strtolower($site->id)],
        'payload' => ['ref' => $ref, 'before' => str_repeat('a', 40), 'after' => $after ?? str_repeat('b', 40), 'commits' => []],
        'metadata' => ['accountId' => ACCOUNT],
    ];
}
