<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Detects Python apps from pyproject.toml / requirements.txt / Pipfile / setup.py.
 *
 * Pre-fills:
 *   - runtime: "python"
 *   - version: from .tool-versions / .python-version / pyproject.toml#requires-python
 *   - framework: django | fastapi | flask | "python"
 *   - build: poetry install / pipenv install / pip install -r requirements.txt / pip install .
 *   - start: framework-aware (gunicorn for django/flask, uvicorn for fastapi)
 *   - app port: 8000 (Python web convention)
 *   - processes: celery worker hint when celery is in deps alongside a Django project
 */
final class PythonRuntimeDetector implements RuntimeDetector
{
    public function runtime(): string
    {
        return 'python';
    }

    public function detect(string $workingDirectory): ?RuntimeDetection
    {
        $root = rtrim($workingDirectory, '/');

        $hasPyproject = is_file($root.'/pyproject.toml');
        $hasRequirements = is_file($root.'/requirements.txt');
        $hasPipfile = is_file($root.'/Pipfile');
        $hasSetupPy = is_file($root.'/setup.py');
        $hasManagePy = is_file($root.'/manage.py');

        if (! $hasPyproject && ! $hasRequirements && ! $hasPipfile && ! $hasSetupPy && ! $hasManagePy) {
            return null;
        }

        $detectedFiles = [];
        $reasons = [];

        if ($hasPyproject) {
            $detectedFiles[] = 'pyproject.toml';
            $reasons[] = 'Found `pyproject.toml` at the repo root.';
        }
        if ($hasRequirements) {
            $detectedFiles[] = 'requirements.txt';
            $reasons[] = 'Found `requirements.txt` at the repo root.';
        }
        if ($hasPipfile) {
            $detectedFiles[] = 'Pipfile';
            $reasons[] = 'Found `Pipfile` at the repo root.';
        }
        if ($hasSetupPy) {
            $detectedFiles[] = 'setup.py';
            $reasons[] = 'Found `setup.py` at the repo root.';
        }

        $pyprojectContents = $hasPyproject ? (string) @file_get_contents($root.'/pyproject.toml') : '';
        $requirementsContents = $hasRequirements ? (string) @file_get_contents($root.'/requirements.txt') : '';
        $pipfileContents = $hasPipfile ? (string) @file_get_contents($root.'/Pipfile') : '';

        $version = $this->detectVersion($root, $pyprojectContents, $detectedFiles, $reasons);
        $deps = $this->collectDependencies($pyprojectContents, $requirementsContents, $pipfileContents);
        $usesPoetry = $this->detectsPoetry($pyprojectContents);
        $framework = $this->detectFramework($deps, $hasManagePy, $detectedFiles, $reasons);

        $buildCommand = $this->detectBuildCommand($hasPyproject, $usesPoetry, $hasRequirements, $hasPipfile, $hasSetupPy, $reasons);
        $djangoProject = $framework === 'django' ? $this->detectDjangoProject($root, $detectedFiles, $reasons) : null;
        $startCommand = $this->detectStartCommand($framework, $root, $djangoProject, $reasons);
        $appPort = 8000;
        $processes = $this->detectProcesses($framework, $deps, $djangoProject, $reasons);

        $confidence = $framework !== 'python' ? 'high' : 'medium';

        return new RuntimeDetection(
            runtime: 'python',
            version: $version,
            framework: $framework,
            buildCommand: $buildCommand,
            startCommand: $startCommand,
            appPort: $appPort,
            detectedFiles: $detectedFiles,
            reasons: $reasons,
            processes: $processes,
            confidence: $confidence,
        );
    }

