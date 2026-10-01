<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>This site is paused</title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 16px/1.5 system-ui, sans-serif; background: #f6f6f3; color: #1c1f1a; }
  @media (prefers-color-scheme: dark) { body { background: #0b0d0a; color: #e8ece3; } p { color: #9aa291; } }
  main { max-width: 32rem; padding: 2rem 1.25rem; text-align: center; }
  h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
  p { margin: 0; color: #5d6259; }
</style>
</head>
<body>
<main>
  <h1>This site is paused</h1>
  @if ($byOwner ?? false)
  <p>Its owner has paused it for now. Please check back later.</p>
  @else
  <p>Its owner's plan has ended or reached its usage limit. If this is your site, sign in to dply and open Billing to bring it back.</p>
  @endif
</main>
</body>
</html>
