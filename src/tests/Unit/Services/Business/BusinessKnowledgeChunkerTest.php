<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use App\Services\Business\BusinessKnowledgeChunker;
use Tests\TestCase;

class BusinessKnowledgeChunkerTest extends TestCase
{
    public function test_it_splits_long_single_paragraph_sources_into_bounded_overlapping_chunks(): void
    {
        config()->set('business-ai.knowledge.chunk_characters', 500);
        config()->set('business-ai.knowledge.chunk_overlap_characters', 50);
        $text = implode(' ', array_fill(0, 120, 'Reportes analítica decisiones automatización infraestructura y datos empresariales.'));

        $chunks = (new BusinessKnowledgeChunker)->chunk($text);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertTrue(collect($chunks)->every(fn (string $chunk): bool => mb_strlen($chunk) <= 500));
        $this->assertTrue(collect($chunks)->every(fn (string $chunk): bool => trim($chunk) === $chunk));
    }
}
