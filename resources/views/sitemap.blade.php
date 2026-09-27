{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ([url('/') => '1.0', route('pricing') => '0.9', route('features') => '0.8'] as $loc => $priority)
  <url><loc>{{ $loc }}</loc><changefreq>weekly</changefreq><priority>{{ $priority }}</priority></url>
@endforeach
@foreach ($docs as $page)
  <url><loc>{{ route('docs.show', $page['slug']) }}</loc><changefreq>weekly</changefreq><priority>0.6</priority></url>
@endforeach
</urlset>