    /**
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectVersion(
        string $root,
        string $pyprojectContents,
        array &$detectedFiles,
        array &$reasons,
    ): ?string {
        $toolVersionsPath = $root.'/.tool-versions';
        if (is_file($toolVersionsPath)) {
            $contents = (string) file_get_contents($toolVersionsPath);
            if (preg_match('/^python\s+(\S+)/m', $contents, $matches) === 1) {
                $detectedFiles[] = '.tool-versions';
                $reasons[] = "Pinned Python {$matches[1]} from `.tool-versions`.";

                return trim($matches[1]);
            }
        }

        $pythonVersionPath = $root.'/.python-version';
        if (is_file($pythonVersionPath)) {
            $version = trim((string) file_get_contents($pythonVersionPath));
            if ($version !== '') {
                $detectedFiles[] = '.python-version';
                $reasons[] = "Pinned Python {$version} from `.python-version`.";

                return $version;
            }
        }

        if ($pyprojectContents !== '' && preg_match('/^\s*requires-python\s*=\s*"([^"]+)"/m', $pyprojectContents, $matches) === 1) {
            $reasons[] = "Pinned Python {$matches[1]} from `pyproject.toml#requires-python`.";

            return $matches[1];
        }

        // Poetry pre-PEP-621 puts the version in [tool.poetry.dependencies] python.
        if ($pyprojectContents !== '') {
            $section = $this->extractTomlSection($pyprojectContents, 'tool.poetry.dependencies');
            if ($section !== null && preg_match('/^\s*python\s*=\s*"([^"]+)"/m', $section, $matches) === 1) {
                $reasons[] = "Pinned Python {$matches[1]} from `pyproject.toml#tool.poetry.dependencies.python`.";

                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectDependencies(
        string $pyprojectContents,
        string $requirementsContents,
        string $pipfileContents,
    ): array {
        $deps = [];

        if ($pyprojectContents !== '') {
            $poetryDeps = $this->extractTomlSection($pyprojectContents, 'tool.poetry.dependencies');
            if ($poetryDeps !== null) {
                foreach (preg_split('/\R/', $poetryDeps) ?: [] as $line) {
                    if (preg_match('/^\s*([A-Za-z0-9_\-]+)\s*=/', $line, $matches) === 1) {
                        $deps[] = strtolower($matches[1]);
                    }
                }
            }

            // PEP 621 — [project] dependencies = ["foo>=1", "bar"]
            $projectSection = $this->extractTomlSection($pyprojectContents, 'project');
            if ($projectSection !== null && preg_match('/^\s*dependencies\s*=\s*\[(.*?)\]/ms', $projectSection, $matches) === 1) {
                if (preg_match_all('/"([^"]+)"/', $matches[1], $depMatches) !== false) {
                    foreach ($depMatches[1] as $rawDep) {
                        $name = $this->extractPackageName($rawDep);
                        if ($name !== '') {
                            $deps[] = $name;
                        }
                    }
                }
            }
        }

        if ($requirementsContents !== '') {
            foreach (preg_split('/\R/', $requirementsContents) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '-')) {
                    continue;
                }
                $name = $this->extractPackageName($line);
                if ($name !== '') {
                    $deps[] = $name;
                }
            }
        }

        if ($pipfileContents !== '') {
            $packagesSection = $this->extractTomlSection($pipfileContents, 'packages');
            if ($packagesSection !== null) {
                foreach (preg_split('/\R/', $packagesSection) ?: [] as $line) {
                    if (preg_match('/^\s*([A-Za-z0-9_\-]+)\s*=/', $line, $matches) === 1) {
                        $deps[] = strtolower($matches[1]);
                    }
                }
            }
        }

        return array_values(array_unique($deps));
    }

    private function detectsPoetry(string $pyprojectContents): bool
    {
        return $pyprojectContents !== '' && preg_match('/^\s*\[tool\.poetry\]\s*$/m', $pyprojectContents) === 1;
    }

    /**
     * @param  list<string>  $deps
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectFramework(array $deps, bool $hasManagePy, array &$detectedFiles, array &$reasons): string
    {
        if (in_array('django', $deps, true)) {
            $reasons[] = 'Detected django from declared dependencies.';

            return 'django';
        }

        if ($hasManagePy) {
            $detectedFiles[] = 'manage.py';
            $reasons[] = 'Detected django from `manage.py` at the repo root.';

            return 'django';
        }

        if (in_array('fastapi', $deps, true)) {
            $reasons[] = 'Detected fastapi from declared dependencies.';

            return 'fastapi';
        }

        if (in_array('flask', $deps, true)) {
            $reasons[] = 'Detected flask from declared dependencies.';

            return 'flask';
        }

        return 'python';
    }

    /**
     * @param  list<string>  $reasons
     */
    private function detectBuildCommand(
        bool $hasPyproject,
        bool $usesPoetry,
        bool $hasRequirements,
        bool $hasPipfile,
        bool $hasSetupPy,
        array &$reasons,
    ): ?string {
        if ($usesPoetry) {
            $reasons[] = 'Suggested build: `poetry install --without dev` (poetry detected in `pyproject.toml`).';

            return 'poetry install --without dev';
        }

        if ($hasPipfile) {
            $reasons[] = 'Suggested build: `pipenv install --deploy --ignore-pipfile` (Pipfile detected).';

            return 'pipenv install --deploy --ignore-pipfile';
        }

        if ($hasRequirements) {
            $reasons[] = 'Suggested build: `pip install -r requirements.txt`.';

            return 'pip install -r requirements.txt';
        }

        if ($hasPyproject) {
            $reasons[] = 'Suggested build: `pip install .` (PEP 621 `pyproject.toml` detected).';

            return 'pip install .';
        }

        if ($hasSetupPy) {
            $reasons[] = 'Suggested build: `pip install .` (`setup.py` detected).';

            return 'pip install .';
        }

        return null;
    }

