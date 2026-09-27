<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\OrganizationActivityTest;

use App\Livewire\Organizations\Activity;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'owner']);
});

test('rows expand by ulid to show the recorded diff', function () {
    $log = AuditLog::create([
        'organization_id' => $this->org->id,
        'user_id' => $this->admin->id,
        'action' => 'team.renamed',
        'old_values' => ['name' => 'before-name'],
        'new_values' => ['name' => 'after-name'],
    ]);

    Livewire::actingAs($this->admin)->test(Activity::class, ['organization' => $this->org])
        ->assertSee("toggleRow('{$log->id}')", false)
        ->assertDontSee('"before-name"', false)
        ->call('toggleRow', $log->id)
        ->assertSee('&quot;before-name&quot;', false)
        ->call('toggleRow', $log->id)
        ->assertDontSee('&quot;before-name&quot;', false);
});

test('the unfiltered total reuses the family count and filters still count their own rows', function () {
    foreach (['team.created', 'team.renamed', 'billing.checkout_started'] as $action) {
        AuditLog::create(['organization_id' => $this->org->id, 'action' => $action]);
    }
    AuditLog::create(['organization_id' => Organization::factory()->create()->id, 'action' => 'team.created']);

    $component = Livewire::actingAs($this->admin)->test(Activity::class, ['organization' => $this->org]);
    expect($component->instance()->auditLogs->total())->toBe(3);

    $component->call('setFamily', 'team');
    expect($component->instance()->auditLogs->total())->toBe(2);

    $component->call('setFamily', 'billing');
    expect($component->instance()->auditLogs->total())->toBe(1);
});

test('search matches the action and recorded names without erroring', function () {
    AuditLog::create(['organization_id' => $this->org->id, 'action' => 'team.renamed', 'new_values' => ['name' => 'Platform Crew']]);
    AuditLog::create(['organization_id' => $this->org->id, 'action' => 'member.invited', 'new_values' => ['email' => 'x@example.com']]);

    Livewire::actingAs($this->admin)->test(Activity::class, ['organization' => $this->org])
        ->set('search', 'platform crew')
        ->assertOk()
        ->assertSee('team.renamed')
        ->assertDontSee('member.invited')
        ->set('search', 'INVITED')
        ->assertSee('member.invited');
});

test('filters offer only live families, and VM-era rows fall under other', function () {
    foreach (['server.created', 'backup.schedule.created', 'database.created', 'queue.created', 'site.edge.created', 'impersonation.started'] as $action) {
        AuditLog::create(['organization_id' => $this->org->id, 'action' => $action]);
    }

    $component = Livewire::actingAs($this->admin)->test(Activity::class, ['organization' => $this->org])
        ->assertDontSee('Servers')->assertDontSee('Backups')->assertDontSee('Insights');
    expect($component->instance()->familyTotals)->toMatchArray(['other' => 2, 'resources' => 2, 'edge' => 1, 'security' => 1]);

    $component->call('setFamily', 'server');
    expect($component->instance()->auditLogs->total())->toBe(6);

    $component->call('setFamily', 'other');
    expect($component->instance()->auditLogs->total())->toBe(2);
});
