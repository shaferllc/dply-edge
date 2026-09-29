{{-- Docs sidebar: sections and pages from docs/site/nav.json. Unwritten pages
     show greyed as "soon" (writers are mid-flight) but still link to a stub. --}}
<nav aria-label="{{ __('Documentation') }}" class="space-y-7 text-sm">
    @foreach ($nav as $section)
        <div>
            <p class="font-terminal mb-2 text-[11px] uppercase tracking-[0.14em] text-edge-faint">{{ $section['title'] }}</p>
            <ul class="space-y-px border-l border-edge-line">
                @foreach ($section['pages'] as $item)
                    @php $current = $item['slug'] === $page['slug']; @endphp
                    <li>
                        <a
                            href="{{ route('docs.show', $item['slug']) }}"
                            @if ($current) aria-current="page" @endif
                            @class([
                                '-ml-px flex items-center justify-between gap-2 border-l py-1.5 pl-3.5 pr-2 leading-5 transition-colors',
                                'border-edge-lime font-medium text-edge-text' => $current,
                                'border-transparent text-edge-mute hover:border-edge-dim hover:text-edge-text' => ! $current && $item['exists'],
                                'border-transparent text-edge-faint hover:text-edge-mute' => ! $current && ! $item['exists'],
                            ])
                        >
                            <span>{{ $item['title'] }}</span>
                            @unless ($item['exists'])
                                <span class="font-terminal shrink-0 text-2xs uppercase tracking-wider text-edge-faint">{{ __('soon') }}</span>
                            @endunless
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
