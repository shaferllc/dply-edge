<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire\Concerns;

use App\Modules\Edge\Support\EdgeDeliveryRecommender;
use App\Modules\Edge\Support\HybridEdgeOriginMatcher;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesEdgeFormPrefills
{
    public function updatedFormName(): void
    {
        if ($this->prefillingFromDetection) {
            return;
        }

    }

    public function updatedFormRuntimeMode(): void
    {
        if ($this->prefillingFromDetection) {
            return;
        }

        $this->runtimeModeTouched = true;

    }

    public function updatedFormOriginUrl(): void
    {
        if ($this->prefillingOrigin) {
            return;
        }

        $this->originUrlTouched = true;
    }

    /** "Use recommended" on the create page: drop the manual pick. */
    public function useRecommendedRuntimeMode(): void
    {
        $this->runtimeModeTouched = false;
        $this->applyDetectedDeliveryPrefills();
    }

    public function updatedFormBuildCommand(): void
    {
        $this->buildOverridesTouched = true;
    }

    public function updatedFormOutputDir(): void
    {
        $this->buildOverridesTouched = true;
    }

    private function applyDetectedDeliveryPrefills(): void
    {
        if ($this->runtimeModeTouched || $this->detectedPlan === []) {
            return;
        }

        if (trim($this->form->name) === '' && trim($this->repo) !== '') {
            $this->prefillingFromDetection = true;
            $this->form->name = $this->defaultNameFromRepo();
            $this->prefillingFromDetection = false;
        }

        $mode = EdgeDeliveryRecommender::for($this->detectedPlan)['mode'] ?? null;

        if ($mode === null) {
            return;
        }

        $this->prefillingFromDetection = true;
        $this->form->runtime_mode = $mode;
        $this->prefillingFromDetection = false;
    }

    private function defaultNameFromRepo(): string
    {
        $repo = HybridEdgeOriginMatcher::normalizeRepo(trim($this->repo));
        if ($repo === '') {
            return '';
        }

        $segment = (string) (array_slice(explode('/', $repo), -1)[0] ?? '');

        return Str::title(str_replace(['-', '_'], ' ', $segment));
    }
}
