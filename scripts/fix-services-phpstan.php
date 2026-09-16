<?php

declare(strict_types=1);

/**
 * Bulk-fix PHPStan level 6 issues in app/Services, app/Support, app/Modules/TaskRunner.
 *
 * Usage: php scripts/fix-services-phpstan.php [--dry-run] [--json=/path/to/phpstan.json]
 */
$dryRun = in_array('--dry-run', $argv ?? [], true);
$jsonPath = '/tmp/phpstan-services.json';

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--json=')) {
        $jsonPath = substr($arg, 7);
    }
}

if (! is_file($jsonPath)) {
    fwrite(STDERR, "JSON file not found: {$jsonPath}\n");
    exit(1);
}

$decoded = json_decode((string) file_get_contents($jsonPath), true);
$byFile = [];

foreach ($decoded['files'] ?? [] as $file => $data) {
    foreach ($data['messages'] ?? [] as $message) {
        $byFile[$file][] = [
            'line' => (int) ($message['line'] ?? 0),
            'identifier' => (string) ($message['identifier'] ?? ''),
            'message' => (string) ($message['message'] ?? ''),
        ];
    }
}

$changedFiles = 0;

foreach ($byFile as $path => $fileErrors) {
    if (! is_file($path)) {
        continue;
    }

    $original = file_get_contents($path);
    if ($original === false) {
        continue;
    }

    $content = $original;
    $content = fixShapeArrayTypesInDocblocks($content);
    $content = fixMissingIterableValue($content, $fileErrors);
    $content = fixNullCoalesceOffsets($content, $fileErrors);
    $content = fixNullsafeNeverNull($content, $fileErrors);

    if ($content !== $original) {
        $changedFiles++;
        if (! $dryRun) {
            file_put_contents($path, $content);
        }
        echo ($dryRun ? '[dry-run] ' : '')."Updated: {$path}\n";
    }
}

echo "Done. {$changedFiles} file(s) ".($dryRun ? 'would be ' : '')."updated.\n";

/**
 * Upgrade bare `array` / `?array` inside @param array{...} shape docblocks.
 */
