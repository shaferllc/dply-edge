{{-- The product wordmark, as it appears on the homepage.

     Defined once and used by BOTH headers (x-edge-marketing-header for the
     public pages, x-site-header for the app) so the two cannot drift apart.

     It replaced images/dply-logo.svg in the app header: that asset hardcodes
     fill="#171a0e" for the mark — light-mode brand-ink — which on the Terminal
     ground (#0b0d0a) rendered as a near-black square on near-black. A token
     cannot reach inside a flat .svg, so the fix is to stop using it as chrome.

     Size with a text utility on the call site: <x-dply-wordmark class="text-lg" />.

     `slash` overrides the accent on the separator, for the one place the lockup
     sits on a light/lime ground (the app footer) where text-edge-lime would be
     lime-on-lime and the mark would read "dplyedge". --}}
@props(['slash' => 'text-edge-lime'])
<span {{ $attributes->class('font-terminal font-bold tracking-[-0.02em] text-brand-ink') }}>dply<span class="{{ $slash }}">/</span>edge</span>
