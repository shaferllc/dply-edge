<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * What a Dockerfile's final image runs: its ENTRYPOINT and CMD, with
 * Docker's own rules, read from the Dockerfile and, when the final stage
 * does not set ENTRYPOINT itself, from the base image's config on its
 * registry (no pull). EdgeContainerAgent puts the dply agent in front of
 * exactly that, so an app with its own Dockerfile starts as before.
 *
 * Rules followed (BuildKit): ENTRYPOINT clears an inherited CMD unless CMD
 * was set earlier in the same stage; a shell-form ENTRYPOINT ignores CMD; a
 * stage FROM an earlier stage inherits that stage's result. Anything this
 * can't resolve (an ARG with no default in FROM, a private registry) throws:
 * the caller deploys without the agent.
 */
final class EdgeDockerfileCommand
{
    private const ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

    /**
     * @return array{entrypoint: list<string>, cmd: list<string>}
     */
    public static function resolve(string $dockerfile): array
    {
        $stages = self::stages($dockerfile);
        if ($stages === []) {
            throw new RuntimeException('no FROM');
        }

        return self::stageResult($stages, count($stages) - 1);
    }

    /**
     * @param  list<array{from: string, name: ?string, steps: list<array{0: string, 1: list<string>, 2: bool}>}>  $stages
     * @return array{entrypoint: list<string>, cmd: list<string>}
     */
    private static function stageResult(array $stages, int $index): array
    {
        $stage = $stages[$index];
        $setsEntrypoint = collect($stage['steps'])->contains(fn (array $s): bool => $s[0] === 'ENTRYPOINT');
        // The base only matters when this stage leaves ENTRYPOINT to it.
        $base = $setsEntrypoint ? ['entrypoint' => [], 'cmd' => []] : self::base($stages, $index);
        [$entrypoint, $cmd, $cmdSet, $shellEntrypoint] = [$base['entrypoint'], $base['cmd'], false, false];
        foreach ($stage['steps'] as [$kind, $value, $shell]) {
            if ($kind === 'ENTRYPOINT') {
                [$entrypoint, $shellEntrypoint] = [$value, $shell];
                if (! $cmdSet) {
                    $cmd = [];
                }
            } else {
                [$cmd, $cmdSet] = [$value, true];
            }
        }

        return ['entrypoint' => $entrypoint, 'cmd' => $shellEntrypoint ? [] : $cmd];
    }

    /**
     * @param  list<array{from: string, name: ?string, steps: list<array{0: string, 1: list<string>, 2: bool}>}>  $stages
     * @return array{entrypoint: list<string>, cmd: list<string>}
     */
    private static function base(array $stages, int $index): array
    {
        $from = $stages[$index]['from'];
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($stages[$i]['name'] !== null && strcasecmp($stages[$i]['name'], $from) === 0) {
                return self::stageResult($stages, $i);
            }
        }
        if (ctype_digit($from) && (int) $from < $index) {
            return self::stageResult($stages, (int) $from);
        }
        if (strtolower($from) === 'scratch') {
            return ['entrypoint' => [], 'cmd' => []];
        }

