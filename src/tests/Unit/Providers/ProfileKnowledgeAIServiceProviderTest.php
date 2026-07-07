<?php

namespace Tests\Unit\Providers;

use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIClient;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIManager;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileKnowledgeAIServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('profile-knowledge-ai.default', 'local');

        app(ProfileKnowledgeAIManager::class)->forgetDrivers();
    }

    #[Test]
    public function it_can_resolve_profile_knowledge_ai_manager(): void
    {
        $this->assertInstanceOf(ProfileKnowledgeAIManager::class, app(ProfileKnowledgeAIManager::class));
    }

    #[Test]
    public function it_can_resolve_profile_knowledge_ai_client(): void
    {
        $this->assertInstanceOf(ProfileKnowledgeAIClient::class, app(ProfileKnowledgeAIClient::class));
    }

    #[Test]
    public function it_can_resolve_profile_knowledge_ai_service(): void
    {
        $service = app(ProfileKnowledgeAIService::class);

        $this->assertInstanceOf(ProfileKnowledgeAIService::class, $service);
        $this->assertInstanceOf(ProfileKnowledgeAIManager::class, $service->getManager());
    }

    #[Test]
    public function profile_knowledge_ai_manager_is_singleton(): void
    {
        $manager1 = app(ProfileKnowledgeAIManager::class);
        $manager2 = app(ProfileKnowledgeAIManager::class);

        $this->assertSame($manager1, $manager2);
    }
}
