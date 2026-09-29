<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A colour for a plain log line, from what it says: most build output
 * (Docker, wrangler, dply's own lines) carries no ANSI colour, so the log
 * dialogs read it instead. ANSI colours inside a line still win: they are
 * spans inside the line this class colours.
 *
 * Order matters: an error line that also says "done" is an error.
 */
final class LogLineTone
{
    public static function classes(string $line): string
    {
        $text = trim($line);

        return match (true) {
            $text === '' => '',
            str_starts_with($text, '[dply:step]') => 'mt-3 font-semibold text-sky-300',
            preg_match('/\b(ERROR|CRITICAL|EMERGENCY|Exception|Fatal|FAILED|failed|SQLSTATE|Traceback|panic:)\b|✘|HTTP 5\d\d\b|exited with code [1-9]/', $text) === 1 => 'text-rose-300',
            preg_match('/\b(WARN|WARNING|warning|deprecated|Deprecated)\b|⚠/', $text) === 1 => 'text-amber-300',
            preg_match('/\b(SUCCESS|DONE|Applied changes|Rollout settled|deployed as|is ready|Pushed|Compiled|built in)\b|✓|✔|HTTP 2\d\d\b/', $text) === 1 => 'text-emerald-300',
            preg_match('/^#\d+ /', $text) === 1 => 'text-zinc-500',
            default => '',
        };
    }
}
