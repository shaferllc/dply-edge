<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use Aws\AppRunner\AppRunnerClient;
use Aws\CloudWatch\CloudWatchClient;
use Aws\CloudWatchLogs\CloudWatchLogsClient;

/**
 * Thin wrapper around AWS App Runner. Used by the dply cloud layer
 * to provision/redeploy/teardown container apps on AWS.
 *
 * Why App Runner over ECS Fargate or Lightsail Containers:
 * - cheapest managed container path on AWS (no LB / no NAT
 *   gateway / no Fargate task minimums for spiky workloads)
 * - built-in HTTPS + custom domain support
 * - public ECR / Docker Hub image support without extra IAM
 * - auto-scaling out of the box
 *
 * AWS region is set at credential-creation time (see
 * ManagesProviderCredentials::storeAwsAppRunner) and threaded
 * through to the client constructor here.
 *
 * Observability (CPU / memory / requests + application logs) is
 * read from CloudWatch under the AWS/AppRunner namespace and
 * /aws/apprunner/{name}/{id}/application log group.
 */
class AwsAppRunnerService
{
    protected AppRunnerClient $client;

    protected ?CloudWatchClient $cloudWatch = null;

    protected ?CloudWatchLogsClient $cloudWatchLogs = null;

    protected string $region;

    /** @var array{key: string, secret: string} */
    protected array $awsCredentials;

    public function __construct(ProviderCredential $credential, ?string $region = null)
    {
        $creds = $credential->credentials ?? [];
        $key = (string) ($creds['access_key_id'] ?? '');
        $secret = (string) ($creds['secret_access_key'] ?? '');
        if ($key === '' || $secret === '') {
            throw new \InvalidArgumentException('AWS access key ID and secret access key are required.');
        }
        $this->region = $region ?? (string) ($creds['region'] ?? 'us-east-1');
        $this->awsCredentials = [
            'key' => $key,
            'secret' => $secret,
        ];
        $this->client = new AppRunnerClient([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $this->awsCredentials,
        ]);
    }

    /**
     * Create an App Runner service from a public container image.
     *
     * Private ECR / DockerHub auth is supported via $authConfigArn —
     * pass null for public images. The 'cpu' and 'memory' values
     * are App Runner's standard slugs (e.g. 0.25 vCPU / 0.5 GB =
     * "256" / "512").
     *
     * @param  array<string, mixed>  $envVars
     * @return array{service_arn: string, service_url: ?string}
     */
    public function createService(
        string $serviceName,
        string $image,
        int $port,
        array $envVars = [],
        ?string $authConfigArn = null,
        string $cpu = '256',
        string $memory = '512',
    ): array {
        $sourceConfig = [
            'ImageRepository' => [
                'ImageIdentifier' => $image,
                'ImageRepositoryType' => str_starts_with($image, 'public.ecr.aws/')
                    ? 'ECR_PUBLIC'
                    : (str_contains($image, '.dkr.ecr.') ? 'ECR' : 'ECR_PUBLIC'),
                'ImageConfiguration' => [
                    'Port' => (string) $port,
                    'RuntimeEnvironmentVariables' => $envVars,
                ],
            ],
            'AutoDeploymentsEnabled' => false,
        ];
        if ($authConfigArn !== null) {
            $sourceConfig['AuthenticationConfiguration'] = ['AccessRoleArn' => $authConfigArn];
        }

        $result = $this->client->createService([
            'ServiceName' => $serviceName,
            'SourceConfiguration' => $sourceConfig,
            'InstanceConfiguration' => [
                'Cpu' => $cpu,
                'Memory' => $memory,
            ],
        ]);

        $service = $result['Service'] ?? [];

        return [
            'service_arn' => (string) ($service['ServiceArn'] ?? ''),
            'service_url' => isset($service['ServiceUrl']) ? 'https://'.$service['ServiceUrl'] : null,
        ];
    }

