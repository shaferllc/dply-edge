<?php

declare(strict_types=1);

namespace Tests\Feature\AwsAppRunnerServiceTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Providers\Services\AwsAppRunnerService;
use Aws\AppRunner\AppRunnerClient;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

test('constructor throws when credentials missing', function () {
    $cred = credentialWithoutKey();

    $this->expectException(\InvalidArgumentException::class);
    new AwsAppRunnerService($cred);
});
test('create service returns arn and url', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('createService')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['ServiceName'] === 'api-acme'
                && $args['SourceConfiguration']['ImageRepository']['ImageIdentifier'] === 'public.ecr.aws/acme/api:v1'
                && $args['SourceConfiguration']['ImageRepository']['ImageRepositoryType'] === 'ECR_PUBLIC'
                && $args['SourceConfiguration']['ImageRepository']['ImageConfiguration']['Port'] === '8080'
                && $args['SourceConfiguration']['ImageRepository']['ImageConfiguration']['RuntimeEnvironmentVariables'] === ['APP_ENV' => 'production'];
        }))
        ->andReturn(new Result([
            'Service' => [
                'ServiceArn' => 'arn:aws:apprunner:us-east-1:1234:service/api-acme/abc',
                'ServiceUrl' => 'api-acme.us-east-1.awsapprunner.com',
            ],
        ]));

    $service = service($client);
    $result = $service->createService(
        serviceName: 'api-acme',
        image: 'public.ecr.aws/acme/api:v1',
        port: 8080,
        envVars: ['APP_ENV' => 'production'],
    );

    expect($result['service_arn'])->toBe('arn:aws:apprunner:us-east-1:1234:service/api-acme/abc');
    expect($result['service_url'])->toBe('https://api-acme.us-east-1.awsapprunner.com');
});
test('create service classifies private ecr image', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('createService')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['SourceConfiguration']['ImageRepository']['ImageRepositoryType'] === 'ECR';
        }))
        ->andReturn(new Result(['Service' => ['ServiceArn' => 'arn:aws:apprunner:test']]));

    service($client)->createService(
        'api',
        '1234.dkr.ecr.us-east-1.amazonaws.com/acme/api:v1',
        8080,
    );
});
test('describe service returns payload', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('describeService')
        ->with(['ServiceArn' => 'arn:test'])
        ->andReturn(new Result(['Service' => ['Status' => 'RUNNING']]));

    expect(service($client)->describeService('arn:test'))->toBe(['Status' => 'RUNNING']);
});
test('start deployment returns operation id', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('startDeployment')
        ->with(['ServiceArn' => 'arn:test'])
        ->andReturn(new Result(['OperationId' => 'op-12345']));

    expect(service($client)->startDeployment('arn:test'))->toBe(['operation_id' => 'op-12345']);
});
test('update image calls update service', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('updateService')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['ServiceArn'] === 'arn:test'
                && $args['SourceConfiguration']['ImageRepository']['ImageIdentifier'] === 'public.ecr.aws/acme/api:v2';
        }));

    service($client)->updateImage('arn:test', 'public.ecr.aws/acme/api:v2', 8080);
});
test('delete service calls delete', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('deleteService')
        ->once()
        ->with(['ServiceArn' => 'arn:test']);

    service($client)->deleteService('arn:test');
});
test('list services returns array', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('listServices')
        ->andReturn(new Result(['ServiceSummaryList' => [
            ['ServiceArn' => 'arn:1', 'ServiceName' => 'a'],
            ['ServiceArn' => 'arn:2', 'ServiceName' => 'b'],
        ]]));

    $list = service($client)->listServices();
    expect($list)->toHaveCount(2);
    expect($list[0]['ServiceName'])->toBe('a');
});
test('validate credentials calls list services', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('listServices')
        ->once()
        ->with(['MaxResults' => 1])
        ->andReturn(new Result(['ServiceSummaryList' => []]));

    service($client)->validateCredentials();
});
test('get regions returns known apprunner set', function () {
    $regions = AwsAppRunnerService::getRegions();
    $slugs = array_column($regions, 'slug');
    expect($slugs)->toContain('us-east-1');
    expect($slugs)->toContain('eu-west-1');
    expect($slugs)->toContain('ap-northeast-1');
});
test('parse service arn extracts name and id', function () {
    expect(AwsAppRunnerService::parseServiceArn('arn:aws:apprunner:us-east-1:1234:service/api-acme/abc'))
        ->toBe(['name' => 'api-acme', 'id' => 'abc']);
    expect(AwsAppRunnerService::parseServiceArn('bogus'))->toBeNull();
});
test('list operations returns normalized rows', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('listOperations')
        ->once()
        ->with(['ServiceArn' => 'arn:test', 'MaxResults' => 10])
        ->andReturn(new Result([
            'OperationSummaryList' => [[
                'Id' => 'op-1',
                'Type' => 'START_DEPLOYMENT',
                'Status' => 'SUCCEEDED',
                'StartedAt' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                'EndedAt' => new \DateTimeImmutable('2026-01-01T00:05:00Z'),
            ]],
        ]));

    $ops = service($client)->listOperations('arn:test');
    expect($ops)->toHaveCount(1);
    expect($ops[0]['id'])->toBe('op-1');
    expect($ops[0]['type'])->toBe('START_DEPLOYMENT');
    expect($ops[0]['status'])->toBe('SUCCEEDED');
});
/*
 | App Runner has no stop/cancel-deployment operation — the SDK exposes
 | startDeployment / pauseService / resumeService only — so the method reports
 | "could not cancel" without touching the client.
 */
