<?php

namespace Tests\Unit\Classes\ProfileKnowledgeAIService;

use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIClient;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIManager;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIService;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeStructure;
use App\Models\Profile;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileKnowledgeAIServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_uses_fallback_driver_when_remote_structuring_is_disabled(): void
    {
        config()->set('profile-knowledge-ai.enabled', false);
        config()->set('profile-knowledge-ai.fallback_driver', 'local');

        $fallbackClient = Mockery::mock(ProfileKnowledgeAIClient::class);
        $result = new ProfileKnowledgeStructure(
            source: 'local',
            status: 'success',
            data: ['summary' => 'Local summary']
        );

        $manager = Mockery::mock(ProfileKnowledgeAIManager::class);
        $manager->shouldReceive('driver')
            ->once()
            ->with('local')
            ->andReturn($fallbackClient);
        $fallbackClient->shouldReceive('structureCv')
            ->once()
            ->with(Mockery::type(Profile::class), 'CV text')
            ->andReturn($result);

        $service = new ProfileKnowledgeAIService($manager);

        $this->assertSame($result, $service->structureCv(new Profile, 'CV text'));
    }
}
