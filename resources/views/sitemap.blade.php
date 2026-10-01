{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
{{-- Google ignores changefreq and priority. No lastmod either: deploys reset file mtimes, so every page would claim to have changed. --}}
@foreach ([url('/'), route('pricing'), route('features'), route('compliance'), route('legal.terms'), route('legal.privacy'), route('legal.acceptable-use'), route('legal.dpa'), route('compare', 'forge'), route('compare', 'laravel-cloud'), route('compare', 'heroku')] as $loc)
  <url><loc>{{ $loc }}</loc></url>
@endforeach
@foreach ($docs as $page)
  <url><loc>{{ route('docs.show', $page['slug']) }}</loc></url>
@endforeach
</urlset>
