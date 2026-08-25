{{-- One theme: Terminal. The app wears the same near-black ground and acid
     accent as the login and the public pages, so `dark` is set before first
     paint rather than read from a user preference. --}}
<meta name="dply-theme" content="dark">
<script>
    (function () {
        document.documentElement.classList.add('dark');
    })();
</script>

{{-- Favicons (served from public/ root). --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<meta name="theme-color" content="#0b0d0a">
