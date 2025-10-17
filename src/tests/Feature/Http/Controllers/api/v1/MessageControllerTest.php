<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAIClient;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Mockery;

class MessageControllerTest extends TestAPI
{
    private const ENDPOINT = '/api/profile';

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unauthorized_user_can_not_send_message(): void
    {
        $profile = Profile::create([
            'user_id' => User::factory()->create()->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->postJson(self::ENDPOINT . '/' . $profile->id . '/messages', []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_store_validates_message_field(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT . '/' . $profile->id . '/messages', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['message']);
    }

    public function test_user_can_send_message_to_profile(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Hello from AI!',
            status: 'success'
        );

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->withArgs(function (Profile $boundProfile, string $message) use ($profile) {
                return $boundProfile->is($profile) && $message === 'Hi there!';
            })
            ->andReturn($chatAiAnswer);

        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT . '/' . $profile->id . '/messages', [
                'message' => 'Hi there!',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Message processed successfully.');
        $response->assertJsonPath('data.answer', 'Hello from AI!');
        $response->assertJsonPath('data.status', 'success');
    }
}
