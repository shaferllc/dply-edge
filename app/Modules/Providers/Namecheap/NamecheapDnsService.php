<?php

declare(strict_types=1);

namespace App\Modules\Providers\Namecheap;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Namecheap DNS API (classic XML). Testing-hostname A/TXT writes run here
 * because Namecheap only accepts requests from the allowlisted ClientIp
 * (the control plane), not from customer VMs.
 *
 * @see https://www.namecheap.com/support/api/methods/domains-dns/
 */
class NamecheapDnsService
{
    private const PRODUCTION = 'https://api.namecheap.com/xml.response';

    private const SANDBOX = 'https://api.sandbox.namecheap.com/xml.response';

    public function __construct(
        private readonly string $apiUser,
        private readonly string $apiKey,
        private readonly string $userName,
        private readonly string $clientIp,
        private readonly bool $sandbox = false,
    ) {
        if ($this->apiUser === '' || $this->apiKey === '' || $this->clientIp === '') {
            throw new \InvalidArgumentException('Namecheap API user, key, and client IP are required.');
        }
    }

    public static function isConfigured(): bool
    {
        return trim((string) config('services.namecheap.api_user', '')) !== ''
            && trim((string) config('services.namecheap.api_key', '')) !== ''
            && trim((string) config('services.namecheap.client_ip', '')) !== '';
    }

    public static function fromAppConfig(): self
    {
        return new self(
            apiUser: trim((string) config('services.namecheap.api_user', '')),
            apiKey: trim((string) config('services.namecheap.api_key', '')),
            userName: trim((string) (config('services.namecheap.api_username') ?: config('services.namecheap.api_user', ''))),
            clientIp: trim((string) config('services.namecheap.client_ip', '')),
            sandbox: (bool) config('services.namecheap.sandbox', false),
        );
    }

    public static function fromCredential(ProviderCredential $credential): self
    {
        $creds = $credential->credentials;

        return new self(
            apiUser: trim((string) ($creds['api_user'] ?? '')),
            apiKey: trim((string) ($creds['api_key'] ?? $creds['api_token'] ?? '')),
            userName: trim((string) ($creds['api_username'] ?? $creds['api_user'] ?? '')),
            clientIp: trim((string) config('services.namecheap.client_ip', '')),
            sandbox: (bool) config('services.namecheap.sandbox', false),
        );
    }

