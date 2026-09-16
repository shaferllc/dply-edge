<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use Aws\EKS\EKSClient;
use Aws\Exception\AwsException;

/**
 * Thin wrapper around the EKS API. Currently only used to list clusters for
 * the server-create wizard's K8s host_kind path; mirrors the shape of
 * {@see AwsEc2Service} so future EKS calls (describe-cluster, kubeconfig
 * resolution, etc.) drop in here without spinning up a parallel client.
 */
class AwsEksService
{
    protected EKSClient $client;

    protected string $region;

    public function __construct(ProviderCredential $credential, ?string $region = null)
    {
        $creds = $credential->credentials ?? [];
        $key = (string) ($creds['access_key_id'] ?? '');
        $secret = (string) ($creds['secret_access_key'] ?? '');
        if ($key === '' || $secret === '') {
            throw new \InvalidArgumentException('AWS access key ID and secret access key are required.');
        }
        $this->region = $region ?? (string) ($creds['region'] ?? config('services.aws.default_region', 'us-east-1'));
        $config = [
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ];

        // Test hook: when a `aws.eks.handler` binding is registered in the
        // container (only ever done by Tests\Support\StubsAwsSdk), pass it
        // to the SDK so canned MockHandler responses replace real HTTP. No
        // production path ever sets this binding.
        if (app()->bound('aws.eks.handler')) {
            $config['handler'] = app()->make('aws.eks.handler');
        }

        $this->client = new EKSClient($config);
    }

    /**
     * Statically maintained list of EKS-capable AWS regions. Sourced from
     * https://docs.aws.amazon.com/general/latest/gr/eks.html — AWS adds new
     * regions every ~6 months; refresh this list when that happens. Static
     * list (rather than `aws ec2 describe-regions`) so the StepWhat region
     * picker renders without a synchronous AWS round-trip per page load.
     */
    public const SUPPORTED_REGIONS = [
        'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2',
        'af-south-1',
        'ap-east-1', 'ap-south-1', 'ap-south-2',
        'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3',
        'ap-southeast-1', 'ap-southeast-2', 'ap-southeast-3', 'ap-southeast-4',
        'ca-central-1', 'ca-west-1',
        'eu-central-1', 'eu-central-2',
        'eu-west-1', 'eu-west-2', 'eu-west-3',
        'eu-north-1', 'eu-south-1', 'eu-south-2',
        'me-central-1', 'me-south-1',
        'sa-east-1',
    ];

    public function region(): string
    {
        return $this->region;
    }

