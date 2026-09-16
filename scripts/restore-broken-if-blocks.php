<?php

declare(strict_types=1);

/**
 * Restore broken `if true` / `if false` blocks introduced by an automated PHPStan pass.
 * Replaces entire if-blocks with the HEAD version when the working tree line is invalid.
 */
$root = dirname(__DIR__);
$broken = [];

foreach (['app/Services', 'app/Support', 'app/Modules/TaskRunner'] as $relDir) {
    $dir = $root.'/'.$relDir;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }

        $path = $fileInfo->getPathname();
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $idx => $line) {
            if (preg_match('/^\s+if (true|false) \{/', $line)) {
                $broken[] = [$path, $idx + 1];
            }
        }
    }
}

$fixed = 0;

foreach ($broken as [$path, $lineNum]) {
    $rel = str_replace($root.'/', '', $path);
    $head = shell_exec('git show HEAD:'.escapeshellarg($rel).' 2>/dev/null');
    if ($head === null || $head === '') {
        fwrite(STDERR, "No HEAD version for {$rel}\n");

        continue;
    }

    $headLines = explode("\n", rtrim($head, "\n"));
    $workLines = file($path, FILE_IGNORE_NEW_LINES);
    if ($workLines === false) {
        continue;
    }

    $headIdx = $lineNum - 1;
    if (! isset($headLines[$headIdx])) {
        fwrite(STDERR, "HEAD line missing for {$rel}:{$lineNum}\n");

        continue;
    }

    if (! preg_match('/^\s+if \(/', $headLines[$headIdx])) {
        fwrite(STDERR, "HEAD line not an if ( for {$rel}:{$lineNum}: {$headLines[$headIdx]}\n");

        continue;
    }

    // Replace from `if` through closing `}` of the block (single-statement body assumed).
    $workLines[$headIdx] = $headLines[$headIdx];
    if (isset($workLines[$headIdx + 1], $headLines[$headIdx + 1])) {
        $workLines[$headIdx + 1] = $headLines[$headIdx + 1];
    }
    if (isset($workLines[$headIdx + 2], $headLines[$headIdx + 2]) && preg_match('/^\s+\}/', $headLines[$headIdx + 2])) {
        $workLines[$headIdx + 2] = $headLines[$headIdx + 2];
    }

    file_put_contents($path, implode("\n", $workLines)."\n");
    echo "Restored if-block: {$rel}:{$lineNum}\n";
    $fixed++;
}

echo "Done. {$fixed} block(s) restored.\n";