function fixShapeArrayTypesInDocblocks(string $content): string
{
    return preg_replace_callback(
        '/\/\*\*(?:[^*]|\*(?!\/))*\*\//s',
        static function (array $m): string {
            $doc = $m[0];
            if (! str_contains($doc, 'array{')) {
                return $doc;
            }

            $doc = preg_replace('/([?:]\s*)\?array(?![<{\w])/', '$1?array<string, mixed>', $doc) ?? $doc;
            $doc = preg_replace('/([?:,]\s*)array(?![<{\w])/', '$1array<string, mixed>', $doc) ?? $doc;

            return $doc;
        },
        $content
    ) ?? $content;
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixMissingIterableValue(string $content, array $fileErrors): string
{
    $fixes = [];

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'missingType.iterableValue') {
            continue;
        }

        $msg = $error['message'];
        $method = null;
        if (preg_match('/::(\w+)\(\)/', $msg, $mm)) {
            $method = $mm[1];
        }

        if (preg_match('/parameter \$(\w+) with no value type/', $msg, $m)) {
            $fixes[] = ['method' => $method, 'line' => $error['line'], 'params' => [$m[1] => 'array<string, mixed>'], 'return' => null];
        } elseif (preg_match('/return type has no value type/', $msg)) {
            $fixes[] = ['method' => $method, 'line' => $error['line'], 'params' => [], 'return' => 'array<string, mixed>'];
        }
    }

    if ($fixes === []) {
        return $content;
    }

    $lines = explode("\n", $content);

    foreach ($fixes as $fix) {
        $fnLineIdx = -1;

        if ($fix['method'] !== null) {
            foreach ($lines as $idx => $line) {
                if (preg_match('/function\s+'.preg_quote($fix['method'], '/').'\s*\(/', $line)) {
                    $fnLineIdx = $idx;
                    break;
                }
            }
        }

        if ($fnLineIdx < 0) {
            $fnIdx = $fix['line'] - 1;
            $fnLineIdx = $fnIdx;
            while ($fnLineIdx >= 0 && ! preg_match('/function\s+\w+\s*\(/', $lines[$fnLineIdx])) {
                $fnLineIdx--;
            }
        }

        if ($fnLineIdx < 0) {
            continue;
        }

        $indent = preg_match('/^(\s*)/', $lines[$fnLineIdx], $m) ? $m[1] : '    ';

        $docEnd = $fnLineIdx;
        $docStart = $fnLineIdx;
        if ($fnLineIdx > 0 && preg_match('/^\s*\*\/\s*$/', $lines[$fnLineIdx - 1])) {
            $docEnd = $fnLineIdx - 1;
            $docStart = $docEnd;
            while ($docStart > 0 && ! preg_match('/^\s*\/\*\*/', $lines[$docStart])) {
                $docStart--;
            }
        } elseif ($fnLineIdx > 0 && preg_match('/^\s*\/\*\*/', $lines[$fnLineIdx - 1])) {
            $docStart = $fnLineIdx - 1;
            $docEnd = $docStart;
            while ($docEnd + 1 < count($lines) && ! preg_match('/^\s*\*\/\s*$/', $lines[$docEnd + 1])) {
                $docEnd++;
            }
        } else {
            $docStart = -1;
        }

        $newDocLines = [];

        if ($docStart >= 0) {
            $existing = implode("\n", array_slice($lines, $docStart, $docEnd - $docStart + 1));

            foreach ($fix['params'] as $var => $type) {
                if (preg_match('/@param\s+.*\$'.preg_quote($var, '/').'\b/', $existing)) {
                    $existing = preg_replace(
                        '/@param\s+\??(array|iterable)\s+(\$'.preg_quote($var, '/').'\b)/',
                        '@param  '.$type.' $2',
                        $existing
                    ) ?? $existing;
                    $existing = preg_replace_callback(
                        '/@param\s+array\{[^}]*\$(?:'.preg_quote($var, '/').')\b[^}]*\}/',
                        static function () use ($type, $var): string {
                            return '@param  '.$type.' $'.$var;
                        },
                        $existing
                    ) ?? $existing;
                } else {
                    $newDocLines[] = $indent.' * @param  '.$type.' $'.$var;
                }
            }

            if ($fix['return'] !== null) {
                if (preg_match('/@return\s+/', $existing)) {
                    $existing = preg_replace(
                        '/@return\s+\??(array|iterable)\b/',
                        '@return '.$fix['return'],
                        $existing
                    ) ?? $existing;
                } else {
                    $newDocLines[] = $indent.' * @return '.$fix['return'];
                }
            }

            if ($newDocLines !== []) {
                array_splice($lines, $docEnd, 0, $newDocLines);
            } elseif ($existing !== implode("\n", array_slice($lines, $docStart, $docEnd - $docStart + 1))) {
                $existingLines = explode("\n", $existing);
                array_splice($lines, $docStart, $docEnd - $docStart + 1, $existingLines);
            }
        } else {
            $docblock = [$indent.'/**'];
            foreach ($fix['params'] as $var => $type) {
                $docblock[] = $indent.' * @param  '.$type.' $'.$var;
            }
            if ($fix['return'] !== null) {
                $docblock[] = $indent.' * @return '.$fix['return'];
            }
            $docblock[] = $indent.' */';
            array_splice($lines, $fnLineIdx, 0, $docblock);
        }
    }

    return implode("\n", $lines);
}

