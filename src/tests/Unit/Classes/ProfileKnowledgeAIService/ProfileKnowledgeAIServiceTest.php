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

    #[Test]
    public function it_uses_fallback_driver_when_remote_structuring_crosses_section_boundaries(): void
    {
        config()->set('profile-knowledge-ai.enabled', true);
        config()->set('profile-knowledge-ai.fallback_driver', 'local');

        $remoteClient = Mockery::mock(ProfileKnowledgeAIClient::class);
        $fallbackClient = Mockery::mock(ProfileKnowledgeAIClient::class);
        $remoteResult = new ProfileKnowledgeStructure(
            source: 'openai',
            status: 'success',
            data: [
                'work' => [
                    [
                        'company' => 'Freelance',
                        'role' => 'Software Developer',
                        'description' => "Projects\nChatbot on WhatsApp\nHabilidades\nPHP, Laravel",
                    ],
                ],
                'projects' => [
                    ['name' => 'Habilidades', 'description' => 'PHP, Laravel'],
                ],
            ]
        );
        $fallbackResult = new ProfileKnowledgeStructure(
            source: 'local',
            status: 'success',
            data: ['summary' => 'Clean local result']
        );

        $manager = Mockery::mock(ProfileKnowledgeAIManager::class);
        $manager->shouldReceive('driver')
            ->once()
            ->with()
            ->andReturn($remoteClient);
        $manager->shouldReceive('driver')
            ->once()
            ->with('local')
            ->andReturn($fallbackClient);
        $remoteClient->shouldReceive('structureCv')
            ->once()
            ->with(Mockery::type(Profile::class), 'CV text')
            ->andReturn($remoteResult);
        $fallbackClient->shouldReceive('structureCv')
            ->once()
            ->with(Mockery::type(Profile::class), 'CV text')
            ->andReturn($fallbackResult);

        $service = new ProfileKnowledgeAIService($manager);

        $this->assertSame($fallbackResult, $service->structureCv(new Profile, 'CV text'));
    }
}
