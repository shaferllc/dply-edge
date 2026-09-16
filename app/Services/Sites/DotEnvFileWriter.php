<?php

declare(strict_types=1);

namespace App\Services\Sites;

/**
 * Render an array of env vars back into .env file content.
 * Always quotes with double-quotes when the value contains
 * whitespace, "#", "=", quotes, or backslashes — matches what our
 * own parser will round-trip cleanly. Wraps backslashes and dquotes
 * in escapes inside quoted values.
 *
 * Output is sorted by key for deterministic diffing across runs.
 *
 * Optional comments map: { KEY => "comment text" } emits `# comment\n`
 * lines immediately above each KEY=value. Multi-line comments (\n in
 * the value) become multiple `# ` lines. Round-trips with
 * {@see DotEnvFileParser::parse()}.
 */
class DotEnvFileWriter
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $comments  KEY => comment text
     */
    public function render(array $variables, array $comments = []): string
    {
        ksort($variables);
        $lines = [];
        foreach ($variables as $key => $value) {
            $comment = trim((string) ($comments[$key] ?? ''));
            if ($comment !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $comment) ?: [] as $commentLine) {
                    $lines[] = '# '.$commentLine;
                }
            }
            $lines[] = $key.'='.$this->formatValue((string) $value);
        }

        return implode("\n", $lines).(empty($lines) ? '' : "\n");
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#=\'"\\\\]/', $value) === 1) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return '"'.$escaped.'"';
        }

        return $value;
    }
}
