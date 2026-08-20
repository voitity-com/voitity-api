<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\BusinessFlowAI;

use App\Classes\BusinessFlowAI\LocalBusinessFlowAI;
use App\Services\Business\BusinessUsageRecorder;
use PHPUnit\Framework\TestCase;

class LocalBusinessFlowAITest extends TestCase
{
    private LocalBusinessFlowAI $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new LocalBusinessFlowAI(new BusinessUsageRecorder);
    }

    public function test_it_classifies_technology_needs(): void
    {
        $result = $this->ai->classifyTechnology('Necesito desarrollar una aplicación con IA y automatización de datos.');

        $this->assertSame('technology', $result->data['branch']);
        $this->assertGreaterThan(0, $result->inputTokens);
    }

    public function test_it_extracts_contact_and_project_fields(): void
    {
        $result = $this->ai->extractLeadData(
            'Me llamo Ana Pérez, email ana@example.com, teléfono +57 300 123 4567, WhatsApp +57 301 765 4321, empresa: Acme, sitio web acme.com, proyecto: automatizar documentos con IA.',
        );
        $data = $result->data['lead_data'];

        $this->assertSame('Ana Pérez', $data['full_name']);
        $this->assertSame('ana@example.com', $data['email']);
        $this->assertSame('+573001234567', $data['phone']);
        $this->assertSame('+573017654321', $data['whatsapp']);
        $this->assertSame('Acme', $data['company']);
        $this->assertSame('https://acme.com', $data['website']);
        $this->assertStringContainsString('automatizar', $data['project_summary']);
    }

    public function test_it_does_not_confuse_a_contact_message_with_the_problem(): void
    {
        $result = $this->ai->extractLeadData(
            'Me llamo Ana Pérez, email ana@example.com, teléfono +57 300 123 4567 y WhatsApp +57 301 765 4321.',
        );

        $this->assertArrayNotHasKey('project_summary', $result->data['lead_data']);
    }
}
