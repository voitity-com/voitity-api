<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product->name }} | {{ $profile->name }} | Bigmelo</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($product->description, 180) }}">
    <link rel="canonical" href="{{ $publicUrl }}">
    <meta property="og:type" content="product">
    <meta property="og:site_name" content="Bigmelo">
    <meta property="og:locale" content="{{ $locale === 'en' ? 'en_US' : 'es_CO' }}">
    <meta property="og:title" content="{{ $product->name }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($product->description, 180) }}">
    <meta property="og:image" content="{{ $openGraphImage['url'] }}">
    @if (\Illuminate\Support\Str::startsWith($openGraphImage['url'], 'https://'))
    <meta property="og:image:secure_url" content="{{ $openGraphImage['url'] }}">
    @endif
    @if ($openGraphImage['mime_type'])
    <meta property="og:image:type" content="{{ $openGraphImage['mime_type'] }}">
    @endif
    @if ($openGraphImage['width'])
    <meta property="og:image:width" content="{{ $openGraphImage['width'] }}">
    @endif
    @if ($openGraphImage['height'])
    <meta property="og:image:height" content="{{ $openGraphImage['height'] }}">
    @endif
    <meta property="og:image:alt" content="{{ $product->name }}">
    <meta property="og:url" content="{{ $publicUrl }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $product->name }}">
    <meta name="twitter:description" content="{{ \Illuminate\Support\Str::limit($product->description, 180) }}">
    <meta name="twitter:image" content="{{ $openGraphImage['url'] }}">
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #17202a; background: #f4f6f8; }
        main { width: min(100% - 32px, 680px); margin: 40px auto; background: #fff; border: 1px solid #dfe3e8; border-radius: 8px; overflow: hidden; }
        img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; background: #edf0f2; }
        section { padding: 24px; }
        h1 { margin: 0 0 10px; font-size: clamp(1.65rem, 5vw, 2.35rem); line-height: 1.15; letter-spacing: 0; }
        p { margin: 0 0 20px; color: #52606d; line-height: 1.6; white-space: pre-line; }
        small { display: block; margin-bottom: 16px; color: #66788a; }
        a { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; padding: 10px 18px; border-radius: 6px; color: #fff; background: #1769aa; font-weight: 700; text-decoration: none; }
        a:focus-visible { outline: 3px solid #8cc8f7; outline-offset: 3px; }
        @media (max-width: 520px) { main { width: 100%; margin: 0; min-height: 100vh; border: 0; border-radius: 0; } section { padding: 20px; } }
    </style>
</head>
<body>
<main>
    <img src="{{ $imageUrl }}" alt="{{ $product->name }}">
    <section>
        <small>{{ $profile->name }}</small>
        <h1>{{ $product->name }}</h1>
        <p>{{ $product->description }}</p>
        <a href="{{ $actionUrl }}" rel="noopener noreferrer">
            {{ $actionLabel }}
        </a>
    </section>
</main>
</body>
</html>
