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

    public function test_it_classifies_chatbot_needs_as_technology(): void
    {
        foreach ([
            'Quiero un chatbot para atender a mis clientes.',
            'Necesitamos un chat bot para soporte.',
            'Busco un asistente virtual que recopile datos.',
            'Queremos implementar un bot conversacional.',
        ] as $message) {
            $this->assertSame('technology', $this->ai->classifyTechnology($message)->data['branch'], $message);
        }
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

    public function test_it_keeps_a_short_problem_for_validation_and_replaces_it_with_a_better_description(): void
    {
        $short = $this->ai->extractLeadData('Problema: Quiero un chatbot', [], true);

        $this->assertSame('Quiero un chatbot', $short->data['lead_data']['project_summary']);

        $detailed = $this->ai->extractLeadData(
            'Queremos atender consultas frecuentes de clientes, derivar casos complejos y recopilar sus datos de contacto.',
            $short->data['lead_data'],
            true,
        );

        $this->assertStringStartsWith('Queremos atender consultas frecuentes', $detailed->data['lead_data']['project_summary']);
    }

    public function test_it_prioritizes_a_conversational_solution_for_chatbots_that_collect_data(): void
    {
        $result = $this->ai->summarizeSolution(
            'Necesitamos un chatbot para responder preguntas frecuentes y recopilar datos de contacto.',
        );

        $this->assertStringContainsString('asistente conversacional', $result->data['summary']);
        $this->assertStringNotContainsString('arquitectura de datos', $result->data['summary']);
    }
}
