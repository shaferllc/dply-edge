{{-- Pinned to the bottom of the sheet: a status line on the left, actions on the right. --}}
<footer {{ $attributes->merge(['class' => 'sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 bg-white/95 px-5 py-3 text-xs text-brand-moss backdrop-blur dark:border-brand-mist/15 dark:bg-zinc-900/95']) }}>{{ $slot }}</footer>