    /**
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectDjangoProject(string $root, array &$detectedFiles, array &$reasons): ?string
    {
        $managePyPath = $root.'/manage.py';
        if (! is_file($managePyPath)) {
            return null;
        }

        $contents = (string) @file_get_contents($managePyPath);
        if (preg_match('/DJANGO_SETTINGS_MODULE["\']\s*,\s*["\']([A-Za-z0-9_]+)\.settings["\']/', $contents, $matches) === 1) {
            if (! in_array('manage.py', $detectedFiles, true)) {
                $detectedFiles[] = 'manage.py';
            }
            $reasons[] = "Detected Django project name `{$matches[1]}` from `manage.py`.";

            return $matches[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function detectStartCommand(?string $framework, string $root, ?string $djangoProject, array &$reasons): ?string
    {
        if ($framework === 'django') {
            if ($djangoProject !== null) {
                $command = "gunicorn {$djangoProject}.wsgi:application --bind 0.0.0.0:8000";
                $reasons[] = "Suggested start: `{$command}` (Django + gunicorn convention).";

                return $command;
            }

            $reasons[] = 'Suggested start: replace `<project>` with your Django package — `gunicorn <project>.wsgi:application --bind 0.0.0.0:8000`.';

            return 'gunicorn <project>.wsgi:application --bind 0.0.0.0:8000';
        }

        if ($framework === 'fastapi') {
            $module = is_file($root.'/main.py') ? 'main:app' : 'app:app';
            $command = "uvicorn {$module} --host 0.0.0.0 --port 8000";
            $reasons[] = "Suggested start: `{$command}` (FastAPI + uvicorn convention).";

            return $command;
        }

        if ($framework === 'flask') {
            $module = is_file($root.'/app.py') ? 'app:app' : 'wsgi:app';
            $command = "gunicorn {$module} --bind 0.0.0.0:8000";
            $reasons[] = "Suggested start: `{$command}` (Flask + gunicorn convention).";

            return $command;
        }

        return null;
    }

    /**
     * @param  list<string>  $deps
     * @param  list<string>  $reasons
     * @return list<DetectedProcess>
     */
    private function detectProcesses(?string $framework, array $deps, ?string $djangoProject, array &$reasons): array
    {
        $processes = [];

        // Celery worker hint: present in deps and we know the Django project name (so we
        // can produce a working `celery -A <project>` invocation). For non-Django apps
        // the conventional `-A` target is project-specific, so we don't guess.
        if (in_array('celery', $deps, true) && $framework === 'django' && $djangoProject !== null) {
            $command = "celery -A {$djangoProject} worker --loglevel=info";
            $processes[] = new DetectedProcess(
                type: 'worker',
                name: 'celery',
                command: $command,
                reason: 'Detected celery in dependencies alongside a Django project — likely a background task worker.',
            );
            $reasons[] = "Suggested worker process: `{$command}` (celery + Django detected).";
        }

        return $processes;
    }

    /**
     * Extract the body of a TOML section. `[parent.child]` is matched as `parent.child`.
     * Returns the raw text between the section header and the next section header (or EOF).
     */
    private function extractTomlSection(string $contents, string $section): ?string
    {
        $escaped = preg_quote($section, '/');
        $pattern = '/^\s*\['.$escaped.'\]\s*$(.*?)(?=^\s*\[|\z)/ms';
        if (preg_match($pattern, $contents, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Strip version specifiers, extras, and environment markers from a requirements line
     * and return the canonical lower-cased package name.
     */
    private function extractPackageName(string $rawDep): string
    {
        $name = trim($rawDep);
        $name = preg_split('/[\s;]/', $name)[0] ?? '';
        $name = preg_split('/[<>=!~\[]/', $name)[0] ?? '';

        return strtolower(trim($name));
    }
}
