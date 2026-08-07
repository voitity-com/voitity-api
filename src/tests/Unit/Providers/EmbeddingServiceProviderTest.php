<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Classes\EmbeddingService\EmbeddingManager;
use App\Classes\EmbeddingService\OpenAI\OpenAIEmbeddingClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmbeddingServiceProviderTest extends TestCase
{
    #[Test]
    public function it_resolves_the_manager_as_a_singleton_and_the_configured_client_contract(): void
    {
        $manager = app(EmbeddingManager::class);

        $this->assertSame($manager, app(EmbeddingManager::class));
        $this->assertInstanceOf(OpenAIEmbeddingClient::class, $manager->driver());
        $this->assertInstanceOf(EmbeddingClient::class, app(EmbeddingClient::class));
    }
}
