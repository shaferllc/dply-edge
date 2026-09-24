@php
    use App\Models\AuditLog;
    use App\Models\Site;
    use Illuminate\Support\Str;

    $entries = AuditLog::query()
        ->with('user:id,name,email')
        ->where(function ($q) use ($site): void {
            $q->where(function ($inner) use ($site): void {
                $inner->where('subject_type', Site::class)
                    ->where('subject_id', $site->id);
            })->orWhere(function ($inner) use ($site): void {
                $inner->where('organization_id', $site->organization_id)
                    ->where('subject_id', $site->id)
                    ->where('action', 'like', 'site.edge.%');
            });
        })
        ->orderByDesc('created_at')
        ->limit(100)
        ->get();
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-audit',
            'what' => __('Control-plane audit trail for this Edge site — who changed settings, bindings, firewall, members, and more.'),
            'steps' => [
                __('Scan recent events after an incident or unexpected config change.'),
                __('Export CSV or JSON when you need a copy outside the dashboard.'),
            ],
            'tips' => [
                __('This is the dply audit log for this app.'),
                __('Deploy history itself lives under Deploys / Build & deploy logs.'),
            ],
        ])
    </section>

    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
        <p class="text-xs text-brand-moss">{{ __('Last 100 events · read-only') }}</p>
        <div class="flex items-center gap-2">
            <a
                href="{{ route('sites.edge.audit.export', ['server' => $site->server_id, 'site' => $site->id, 'format' => 'csv']) }}"
                class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900"
            >
                <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                {{ __('CSV') }}
            </a>
            <a
                href="{{ route('sites.edge.audit.export', ['server' => $site->server_id, 'site' => $site->id, 'format' => 'json']) }}"
                class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900"
            >
                <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                {{ __('JSON') }}
            </a>
        </div>
    </div>

    @if ($entries->isEmpty())
        <p class="px-5 py-10 text-center text-sm text-brand-moss sm:px-6">{{ __('No audited events yet.') }}</p>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($entries as $entry)
                @php
                    $action = (string) $entry->action;
                    $actionLabel = Str::startsWith($action, 'site.edge.')
                        ? Str::after($action, 'site.edge.')
                        : $action;
                    $details = [];
                    foreach ((array) ($entry->new_values ?? []) as $key => $value) {
                        if (is_scalar($value)) {
                            $details[] = $key.'='.Str::limit((string) $value, 48);
                        } elseif (is_array($value)) {
                            $details[] = $key.'=['.count($value).']';
                        }
                    }
                    $detailLine = implode(' · ', array_slice($details, 0, 4));
                @endphp
                <li class="px-5 py-3 sm:px-6" wire:key="audit-{{ $entry->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p class="min-w-0 font-mono text-xs font-semibold text-brand-ink">{{ $actionLabel }}</p>
                        <time class="shrink-0 text-xs text-brand-mist" title="{{ $entry->created_at?->toIso8601String() }}">
                            {{ $entry->created_at?->diffForHumans() ?? '—' }}
                        </time>
                    </div>
                    <p class="mt-0.5 text-xs text-brand-moss">
                        {{ $entry->user?->name ?? __('System') }}
                        @if ($entry->user?->email)
                            <span class="text-brand-mist">· {{ $entry->user->email }}</span>
                        @endif
                    </p>
                    @if ($detailLine !== '')
                        <p class="mt-1 truncate font-mono text-xs text-brand-mist" title="{{ $detailLine }}">{{ $detailLine }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
