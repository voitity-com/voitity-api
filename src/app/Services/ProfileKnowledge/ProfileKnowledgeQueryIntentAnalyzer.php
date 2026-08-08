<?php

namespace App\Services\ProfileKnowledge;

use Illuminate\Support\Str;

class ProfileKnowledgeQueryIntentAnalyzer
{
    /** @var array<int, string> */
    private const STOP_WORDS = [
        'about', 'ademas', 'algo', 'como', 'con', 'cual', 'cuales', 'cuando', 'dame', 'del', 'desde',
        'donde', 'ella', 'ellos', 'esta', 'este', 'esto', 'from', 'have', 'para', 'pero', 'por', 'porque',
        'puede', 'puedes', 'que', 'quiero', 'segun', 'sobre', 'the', 'their', 'tiene', 'tienes', 'una',
        'uno', 'ver', 'what', 'when', 'where', 'which', 'with', 'your',
    ];

    /** @var array<string, array<int, string>> */
    private const PROVIDER_ALIASES = [
        'instagram' => ['instagram'],
        'tiktok' => ['tiktok', 'tik tok', 'videos cortos', 'video corto', 'short videos', 'short-form videos'],
        'facebook' => ['facebook'],
        'youtube' => ['youtube', 'you tube', 'videos largos', 'videos extensos', 'long videos', 'long-form videos'],
        'linkedin' => ['linkedin', 'linked in', 'red profesional', 'perfil profesional', 'professional network', 'professional profile'],
        'github' => ['github', 'git hub', 'repositorio', 'repositorios', 'perfil de codigo', 'codigo fuente', 'repository', 'repositories', 'code profile', 'source code'],
        'onlyfans' => ['onlyfans', 'only fans'],
        'x' => ['twitter', 'x.com'],
        'blog' => ['blog'],
        'website' => ['sitio web', 'website', 'pagina web', 'página web'],
        'newspaper' => ['diario', 'periodico', 'periódico', 'newspaper'],
    ];

    public function analyze(string $query): ProfileKnowledgeQueryIntent
    {
        $normalized = $this->normalize($query);
        $providers = collect(self::PROVIDER_ALIASES)
            ->filter(fn (array $aliases): bool => $this->containsAny($normalized, $aliases))
            ->keys()
            ->values()
            ->all();
        $hasMediaNoun = $this->containsAny($normalized, [
            'foto', 'fotos', 'photo', 'photos', 'imagen', 'imagenes', 'image', 'images', 'picture',
            'pictures', 'video', 'videos', 'clip', 'clips', 'media', 'contenido', 'content', 'post',
            'publicacion', 'publicaciones', 'infografia', 'infografias', 'sesion editorial',
        ]);
        $hasMediaShowVerb = $this->containsAny($normalized, [
            'muestra', 'muestrame', 'mostrar', 'ensename', 'comparte', 'comparteme', 'quiero ver',
            'show', 'show me', 'share', 'send me', 'let me see',
        ]);
        $providerContentQuery = collect($providers)->intersect([
            'instagram', 'tiktok', 'youtube', 'onlyfans',
        ])->isNotEmpty() && $this->containsAny($normalized, [
            'sobre', 'acerca de', 'relacionado con', 'about', 'related to',
        ]) && $this->containsAny($normalized, [
            'tienes', 'tiene', 'hay', 'have', 'do you have',
        ]);
        $media = $hasMediaNoun || $providerContentQuery || ($hasMediaShowVerb && $this->containsAny($normalized, [
            'instagram', 'tiktok', 'youtube', 'onlyfans', 'blog', 'sitio web', 'website',
        ]));
        $explicitMediaShow = $media && $hasMediaShowVerb;
        $hasSocialRoutingLanguage = $this->containsAny($normalized, [
            'link', 'enlace', 'perfil', 'profile', 'cuenta', 'account', 'usuario', 'username', 'seguirte', 'follow',
            'red social', 'redes sociales', 'social network', 'social media', 'canal', 'channel',
            'llevame', 'ir a', 'go to', 'donde puedo ver', 'where can i watch', 'where can i see',
        ]);
        $genericSocialRequest = $this->containsAny($normalized, [
            'red social', 'redes sociales', 'social network', 'social networks', 'social media',
            'tus redes', 'your socials', 'donde seguirte', 'where can i follow',
        ]);
        $socialLink = ($providers !== [] && ($hasSocialRoutingLanguage || ! $media)) || $genericSocialRequest;
        $product = $this->containsAny($normalized, [
            'producto', 'productos', 'product', 'products', 'comprar', 'compra', 'buy', 'precio',
            'price', 'tienda', 'store', 'balon', 'balones', 'proteina', 'creatina', 'referencia',
        ]);
        $productRecommendation = $product || $this->containsAny($normalized, [
            'recomienda', 'recomendacion', 'recommend', 'recommendation', 'cual me conviene',
            'que me recomiendas', 'what do you recommend', 'que elegirias', 'cual elegirias',
            'elegir', 'choose', 'which option',
        ]);
        $sourceTypes = [];

        if ($media) {
            $sourceTypes[] = 'integration_media';
        }

        if ($socialLink) {
            $sourceTypes[] = 'social_link';
        }

        if ($product || $productRecommendation) {
            $sourceTypes[] = 'product';
            $sourceTypes[] = 'product_guidance';
        }

        $excludedSourceTypes = $socialLink && ! $explicitMediaShow && ! $providerContentQuery
            ? ['integration_media']
            : [];
        $terms = $this->terms($normalized);
        $identifiers = collect($terms)
            ->filter(fn (string $term): bool => preg_match('/\d/u', $term) === 1)
            ->values()
            ->all();

        return new ProfileKnowledgeQueryIntent(
            media: $media,
            explicitMediaShow: $explicitMediaShow,
            socialLink: $socialLink,
            product: $product,
            productRecommendation: $productRecommendation,
            sourceTypes: array_values(array_unique($sourceTypes)),
            excludedSourceTypes: $excludedSourceTypes,
            providers: $providers,
            terms: $terms,
            identifiers: $identifiers,
        );
    }

    /** @return array<int, string> */
    private function terms(string $normalized): array
    {
        preg_match_all('/[\pL\pN]+/u', $normalized, $matches);

        return collect($matches[0] ?? [])
            ->filter(fn (string $term): bool => preg_match('/\d/u', $term) === 1 || mb_strlen($term) >= 3)
            ->reject(fn (string $term): bool => in_array($term, self::STOP_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, string> $needles */
    private function containsAny(string $text, array $needles): bool
    {
        return collect($needles)->contains(
            fn (string $needle): bool => str_contains($text, $this->normalize($needle))
        );
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }
}
