{{--
    Centralised SEO / social meta for consumer-facing pages.

    Props:
      title       page-specific part, rendered as "{title} – {app name}"
      full-title  verbatim <title> override (use instead of `title`)
      description  optional; falls back to the platform default below
      image       optional asset path; defaults to the branded OG share image
      type        Open Graph type (default "website")
      canonical   optional canonical URL; defaults to the current URL

    Renders the title, meta description, canonical, Open Graph, and Twitter card
    tags plus a default 1200x630 share image. Note: do NOT put component-tag
    examples in this comment — Blade's tag compiler ignores blade comments and
    would treat them as real (recursive) invocations.
--}}
@props([
    'title' => null,
    'fullTitle' => null,
    'description' => null,
    'image' => null,
    'type' => 'website',
    'canonical' => null,
])
@php
    $appName = config('app.name', 'dply');

    $resolvedTitle = $fullTitle
        ?? ($title ? $title.' – '.$appName : $appName);

    $resolvedDescription = $description
        ?? 'Deploy static, SSG, and SSR sites straight from git to a global edge network. Preview URLs on every branch, custom domains with automatic TLS, access rules, and request analytics.';

    $resolvedCanonical = $canonical ?? url()->current();
    $resolvedImage = $image ? asset($image) : asset('images/og/dply-og.png');
@endphp
<title>{{ $resolvedTitle }}</title>
<meta name="description" content="{{ $resolvedDescription }}">
<link rel="canonical" href="{{ $resolvedCanonical }}">

{{-- Open Graph --}}
<meta property="og:type" content="{{ $type }}">
<meta property="og:site_name" content="{{ $appName }}">
<meta property="og:title" content="{{ $resolvedTitle }}">
<meta property="og:description" content="{{ $resolvedDescription }}">
<meta property="og:url" content="{{ $resolvedCanonical }}">
<meta property="og:image" content="{{ $resolvedImage }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $appName }} — edge hosting for static, SSG, and SSR sites">

{{-- Twitter --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $resolvedTitle }}">
<meta name="twitter:description" content="{{ $resolvedDescription }}">
<meta name="twitter:image" content="{{ $resolvedImage }}">
