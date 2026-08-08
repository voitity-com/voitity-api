<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ProfileKnowledge;

use App\Services\ProfileKnowledge\ProfileKnowledgeQueryIntentAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileKnowledgeQueryIntentAnalyzerTest extends TestCase
{
    #[Test]
    public function it_distinguishes_social_profiles_from_media_on_the_same_provider(): void
    {
        $analyzer = app(ProfileKnowledgeQueryIntentAnalyzer::class);

        $social = $analyzer->analyze('Llévame a tu perfil de GitHub.');
        $media = $analyzer->analyze('Muéstrame el video de YouTube para cargar información.');

        $this->assertTrue($social->socialLink);
        $this->assertFalse($social->media);
        $this->assertContains('integration_media', $social->excludedSourceTypes);
        $this->assertTrue($media->media);
        $this->assertTrue($media->explicitMediaShow);
        $this->assertFalse($media->socialLink);
        $this->assertContains('youtube', $media->providers);
    }

    #[Test]
    public function it_preserves_numeric_references_as_exact_identifiers(): void
    {
        $intent = app(ProfileKnowledgeQueryIntentAnalyzer::class)
            ->analyze('¿Cuál es el balón con referencia 61385?');

        $this->assertTrue($intent->product);
        $this->assertContains('61385', $intent->identifiers);
        $this->assertContains('product', $intent->sourceTypes);
        $this->assertContains('product_guidance', $intent->sourceTypes);
    }

    #[Test]
    public function it_resolves_indirect_social_network_language_without_selecting_media(): void
    {
        $analyzer = app(ProfileKnowledgeQueryIntentAnalyzer::class);

        foreach ([
            'Quiero ir a tu cuenta de videos cortos, ¿me ayudas?' => 'tiktok',
            '¿Dónde puedo ver tus videos largos?' => 'youtube',
            '¿Dónde encuentro tu red profesional oficial?' => 'linkedin',
            'Compárteme tu repositorio oficial.' => 'github',
            'Quiero ir a tu perfil de código, ¿qué enlace uso?' => 'github',
        ] as $query => $provider) {
            $intent = $analyzer->analyze($query);

            $this->assertTrue($intent->socialLink, $query);
            $this->assertContains($provider, $intent->providers, $query);
            $this->assertContains('social_link', $intent->sourceTypes, $query);
            $this->assertContains('integration_media', $intent->excludedSourceTypes, $query);
        }
    }

    #[Test]
    public function it_treats_a_provider_topic_question_as_media_instead_of_a_profile_link(): void
    {
        $intent = app(ProfileKnowledgeQueryIntentAnalyzer::class)
            ->analyze('¿Tienes un TikTok sobre validar ideas antes de construir?');

        $this->assertTrue($intent->media);
        $this->assertFalse($intent->socialLink);
        $this->assertContains('tiktok', $intent->providers);
        $this->assertContains('integration_media', $intent->sourceTypes);
    }

    #[Test]
    public function it_recognizes_choice_language_as_a_product_recommendation(): void
    {
        $intent = app(ProfileKnowledgeQueryIntentAnalyzer::class)
            ->analyze('¿Qué elegirías para aprender solo, planificar y recibir acompañamiento?');

        $this->assertTrue($intent->productRecommendation);
        $this->assertContains('product', $intent->sourceTypes);
        $this->assertContains('product_guidance', $intent->sourceTypes);
    }
}
