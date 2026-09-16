<?php

declare(strict_types=1);

/**
 * Adds @property PHPDoc lines to Eloquent models from $fillable + casts().
 * Run: php scripts/add-model-property-phpdoc.php [model class names...]
 */
$root = dirname(__DIR__);
$propsNeeded = json_decode(file_get_contents('/tmp/phpstan-props.json'), true, 512, JSON_THROW_ON_ERROR);

$castTypeMap = [
    'int' => 'int',
    'integer' => 'int',
    'float' => 'float',
    'double' => 'float',
    'string' => 'string',
    'bool' => 'bool',
    'boolean' => 'bool',
    'array' => 'array',
    'json' => 'array',
    'object' => 'object',
    'collection' => 'Illuminate\Support\Collection',
    'datetime' => 'Carbon\Carbon',
    'date' => 'Carbon\Carbon',
    'immutable_datetime' => 'Carbon\CarbonImmutable',
    'immutable_date' => 'Carbon\CarbonImmutable',
    'timestamp' => 'Carbon\Carbon',
    'encrypted' => 'string',
    'encrypted:array' => 'array',
    'encrypted:collection' => 'Illuminate\Support\Collection',
    'encrypted:object' => 'object',
    'hashed' => 'string',
];

function resolveModelPath(string $class, string $root): ?string
{
    if (! str_starts_with($class, 'App\\Models\\')) {
        return null;
    }

    $relative = 'app/Models/'.str_replace('App\\Models\\', '', $class).'.php';

    $path = $root.'/'.$relative;

    return is_file($path) ? $path : null;
}

function parseFillable(string $contents): array
{
    if (! preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\];/s', $contents, $m)) {
        return [];
    }

    preg_match_all("/'([^']+)'/", $m[1], $fields);

    return $fields[1];
}

function parseCasts(string $contents): array
{
    if (! preg_match('/protected\s+function\s+casts\s*\(\)\s*:\s*array\s*\{.*?return\s*\[(.*?)\];/s', $contents, $m)) {
        return [];
    }

    $casts = [];
    preg_match_all("/'([^']+)'\s*=>\s*([^,\n]+)/", $m[1], $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $casts[$match[1]] = trim($match[2], " \t\n\r\0\x0B,");
    }

    return $casts;
}

function phpTypeForCast(string $castExpr, array $castTypeMap): string
{
    $castExpr = trim($castExpr, "'\"");

    if (str_contains($castExpr, '::class')) {
        return str_replace('::class', '', $castExpr);
    }

    if (preg_match('/^decimal:\d+$/', $castExpr)) {
        return 'string';
    }

    return $castTypeMap[$castExpr] ?? 'mixed';
}

function buildPropertyLines(array $fields, array $casts, array $castTypeMap): array
{
    $lines = [];
    $allFields = array_unique(array_merge($fields, array_keys($casts)));

    foreach ($allFields as $field) {
        $type = 'string';
        if (isset($casts[$field])) {
            $type = phpTypeForCast($casts[$field], $castTypeMap);
        }

        $nullable = in_array($field, ['meta', 'comment', 'label', 'finished_at', 'started_at', 'error_message'], true)
            || str_ends_with($field, '_at')
            || str_ends_with($field, '_id');

        $docType = $nullable ? "?{$type}" : $type;
        $lines["\${$field}"] = "@property {$docType} \${$field}";
    }

    return $lines;
}

function mergePhpDoc(string $contents, array $propertyLines): string
{
    $propertyBlock = '';
    foreach ($propertyLines as $line) {
        $propertyBlock .= " * {$line}\n";
    }

    if (preg_match('/\/\*\*(.*?)\*\/\s*\n(class\s+)/s', $contents, $m, PREG_OFFSET_CAPTURE)) {
        $doc = $m[1][0];
        $classPos = $m[2][1];

        foreach ($propertyLines as $prop => $line) {
            if (str_contains($doc, $prop)) {
                continue;
            }
            $doc = rtrim($doc)."\n * {$line}\n";
        }

        $newDoc = "/**{$doc} */\n";

        return substr($contents, 0, $m[0][1]).$newDoc.substr($contents, $classPos);
    }

    $newDoc = "/**\n{$propertyBlock} */\n";

    return preg_replace('/(\nclass\s+)/', "\n{$newDoc}class ", $contents, 1) ?? $contents;
}

$targets = $argv[1] ?? null;

if ($targets === null) {
    $classes = array_keys($propsNeeded);
} else {
    $classes = array_slice($argv, 1);
}

foreach ($classes as $class) {
    if ($class === 'Illuminate\\Database\\Eloquent\\Model') {
        continue;
    }

    $path = resolveModelPath($class, $root);
    if ($path === null) {
        echo "SKIP (no file): {$class}\n";

        continue;
    }

    $contents = file_get_contents($path);
    $fillable = parseFillable($contents);
    $casts = parseCasts($contents);
    $propertyLines = buildPropertyLines($fillable, $casts, $castTypeMap);

    // Ensure PHPStan-reported properties are included.
    foreach ($propsNeeded[$class] ?? [] as $prop => $_) {
        if (! isset($propertyLines[$prop])) {
            $field = ltrim($prop, '$');
            $type = isset($casts[$field]) ? phpTypeForCast($casts[$field], $castTypeMap) : 'mixed';
            $propertyLines[$prop] = "@property {$type} {$prop}";
        }
    }

    ksort($propertyLines);
    $updated = mergePhpDoc($contents, $propertyLines);

    if ($updated !== $contents) {
        file_put_contents($path, $updated);
        echo "UPDATED: {$class}\n";
    } else {
        echo "UNCHANGED: {$class}\n";
    }
}
