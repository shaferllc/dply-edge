<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * The cron expressions a container app's scheduled tasks can use.
 *
 * Container apps have one every-minute Cron Trigger and the site Worker
 * decides which tasks are due (cronDue() in EdgeContainerDeployer's Worker
 * script), so there is no 5-schedule limit. This is that matcher's grammar,
 * checked in PHP so the dashboard can refuse or flag an expression the Worker
 * would never run: five fields of `*`, numbers, `a-b` ranges, `,` lists and
 * `/n` steps, with JAN–DEC and SUN–SAT names. Cloudflare's own extras
 * (`L`, `W`, `#`, `?`) are not supported.
 */
final class EdgeCronExpression
{
    /** Most scheduled tasks a container app runs. */
    public const MAX_TASKS = 50;

    private const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    private const DAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

    /** minute, hour, day of month, month, day of week (0 and 7 are Sunday) */
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    public static function supported(string $expression): bool
    {
        $fields = preg_split('/\s+/', strtoupper(trim($expression))) ?: [];
        if (count($fields) !== 5) {
            return false;
        }

        foreach ($fields as $i => $field) {
            $field = self::numbered($field, $i);
            if ($field === null) {
                return false;
            }
            [$lo, $hi] = self::RANGES[$i];
            foreach (explode(',', $field) as $part) {
                if (preg_match('#^(\*|(\d+)(?:-(\d+))?)(?:/(\d+))?$#', $part, $m) !== 1) {
                    return false;
                }
                if (isset($m[4]) && (int) $m[4] < 1) {
                    return false;
                }
                if ($m[1] === '*') {
                    continue;
                }
                $a = (int) $m[2];
                $b = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $a;
                if ($a < $lo || $b > $hi || $a > $b) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Month and weekday names as numbers, the way the Worker reads them; null for an unknown name. */
    private static function numbered(string $field, int $index): ?string
    {
        $names = match ($index) {
            3 => self::MONTHS,
            4 => self::DAYS,
            default => [],
        };
        $unknown = false;
        $field = (string) preg_replace_callback('/[A-Z]+/', function (array $m) use ($names, $index, &$unknown): string {
            $at = array_search($m[0], $names, true);
            if ($at === false) {
                $unknown = true;

                return $m[0];
            }

            return (string) ($index === 3 ? $at + 1 : $at);
        }, $field);

        return $unknown ? null : $field;
    }
}