    public function zoneExists(string $zoneName): bool
    {
        try {
            $this->getHosts($zoneName);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertARecord(string $zoneName, string $relativeRecordName, string $ipv4): array
    {
        return $this->upsertHost($zoneName, $relativeRecordName, 'A', $ipv4);
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertTxtRecord(string $zoneName, string $relativeRecordName, string $value): array
    {
        return $this->upsertHost($zoneName, $relativeRecordName, 'TXT', $value);
    }

    public function deleteDnsRecord(string $zoneName, string $recordId): void
    {
        $recordId = trim($recordId);
        if ($recordId === '' || ! str_contains($recordId, '/')) {
            return;
        }

        [$name, $type] = explode('/', $recordId, 2);
        $this->deleteHost($zoneName, $name, $type);
    }

    public function deleteHost(string $zoneName, string $relativeRecordName, string $type): void
    {
        $name = $this->relativeName($relativeRecordName);
        $type = strtoupper(trim($type));
        $bundle = $this->getHosts($zoneName);
        $kept = array_values(array_filter(
            $bundle['hosts'],
            static fn (array $host): bool => ! (strcasecmp((string) $host['name'], $name) === 0 && strtoupper((string) $host['type']) === $type),
        ));

        if (count($kept) === count($bundle['hosts'])) {
            return;
        }

        $this->setHosts($zoneName, $kept, $bundle['email_type']);
    }

    /**
     * @return array{id: string, type: string, name: string, value: string}
     */
    private function upsertHost(string $zoneName, string $relativeRecordName, string $type, string $value): array
    {
        $name = $this->relativeName($relativeRecordName);
        $type = strtoupper(trim($type));
        $bundle = $this->getHosts($zoneName);
        $replaced = false;

        foreach ($bundle['hosts'] as $index => $host) {
            if (strcasecmp((string) $host['name'], $name) === 0 && strtoupper((string) $host['type']) === $type) {
                $bundle['hosts'][$index]['address'] = $value;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $bundle['hosts'][] = [
                'name' => $name,
                'type' => $type,
                'address' => $value,
                'ttl' => 60,
                'mx_pref' => '10',
            ];
        }

        $this->setHosts($zoneName, $bundle['hosts'], $bundle['email_type']);

        return [
            'id' => $name.'/'.$type,
            'type' => $type,
            'name' => $name,
            'value' => $value,
        ];
    }

    /**
     * @return array{email_type: string, hosts: list<array{name: string, type: string, address: string, ttl: int, mx_pref: string}>}
     */
    private function getHosts(string $zoneName): array
    {
        $parts = $this->splitZone($zoneName);
        $xml = $this->request('namecheap.domains.dns.getHosts', $parts);
        $result = $xml->CommandResponse->DomainDNSGetHostsResult ?? null;
        if (! $result instanceof SimpleXMLElement) {
            throw new \RuntimeException('Namecheap getHosts returned no host list for '.$zoneName.'.');
        }

        $hosts = [];
        foreach ($result->host as $host) {
            $attrs = $host->attributes();
            $hosts[] = [
                'name' => strtolower(trim((string) ($attrs['Name'] ?? ''))),
                'type' => strtoupper(trim((string) ($attrs['Type'] ?? ''))),
                'address' => (string) ($attrs['Address'] ?? ''),
                'ttl' => (int) ($attrs['TTL'] ?? 1799),
                'mx_pref' => (string) ($attrs['MXPref'] ?? '10'),
            ];
        }

        $emailType = strtoupper(trim((string) ($result->attributes()['EmailType'] ?? 'FWD')));

        return [
            'email_type' => $emailType !== '' ? $emailType : 'FWD',
            'hosts' => $hosts,
        ];
    }

    /**
     * @param  list<array{name: string, type: string, address: string, ttl: int, mx_pref: string}>  $hosts
     */
    private function setHosts(string $zoneName, array $hosts, string $emailType): void
    {
        if ($hosts === []) {
            throw new \RuntimeException('Namecheap setHosts refuses an empty host list for '.$zoneName.'.');
        }

        $params = $this->splitZone($zoneName);
        $params['EmailType'] = $emailType !== '' ? $emailType : 'FWD';

        foreach ($hosts as $index => $host) {
            $n = $index + 1;
            $params['HostName'.$n] = $host['name'] !== '' ? $host['name'] : '@';
            $params['RecordType'.$n] = $host['type'];
            $params['Address'.$n] = $host['address'];
            $params['TTL'.$n] = (string) max(60, $host['ttl']);
            $params['MXPref'.$n] = $host['mx_pref'] !== '' ? $host['mx_pref'] : '10';
        }

        $this->request('namecheap.domains.dns.setHosts', $params);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function request(string $command, array $params): SimpleXMLElement
    {
        $query = array_merge([
            'ApiUser' => $this->apiUser,
            'ApiKey' => $this->apiKey,
            'UserName' => $this->userName !== '' ? $this->userName : $this->apiUser,
            'ClientIp' => $this->clientIp,
            'Command' => $command,
        ], $params);

        $response = Http::timeout(30)->accept('application/xml')->get(
            $this->sandbox ? self::SANDBOX : self::PRODUCTION,
            $query,
        );

        $this->assertXmlSuccess($response, $command);

        $xml = simplexml_load_string((string) $response->body());
        if (! $xml instanceof SimpleXMLElement) {
            throw new \RuntimeException('Namecheap returned invalid XML for '.$command.'.');
        }

        return $xml;
    }

    private function assertXmlSuccess(Response $response, string $command): void
    {
        if (! $response->successful()) {
            throw new \RuntimeException('Namecheap '.$command.' failed HTTP '.$response->status().'.');
        }

        if (preg_match('/Status="ERROR"/i', (string) $response->body()) === 1) {
            $message = 'Namecheap '.$command.' failed.';
            if (preg_match('/<Error[^>]*>([^<]+)</', (string) $response->body(), $matches) === 1) {
                $message .= ' '.trim($matches[1]);
            }

            throw new \RuntimeException($message);
        }
    }

    /**
     * @return array{SLD: string, TLD: string}
     */
    private function splitZone(string $zoneName): array
    {
        $zone = strtolower(trim($zoneName));
        $dot = strrpos($zone, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($zone) - 1) {
            throw new \InvalidArgumentException('Invalid Namecheap zone ['.$zoneName.'].');
        }

        return [
            'SLD' => substr($zone, 0, $dot),
            'TLD' => substr($zone, $dot + 1),
        ];
    }

    private function relativeName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '' || $name === '@') {
            return '@';
        }

        return $name;
    }
}