        return self::imageConfig($from);
    }

    /**
     * FROM, ENTRYPOINT and CMD of each stage, in order.
     *
     * @return list<array{from: string, name: ?string, steps: list<array{0: string, 1: list<string>, 2: bool}>}>
     */
    public static function stages(string $dockerfile): array
    {
        $joined = preg_replace('/\\\\\r?\n/', ' ', $dockerfile) ?? $dockerfile;
        $args = [];
        $stages = [];
        foreach (preg_split('/\r?\n/', $joined) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$instruction, $rest] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');
            $instruction = strtoupper($instruction);
            $rest = trim($rest);
            if ($instruction === 'ARG' && $stages === []) {
                foreach (preg_split('/\s+/', $rest) ?: [] as $arg) {
                    [$name, $default] = array_pad(explode('=', $arg, 2), 2, null);
                    $args[$name] = $default !== null ? trim($default, '"\'') : null;
                }
            } elseif ($instruction === 'FROM') {
                $parts = preg_split('/\s+/', preg_replace('/--platform=\S+\s*/i', '', $rest) ?? $rest) ?: [];
                $stages[] = [
                    'from' => self::expand((string) ($parts[0] ?? ''), $args),
                    'name' => isset($parts[1], $parts[2]) && strtoupper($parts[1]) === 'AS' ? $parts[2] : null,
                    'steps' => [],
                ];
            } elseif (($instruction === 'ENTRYPOINT' || $instruction === 'CMD') && $stages !== []) {
                $exec = json_decode($rest, true);
                $isExec = is_array($exec) && array_is_list($exec) && $exec !== [] && collect($exec)->every(fn ($p): bool => is_string($p));
                $stages[count($stages) - 1]['steps'][] = [$instruction, $isExec ? $exec : ['/bin/sh', '-c', $rest], ! $isExec];
            }
        }

        return $stages;
    }

    /** @param  array<string, ?string>  $args */
    private static function expand(string $ref, array $args): string
    {
        return (string) preg_replace_callback('/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/', function (array $m) use ($args): string {
            $value = $args[$m[1]] ?? null;
            if ($value === null) {
                throw new RuntimeException("FROM uses \${$m[1]}, which has no default");
            }

            return $value;
        }, $ref);
    }

    /**
     * The image's Entrypoint and Cmd from its registry, for linux/amd64.
     * Anonymous pulls only (Docker Hub, GHCR, Quay, …).
     *
     * @return array{entrypoint: list<string>, cmd: list<string>}
     */
    public static function imageConfig(string $ref): array
    {
        [$registry, $repository, $reference] = self::parseRef($ref);
        $token = null;
        $get = function (string $path, string $accept) use ($registry, $repository, &$token) {
            $url = 'https://'.$registry.'/v2/'.$repository.'/'.$path;
            $response = Http::timeout(15)->withHeaders(array_filter(['Accept' => $accept, 'Authorization' => $token !== null ? 'Bearer '.$token : null]))->get($url);
            if ($response->status() === 401 && $token === null) {
                $token = self::token((string) $response->header('WWW-Authenticate'), $repository);
                $response = Http::timeout(15)->withHeaders(['Accept' => $accept, 'Authorization' => 'Bearer '.$token])->get($url);
            }
            if (! $response->successful()) {
                throw new RuntimeException("{$registry}/{$repository}: HTTP {$response->status()}");
            }

            return $response;
        };
        try {
            $manifest = $get('manifests/'.$reference, self::ACCEPT)->json();
            if (isset($manifest['manifests'])) {
                $pick = collect($manifest['manifests'])->first(fn (array $m): bool => ($m['platform']['os'] ?? '') === 'linux' && ($m['platform']['architecture'] ?? '') === 'amd64');
                if ($pick === null) {
                    throw new RuntimeException("{$ref} has no linux/amd64 image");
                }
                $manifest = $get('manifests/'.$pick['digest'], self::ACCEPT)->json();
            }
            $config = $get('blobs/'.($manifest['config']['digest'] ?? ''), '*/*')->json('config') ?? [];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException("{$ref}: {$e->getMessage()}", previous: $e);
        }

        return ['entrypoint' => array_values((array) ($config['Entrypoint'] ?? [])), 'cmd' => array_values((array) ($config['Cmd'] ?? []))];
    }

    /** @return array{0: string, 1: string, 2: string} registry host, repository, tag or digest */
    public static function parseRef(string $ref): array
    {
        $first = explode('/', $ref, 2);
        $hasRegistry = count($first) === 2 && (str_contains($first[0], '.') || str_contains($first[0], ':') || $first[0] === 'localhost');
        $registry = $hasRegistry ? $first[0] : 'registry-1.docker.io';
        $path = $hasRegistry ? $first[1] : $ref;
        if (str_contains($path, '@')) {
            [$repository, $reference] = explode('@', $path, 2);
        } else {
            $colon = strrpos($path, ':');
            [$repository, $reference] = $colon !== false && ! str_contains(substr($path, $colon), '/') ? [substr($path, 0, $colon), substr($path, $colon + 1)] : [$path, 'latest'];
        }
        if (! $hasRegistry && ! str_contains($repository, '/')) {
            $repository = 'library/'.$repository;
        }

        return [$registry === 'docker.io' ? 'registry-1.docker.io' : $registry, $repository, $reference];
    }

    private static function token(string $challenge, string $repository): string
    {
        if (preg_match_all('/(\w+)="([^"]*)"/', $challenge, $m, PREG_SET_ORDER) === 0) {
            throw new RuntimeException('registry asked for auth it did not describe');
        }
        $params = [];
        foreach ($m as $pair) {
            $params[$pair[1]] = $pair[2];
        }
        $realm = $params['realm'] ?? '';
        $response = Http::timeout(15)->get($realm, array_filter(['service' => $params['service'] ?? null, 'scope' => $params['scope'] ?? 'repository:'.$repository.':pull']));
        $token = $response->json('token') ?? $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException('the registry refused an anonymous pull (private image?)');
        }

        return $token;
    }
}
