<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\EdgePreviewComments;
use App\Models\EdgeDeployment;
use App\Models\EdgePreviewComment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Deploy contract promote-gate is out of scope for these review-hub assertions.
    config(['deploy_contract.require_for_promote' => false]);
});

test('preview review hub renders for edge preview site', function () {
    [$user, $server, $parent, $preview] = previewReviewFixtures();

    Livewire::actingAs($user)
        ->test(EdgePreviewComments::class, ['server' => $server, 'site' => $preview])
        ->assertOk()
        ->assertSee('Preview review hub')
        ->assertSee('PR #42')
        ->assertSee('Review threads');
});

test('preview review hub supports threaded replies', function () {
    [$user, $server, $parent, $preview] = previewReviewFixtures();

    $thread = EdgePreviewComment::query()->create([
        'organization_id' => $preview->organization_id,
        'site_id' => $preview->id,
        'created_by_user_id' => $user->id,
        'url_path' => '/pricing',
        'body' => 'Adjust hero spacing',
    ]);

    Livewire::actingAs($user)
        ->test(EdgePreviewComments::class, ['server' => $server, 'site' => $preview])
        ->call('startReply', (string) $thread->id)
        ->set('replyBody', 'Will tweak in next push')
        ->call('submitReply')
        ->assertHasNoErrors();

    expect(EdgePreviewComment::query()->where('parent_id', $thread->id)->count())->toBe(1);
});

test('promote is blocked when open review comments remain', function () {
    config(['edge.preview_review.block_open_comments' => true]);

    [$user, $server, $parent, $preview] = previewReviewFixtures();

    EdgePreviewComment::query()->create([
        'organization_id' => $preview->organization_id,
        'site_id' => $preview->id,
        'created_by_user_id' => $user->id,
        'url_path' => '/',
        'body' => 'Fix footer',
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $preview->id,
        'organization_id' => $preview->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_commit' => 'abc1234567890123456789012345678901234567890',
        'git_branch' => 'feature/review',
        'storage_prefix' => 'edge/preview/live',
        'published_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(EdgePreviewComments::class, ['server' => $server, 'site' => $preview])
        ->call('confirmPromoteToProduction')
        ->assertSet('showConfirmActionModal', false);
});

test('approval unlocks promote when required', function () {
    config([
        'edge.preview_review.require_approval' => true,
        'edge.preview_review.min_approvals' => 1,
        'edge.preview_review.block_open_comments' => true,
    ]);

    [$user, $server, $parent, $preview] = previewReviewFixtures();

    EdgeDeployment::query()->create([
        'site_id' => $preview->id,
        'organization_id' => $preview->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_commit' => 'abc1234567890123456789012345678901234567890',
        'git_branch' => 'feature/review',
        'storage_prefix' => 'edge/preview/live',
        'published_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(EdgePreviewComments::class, ['server' => $server, 'site' => $preview])
        ->call('approveReview')
        ->call('confirmPromoteToProduction')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'promoteToProduction');
});

/**
 * @return array{0: User, 1: Server, 2: Site, 3: Site}
 */
function previewReviewFixtures(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $parent = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/storefront', 'branch' => 'main'],
            ],
        ],
    ]);

    $preview = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/review',
                'preview_pr_number' => 42,
                'preview_head_sha' => 'abc1234567890123456789012345678901234567890',
                'live_url' => 'https://preview-review.on-dply.site',
            ],
        ],
    ]);

    return [$user, $server, $parent, $preview];
}
