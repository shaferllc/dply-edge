@php
    use App\Modules\Edge\Support\EdgeBuildLogLintParser;

    $lint = EdgeBuildLogLintParser::parse($buildLog ?? null, $failureReason ?? null);
    $show = ($lint['lint_failed'] ?? false)
        || ($lint['errors'] ?? []) !== []
        || ($lint['warnings'] ?? []) !== [];
@endphp

@if ($show)
    <div @class([
        'rounded-xl border px-4 py-3 text-sm',
        'border-rose-300/70 bg-rose-50 text-rose-950 dark:border-raw-rose-900/40 dark:bg-rose-950/30 dark:text-raw-rose-100' => ($lint['lint_failed'] ?? false) || ($lint['errors'] ?? []) !== [],
        'border-amber-300/70 bg-amber-50 text-amber-950 dark:border-raw-amber-900/40 dark:bg-raw-amber-950/30 dark:text-raw-amber-100' => ! ($lint['lint_failed'] ?? false) && ($lint['errors'] ?? []) === [] && ($lint['warnings'] ?? []) !== [],
    ])>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="inline-flex items-center gap-2 font-semibold">
                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0" aria-hidden="true" />
                    @if ($lint['lint_failed'] ?? false)
                        {{ __('dply.yaml lint failed') }}
                    @elseif (($lint['warnings'] ?? []) !== [])
                        {{ __('dply.yaml lint warnings') }}
                    @else
                        {{ __('dply.yaml lint') }}
                    @endif
                </p>
                <p class="mt-1 text-xs opacity-90">
                    {{ __('Fix :file in your repository, run `dply edge lint`, then redeploy.', ['file' => 'dply.yaml']) }}
                </p>
            </div>
            @if (isset($deployment) && $deployment !== null)
                <a
                    href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'edge-build']) }}#edge-build-routing"
                    wire:navigate
                    class="text-xs font-semibold underline underline-offset-2"
                >
                    {{ __('Open Build →') }}
                </a>
            @endif
        </div>

        @if (($lint['errors'] ?? []) !== [])
            <ul class="mt-3 list-disc space-y-1 pl-4 font-mono text-xs">
                @foreach ($lint['errors'] as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @if (($lint['warnings'] ?? []) !== [])
            <ul class="mt-3 list-disc space-y-1 pl-4 font-mono text-xs">
                @foreach ($lint['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
