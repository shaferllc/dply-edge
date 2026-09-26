<?php

declare(strict_types=1);

namespace Tests\Feature\ContainerProviderCredentialsTest;

use App\Livewire\Credentials\AddProviderCredentialModal;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('aws app runner panel hidden when provider disabled', function () {
    config(['server_providers.enabled.aws_app_runner' => false]);
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', $org));

    $response->assertOk()->assertDontSee('AWS App Runner');
});

test('store aws app runner credential', function () {
    config(['server_providers.enabled.aws_app_runner' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(AddProviderCredentialModal::class)
        ->call('openModal', 'aws_app_runner')
        ->set('aws_app_runner_name', 'AppRunner US')
        ->set('aws_app_runner_access_key_id', 'AKIA1234567890')
        ->set('aws_app_runner_secret_access_key', 'verysecret')
        ->set('aws_app_runner_region', 'us-west-2')
        ->set('aws_app_runner_github_connection_arn', 'arn:aws:apprunner:us-west-2:123456789012:connection/github/abc')
        ->set('aws_app_runner_access_role_arn', 'arn:aws:iam::123456789012:role/AppRunnerECRAccessRole')
        ->call('storeAwsAppRunner');

    $cred = ProviderCredential::query()
        ->where('user_id', $user->id)
        ->where('provider', 'aws_app_runner')
        ->first();
    expect($cred)->not->toBeNull();
    expect($cred->name)->toBe('AppRunner US');
    expect($cred->credentials['access_key_id'])->toBe('AKIA1234567890');
    expect($cred->credentials['region'])->toBe('us-west-2');
    expect($cred->credentials['github_connection_arn'])->toBe('arn:aws:apprunner:us-west-2:123456789012:connection/github/abc');
    expect($cred->credentials['access_role_arn'])->toBe('arn:aws:iam::123456789012:role/AppRunnerECRAccessRole');
});

test('store aws app runner rejects invalid github connection arn', function () {
    config(['server_providers.enabled.aws_app_runner' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(AddProviderCredentialModal::class)
        ->call('openModal', 'aws_app_runner')
        ->set('aws_app_runner_access_key_id', 'AKIA1234567890')
        ->set('aws_app_runner_secret_access_key', 'verysecret')
        ->set('aws_app_runner_region', 'us-east-1')
        ->set('aws_app_runner_github_connection_arn', 'not-an-arn')
        ->call('storeAwsAppRunner')
        ->assertHasErrors(['aws_app_runner_github_connection_arn']);

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('aws app runner validates required fields', function () {
    config(['server_providers.enabled.aws_app_runner' => true]);
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(AddProviderCredentialModal::class)
        ->call('openModal', 'aws_app_runner')
        ->call('storeAwsAppRunner')
        ->assertHasErrors(['aws_app_runner_access_key_id', 'aws_app_runner_secret_access_key']);

    $this->assertDatabaseCount('provider_credentials', 0);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
