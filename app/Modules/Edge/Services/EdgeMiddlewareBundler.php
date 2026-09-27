<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Detects user-authored middleware (`middleware.{ts,js}` or
 * `src/middleware.{ts,js}` in the build directory) and bundles it
 * into a single ESM module with esbuild — runs inside the same
 * Docker image as the main build so we don't depend on host-side
 * Node + esbuild versions.
 *
 * Resulting bundle is uploaded to the dispatch namespace by
 * {@see EdgeMiddlewareBundleUploader} and the platform Worker
 * dispatches every request to it before the R2 / origin lookup.
 *
 * Skipped entirely when:
 *   - runtime_mode === ssr (the SSR Worker owns middleware via OpenNext)
 *   - no middleware file is present
 *   - the esbuild bundle fails (logged + treated as no middleware)
 */
class EdgeMiddlewareBundler
{
    /** Cap bundle size — Workers script limit is ~10 MB; middleware should be tiny. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const CANDIDATE_PATHS = [
        'src/middleware.ts',
        'src/middleware.tsx',
        'src/middleware.js',
        'src/middleware.mjs',
        'middleware.ts',
        'middleware.tsx',
        'middleware.js',
        'middleware.mjs',
    ];

    /**
     * @param  callable  $log  fn(string) — appends a line to the build log
     * @return array{bundled: false}|array{bundled: true, source_path: string, modules: array<string, string>, entry_module: string, bytes: int}
     */
    public function bundle(string $checkoutDir, string $dockerImage, callable $log): array
    {
        $sourcePath = $this->detectSource($checkoutDir);
        if ($sourcePath === null) {
            return ['bundled' => false];
        }
        $log('[middleware] detected '.$sourcePath.' — bundling with esbuild');

        $outputRel = '.dply/middleware.bundled.mjs';
        $outputAbs = $checkoutDir.'/'.$outputRel;
        @mkdir(dirname($outputAbs), 0o755, true);

        // Bundle inside the same node image as the build so the
        // user's lockfile/node version drives the esbuild + target.
        // --packages=external would break since we want a self-
        // contained module; runtime imports must come from Workers
        // built-ins or be inlined.
        $bundleScript = sprintf(
            'npx --yes esbuild %s --bundle --format=esm --platform=neutral --target=es2022 --conditions=worker,browser --outfile=%s --log-level=warning',
            escapeshellarg($sourcePath),
            escapeshellarg($outputRel),
        );
        $process = Process::timeout(180)->run([
            'docker', 'run', '--rm',
            // npx resolves the repo's own node_modules/.bin — customer code.
            ...EdgeBuildRunner::sandboxFlags(),
            '-v', $checkoutDir.':/src',
            '-w', '/src',
            $dockerImage,
            'bash', '-lc', EdgeBuildRunner::sandboxScript($bundleScript),
        ]);
        $log($process->output().$process->errorOutput());

        if (! $process->successful()) {
            $log('[middleware] esbuild bundle failed — skipping middleware upload');

            return ['bundled' => false];
        }
        if (! is_file($outputAbs)) {
            $log('[middleware] esbuild reported success but produced no output — skipping');

            return ['bundled' => false];
        }

        $bytes = filesize($outputAbs);
        if ($bytes === false || $bytes <= 0) {
            $log('[middleware] esbuild output was empty — skipping');

            return ['bundled' => false];
        }
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException(
                'Middleware bundle '.number_format($bytes).' bytes exceeds the '.number_format(self::MAX_BYTES).' byte cap.'
            );
        }

        $log(sprintf('[middleware] bundled %s → %s (%d bytes)', $sourcePath, $outputRel, $bytes));

        return [
            'bundled' => true,
            'source_path' => $sourcePath,
            'entry_module' => 'middleware.js',
            'modules' => ['middleware.js' => (string) file_get_contents($outputAbs)],
            'bytes' => $bytes,
        ];
    }

    private function detectSource(string $checkoutDir): ?string
    {
        foreach (self::CANDIDATE_PATHS as $candidate) {
            if (is_file($checkoutDir.'/'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
