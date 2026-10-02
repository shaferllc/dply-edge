<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use RuntimeException;
use Throwable;

/**
 * The dply agent (packages/dply-agent) in front of a container app: PID 1,
 * running the image's own command as its child, and taking one-off commands
 * from dply on PORT (T-037, ruling r-5w7h5d0b902aeq0n). The Worker reaches it
 * at /_dply/agent/* with the app's queue token.
 *
 * Fail-safe by design: anything that stops the agent being added (flag off,
 * the app's own Dockerfile, no binary on this builder, a port clash) deploys
 * the image exactly as before and says why in the log. Behind the per-org
 * flag resource-agent (dply:feature agent {org}) until it has run on real
 * apps.
 */
final class EdgeContainerAgent
{
    /** packages/dply-agent's defaultPort. */
    public const PORT = 7879;

    /** In the build context, beside the Dockerfile it is copied by. */
    public const CONTEXT_FILE = '.dply-agent';

    /**
     * Add the agent to the image $image describes. Null when it was added,
     * else why not (for the deploy log).
     *
     * @param  array{path: string, port: int, generated: bool}  $image
     */
    public static function wrap(Site $site, string $checkout, array $image): ?string
    {
        if (! self::flagOn($site)) {
            return $site->organization === null ? 'no organization' : 'not turned on for this organization';
        }
        if (($site->edgeMeta()['container_agent'] ?? true) === false) {
            return 'turned off for this app';
        }
        if ((int) $image['port'] === self::PORT) {
            return 'the app listens on the agent\'s port, '.self::PORT;
        }
        $binary = (string) config('edge.build.containers.agent_binary', '/usr/local/bin/dply-agent');
        if (! is_file($binary)) {
            return 'this build server has no agent binary';
        }
        $dockerfile = (string) file_get_contents($image['path']);
        if ($image['generated']) {
            $cmd = self::lastCmd($dockerfile);
            if ($cmd === null) {
                return 'the Dockerfile has no CMD to run under it';
            }
            $lines = self::lines($cmd);
        } else {
            // The app's own Dockerfile: run exactly what its image would have.
            try {
                $command = EdgeDockerfileCommand::resolve($dockerfile);
            } catch (Throwable $e) {
                return 'could not tell what this Dockerfile runs ('.$e->getMessage().')';
            }
            if ($command['entrypoint'] === [] && $command['cmd'] === []) {
                return 'this Dockerfile\'s image has no ENTRYPOINT or CMD';
            }
            $lines = self::ownLines($command['entrypoint'], $command['cmd']);
        }
        try {
            File::copy($binary, $checkout.'/'.self::CONTEXT_FILE);
            // A repo's .dockerignore can leave dotfiles (or everything) out of
            // the context; the last matching rule wins, so let this one in.
            if (is_file($checkout.'/.dockerignore')) {
                File::append($checkout.'/.dockerignore', "\n!".self::CONTEXT_FILE."\n");
            }
            File::put($image['path'], rtrim($dockerfile)."\n\n".implode("\n", $lines)."\n");
        } catch (Throwable $e) {
            return 'could not add it: '.$e->getMessage();
        }

        return null;
    }

    /**
     * Appended to the final stage. The base image's own ENTRYPOINT is
     * replaced: generated images run their boot script through `sh -c` (the
     * CMD), which docker-php-entrypoint and node's docker-entrypoint.sh only
     * exec. Setting ENTRYPOINT clears an inherited CMD, so the CMD is written
     * again explicitly after it.
     *
     * @return list<string>
     */
    public static function lines(string $cmd): array
    {
        return [
            '# dply agent (EdgeContainerAgent): runs the command below as its child and',
            '# takes commands from the dply dashboard on port '.self::PORT.'.',
            'COPY --chmod=0755 '.self::CONTEXT_FILE.' /usr/local/bin/dply-agent',
            'ENTRYPOINT ["/usr/local/bin/dply-agent", "--"]',
            $cmd,
        ];
    }

    /**
     * For an app's own Dockerfile: the agent, then the image's own
     * ENTRYPOINT as the agent's command, and its CMD written again (setting
     * ENTRYPOINT clears an inherited one), so `docker run image args` still
     * passes args the same way.
     *
     * @param  list<string>  $entrypoint
     * @param  list<string>  $cmd
     * @return list<string>
     */
    public static function ownLines(array $entrypoint, array $cmd): array
    {
        $json = fn (array $parts): string => json_encode(array_values($parts), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            '# dply agent (EdgeContainerAgent): runs this image\'s own ENTRYPOINT/CMD as its',
            '# child and takes commands from the dply dashboard on port '.self::PORT.'.',
            'COPY --chmod=0755 '.self::CONTEXT_FILE.' /usr/local/bin/dply-agent',
            'ENTRYPOINT '.$json(['/usr/local/bin/dply-agent', '--', ...$entrypoint]),
            'CMD '.$json($cmd),
        ];
    }

