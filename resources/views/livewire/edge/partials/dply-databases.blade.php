{{-- The organization's dply databases (DplyDatabases): which apps use each, attach to another app, detach,
     and delete one no app uses. A database no app uses still runs and bills, so it must be visible here. --}}
@php $engineNames = ['postgres' => 'Postgres', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB']; @endphp
<section class="grid gap-3" aria-labelledby="dply-databases-heading">
    <div>
        <h2 id="dply-databases-heading" class="text-sm font-semibold text-brand-ink">{{ __('Postgres, MySQL & MongoDB') }}</h2>
        <p class="text-xs text-brand-moss">{{ __('Databases your apps connect to. Add one from an app’s Overview → Add resource → Database.') }}</p>
    </div>
    @if (session('status'))
        <p class="rounded-lg bg-brand-sage/10 px-3 py-2 text-xs text-brand-ink" role="status">{{ session('status') }}</p>
    @endif
    @forelse ($dplyDatabases as $db)
        @php
            $backupAt = filled($db->state['backup']['last_ok_at'] ?? null) ? \Illuminate\Support\Carbon::parse($db->state['backup']['last_ok_at']) : null;
            $size = \App\Modules\Edge\Services\EdgeAppDatabase::POSTGRES_SIZES[$db->size] ?? null;
        @endphp
        <div class="grid gap-2 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/20" wire:key="dply-db-{{ $db->id }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-semibold text-brand-ink">{{ $db->name }} <span class="font-normal text-brand-moss">· {{ $engineNames[$db->engine] ?? $db->engine }} · {{ $size ? $size['cpu'].' · '.$size['memory'] : $db->size }} · {{ $db->disk_gb }} GB</span></p>
                <p class="text-2xs text-brand-mist">{{ __('Last backup') }}: {{ $backupAt?->diffForHumans(short: true) ?? __('none yet') }}</p>
            </div>
            @if ($db->sites->isEmpty())
                <p class="text-xs text-amber-700 dark:text-amber-300">{{ __('No app uses it. It still runs and is billed for its disk.') }}</p>
            @else
                <ul class="flex flex-wrap gap-2 text-xs">
                    @foreach ($db->sites as $app)
                        <li class="flex items-center gap-1.5 rounded-full border border-brand-ink/10 px-2.5 py-0.5 dark:border-brand-mist/20">
                            <span class="text-brand-ink">{{ $app->name }}</span>
                            <span class="font-mono text-2xs text-brand-mist">{{ $app->pivot->primary ? 'DB_*' : $app->pivot->env_name.'_*' }}</span>
                            <button type="button" wire:click="detachDply('{{ $db->id }}', '{{ $app->id }}')" class="text-2xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Detach') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <select wire:model="dplyAttachSite.{{ $db->id }}" class="dply-input mt-0 w-auto py-1 text-xs" aria-label="{{ __('App to attach :name to', ['name' => $db->name]) }}">
                    <option value="">{{ __('Attach to an app…') }}</option>
                    @foreach ($sites->reject(fn ($s) => $db->sites->contains('id', $s->id)) as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-1 text-brand-moss"><input type="checkbox" wire:model="dplyAttachPrimary.{{ $db->id }}" /> {{ __('as its primary') }}</label>
                <button type="button" wire:click="attachDply('{{ $db->id }}')" class="font-semibold text-brand-forest hover:underline dark:text-brand-sage">{{ __('Attach') }}</button>
                @if ($db->sites->isEmpty())
                    <span class="ms-auto flex items-center gap-2">
                        <input type="text" wire:model="dplyDeleteConfirm.{{ $db->id }}" placeholder="{{ $db->name }}" aria-label="{{ __('Type :name to delete it', ['name' => $db->name]) }}" class="dply-input mt-0 w-36 py-1 font-mono text-xs" />
                        <button type="button" wire:click="deleteDply('{{ $db->id }}')" class="font-semibold text-rose-700 hover:underline dark:text-rose-300">{{ __('Delete') }}</button>
                    </span>
                @endif
            </div>
            @if ($db->sites->isEmpty())
                <p class="text-2xs text-brand-mist">{{ __('Deleting removes it right away, and its backups within a week.') }}</p>
            @endif
            @error('dply.'.$db->id) <p class="text-xs text-rose-700 dark:text-rose-300" role="alert">{{ $message }}</p> @enderror
        </div>
    @empty
        <p class="text-xs text-brand-moss">{{ __('No Postgres, MySQL or MongoDB databases yet.') }}</p>
    @endforelse
</section>