    /**
     * List EKS cluster names in the credential's region. Paginated under the
     * hood — EKS returns up to 100 names per page; loop until nextToken empty.
     *
     * @return list<string>
     */
    public function listClusterNames(): array
    {
        $names = [];
        $token = null;

        do {
            $params = [];
            if ($token !== null) {
                $params['nextToken'] = $token;
            }
            $result = $this->client->listClusters($params);
            foreach ((array) ($result['clusters'] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
            $token = is_string($result['nextToken'] ?? null) && $result['nextToken'] !== '' ? $result['nextToken'] : null;
        } while ($token !== null);

        return $names;
    }

    /**
     * DescribeCluster wrapper. Returns the AWS cluster array (status, endpoint,
     * certificateAuthority.data, version, arn, createdAt, etc.) or null when
     * the cluster has been deleted out from under us (so callers can stop
     * polling cleanly).
     *
     * @return array<string, mixed>|null
     */
    public function getCluster(string $name): ?array
    {
        try {
            $result = $this->client->describeCluster(['name' => $name]);
        } catch (AwsException $e) {
            // EksException extends AwsException; catching the parent covers
            // both the production SDK's service-specific exception class and
            // anything our MockHandler test infra might inject.
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }

        $cluster = (array) ($result['cluster'] ?? []);

        return $cluster !== [] ? $cluster : null;
    }

    /**
     * List + DescribeNodegroup for each. Returns a shape compatible with the
     * WorkspaceCluster node-pool table (which was modeled on DOKS). Synthesises
     * a nodes[] array per pool — EKS doesn't expose per-node state, so we
     * write `desiredSize` "running" entries when nodegroup is ACTIVE, empty
     * otherwise. UI fiction that matches the AWS console's resolution.
     *
     * @return list<array<string, mixed>>
     */
    public function listAndDescribeNodegroups(string $clusterName): array
    {
        $names = [];
        $token = null;
        do {
            $params = ['clusterName' => $clusterName];
            if ($token !== null) {
                $params['nextToken'] = $token;
            }
            $result = $this->client->listNodegroups($params);
            foreach ((array) ($result['nodegroups'] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
            $token = is_string($result['nextToken'] ?? null) && $result['nextToken'] !== '' ? $result['nextToken'] : null;
        } while ($token !== null);

        $out = [];
        foreach ($names as $name) {
            try {
                $detail = $this->client->describeNodegroup([
                    'clusterName' => $clusterName,
                    'nodegroupName' => $name,
                ]);
            } catch (\Throwable) {
                continue;
            }
            $ng = (array) ($detail['nodegroup'] ?? []);
            if ($ng === []) {
                continue;
            }
            $out[] = $this->normalizeNodegroup($ng);
        }

        return $out;
    }

    /**
     * Build a kubeconfig YAML that points kubectl at this cluster via the
     * standard `aws eks get-token` exec block — byte-compatible with what
     * `aws eks update-kubeconfig --name X --region Y` produces. Users need
     * the AWS CLI installed with credentials configured wherever they run
     * kubectl; everyone running EKS already has that.
     *
     * @param  array<string, mixed>  $cluster  the DescribeCluster output
     */
    public function generateKubeconfig(array $cluster): string
    {
        $name = (string) ($cluster['name'] ?? '');
        $arn = (string) ($cluster['arn'] ?? '');
        $endpoint = (string) ($cluster['endpoint'] ?? '');
        $ca = (string) ($cluster['certificateAuthority']['data'] ?? '');
        $region = $this->region;

        if ($name === '' || $endpoint === '' || $ca === '') {
            throw new \RuntimeException('EKS cluster is missing fields needed to build a kubeconfig (name/endpoint/CA).');
        }

        return <<<YAML
apiVersion: v1
kind: Config
clusters:
- cluster:
    server: {$endpoint}
    certificate-authority-data: {$ca}
  name: {$arn}
contexts:
- context:
    cluster: {$arn}
    user: {$arn}
  name: {$arn}
current-context: {$arn}
preferences: {}
users:
- name: {$arn}
  user:
    exec:
      apiVersion: client.authentication.k8s.io/v1beta1
      command: aws
      args:
      - --region
      - {$region}
      - eks
      - get-token
      - --cluster-name
      - {$name}
      - --output
      - json

YAML;
    }

    /**
     * @param  array<string, mixed>  $nodegroup
     * @return array<string, mixed>
     */
    private function normalizeNodegroup(array $nodegroup): array
    {
        $status = (string) ($nodegroup['status'] ?? 'UNKNOWN');
        $instanceTypes = (array) ($nodegroup['instanceTypes'] ?? []);
        $size = is_string($instanceTypes[0] ?? null) ? $instanceTypes[0] : '—';
        $desired = (int) ($nodegroup['scalingConfig']['desiredSize'] ?? 0);

        $nodes = [];
        if ($status === 'ACTIVE') {
            for ($i = 0; $i < $desired; $i++) {
                $nodes[] = ['status' => ['state' => 'running']];
            }
        }

        return [
            'name' => (string) ($nodegroup['nodegroupName'] ?? ''),
            'size' => $size,
            'count' => $desired,
            'nodes' => $nodes,
            'status' => $status,
            'scaling_config' => (array) ($nodegroup['scalingConfig'] ?? []),
            'disk_size_gb' => isset($nodegroup['diskSize']) ? (int) $nodegroup['diskSize'] : null,
            'ami_type' => (string) ($nodegroup['amiType'] ?? ''),
        ];
    }
}
