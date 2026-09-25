{{-- One theme: Terminal. `class="dark"` is a static attribute on <html> in every
     layout that includes this partial — not added by script. A script can be
     blocked, deferred, or lost in a wire:navigate swap, and the moment the class
     is missing the whole app falls back to the light `:root` palette, which is
     what "defaults to light on first load" was. Static markup cannot race. --}}
<meta name="dply-theme" content="dark">
{{-- Ground painted inline, before @vite. `color-scheme: dark` lives on
     `html.dark` in dply-theme.css, which cannot apply until the stylesheet has
     loaded — so the browser paints its default white canvas first and the page
     flashes light. That is worst under `npm run dev`, where Vite injects CSS
     with JavaScript and there is no render-blocking <link> at all. #0b0d0a is
     the dark ground for both body classes in use (`bg-brand-cream`, which the
     dark block redefines, and `bg-edge-void`). Keep in sync with theme-color. --}}
<meta name="color-scheme" content="dark">
<style>html{color-scheme:dark;background-color:#0b0d0a}</style>
{{-- Workspace sidebar width. The body script that used to set this runs after
     the sidebar HTML, so a collapsed rail painted open and then snapped shut.
     Read localStorage here, before first paint. Callers: every layout that
     includes partials/theme-head. No API or schema change. User: "the sidebar
     flashes open when the page loads". --}}
<script>
try {
    if (localStorage.getItem('dply.wsnav.collapsed') === '1') {
        document.documentElement.dataset.wsnav = 'collapsed';
    }
} catch (e) {}
</script>

{{-- Fonts: the Terminal pair. Loaded here, once, because every layout that
     needs them already includes this partial — it used to be copy-pasted into
     14 views, and the weight list drifted out of sync with what the app uses.
     600 matters: `font-semibold` is the app's dominant weight (~1700 uses) and
     without it the browser snapped to 700, collapsing semibold and bold into
     the same rendered weight. --}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700|space-mono:400,700&display=swap" rel="stylesheet">

{{-- Favicons (served from public/ root). --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<meta name="theme-color" content="#0b0d0a">