test('stop deployment reports failure without calling the api', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldNotReceive('stopDeployment');

    expect(service($client)->stopDeployment('arn:test', 'op-1'))->toBeFalse();
});
test('get service metrics maps cloudwatch series', function () {
    $cw = Mockery::mock(\Aws\CloudWatch\CloudWatchClient::class);
    $cw->shouldReceive('getMetricData')
        ->once()
        ->andReturn(new Result([
            'MetricDataResults' => [
                [
                    'Id' => 'cpu',
                    'Timestamps' => [new \DateTimeImmutable('@1700000000')],
                    'Values' => [12.5],
                ],
                [
                    'Id' => 'memory',
                    'Timestamps' => [new \DateTimeImmutable('@1700000000')],
                    'Values' => [40.0],
                ],
                [
                    'Id' => 'requests',
                    'Timestamps' => [new \DateTimeImmutable('@1700000000')],
                    'Values' => [3.0],
                ],
            ],
        ]));

    $series = service(Mockery::mock(AppRunnerClient::class))
        ->withCloudWatchClient($cw)
        ->getServiceMetrics('arn:aws:apprunner:us-east-1:1:service/edge/x', 1700000000 - 3600, 1700000000);

    expect($series['cpu'])->toBe([['t' => 1700000000, 'v' => 12.5]]);
    expect($series['memory'][0]['v'])->toBe(40.0);
    expect($series['requests'][0]['v'])->toBe(3.0);
});
test('create service from source uses code repository', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('createService')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            $code = $args['SourceConfiguration']['CodeRepository'] ?? null;
            $auth = $args['SourceConfiguration']['AuthenticationConfiguration'] ?? null;

            return is_array($code)
                && $code['RepositoryUrl'] === 'https://github.com/acme/api'
                && $code['SourceCodeVersion']['Type'] === 'BRANCH'
                && $code['SourceCodeVersion']['Value'] === 'main'
                && $code['CodeConfiguration']['CodeConfigurationValues']['Runtime'] === 'DOCKER'
                && $code['CodeConfiguration']['CodeConfigurationValues']['Port'] === '8080'
                && ($args['SourceConfiguration']['AutoDeploymentsEnabled'] ?? null) === true
                && is_array($auth)
                && $auth['ConnectionArn'] === 'arn:aws:apprunner:us-east-1:1234:connection/dply-gh/xyz';
        }))
        ->andReturn(new Result([
            'Service' => [
                'ServiceArn' => 'arn:aws:apprunner:us-east-1:1234:service/api-acme/src',
                'ServiceUrl' => 'api-acme.awsapprunner.com',
            ],
        ]));

    $service = service($client);
    $result = $service->createServiceFromSource(
        serviceName: 'api-acme',
        repositoryUrl: 'https://github.com/acme/api',
        branch: 'main',
        connectionArn: 'arn:aws:apprunner:us-east-1:1234:connection/dply-gh/xyz',
        port: 8080,
        dockerfilePath: 'Dockerfile',
    );

    expect($result['service_arn'])->toStartWith('arn:aws:apprunner:');
    expect($result['service_url'])->toBe('https://api-acme.awsapprunner.com');
});
function service(AppRunnerClient $client): AwsAppRunnerService
{
    return (new AwsAppRunnerService(credential()))->withClient($client);
}
function credential(): ProviderCredential
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws_app_runner',
        'name' => 'Test',
        'credentials' => [
            'access_key_id' => 'AKIAFAKE',
            'secret_access_key' => 'fake-secret',
            'region' => 'us-east-1',
        ],
    ]);
}
function credentialWithoutKey(): ProviderCredential
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws_app_runner',
        'name' => 'Test',
        'credentials' => ['access_key_id' => '', 'secret_access_key' => ''],
    ]);
}
