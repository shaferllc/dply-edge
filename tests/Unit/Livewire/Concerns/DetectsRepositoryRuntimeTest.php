<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Concerns\DetectsRepositoryRuntimeTest;

use App\Livewire\Concerns\DetectsRepositoryRuntime;

function subject(): object
{
    return new class
    {
        use DetectsRepositoryRuntime;

        public function applyDetectedRuntimePrefills(): void {}

        /**
         * @param  array<string, mixed>  $detection
         * @return array<string, mixed>
         */
        public function exposeServerlessDetectionToArray(array $detection, string $url, string $branch): array
        {
            return $this->serverlessDetectionToArray($detection, $url, $branch);
        }
    };
}
test('normalize to clone url expands owner name shorthand', function () {
    expect(subject()->normalizeToCloneUrl('acme/api'))->toBe('https://github.com/acme/api.git');
});
test('normalize to clone url trims surrounding slashes', function () {
    expect(subject()->normalizeToCloneUrl('/acme/api/'))->toBe('https://github.com/acme/api.git');
});
test('normalize to clone url passes full urls through', function () {
    $subject = subject();

    expect($subject->normalizeToCloneUrl('https://github.com/acme/api.git'))->toBe('https://github.com/acme/api.git');
    expect($subject->normalizeToCloneUrl('git@github.com:acme/api.git'))->toBe('git@github.com:acme/api.git');
});
test('normalize to clone url returns empty for blank input', function () {
    expect(subject()->normalizeToCloneUrl('   '))->toBe('');
});
