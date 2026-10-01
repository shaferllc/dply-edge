<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Diagnostics;

use App\Mcp\Exceptions\DplyMcpException;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\Site;
use App\Support\Http\PublicOutboundUrl;
use App\Support\Http\UnsafeOutboundUrlException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Throwable;

/**
 * Requests a path on one of the app's own hostnames, as an anonymous visitor
 * would, and reports what came back. Read-only by design: GET and HEAD only,
 * redirects are reported rather than followed, and the host must be the app's
 * live hostname or one of its custom domains (never an arbitrary URL). A custom
 * domain is the owner's to point anywhere, so it also goes through the SSRF
 * guard: a host that resolves to a private address is refused, and the request
 * is pinned to the address that was checked.
 */
class ProbeUrl extends AbstractDplyTool
{
    protected string $name = 'probe_url';

    protected string $description = 'Request a path on one of the app\'s own hostnames (live URL or a custom domain) as an anonymous visitor and see the status, timing, response headers (cache, x-dply-*) and the start of the body. GET or HEAD only; redirects are reported, not followed.';

    protected string $ability = 'edge.read';

    private const BODY_CHARS = 4000;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()->description('The app id (or slug).')->required(),
            'path' => $schema->string()->description('Path and query, e.g. /login?next=/. Default /.'),
            'host' => $schema->string()->description('One of the app\'s hostnames. Default: its public URL (the primary custom domain once it serves).'),
            'method' => $schema->string()->enum(['GET', 'HEAD'])->description('Default GET.'),
            'headers' => $schema->object()->description('Request headers to send, e.g. {"Accept": "application/json"}.'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $input = $request->validate([
            'site_id' => ['required', 'string'],
            'path' => ['nullable', 'string', 'max:2000'],
            'host' => ['nullable', 'string', 'max:253'],
            'method' => ['nullable', 'in:GET,HEAD'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['string', 'max:2000'],
        ]);
        $site = $this->resolveSite($input['site_id'], $organization);
        $hosts = self::hosts($site);
        $host = strtolower((string) ($input['host'] ?? ($hosts[0] ?? '')));
        if ($host === '' || ! in_array($host, $hosts, true)) {
            throw new DplyMcpException('host must be one of this app\'s hostnames: '.implode(', ', $hosts ?: ['(none yet)']).'.');
        }
        $path = '/'.ltrim((string) ($input['path'] ?? '/'), '/');
        $method = $input['method'] ?? 'GET';

        try {
            $safe = PublicOutboundUrl::parse('https://'.$host.$path);
        } catch (UnsafeOutboundUrlException $e) {
            throw new DplyMcpException($e->getMessage());
        }

        $started = microtime(true);
        try {
            $response = Http::timeout(30)->withOptions($safe->httpClientOptions())
                ->withHeaders(['User-Agent' => 'dply-probe/1 (MCP)'] + ($input['headers'] ?? []))
                ->send($method, 'https://'.$host.$path);
        } catch (Throwable $e) {
            return Response::json(['data' => ['url' => 'https://'.$host.$path, 'error' => $e->getMessage()]]);
        }

        return Response::json(['data' => [
            'url' => 'https://'.$host.$path,
            'status' => $response->status(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'headers' => array_map(fn (array $values): string => implode(', ', $values), $response->headers()),
            'body' => $method === 'HEAD' ? null : mb_substr($response->body(), 0, self::BODY_CHARS),
            'body_truncated' => mb_strlen($response->body()) > self::BODY_CHARS,
        ]]);
    }

    /** @return list<string> the public URL's host first, then the platform host and custom domains */
    public static function hosts(Site $site): array
    {
        $public = parse_url((string) $site->edgePublicUrl(), PHP_URL_HOST);
        $live = parse_url((string) $site->edgeLiveUrl(), PHP_URL_HOST);
        $domains = array_keys((array) ($site->edgeMeta()['routing']['custom_domains'] ?? []));

        return array_values(array_unique(array_filter(array_map(
            static fn ($h): string => strtolower((string) $h),
            [$public, $live, ...$domains],
        ))));
    }
}
