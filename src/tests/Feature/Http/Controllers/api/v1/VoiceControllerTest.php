<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Voice;

class VoiceControllerTest extends TestAPI
{

    /**
     * Voice api endpoint
     */
    const ENDPOINT_VOICE = '/api/voice';

    public function test_store_fails_with_invalid_fields()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->postJson(self::ENDPOINT_VOICE, [
                'name' => '', // empty
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_unauthorized_user_can_not_create_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->json('POST', self::ENDPOINT_VOICE, []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_create_a_voice()
    {
        $voice_data = [
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200)
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->postJson(self::ENDPOINT_VOICE, $voice_data);

        $response->assertJsonPath('message', 'Voice created successfully.');
        $response->assertStatus(200);

        $response_content = json_decode($response->getContent());

        $new_voice = Voice::find($response_content->data->id);
        $this->assertEquals($voice_data['name'], $new_voice->name);
        $this->assertEquals($voice_data['description'], $new_voice->description);
        $this->assertTrue((boolean)$new_voice->active);
    }

    public function test_user_can_not_store_voice_if_he_already_has_one()
    {
        // Get token and create user first
        $token = $this->getToken();
        $user = \App\Models\User::where('email', 'voitity@gmail.com')->first();
        
        $voice = Voice::create([
            'user_id'       => $user->id,
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200)
        ]);

        $voice_data = [
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200)
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson(self::ENDPOINT_VOICE, $voice_data);

        $response->assertJsonPath('message', 'User already has an active voice.');
        $response->assertStatus(400);
    }

}