    /**
     * Create an App Runner service from a GitHub repo. App Runner
     * does the build + deploy + auto-redeploy on push — same Vercel-
     * style source mode DO App Platform offers, just over the
     * CODE_REPOSITORY source type.
     *
     * `connectionArn` is an App Runner GitHub connection ARN — the
     * operator authorizes a GitHub App once per AWS account; the
     * connection ARN is what we keep on the ProviderCredential.
     *
     * @param  array<string, mixed>  $envVars
     * @param  array<string, mixed>  $buildEnvVars
     * @return array{service_arn: string, service_url: ?string}
     */
    public function createServiceFromSource(
        string $serviceName,
        string $repositoryUrl,
        string $branch,
        string $connectionArn,
        int $port,
        array $envVars = [],
        array $buildEnvVars = [],
        ?string $dockerfilePath = null,
        bool $autoDeploy = true,
        string $cpu = '256',
        string $memory = '512',
    ): array {
        $codeConfigurationValues = [
            'Runtime' => $dockerfilePath !== null && $dockerfilePath !== '' ? 'DOCKER' : 'NODEJS_18',
            'Port' => (string) $port,
            'RuntimeEnvironmentVariables' => $envVars,
        ];
        if ($buildEnvVars !== []) {
            $codeConfigurationValues['BuildEnvironmentVariables'] = $buildEnvVars;
        }

        $sourceConfig = [
            'CodeRepository' => [
                'RepositoryUrl' => $repositoryUrl,
                'SourceCodeVersion' => [
                    'Type' => 'BRANCH',
                    'Value' => $branch,
                ],
                'CodeConfiguration' => [
                    'ConfigurationSource' => 'API',
                    'CodeConfigurationValues' => $codeConfigurationValues,
                ],
            ],
            'AuthenticationConfiguration' => [
                'ConnectionArn' => $connectionArn,
            ],
            'AutoDeploymentsEnabled' => $autoDeploy,
        ];

        $result = $this->client->createService([
            'ServiceName' => $serviceName,
            'SourceConfiguration' => $sourceConfig,
            'InstanceConfiguration' => [
                'Cpu' => $cpu,
                'Memory' => $memory,
            ],
        ]);

        $service = $result['Service'] ?? [];

        return [
            'service_arn' => (string) ($service['ServiceArn'] ?? ''),
            'service_url' => isset($service['ServiceUrl']) ? 'https://'.$service['ServiceUrl'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function describeService(string $serviceArn): array
    {
        $result = $this->client->describeService(['ServiceArn' => $serviceArn]);

        return $result['Service'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listServices(): array
    {
        $result = $this->client->listServices();
        $services = $result['ServiceSummaryList'] ?? [];

        return is_array($services) ? array_values($services) : [];
    }

    /**
     * Trigger a new deployment using the existing source config.
     * For images on public registries, this re-pulls the tag (so
     * "v1.2.3" → "v1.2.4" requires updateService first; "latest"
     * just re-pulls).
     */
    public function startDeployment(string $serviceArn): array
    {
        $result = $this->client->startDeployment(['ServiceArn' => $serviceArn]);

        return [
            'operation_id' => (string) ($result['OperationId'] ?? ''),
        ];
    }

    /**
     * Patch the service's source image (for image tag bumps).
     *
     * @param  array<string, mixed>  $envVars
     */
    public function updateImage(string $serviceArn, string $image, int $port, array $envVars = []): void
    {
        $this->client->updateService([
            'ServiceArn' => $serviceArn,
            'SourceConfiguration' => [
                'ImageRepository' => [
                    'ImageIdentifier' => $image,
                    'ImageRepositoryType' => str_starts_with($image, 'public.ecr.aws/')
                        ? 'ECR_PUBLIC'
                        : (str_contains($image, '.dkr.ecr.') ? 'ECR' : 'ECR_PUBLIC'),
                    'ImageConfiguration' => [
                        'Port' => (string) $port,
                        'RuntimeEnvironmentVariables' => $envVars,
                    ],
                ],
            ],
        ]);
    }

    public function deleteService(string $serviceArn): void
    {
        $this->client->deleteService(['ServiceArn' => $serviceArn]);
    }

    /**
     * Update the App Runner service's HealthCheckConfiguration. App
     * Runner supports an HTTP or TCP health check first-class on the
     * service — Protocol HTTP needs a Path; TCP omits it.
     *
     * App Runner field names differ from DO: Interval / Timeout /
     * HealthyThreshold / UnhealthyThreshold (seconds + counts). There
     * is no "initial delay" equivalent — App Runner waits for the
     * first healthy response before routing traffic.
     *
     * @param  array{http_path: string, period_seconds: int, timeout_seconds: int, success_threshold: int, failure_threshold: int}  $config
     */
    public function updateHealthCheck(string $serviceArn, array $config): void
    {
        $this->client->updateService([
            'ServiceArn' => $serviceArn,
            'HealthCheckConfiguration' => [
                'Protocol' => 'HTTP',
                'Path' => $config['http_path'],
                'Interval' => max(1, $config['period_seconds']),
                'Timeout' => max(1, $config['timeout_seconds']),
                'HealthyThreshold' => max(1, $config['success_threshold']),
                'UnhealthyThreshold' => max(1, $config['failure_threshold']),
            ],
        ]);
    }

    /**
     * Create an App Runner AutoScalingConfiguration (min/max service
     * instances) and associate it with the service via updateService.
     *
     * App Runner autoscaling is concurrency-driven (MaxConcurrency),
     * not CPU-target like DigitalOcean — dply's cpu_percent intent is
     * recorded in meta but not mapped to App Runner. What App Runner
     * does take is MinSize / MaxSize, which we set from the dply
     * min/max instance config.
     *
     * Returns the created AutoScalingConfigurationArn.
     */
    public function applyAutoScaling(string $serviceArn, string $configName, int $minSize, int $maxSize): string
    {
        $result = $this->client->createAutoScalingConfiguration([
            'AutoScalingConfigurationName' => $configName,
            'MinSize' => max(1, $minSize),
            'MaxSize' => max(max(1, $minSize), $maxSize),
        ]);
        $arn = (string) ($result['AutoScalingConfiguration']['AutoScalingConfigurationArn'] ?? '');

        if ($arn !== '') {
            $this->client->updateService([
                'ServiceArn' => $serviceArn,
                'AutoScalingConfigurationArn' => $arn,
            ]);
        }

        return $arn;
    }

    /**
     * Push new env vars to a CODE_REPOSITORY-mode service without
     * changing the source repo / branch / Dockerfile path. Used by
     * the source-mode env editor.
     *
     * @param  array<string, mixed>  $envVars
     * @param  array<string, mixed>  $buildEnvVars
     */
    public function updateServiceSourceEnv(string $serviceArn, string $repositoryUrl, string $branch, string $connectionArn, int $port, array $envVars, array $buildEnvVars = [], ?string $dockerfilePath = null): void
    {
        $codeConfigurationValues = [
            'Runtime' => $dockerfilePath !== null && $dockerfilePath !== '' ? 'DOCKER' : 'NODEJS_18',
            'Port' => (string) $port,
            'RuntimeEnvironmentVariables' => $envVars,
        ];
        if ($buildEnvVars !== []) {
            $codeConfigurationValues['BuildEnvironmentVariables'] = $buildEnvVars;
        }

        $this->client->updateService([
            'ServiceArn' => $serviceArn,
            'SourceConfiguration' => [
                'CodeRepository' => [
                    'RepositoryUrl' => $repositoryUrl,
                    'SourceCodeVersion' => [
                        'Type' => 'BRANCH',
                        'Value' => $branch,
                    ],
                    'CodeConfiguration' => [
                        'ConfigurationSource' => 'API',
                        'CodeConfigurationValues' => $codeConfigurationValues,
                    ],
                ],
                'AuthenticationConfiguration' => [
                    'ConnectionArn' => $connectionArn,
                ],
            ],
        ]);
    }

    /**
     * Associate a custom domain with an App Runner service. Returns
     * the DNS validation records the operator must add at their
     * registrar so AWS can verify ownership and issue the cert.
     *
     * @return list<array{name: string, type: string, value: string, status: string}>
     */
    public function associateCustomDomain(string $serviceArn, string $hostname): array
    {
        $result = $this->client->associateCustomDomain([
            'ServiceArn' => $serviceArn,
            'DomainName' => $hostname,
            'EnableWWWSubdomain' => false,
        ]);

        $records = [];
        foreach (($result['CustomDomain']['CertificateValidationRecords'] ?? []) as $r) {
            if (! is_array($r)) {
                continue;
            }
            $records[] = [
                'name' => (string) ($r['Name'] ?? ''),
                'type' => (string) ($r['Type'] ?? ''),
                'value' => (string) ($r['Value'] ?? ''),
                'status' => (string) ($r['Status'] ?? ''),
            ];
        }

        return $records;
    }

    public function disassociateCustomDomain(string $serviceArn, string $hostname): void
    {
        $this->client->disassociateCustomDomain([
            'ServiceArn' => $serviceArn,
            'DomainName' => $hostname,
        ]);
    }

    /**
     * Cheap auth probe — listServices is read-only and returns
     * fast even on empty accounts. Throws on auth failure.
     */
    public function validateCredentials(): void
    {
        $this->client->listServices(['MaxResults' => 1]);
    }

    /**
     * Recent App Runner operations (deploys, updates, creates) for a
     * service — used for deploy history in the dashboard / CLI.
     *
     * @return list<array{id: string, type: string, status: string, started_at: ?string, finished_at: ?string}>
     */
    public function listOperations(string $serviceArn, int $limit = 10): array
    {
        $result = $this->client->listOperations([
            'ServiceArn' => $serviceArn,
            'MaxResults' => max(1, min(20, $limit)),
        ]);

        $ops = [];
        foreach (($result['OperationSummaryList'] ?? []) as $op) {
            if (! is_array($op)) {
                continue;
            }
            $ops[] = [
                'id' => (string) ($op['Id'] ?? ''),
                'type' => (string) ($op['Type'] ?? ''),
                'status' => (string) ($op['Status'] ?? ''),
                'started_at' => $this->awsTimestampToIso($op['StartedAt'] ?? null),
                'finished_at' => $this->awsTimestampToIso($op['EndedAt'] ?? null),
            ];
        }

        return $ops;
    }

    /**
     * Stop an in-progress deployment. Always false: App Runner has no
     * stop/cancel-deployment operation.
     *
     * This previously called $this->client->stopDeployment(), which does not
     * exist in the SDK (the API exposes startDeployment / pauseService /
     * resumeService only), so every cancel attempt threw BadMethodCallException
     * out of the AWS client's __call(). Returning false keeps the
     * "could not cancel" contract callers already handle.
     */
    public function stopDeployment(string $serviceArn, string $operationId): bool
    {
        return false;
    }

    /**
     * CPU / memory / request series from CloudWatch (AWS/AppRunner).
     * Points match the CloudBackend shape: list of {t, v}.
     *
     * @return array{cpu: list<array{t: int, v: float}>, memory: list<array{t: int, v: float}>, requests: list<array{t: int, v: float}>}
     */
    public function getServiceMetrics(string $serviceArn, int $start, int $end): array
    {
        $parsed = self::parseServiceArn($serviceArn);
        if ($parsed === null) {
            return ['cpu' => [], 'memory' => [], 'requests' => []];
        }

        $period = match (true) {
            ($end - $start) <= 3600 => 60,
            ($end - $start) <= 21600 => 300,
            default => 900,
        };

        $queries = [];
        foreach ([
            'cpu' => 'CPUUtilization',
            'memory' => 'MemoryUtilization',
            'requests' => 'Requests',
        ] as $id => $metricName) {
            $queries[] = [
                'Id' => $id,
                'MetricStat' => [
                    'Metric' => [
                        'Namespace' => 'AWS/AppRunner',
                        'MetricName' => $metricName,
                        'Dimensions' => [
                            ['Name' => 'ServiceName', 'Value' => $parsed['name']],
                            ['Name' => 'ServiceId', 'Value' => $parsed['id']],
                        ],
                    ],
                    'Period' => $period,
                    'Stat' => $metricName === 'Requests' ? 'Sum' : 'Average',
                ],
                'ReturnData' => true,
            ];
        }

        $result = $this->cloudWatch()->getMetricData([
            'StartTime' => $start,
            'EndTime' => $end,
            'MetricDataQueries' => $queries,
            'ScanBy' => 'TimestampAscending',
        ]);

        $series = ['cpu' => [], 'memory' => [], 'requests' => []];
        foreach (($result['MetricDataResults'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['Id'] ?? '');
            if (! isset($series[$id])) {
                continue;
            }
            $timestamps = is_array($row['Timestamps'] ?? null) ? $row['Timestamps'] : [];
            $values = is_array($row['Values'] ?? null) ? $row['Values'] : [];
            foreach ($timestamps as $i => $ts) {
                $series[$id][] = [
                    't' => $this->awsTimestampToUnix($ts),
                    'v' => (float) ($values[$i] ?? 0),
                ];
            }
        }

        return $series;
    }

    /**
     * Tail application log lines from CloudWatch Logs for the
     * service. Returns newest-last lines, capped at $limit.
     *
     * @return list<string>
     */
    public function getApplicationLogLines(string $serviceArn, int $limit = 200): array
    {
        $parsed = self::parseServiceArn($serviceArn);
        if ($parsed === null) {
            return [];
        }

        $logGroup = sprintf('/aws/apprunner/%s/%s/application', $parsed['name'], $parsed['id']);
        $limit = max(1, min(2000, $limit));

        $result = $this->cloudWatchLogs()->filterLogEvents([
            'logGroupName' => $logGroup,
            'limit' => $limit,
            'interleaved' => true,
        ]);

        $lines = [];
        foreach (($result['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $message = trim((string) ($event['message'] ?? ''));
            if ($message !== '') {
                $lines[] = $message;
            }
        }

        return $lines;
    }

    /**
     * Eight regions where App Runner is generally available, ordered
     * roughly by cost (cheapest first). Mirrors the official AWS
     * regional availability matrix.
     *
     * @return list<array{slug: string, label: string}>
     */
    public static function getRegions(): array
    {
        return [
            ['slug' => 'us-east-1', 'label' => 'N. Virginia (us-east-1)'],
            ['slug' => 'us-west-2', 'label' => 'Oregon (us-west-2)'],
            ['slug' => 'us-east-2', 'label' => 'Ohio (us-east-2)'],
            ['slug' => 'eu-west-1', 'label' => 'Ireland (eu-west-1)'],
            ['slug' => 'eu-central-1', 'label' => 'Frankfurt (eu-central-1)'],
            ['slug' => 'ap-northeast-1', 'label' => 'Tokyo (ap-northeast-1)'],
            ['slug' => 'ap-southeast-1', 'label' => 'Singapore (ap-southeast-1)'],
            ['slug' => 'ap-southeast-2', 'label' => 'Sydney (ap-southeast-2)'],
        ];
    }

    /**
     * Replace the underlying AppRunnerClient — used by tests so
     * they can inject a Mockery double without the full SDK
     * credential dance.
     */
    public function withClient(AppRunnerClient $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function withCloudWatchClient(CloudWatchClient $client): self
    {
        $this->cloudWatch = $client;

        return $this;
    }

    public function withCloudWatchLogsClient(CloudWatchLogsClient $client): self
    {
        $this->cloudWatchLogs = $client;

        return $this;
    }

    /**
     * @return array{name: string, id: string}|null
     */
    public static function parseServiceArn(string $serviceArn): ?array
    {
        if (preg_match('#^arn:aws:apprunner:[^:]+:\d+:service/([^/]+)/([^/]+)$#', $serviceArn, $m) !== 1) {
            return null;
        }

        return ['name' => $m[1], 'id' => $m[2]];
    }

    private function cloudWatch(): CloudWatchClient
    {
        return $this->cloudWatch ??= new CloudWatchClient([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $this->awsCredentials,
        ]);
    }

    private function cloudWatchLogs(): CloudWatchLogsClient
    {
        return $this->cloudWatchLogs ??= new CloudWatchLogsClient([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $this->awsCredentials,
        ]);
    }

    private function awsTimestampToIso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_int($value) || is_float($value)) {
            return gmdate(\DateTimeInterface::ATOM, (int) $value);
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private function awsTimestampToUnix(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);

            return $parsed !== false ? $parsed : 0;
        }

        return 0;
    }
}
