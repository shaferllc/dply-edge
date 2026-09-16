<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Detects Go apps from go.mod and the conventional cmd/ layout.
 *
 * Pre-fills:
 *   - runtime: "go"
 *   - version: from .tool-versions / go.mod `go` directive
 *   - framework: gin | echo | fiber | chi | "go"
 *   - build: `go build -o bin/<entrypoint> <package-path>`
 *   - start: `./bin/<entrypoint>`
 *   - app port: 8080 (most common Go web default)
 *   - processes: none (Go apps rarely have a conventional worker shape)
 */
final class GoRuntimeDetector implements RuntimeDetector
{
    public function runtime(): string
    {
        return 'go';
    }

    public function detect(string $workingDirectory): ?RuntimeDetection
    {
        $root = rtrim($workingDirectory, '/');
        $goModPath = $root.'/go.mod';

        if (! is_file($goModPath)) {
            return null;
        }

        $goModContents = (string) @file_get_contents($goModPath);
        $detectedFiles = ['go.mod'];
        $reasons = ['Found `go.mod` at the repo root.'];

        $version = $this->detectVersion($root, $goModContents, $detectedFiles, $reasons);
        $imports = $this->collectImports($goModContents);
        $framework = $this->detectFramework($imports, $reasons);

        [$entrypointName, $packagePath] = $this->detectEntrypoint($root, $detectedFiles, $reasons);
        $buildCommand = "go build -o bin/{$entrypointName} {$packagePath}";
        $startCommand = "./bin/{$entrypointName}";
        $reasons[] = "Suggested build: `{$buildCommand}`.";
        $reasons[] = "Suggested start: `{$startCommand}`.";

        $confidence = $framework !== 'go' ? 'high' : 'medium';

        return new RuntimeDetection(
            runtime: 'go',
            version: $version,
            framework: $framework,
            buildCommand: $buildCommand,
            startCommand: $startCommand,
            appPort: 8080,
            detectedFiles: $detectedFiles,
            reasons: $reasons,
            processes: [],
            confidence: $confidence,
        );
    }

    /**
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectVersion(
        string $root,
        string $goModContents,
        array &$detectedFiles,
        array &$reasons,
    ): ?string {
        $toolVersionsPath = $root.'/.tool-versions';
        if (is_file($toolVersionsPath)) {
            $contents = (string) file_get_contents($toolVersionsPath);
            // mise / asdf use either "go" or "golang" as the plugin name.
            if (preg_match('/^(?:go|golang)\s+(\S+)/m', $contents, $matches) === 1) {
                $detectedFiles[] = '.tool-versions';
                $reasons[] = "Pinned Go {$matches[1]} from `.tool-versions`.";

                return trim($matches[1]);
            }
        }

        if (preg_match('/^\s*go\s+(\d[\d.]*)/m', $goModContents, $matches) === 1) {
            $reasons[] = "Pinned Go {$matches[1]} from `go.mod`.";

            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectImports(string $goModContents): array
    {
        $imports = [];

        // Block: `require ( ... )`
        if (preg_match('/^\s*require\s*\((.*?)\)/ms', $goModContents, $matches) === 1) {
            foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '//')) {
                    continue;
                }
                $parts = preg_split('/\s+/', $line);
                if (is_array($parts) && $parts[0] !== '') {
                    $imports[] = $parts[0];
                }
            }
        }

        // Single-line: `require github.com/foo v1`
        if (preg_match_all('/^\s*require\s+(\S+)\s+\S+\s*$/m', $goModContents, $matches) !== false) {
            foreach ($matches[1] as $importPath) {
                $imports[] = $importPath;
            }
        }

        return array_values(array_unique($imports));
    }

    /**
     * @param  list<string>  $imports
     * @param  list<string>  $reasons
     */
    private function detectFramework(array $imports, array &$reasons): string
    {
        $frameworks = [
            'gin' => '/^github\.com\/gin-gonic\/gin(\/|$)/',
            'echo' => '/^github\.com\/labstack\/echo(\/|$)/',
            'fiber' => '/^github\.com\/gofiber\/fiber(\/|$)/',
            'chi' => '/^github\.com\/go-chi\/chi(\/|$)/',
        ];

        foreach ($frameworks as $key => $pattern) {
            foreach ($imports as $importPath) {
                if (preg_match($pattern, $importPath) === 1) {
                    $reasons[] = "Detected {$key} from `go.mod` require `{$importPath}`.";

                    return $key;
                }
            }
        }

        return 'go';
    }

    /**
     * Pick the build entrypoint. Prefers `cmd/<name>/main.go` (canonical Go
     * layout), falls back to `main.go` at the repo root, then to a generic
     * `./...` build of all packages.
     *
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     * @return array{0: string, 1: string} [entrypointName, packagePath]
     */
    private function detectEntrypoint(string $root, array &$detectedFiles, array &$reasons): array
    {
        $cmdDir = $root.'/cmd';
        if (is_dir($cmdDir)) {
            foreach ((scandir($cmdDir) ?: []) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $candidate = $cmdDir.'/'.$entry;
                if (is_dir($candidate) && is_file($candidate.'/main.go')) {
                    $detectedFiles[] = "cmd/{$entry}/main.go";
                    $reasons[] = "Detected entrypoint `cmd/{$entry}/main.go` (standard Go cmd/ layout).";

                    return [$entry, "./cmd/{$entry}"];
                }
            }
        }

        if (is_file($root.'/main.go')) {
            $detectedFiles[] = 'main.go';
            $reasons[] = 'Detected entrypoint `main.go` at the repo root.';

            return ['app', '.'];
        }

        $reasons[] = 'No entrypoint detected — building all packages with `./...`.';

        return ['app', './...'];
    }
}
