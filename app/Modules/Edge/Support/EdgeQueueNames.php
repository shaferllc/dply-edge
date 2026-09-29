<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * The queue names a Laravel app uses, read from its code at build so the
 * "Add queue workers" sheet can prefill them (stored on the deployment as
 * meta.queue_scan).
 *
 * Sources, all literal strings (a name built at runtime can't be found):
 *   - config/horizon.php: each supervisor's `queue` list, kept as a group;
 *   - config/queue.php: each connection's `queue` (the env() default too);
 *   - app/ and routes/: onQueue('x'), `$queue = 'x'` on jobs and listeners.
 */
final class EdgeQueueNames
{
    /** Most names kept: past this it is noise, not a queue list. */
    private const MAX = 20;

    /**
     * @return array{queues: list<string>, groups: list<list<string>>}
     */
    public static function scan(string $checkout): array
    {
        $names = [];
        $groups = [];

        $horizon = self::read($checkout.'/config/horizon.php');
        if ($horizon !== '') {
            foreach (self::queueValues($horizon) as $list) {
                $groups[implode(',', $list)] = $list;
                array_push($names, ...$list);
            }
        }

        $queue = self::read($checkout.'/config/queue.php');
        foreach (self::queueValues($queue) as $list) {
            array_push($names, ...$list);
        }

        foreach (['app', 'routes'] as $dir) {
            if (! is_dir($checkout.'/'.$dir)) {
                continue;
            }
            try {
                foreach ((new Finder)->files()->in($checkout.'/'.$dir)->name('*.php')->size('< 256K') as $file) {
                    $code = $file->getContents();
                    preg_match_all('/onQueue\(\s*[\'"]([\w.:-]+)[\'"]/', $code, $m);
                    array_push($names, ...$m[1]);
                    preg_match_all('/\$queue\s*=\s*[\'"]([\w.:-]+)[\'"]/', $code, $m);
                    array_push($names, ...$m[1]);
                }
            } catch (Throwable) {
                // An unreadable tree just means fewer suggestions.
            }
        }

        return [
            'queues' => array_slice(self::ordered($names), 0, self::MAX),
            'groups' => array_values(count($groups) > 1 ? array_map(self::ordered(...), $groups) : []),
        ];
    }

    /**
     * Laravel's --queue order: urgent names first, then default, then the
     * rest as found, with the slow ones last. Duplicates dropped.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function ordered(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names), static fn ($n) => $n !== '')));
        $rank = static fn (string $n): int => match (true) {
            preg_match('/critical|urgent|high|priority/i', $n) === 1 => 0,
            $n === 'default' => 1,
            preg_match('/low|slow|bulk|batch|background/i', $n) === 1 => 3,
            default => 2,
        };
        $indexed = array_map(null, $names, array_keys($names));
        usort($indexed, static fn (array $a, array $b): int => [$rank($a[0]), $a[1]] <=> [$rank($b[0]), $b[1]]);

        return array_column($indexed, 0);
    }

    /** Save a scan on the deployment without a model save (the runner holds its own copy). */
    public static function record(string $deploymentId, array $scan): void
    {
        DB::update(
            "update edge_deployments set meta = jsonb_set(coalesce(meta::jsonb, '{}'::jsonb), '{queue_scan}', ?::jsonb)::json where id = ?",
            [json_encode($scan, JSON_THROW_ON_ERROR), $deploymentId],
        );
    }

    /**
     * Every `'queue' => …` value in a config file: a list, a comma string,
     * or env('X', 'default').
     *
     * @return list<list<string>>
     */
    private static function queueValues(string $php): array
    {
        $out = [];
        preg_match_all('/[\'"]queue[\'"]\s*=>\s*(\[[^\]]*\]|env\([^)]*\)|[\'"][^\'"]*[\'"])/', $php, $m);
        foreach ($m[1] as $value) {
            if (str_starts_with($value, 'env(')) {
                // Only the default: the first argument is the variable's name.
                $value = preg_match('/,\s*([\'"][^\'"]*[\'"])/', $value, $d) === 1 ? $d[1] : '';
            }
            preg_match_all('/[\'"]([^\'"]*)[\'"]/', $value, $strings);
            $list = [];
            foreach ($strings[1] as $string) {
                foreach (explode(',', $string) as $name) {
                    if (preg_match('/^[\w.:-]+$/', trim($name)) === 1) {
                        $list[] = trim($name);
                    }
                }
            }
            if ($list !== []) {
                $out[] = $list;
            }
        }

        return $out;
    }

    private static function read(string $path): string
    {
        return is_file($path) ? (string) @file_get_contents($path, length: 262_144) : '';
    }
}
