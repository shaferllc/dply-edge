@php
    $contract = $deployContract ?? [];
    $enabled = ! empty($deployContractEnabled);
@endphp
@if ($enabled && ($previewIsLive ?? false))
    @php
        $status = (string) ($contract['status'] ?? '');
        $ready = ! empty($contract['ready_to_promote']);
        $failed = $status === \App\Models\DeployContractRun::STATUS_FAILED;
        $passed = in_array($status, [\App\Models\DeployContractRun::STATUS_PASSED, \App\Models\DeployContractRun::STATUS_WAIVED], true);
        $stale = ! empty($contract['has_run']) && empty($contract['run_current']);
    @endphp
    <div class="w-full max-w-md space-y-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="inline-flex items-center gap-1 font-semibold text-brand-ink">
                <x-heroicon-o-clipboard-document-check class="h-3 w-3" aria-hidden="true" />
                {{ __('Deploy contract') }}
            </span>
            <button
                type="button"
                wire:click="{{ $runContractMethod ?? 'runDeployContract' }}('{{ $preview->id }}')"
                wire:loading.attr="disabled"
                wire:target="{{ $runContractMethod ?? 'runDeployContract' }}('{{ $preview->id }}')"
                class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
            >
                {{ __('Run checks') }}
            </button>
        </div>
        @if (! empty($contract['policy_source']) && $contract['policy_source'] === 'repo_contract')
            <p class="text-2xs uppercase tracking-wide text-brand-moss">{{ __('Repo policy active (dply-contract.yaml)') }}</p>
        @endif
        @if (empty($contract['has_run']))
            <p>{{ __('Run automated checks (build, review, replay, Cloud, BYO) before promoting to production.') }}</p>
        @elseif ($stale)
            <p class="text-amber-800 dark:text-amber-300">{{ __('Preview deployment changed — re-run the contract.') }}</p>
        @elseif ($passed && $ready)
            <p class="text-brand-forest dark:text-brand-sage">{{ __('Contract passed — promote is allowed when review policy is satisfied.') }}</p>
        @elseif ($failed)
            <p class="text-rose-800 dark:text-rose-300">{{ __('Contract failed — fix checks below or record a waiver.') }}</p>
        @else
            <p>{{ __('Contract status: :status', ['status' => $status !== '' ? $status : __('unknown')]) }}</p>
        @endif
        @if (! empty($contract['checks']))
            <ul class="space-y-1 border-t border-brand-ink/10 pt-2">
                @foreach ($contract['checks'] as $check)
                    @php
                        $checkStatus = (string) ($check['status'] ?? '');
                        $icon = match ($checkStatus) {
                            'pass' => 'text-brand-forest',
                            'skip' => 'text-brand-moss',
                            default => 'text-rose-700 dark:text-rose-400',
                        };
                    @endphp
                    <li class="flex gap-2">
                        <span class="shrink-0 font-semibold {{ $icon }}">●</span>
                        <span>
                            <span class="font-semibold text-brand-ink">{{ $check['label'] ?? $check['key'] }}</span>
                            — {{ $check['message'] ?? '' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($failed && ! empty($contract['allow_waivers']))
            <div class="space-y-2 border-t border-brand-ink/10 pt-2">
                <label class="block text-2xs font-semibold uppercase tracking-wide text-brand-ink" for="waiver-{{ $preview->id }}">
                    {{ __('Waiver reason') }}
                </label>
                <textarea
                    id="waiver-{{ $preview->id }}"
                    wire:model="deployContractWaiverReason"
                    rows="2"
                    class="w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    placeholder="{{ __('What was verified manually?') }}"
                ></textarea>
                <button
                    type="button"
                    wire:click="{{ $waiveContractMethod ?? 'confirmWaiveDeployContract' }}('{{ $preview->id }}')"
                    wire:loading.attr="disabled"
                    class="rounded-md border border-amber-300 bg-amber-50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-900 hover:bg-amber-100 dark:border-raw-amber-700 dark:bg-raw-amber-950/40 dark:text-raw-amber-200"
                >
                    {{ __('Record waiver') }}
                </button>
            </div>
        @endif
    </div>
@endif
