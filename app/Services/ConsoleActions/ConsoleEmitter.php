<?php

namespace App\Services\ConsoleActions;

use App\Models\ConsoleAction;
use Illuminate\Support\Facades\DB;

/**
 * The function-shaped emitter passed into provisioners and other workers that
 * stream progress lines. Hard-cutover signature:
 *
 *   $emit(string $line, string $level = 'info', ?string $source = null): void
 *
 * Every entry lands in {@see ConsoleAction::output} as a JSON wrapper of shape
 *   { v: 1, lines: [{t, level, source, line}, ...] }
 * with line count capped at config('console_actions.max_lines').
 *
 * Implemented as `__invoke` so callsites can keep treating it as a callable —
 * the existing 7 jobs pass a Closure into the provisioner; we pass a
 * `ConsoleEmitter` instead and `$emit('foo')` keeps working, with optional
 * extra args.
 *
 * A null run ID makes it a no-op so unit tests / dry-runs can pass an emitter
 * that drops everything.
 */
class ConsoleEmitter
{
    /**
     * @param  string|null  $runId  console_actions.id; null for no-op mode.
     */
    public function __construct(
        private ?string $runId = null,
    ) {}

    /**
     * Append one entry to the run's output.
     *
     * Levels are normalised against {@see ConsoleAction::LEVELS} — anything
     * unknown collapses to 'info' so a typo at a callsite doesn't poison the
     * column.
     *
     * If `$line` starts with `[bracket]` and `$source` was not supplied, the
     * bracket is parsed off as the source. This mirrors the existing
     * `[nginx] resolving server connection` convention so old call patterns
     * still produce structured rows when migrated mechanically.
     */
    public function __invoke(string $line, string $level = ConsoleAction::LEVEL_INFO, ?string $source = null): void
    {
        if ($this->runId === null) {
            return;
        }

        if (str_contains($line, "\n") || str_contains($line, "\r")) {
            foreach (preg_split('/\R/u', $line) ?: [] as $part) {
                $this->__invoke($part, $level, $source);
            }

            return;
        }

        $line = $this->trimLine($line);
        if ($line === '') {
            return;
        }

        if ($source === null) {
            [$line, $source] = $this->splitBracketSource($line);
        }

        $level = in_array($level, ConsoleAction::LEVELS, true) ? $level : ConsoleAction::LEVEL_INFO;

        $this->appendEntry([
            't' => (int) round(microtime(true) * 1000),
            'level' => $level,
            'source' => $source,
            'line' => $line,
        ]);
    }

    public function step(string $source, string $line): void
    {
        $this->__invoke($line, ConsoleAction::LEVEL_STEP, $source);
    }

    public function info(string $line, ?string $source = null): void
    {
        $this->__invoke($line, ConsoleAction::LEVEL_INFO, $source);
    }

    public function warn(string $line, ?string $source = null): void
    {
        $this->__invoke($line, ConsoleAction::LEVEL_WARN, $source);
    }

    public function error(string $line, ?string $source = null): void
    {
        $this->__invoke($line, ConsoleAction::LEVEL_ERROR, $source);
    }

    public function success(string $line, ?string $source = null): void
    {
        $this->__invoke($line, ConsoleAction::LEVEL_SUCCESS, $source);
    }

    /**
     * Read-modify-write the JSON column inside a row lock. Simpler than
     * driver-specific JSON ops and correct for our concurrency model — every
     * console-able job is `ShouldBeUnique`d on (subject, kind), so at most
     * one writer per row at a time. The lock just guards against the rare
     * race where the worker writes a line while the user's poll request is
     * also reading the row.
     *
     * @param  array<string, mixed>  $entry
     */
    private function appendEntry(array $entry): void
    {
        $maxLines = (int) config('console_actions.max_lines', 5000);
        $version = (int) config('console_actions.current_version', 1);

        DB::transaction(function () use ($entry, $maxLines, $version): void {
            $row = ConsoleAction::query()->lockForUpdate()->find($this->runId);
            if ($row === null) {
                return;
            }

            $output = is_array($row->output) ? $row->output : [];
            $lines = $output['lines'] ?? [];
            if (! is_array($lines)) {
                $lines = [];
            }

            $lines[] = $entry;
            if (count($lines) > $maxLines) {
                $lines = array_slice($lines, -$maxLines);
            }

            $row->output = ['v' => $version, 'lines' => array_values($lines)];
            $row->save();
        });
    }

    /**
     * Cap a single line at a sane size so a runaway log line can't blow the
     * row past Postgres's TOAST inline budget on its own.
     */
    private function trimLine(string $line): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }

        $cap = 4096;

        return mb_strlen($line) > $cap ? mb_substr($line, 0, $cap).'…' : $line;
    }

    /**
     * @return array{0: string, 1: string|null} [stripped line, parsed source or null]
     */
    private function splitBracketSource(string $line): array
    {
        if (preg_match('/^\[([a-z0-9_-]{1,32})\]\s*(.*)$/i', $line, $m) === 1) {
            return [trim($m[2]), strtolower($m[1])];
        }

        return [$line, null];
    }
}
