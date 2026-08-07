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
}
