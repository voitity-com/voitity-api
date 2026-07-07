<?php

namespace Tests\Unit\Classes\ProfileKnowledgeAIService;

use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeStructure;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileKnowledgeStructureTest extends TestCase
{
    #[Test]
    public function it_normalizes_profile_knowledge_data(): void
    {
        $structure = new ProfileKnowledgeStructure(
            source: 'openai',
            status: 'success',
            data: [
                'about' => 'Backend developer',
                'experience' => [
                    [
                        'employer' => 'Nu Image Medical',
                        'title' => 'PHP Software Developer',
                        'skills' => 'Laravel, Vue.js',
                    ],
                ],
                'skills' => 'PHP, Laravel',
            ],
            confidence: 0.9
        );

        $this->assertTrue($structure->isSuccessful());
        $this->assertTrue($structure->hasData());
        $this->assertSame('Backend developer', $structure->data['summary']);
        $this->assertSame('Nu Image Medical', $structure->items('work')[0]['company']);
        $this->assertSame('PHP Software Developer', $structure->items('work')[0]['role']);
        $this->assertSame(['Laravel', 'Vue.js'], $structure->items('work')[0]['technologies']);
        $this->assertSame('PHP', $structure->items('skills')[0]['name']);
        $this->assertSame(0.9, $structure->toArray()['confidence']);
    }
}
