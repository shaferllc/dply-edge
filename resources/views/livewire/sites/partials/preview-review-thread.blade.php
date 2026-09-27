<li class="px-6 py-4 {{ ($depth ?? 0) > 0 ? 'bg-brand-sand/10 pl-12' : '' }}">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xs text-brand-moss">
                <span class="font-semibold text-brand-ink">{{ $comment->authorDisplayName() }}</span>
                <span class="font-mono">{{ $comment->url_path }}</span>
                @if ($comment->viewport_width)
                    <span>{{ $comment->viewport_width }}px</span>
                @endif
                @if ($comment->selector)
                    <span class="font-mono opacity-70">{{ $comment->selector }}</span>
                @endif
                <span class="opacity-60">{{ $comment->created_at?->diffForHumans() }}</span>
            </div>
            <p class="mt-2 whitespace-pre-line text-sm text-brand-ink">{{ $comment->body }}</p>
            @if ($comment->resolved_at)
                <p class="mt-2 text-xs text-emerald-700">
                    {{ __('Resolved') }}
                    @if ($comment->resolvedBy)
                        {{ __('by :name', ['name' => $comment->resolvedBy->name ?: $comment->resolvedBy->email]) }}
                    @endif
                    {{ $comment->resolved_at?->diffForHumans() }}
                </p>
            @endif
        </div>
        @can('deploy', $site)
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                @if (($depth ?? 0) === 0)
                    <button type="button" wire:click="startReply('{{ $comment->id }}')" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40">
                        {{ __('Reply') }}
                    </button>
                @endif
                <button type="button" wire:click="toggleResolved('{{ $comment->id }}')" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40">
                    {{ $comment->resolved_at ? __('Reopen') : __('Resolve') }}
                </button>
                <button type="button" wire:click="confirmDeleteComment('{{ $comment->id }}')" class="inline-flex items-center rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-medium text-rose-900 hover:bg-rose-50">
                    {{ __('Delete') }}
                </button>
            </div>
        @endcan
    </div>
</li>
