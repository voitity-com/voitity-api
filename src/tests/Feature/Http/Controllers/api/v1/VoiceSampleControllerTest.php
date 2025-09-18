<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Voice;
use App\Models\VoiceSample;

class VoiceSampleControllerTest extends TestAPI
{
    const ENDPOINT = '/api/voicesample';

    public function test_store_fails_with_invalid_fields()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->postJson(self::ENDPOINT, [
                'file' => '', // empty
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['voice_id', 'file', 'duration']);
    }

    public function test_user_can_create_voice_sample()
    {
        $voice = Voice::factory()->create();
        $data = [
            'voice_id' => $voice->id,
            'file' => 'sample.mp3',
            'duration' => 120,
            'active' => true,
        ];
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->postJson(self::ENDPOINT, $data);
        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice sample created successfully.');
        $response_content = json_decode($response->getContent());
        $new_sample = VoiceSample::find($response_content->data->id);
        $this->assertEquals($data['file'], $new_sample->file);
        $this->assertEquals($data['duration'], $new_sample->duration);
        $this->assertTrue((boolean)$new_sample->active);
    }
}