    /** The last CMD instruction, as written (line continuations joined). */
    public static function lastCmd(string $dockerfile): ?string
    {
        $joined = preg_replace('/\\\\\r?\n/', ' ', $dockerfile) ?? $dockerfile;
        $last = null;
        foreach (preg_split('/\r?\n/', $joined) ?: [] as $line) {
            if (preg_match('/^\s*CMD\s+\S/i', $line) === 1) {
                $last = trim($line);
            }
        }

        return $last;
    }

    /**
     * A wss:// URL for the operator terminal in one container, good for
     * $ttl seconds (at most 120, the Worker's limit). Signed with the app's
     * queue token; the Worker checks it (terminalFetch). $operator is shown
     * in the agent's log lines for the session.
     */
    public static function terminalUrl(Site $site, string $target, string $operator, int $cols = 120, int $rows = 32, int $ttl = 60): string
    {
        $exp = time() + min(120, max(10, $ttl));
        $sig = hash_hmac('sha256', 'terminal:'.$target.':'.$exp.':'.$operator, EdgeContainerDeployer::queueToken($site));
        $base = preg_replace('#^http#', 'ws', rtrim((string) $site->edgeLiveUrl(), '/'));

        return $base.'/_dply/agent/terminal?'.http_build_query(['target' => $target, 'exp' => $exp, 'op' => $operator, 'cols' => $cols, 'rows' => $rows, 'sig' => $sig]);
    }

    /** Whether the app's live deployment was built with the agent. */
    public static function live(Site $site): bool
    {
        $live = EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_LIVE)->latest()->first();

        return ($live?->meta['agent'] ?? null) === '1';
    }

    public static function flagOn(Site $site): bool
    {
        $organization = $site->organization;

        return $organization !== null && Feature::for($organization)->active(EdgeContainerConnections::flag('agent'));
    }

    /**
     * GET one of the agent's JSON routes ("processes", "env") in one
     * container. Never wakes it: a sleeping container answers ['asleep' => true].
     *
     * @return array<string, mixed>
     */
    public static function get(Site $site, string $route, ?string $target = null): array
    {
        $url = rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/agent/'.$route.($target !== null ? '?target='.rawurlencode($target) : '');
        $response = Http::timeout(20)->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site), 'x-dply-no-wake' => '1'])->get($url);
        if ($response->status() === 409) {
            return ['asleep' => true];
        }
        if (! $response->successful()) {
            throw new RuntimeException(sprintf('The agent answered HTTP %d: %s', $response->status(), mb_substr(trim($response->body()), 0, 300)));
        }

        return (array) $response->json();
    }

    /**
     * Run one command in one of the app's containers and hand each output
     * line to $line as it arrives: ['out' => '…'], ['err' => '…'], then the
     * result ['exit' => n, 'seconds' => s, 'truncated' => bool, 'timed_out' =>
     * bool]. $target: "jobs", "instance-N" or "worker-N"; null lets the
     * Worker pick (jobs when there is one). Without $wake a sleeping container
     * answers ['asleep' => true] instead of starting.
     *
     * @param  callable(array<string, mixed>): void  $line
     * @return array<string, mixed> the result line
     */
    public static function exec(Site $site, string $command, callable $line, ?string $target = null, int $timeout = 300, bool $wake = true): array
    {
        $url = rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/agent/exec'.($target !== null ? '?target='.rawurlencode($target) : '');
        $response = Http::timeout($timeout + 60)
            ->withHeaders(array_filter(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site), 'x-dply-no-wake' => $wake ? null : '1']))
            ->withOptions(['stream' => true])
            ->post($url, ['command' => $command, 'timeout' => $timeout]);
        if ($response->status() === 409) {
            return ['asleep' => true];
        }
        if (! $response->successful()) {
            throw new RuntimeException(sprintf('The agent answered HTTP %d: %s', $response->status(), mb_substr(trim($response->body()), 0, 300)));
        }
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $result = [];
        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($at = strpos($buffer, "\n")) !== false) {
                $decoded = json_decode(substr($buffer, 0, $at), true);
                $buffer = substr($buffer, $at + 1);
                if (! is_array($decoded)) {
                    continue;
                }
                if (array_key_exists('exit', $decoded)) {
                    $result = $decoded;
                }
                $line($decoded);
            }
        }

        return $result !== [] ? $result : throw new RuntimeException('The command\'s output ended before its result: the container may have stopped.');
    }
}
