<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Detects Ruby apps from Gemfile and related repo signals.
 *
 * Pre-fills:
 *   - runtime: "ruby"
 *   - version: from .tool-versions / .ruby-version / Gemfile `ruby` line
 *   - framework: rails | sinatra | "ruby"
 *   - build: bundle install (+ rails assets:precompile for Rails)
 *   - start: bundle exec puma (Rails) / bundle exec rackup (others)
 *   - app port: 3000 (Ruby web convention)
 *   - processes: sidekiq worker hint when sidekiq is in Gemfile and
 *     a sidekiq config file exists
 */
final class RubyRuntimeDetector implements RuntimeDetector
{
    public function runtime(): string
    {
        return 'ruby';
    }

    public function detect(string $workingDirectory): ?RuntimeDetection
    {
        $root = rtrim($workingDirectory, '/');
        $gemfilePath = $root.'/Gemfile';

        if (! is_file($gemfilePath)) {
            return null;
        }

        $gemfileContents = (string) @file_get_contents($gemfilePath);
        $detectedFiles = ['Gemfile'];
        $reasons = ['Found `Gemfile` at the repo root.'];

        $gems = $this->collectGems($gemfileContents);
        $version = $this->detectVersion($root, $gemfileContents, $detectedFiles, $reasons);
        $framework = $this->detectFramework($gems, $root, $detectedFiles, $reasons);

        $buildCommand = $this->detectBuildCommand($framework, $reasons);
        $startCommand = $this->detectStartCommand($framework, $root, $reasons);
        $appPort = 3000;
        $processes = $this->detectProcesses($gems, $root, $detectedFiles, $reasons);

        $confidence = $framework !== 'ruby' ? 'high' : 'medium';

        return new RuntimeDetection(
            runtime: 'ruby',
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
     * @return list<string>
     */
    private function collectGems(string $gemfileContents): array
    {
        $gems = [];
        if (preg_match_all('/^\s*gem\s+[\'"]([A-Za-z0-9_\-]+)[\'"]/m', $gemfileContents, $matches) !== false) {
            foreach ($matches[1] as $name) {
                $gems[] = strtolower($name);
            }
        }

        return array_values(array_unique($gems));
    }

    /**
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectVersion(
        string $root,
        string $gemfileContents,
        array &$detectedFiles,
        array &$reasons,
    ): ?string {
        $toolVersionsPath = $root.'/.tool-versions';
        if (is_file($toolVersionsPath)) {
            $contents = (string) file_get_contents($toolVersionsPath);
            if (preg_match('/^ruby\s+(\S+)/m', $contents, $matches) === 1) {
                $detectedFiles[] = '.tool-versions';
                $reasons[] = "Pinned Ruby {$matches[1]} from `.tool-versions`.";

                return trim($matches[1]);
            }
        }

        $rubyVersionPath = $root.'/.ruby-version';
        if (is_file($rubyVersionPath)) {
            $version = trim((string) file_get_contents($rubyVersionPath));
            if ($version !== '') {
                $detectedFiles[] = '.ruby-version';
                $version = ltrim($version, 'ruby-');
                $reasons[] = "Pinned Ruby {$version} from `.ruby-version`.";

                return $version;
            }
        }

        if (preg_match('/^\s*ruby\s+[\'"]([^\'"]+)[\'"]/m', $gemfileContents, $matches) === 1) {
            $reasons[] = "Pinned Ruby {$matches[1]} from `Gemfile`.";

            return $matches[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $gems
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectFramework(array $gems, string $root, array &$detectedFiles, array &$reasons): string
    {
        if (in_array('rails', $gems, true)) {
            $reasons[] = 'Detected rails from `Gemfile`.';

            return 'rails';
        }

        if (is_file($root.'/config/application.rb')) {
            $detectedFiles[] = 'config/application.rb';
            $reasons[] = 'Detected rails from `config/application.rb`.';

            return 'rails';
        }

        if (in_array('sinatra', $gems, true)) {
            $reasons[] = 'Detected sinatra from `Gemfile`.';

            return 'sinatra';
        }

        return 'ruby';
    }

    /**
     * @param  list<string>  $reasons
     */
    private function detectBuildCommand(?string $framework, array &$reasons): string
    {
        if ($framework === 'rails') {
            $command = 'bundle install && bundle exec rails assets:precompile';
            $reasons[] = "Suggested build: `{$command}` (Rails convention — install gems then build assets).";

            return $command;
        }

        $reasons[] = 'Suggested build: `bundle install`.';

        return 'bundle install';
    }

    /**
     * @param  list<string>  $reasons
     */
    private function detectStartCommand(?string $framework, string $root, array &$reasons): string
    {
        if ($framework === 'rails') {
            if (is_file($root.'/config/puma.rb')) {
                $command = 'bundle exec puma -C config/puma.rb';
                $reasons[] = "Suggested start: `{$command}` (Rails + `config/puma.rb` detected).";

                return $command;
            }

            $command = 'bundle exec rails server -b 0.0.0.0 -p 3000';
            $reasons[] = "Suggested start: `{$command}` (Rails default).";

            return $command;
        }

        if ($framework === 'sinatra' && is_file($root.'/config.ru')) {
            $command = 'bundle exec rackup -o 0.0.0.0 -p 3000';
            $reasons[] = "Suggested start: `{$command}` (Sinatra + `config.ru` detected).";

            return $command;
        }

        if (is_file($root.'/config.ru')) {
            $command = 'bundle exec rackup -o 0.0.0.0 -p 3000';
            $reasons[] = "Suggested start: `{$command}` (`config.ru` detected — Rack-compatible app).";

            return $command;
        }

        $command = 'bundle exec rackup -o 0.0.0.0 -p 3000';
        $reasons[] = "Suggested start: `{$command}`.";

        return $command;
    }

    /**
     * @param  list<string>  $gems
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     * @return list<DetectedProcess>
     */
    private function detectProcesses(array $gems, string $root, array &$detectedFiles, array &$reasons): array
    {
        $processes = [];

        // Sidekiq worker hint: present in Gemfile AND a sidekiq config file exists.
        // Matches the Node detector's two-signal rule (dep + script) so we don't
        // false-positive on apps that have one but not the other.
        $hasSidekiq = in_array('sidekiq', $gems, true);
        $configFile = null;
        if (is_file($root.'/config/sidekiq.yml')) {
            $configFile = 'config/sidekiq.yml';
        } elseif (is_file($root.'/config/initializers/sidekiq.rb')) {
            $configFile = 'config/initializers/sidekiq.rb';
        }

        if ($hasSidekiq && $configFile !== null) {
            $detectedFiles[] = $configFile;
            $command = $configFile === 'config/sidekiq.yml'
                ? 'bundle exec sidekiq -C config/sidekiq.yml'
                : 'bundle exec sidekiq';

            $processes[] = new DetectedProcess(
                type: 'worker',
                name: 'sidekiq',
                command: $command,
                reason: "Detected sidekiq in `Gemfile` plus `{$configFile}` — likely a background queue worker.",
            );
            $reasons[] = "Suggested worker process: `{$command}` (sidekiq detected).";
        }

        return $processes;
    }
}
