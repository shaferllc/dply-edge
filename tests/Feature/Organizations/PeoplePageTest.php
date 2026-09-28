<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\PeoplePageTest;

use App\Livewire\Organizations\Members;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
| Members and Teams merged into one People page (org redesign 2026-09-27):
| /teams redirects to /members with a team selected, and every team action
| now lives on the Members component.
*/

beforeEach(function () {
    Queue::fake();
    Notification::fake();
    Organization::flushMemberRoleCache();

    $this->org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $this->owner = User::factory()->create();
    $this->org->users()->attach($this->owner->id, ['role' => 'owner']);
    $this->beta = $this->org->teams()->create(['name' => 'Beta', 'slug' => 'beta']);
    $this->alpha = $this->org->teams()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $this->actingAs($this->owner);
});

test('/teams lands on People with the first team, or the requested one, selected', function () {
    $this->get(route('organizations.teams', $this->org))
        ->assertRedirect(route('organizations.members', ['organization' => $this->org, 'team' => $this->alpha->id]));

    $this->get(route('organizations.teams', ['organization' => $this->org, 'team' => $this->beta->id]))
        ->assertRedirect(route('organizations.members', ['organization' => $this->org, 'team' => $this->beta->id]));
});

test('/teams with no teams lands on People unfiltered', function () {
    $this->org->teams()->delete();

    $this->get(route('organizations.teams', $this->org))
        ->assertRedirect(route('organizations.members', $this->org));
});

test('a selected team shows its detail; a foreign team id falls back to everyone', function () {
    $foreign = Team::create([
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'Secret squad',
        'slug' => 'secret-squad',
    ]);

    Livewire::withQueryParams(['team' => $this->alpha->id])
        ->test(Members::class, ['organization' => $this->org])
        ->assertSee('What this team hears about')
        ->assertSee('Nobody on this team yet.');

    Livewire::withQueryParams(['team' => $foreign->id])
        ->test(Members::class, ['organization' => $this->org])
        ->assertDontSee('Secret squad')
        ->assertDontSee('What this team hears about')
        ->assertSee($this->owner->email);
});

test('rename is an explicit edit, and delete clears the selection', function () {
    Livewire::test(Members::class, ['organization' => $this->org])
        ->call('select', '', (string) $this->alpha->id)
        ->call('startRename', (string) $this->alpha->id)
        ->set('renameName', '  Platform ')
        ->call('saveRename')
        ->assertHasNoErrors()
        ->assertSet('renamingTeamId', null)
        ->assertSee('Platform')
        ->call('deleteTeam', (string) $this->alpha->id)
        ->assertSet('team', '');

    expect(Team::find($this->alpha->id))->toBeNull();
});

test('create, add and invite-to-team work from the People page', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);
    $other = User::factory()->create(['email' => 'other@example.com']);
    $this->org->users()->attach($other->id, ['role' => 'deployer']);

    $c = Livewire::test(Members::class, ['organization' => $this->org])
        ->set('team_name', 'On-call')
        ->call('createTeam')
        ->assertHasNoErrors();
    $team = $this->org->teams()->where('name', 'On-call')->firstOrFail();
    $c->assertSet('team', (string) $team->id);

    $c->set('addMemberSelected.'.$team->id, (string) $member->id)
        ->call('addTeamMember', (string) $team->id)
        ->assertHasNoErrors()
        // An existing member invited by email is attached, not mailed.
        ->call('openTeamInviteModal', (string) $team->id)
        ->set('invite_email', 'OTHER@example.com')
        ->call('inviteToTeam')
        ->assertHasNoErrors();

    expect($team->users()->pluck('users.id')->all())->toEqualCanonicalizing([$member->id, $other->id])
        ->and($this->org->invitations()->count())->toBe(0);
});

test('non-admins see People read-only', function () {
    $viewer = User::factory()->create();
    $this->org->users()->attach($viewer->id, ['role' => 'member']);

    Livewire::actingAs($viewer)
        ->withQueryParams(['team' => $this->alpha->id])
        ->test(Members::class, ['organization' => $this->org])
        ->assertDontSee('Invite people')
        ->assertDontSee('Rename')
        ->set('team_name', 'Sneaky')
        ->call('createTeam')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test(Members::class, ['organization' => $this->org])
        ->call('deleteTeam', (string) $this->alpha->id)
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test(Members::class, ['organization' => $this->org])
        ->call('startRename', (string) $this->alpha->id)
        ->assertForbidden();
});

test('a team shows what it hears about: its channels and the events routed to them', function () {
    $channel = $this->alpha->notificationChannels()->create([
        'type' => 'email',
        'label' => 'Alpha inbox',
        'config' => ['email' => 'alpha@example.com'],
    ]);
    $channel->subscriptions()->create([
        'subscribable_type' => $this->org->getMorphClass(),
        'subscribable_id' => $this->org->id,
        'event_key' => 'edge.deploy.failed',
    ]);

    Livewire::withQueryParams(['team' => $this->alpha->id])
        ->test(Members::class, ['organization' => $this->org])
        ->assertSee('Alpha inbox')
        ->assertSee('alpha@example.com')
        ->assertSee('Edge deploy failed (action required)')
        ->assertDontSee('No channels yet')
        ->assertSeeHtml(route('teams.notification-channels', [$this->org, $this->alpha]));
});