// Legacy function removed

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixNullCoalesceOffsets(string $content, array $fileErrors): string
{
    $lines = explode("\n", $content);

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'nullCoalesce.offset') {
            continue;
        }

        $lineIdx = $error['line'] - 1;
        if (! isset($lines[$lineIdx])) {
            continue;
        }

        if (! preg_match("/Offset '([^']+)'/", $error['message'], $m)) {
            continue;
        }

        $offset = $m[1];
        $line = $lines[$lineIdx];

        // Only strip ?? when the fallback is a simple literal — avoids breaking
        // expressions like ($arr['key'] ?? $other->method()).
        $newLine = preg_replace(
            '/(\[[\'"]'.preg_quote($offset, '/').'[\'"]\])\s*\?\?\s*(?:null|0|\'\'|""|\[\]|false|true)\b/',
            '$1',
            $line
        );

        if ($newLine !== null && $newLine !== $line) {
            $lines[$lineIdx] = $newLine;
        }
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixAlreadyNarrowedTypes(string $content, array $fileErrors): string
{
    $lines = explode("\n", $content);

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'function.alreadyNarrowedType') {
            continue;
        }

        $lineIdx = $error['line'] - 1;
        if (! isset($lines[$lineIdx])) {
            continue;
        }

        $line = $lines[$lineIdx];
        $original = $line;

        if (str_contains($error['message'], 'is_array()')) {
            $line = preg_replace('/is_array\(([^)]+)\)\s*\?\s*([^:]+)\s*:\s*\[\]/', '($2)', $line) ?? $line;
            $line = preg_replace('/is_array\(([^)]+)\)\s*\?\s*([^:]+)\s*:\s*null/', '($2)', $line) ?? $line;
            $line = preg_replace('/is_array\(([^)]+)\)\s*&&/', '($1) &&', $line) ?? $line;
            // Unwrap redundant is_array() guard — keep valid PHP syntax.
            $line = preg_replace('/if\s*\(\s*is_array\(([^)]+)\)\s*\)\s*\{/', '{', $line) ?? $line;
        }

        if (str_contains($error['message'], 'is_string()')) {
            $line = preg_replace('/is_string\(([^)]+)\)\s*\?\s*([^:]+)\s*:\s*\'\'/', '($2)', $line) ?? $line;
            $line = preg_replace('/is_string\(([^)]+)\)\s*&&\s*/', '($1) && ', $line) ?? $line;
            $line = preg_replace('/is_string\(([^)]+)\)\s*\?\s*([^:]+)\s*:\s*null/', '($2)', $line) ?? $line;
        }

        if (str_contains($error['message'], 'is_int()')) {
            $line = preg_replace('/is_int\(([^)]+)\)\s*&&\s*/', '($1) && ', $line) ?? $line;
        }

        if (str_contains($error['message'], 'is_numeric()')) {
            $line = preg_replace('/is_numeric\(([^)]+)\)\s*&&\s*/', '($1) && ', $line) ?? $line;
        }

        if ($line !== $original) {
            $lines[$lineIdx] = $line;
        }
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixNullsafeNeverNull(string $content, array $fileErrors): string
{
    $lines = explode("\n", $content);

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'nullsafe.neverNull') {
            continue;
        }

        $lineIdx = $error['line'] - 1;
        if (! isset($lines[$lineIdx])) {
            continue;
        }

        $lines[$lineIdx] = str_replace('?->', '->', $lines[$lineIdx]);
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixNotIdenticalAlwaysTrue(string $content, array $fileErrors): string
{
    $lines = explode("\n", $content);

    foreach ($fileErrors as $error) {
        if (! in_array($error['identifier'], ['notIdentical.alwaysTrue', 'notIdentical.alwaysFalse', 'identical.alwaysTrue', 'identical.alwaysFalse'], true)) {
            continue;
        }

        $lineIdx = $error['line'] - 1;
        if (! isset($lines[$lineIdx])) {
            continue;
        }

        $line = $lines[$lineIdx];

        if (str_contains($error['message'], 'null')) {
            // Remove redundant null checks on non-nullable types
            $line = preg_replace('/\s*&&\s*\$[a-zA-Z_][\w\->]*\s*!==\s*null/', '', $line) ?? $line;
            $line = preg_replace('/\s*\|\|\s*\$[a-zA-Z_][\w\->]*\s*===\s*null/', '', $line) ?? $line;
            $line = preg_replace('/\(\s*\$[a-zA-Z_][\w\->]*\s*!==\s*null\s*\)/', 'true', $line) ?? $line;
            $line = preg_replace('/\(\s*\$[a-zA-Z_][\w\->]*\s*===\s*null\s*\)/', 'false', $line) ?? $line;
        }

        $lines[$lineIdx] = $line;
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array{line: int, identifier: string, message: string}>  $fileErrors
 */
function fixInstanceofAlwaysTrue(string $content, array $fileErrors): string
{
    $lines = explode("\n", $content);

    foreach ($fileErrors as $error) {
        if ($error['identifier'] !== 'instanceof.alwaysTrue') {
            continue;
        }

        $lineIdx = $error['line'] - 1;
        if (! isset($lines[$lineIdx])) {
            continue;
        }

        $line = $lines[$lineIdx];
        $line = preg_replace(
            '/\$[a-zA-Z_][\w]*\s+instanceof\s+[\\\\\w]+\s*\?/',
            'true ?',
            $line
        ) ?? $line;

        $lines[$lineIdx] = $line;
    }

    return implode("\n", $lines);
}
