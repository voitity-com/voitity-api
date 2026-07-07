<?php

namespace Tests\Unit\Classes\ProfileKnowledgeAIService\Local;

use App\Classes\ProfileKnowledgeAIService\Local\LocalProfileKnowledgeClient;
use App\Models\Profile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocalProfileKnowledgeClientTest extends TestCase
{
    #[Test]
    public function it_splits_cv_work_experience_into_multiple_jobs(): void
    {
        $client = new LocalProfileKnowledgeClient;
        $profile = new Profile(['profession_key' => 'developer']);

        $result = $client->structureCv($profile, $this->abelCvText());

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(6, $result->items('work'));
        $this->assertSame('Freelance', $result->items('work')[0]['company']);
        $this->assertSame('Software Developer', $result->items('work')[0]['role']);
        $this->assertSame('Nu Image Medical', $result->items('work')[1]['company']);
        $this->assertSame('World Food Programme - UN', $result->items('work')[5]['company']);
        $skillNames = collect($result->items('skills'))->pluck('name')->all();

        $this->assertContains('Laravel', $skillNames);
        $this->assertNotEmpty($result->items('projects'));
    }

    private function abelCvText(): string
    {
        return implode("\n", [
            'LAST WORK EXPERIENCE',
            'Freelance',
            'SOFTWARE DEVELOPER',
            'Chatbot on WhatsApp integrating OpenAI API.',
            'Integration with Elevenlabs and OpenAI to create profiles using voice and data from users.',
            'Mobile App integrating Football API, showing soccer matches data, and allowing people to set their own scores as predictions.',
            '1 Year',
            'Jul 2025 - Current day',
            'Nu Image Medical Website',
            'PHP SOFTWARE DEVELOPER',
            '2 Years',
            'Jul 2023 - Jun 2025',
            'Remote',
            'I served as a Senior PHP Developer, focusing primarily on backend development with Laravel and Vue.js.',
            'Bl3ndlabs Website',
            'SENIOR PHP DEVELOPER',
            'less than 2 years',
            'Nov 2021 - Jul 2023',
            'Remote',
            'I built APIs for web and mobile applications using Laravel, Twilio, Azure, and TDD.',
            'Teravision Technologies Website',
            'SOFTWARE DEVELOPER',
            'less than 1 year',
            'Mar 2021 - Nov 2021',
            'Remote',
            'I worked on developing APIs using Laravel for web and mobile applications.',
            'Sproutloud Media Network Website',
            'SOFTWARE DEVELOPER',
            '2 Years',
            'Jan 2019 - Mar 2021',
            'Medellin, Colombia',
            'I worked with PHP, Laravel, Vue.js, Apache Kafka, MongoDB, and PostgreSQL.',
            'World Food Programme - UN Website',
            'SOFTWARE DEVELOPER',
            '3.5 Years',
            'Sep 2014 - Feb 2018',
            'Bogota, Colombia',
            'Define software architecture and workflow for the project Nutrifami.',
            'TECHNOLOGIES',
            'PHP, Laravel, Vue.js, PostgreSQL, MongoDB, Apache Kafka, Azure, OpenAI API, ElevenLabs, Twilio',
        ]);
    }
}
