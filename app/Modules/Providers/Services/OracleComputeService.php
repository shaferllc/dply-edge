<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use App\Support\Cloud\OciRequestSigner;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OracleComputeService
{
    private readonly string $tenancyOcid;

    private readonly string $userOcid;

    private readonly string $fingerprint;

    private readonly string $privateKey;

    private readonly string $region;

    private readonly string $compartmentId;

    private readonly OciRequestSigner $signer;

    private readonly string $identityBaseUrl;

    private readonly string $computeBaseUrl;

    public function __construct(
        private readonly ProviderCredential $credential,
    ) {
        $credentials = $credential->credentials ?? [];

        $this->tenancyOcid = trim((string) ($credentials['tenancy_ocid'] ?? ''));
        $this->userOcid = trim((string) ($credentials['user_ocid'] ?? ''));
        $this->fingerprint = trim((string) ($credentials['fingerprint'] ?? ''));
        $this->privateKey = trim((string) ($credentials['private_key'] ?? ''));
        $this->region = trim((string) ($credentials['region'] ?? ''));
        $this->compartmentId = trim((string) ($credentials['compartment_id'] ?? $this->tenancyOcid));

        if (
            $this->tenancyOcid === ''
            || $this->userOcid === ''
            || $this->fingerprint === ''
            || $this->privateKey === ''
            || $this->region === ''
        ) {
            throw new \InvalidArgumentException('Oracle Cloud credentials are incomplete.');
        }

        $this->signer = new OciRequestSigner(
            $this->tenancyOcid,
            $this->userOcid,
            $this->fingerprint,
            $this->privateKey,
        );

        $this->identityBaseUrl = 'https://identity.'.$this->region.'.oraclecloud.com/20160918';
        $this->computeBaseUrl = 'https://iaas.'.$this->region.'.oraclecloud.com/20160918';
    }

    public function validateCredentials(): void
    {
        $this->listAvailabilityDomains();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAvailabilityDomains(): array
    {
        $payload = $this->request(
            method: 'GET',
            baseUrl: $this->identityBaseUrl,
            path: '/availabilityDomains',
            query: [
                'compartmentId' => $this->compartmentId,
            ],
        );

        $domains = [];
        foreach ($payload as $item) {
            if (is_array($item)) {
                $domains[] = $item;
            }
        }

        return $domains;
    }

    /**
     * @return list<array<mixed>>
     */
    public function listShapes(?string $availabilityDomain = null): array
    {
        $query = [
            'compartmentId' => $this->compartmentId,
        ];
        if (is_string($availabilityDomain) && trim($availabilityDomain) !== '') {
            $query['availabilityDomain'] = trim($availabilityDomain);
        }

        try {
            $payload = $this->request(
                method: 'GET',
                baseUrl: $this->computeBaseUrl,
                path: '/shapes',
                query: $query,
            );

            $shapes = [];
            foreach ($payload as $shape) {
                if (is_array($shape)) {
                    $shapes[] = $shape;
                }
            }

            if ($shapes !== []) {
                return $shapes;
            }
        } catch (\Throwable) {
            // fall back to static defaults
        }

        return self::defaultShapes();
    }

    /**
     * @param  array<string, mixed>  $freeformTags  Instance tags, e.g. ProviderResourceTags::labels()
     */
    public function launchInstance(
        string $displayName,
        string $availabilityDomain,
        string $shape,
        string $sshPublicKey,
        array $freeformTags = [],
    ): string {
        $imageId = trim((string) config('services.oracle.default_image_id', ''));
        if ($imageId === '') {
            throw new RuntimeException('Oracle image id is not configured.');
        }

        $subnetId = $this->resolveDefaultSubnetId($availabilityDomain);
        if ($subnetId === null) {
            throw new RuntimeException('Oracle subnet could not be resolved for this compartment.');
        }

        $payload = $this->request(
            method: 'POST',
            baseUrl: $this->computeBaseUrl,
            path: '/instances',
            body: array_filter([
                'compartmentId' => $this->compartmentId,
                'displayName' => $displayName,
                'availabilityDomain' => $availabilityDomain,
                'shape' => $shape,
                'freeformTags' => array_map(static fn (mixed $v): string => (string) $v, $freeformTags),
                'sourceDetails' => [
                    'sourceType' => 'image',
                    'imageId' => $imageId,
                ],
                'metadata' => [
                    'ssh_authorized_keys' => trim($sshPublicKey),
                ],
                'createVnicDetails' => [
                    'assignPublicIp' => true,
                    'subnetId' => $subnetId,
                ],
            ]),
        );

        $id = $payload['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Oracle Cloud did not return an instance OCID.');
        }

        return $id;
    }

    /**
     * The instance object itself, not a list. The previous list<> annotation
     * hid every field: PollOracleIpJob reads $instance['lifecycleState'], which
     * therefore always resolved to '' and never matched 'RUNNING', so an Oracle
     * server was never marked READY.
     *
     * @return array<string, mixed>
     */
    public function getInstance(string $instanceId): array
    {
        $payload = $this->request(
            method: 'GET',
            baseUrl: $this->computeBaseUrl,
            path: '/instances/'.rawurlencode($instanceId),
        );

        if ($payload === []) {
            throw new RuntimeException('Oracle Cloud did not return an instance payload.');
        }

        return $payload;
    }

    public function getPublicIp(string $instanceId): ?string
    {
        $attachment = $this->firstVnicAttachment($instanceId);
        if ($attachment === null) {
            return null;
        }

        $vnicId = (string) ($attachment['vnicId'] ?? '');
        if ($vnicId === '') {
            return null;
        }

        $vnic = $this->request(
            method: 'GET',
            baseUrl: $this->computeBaseUrl,
            path: '/vnics/'.rawurlencode($vnicId),
        );

        $ip = $vnic['publicIp'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    public function terminateInstance(string $instanceId): void
    {
        $this->request(
            method: 'DELETE',
            baseUrl: $this->computeBaseUrl,
            path: '/instances/'.rawurlencode($instanceId),
        );
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public static function defaultRegions(): array
    {
        return [
            ['id' => 'us-phoenix-1', 'name' => 'US West (Phoenix)'],
            ['id' => 'us-ashburn-1', 'name' => 'US East (Ashburn)'],
            ['id' => 'eu-frankfurt-1', 'name' => 'Germany Central (Frankfurt)'],
            ['id' => 'eu-amsterdam-1', 'name' => 'Netherlands Northwest (Amsterdam)'],
            ['id' => 'uk-london-1', 'name' => 'UK South (London)'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultShapes(): array
    {
        return [
            ['shape' => 'VM.Standard.E2.1.Micro', 'ocpus' => 1, 'memoryInGBs' => 1],
            ['shape' => 'VM.Standard.A1.Flex', 'ocpus' => 1, 'memoryInGBs' => 6],
            ['shape' => 'VM.Standard.E4.Flex', 'ocpus' => 1, 'memoryInGBs' => 16],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function request(
        string $method,
        string $baseUrl,
        string $path,
        array $query = [],
        ?array $body = null,
    ): array {
        $queryString = $query !== [] ? ('?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)) : '';
        $url = rtrim($baseUrl, '/').$path.$queryString;
        $bodyJson = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $headers = $this->signer->sign($method, $url, [], $bodyJson);

        $request = Http::withHeaders($headers)->timeout(30);
        $method = strtoupper($method);

        $response = match ($method) {
            'GET' => $request->get($url),
            'POST' => $request->withBody($bodyJson ?? '{}', 'application/json')->post($url),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException('Unsupported OCI method: '.$method),
        };

        $this->assertSuccess($response, $method.' '.$path);

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message');
        if (! is_string($message) || $message === '') {
            $message = (string) $response->json('code', '');
        }
        if ($message === '') {
            $message = $response->body() !== '' ? $response->body() : $response->reason();
        }

        throw new RuntimeException("Oracle Cloud API failed to {$action}: {$message}");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstVnicAttachment(string $instanceId): ?array
    {
        $payload = $this->request(
            method: 'GET',
            baseUrl: $this->computeBaseUrl,
            path: '/vnicAttachments',
            query: [
                'compartmentId' => $this->compartmentId,
                'instanceId' => $instanceId,
                'limit' => 10,
            ],
        );

        foreach ($payload as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            if (($attachment['lifecycleState'] ?? '') !== 'ATTACHED') {
                continue;
            }

            if (($attachment['isPrimary'] ?? false) === true) {
                return $attachment;
            }
        }

        foreach ($payload as $attachment) {
            if (is_array($attachment)) {
                return $attachment;
            }
        }

        return null;
    }

    private function resolveDefaultSubnetId(string $availabilityDomain): ?string
    {
        $payload = $this->request(
            method: 'GET',
            baseUrl: $this->computeBaseUrl,
            path: '/subnets',
            query: [
                'compartmentId' => $this->compartmentId,
                'availabilityDomain' => $availabilityDomain,
                'lifecycleState' => 'AVAILABLE',
                'limit' => 50,
            ],
        );

        foreach ($payload as $subnet) {
            if (! is_array($subnet)) {
                continue;
            }

            $id = (string) ($subnet['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }
}
