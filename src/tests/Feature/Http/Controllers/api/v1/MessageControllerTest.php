<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAIClient;
use App\Models\Profile;
use App\Models\Chat;
use App\Models\Message;
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

        $chat = Chat::create([
            'profile_id' => $profile->id,
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Hello from AI!',
            status: 'success'
        );

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->withArgs(function (Profile $boundProfile, string $message) use ($profile) {
                return $boundProfile->is($profile) && $message === 'Hi there!';
            })
            ->once()
            ->andReturn($chatAiAnswer);

        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT . '/' . $profile->id . '/messages', [
                'message' => 'Hi there!',
                'chat_id' => $chat->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Message processed successfully.');
        $response->assertJsonPath('data.chat_id', $chat->id);
        $response->assertJsonPath('data.text', 'Hello from AI!');
        $response->assertJsonPath('data.audio_url', null);
        $response->assertJsonPath('data.source', 'openai');
        $response->assertJsonPath('data.data.chat_ai.answer', 'Hello from AI!');

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'profile_id' => $profile->id,
            'type' => 'question',
            'text' => 'Hi there!',
            'source' => 'api',
            'audio' => null,
        ]);

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'profile_id' => $profile->id,
            'type' => 'answer',
            'source' => 'openai',
            'audio' => null,
        ]);

        $this->assertSame(1, Message::where('chat_id', $chat->id)->where('type', 'answer')->count());
    }

    public function test_store_fails_when_chat_does_not_belong_to_profile(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $otherProfile = Profile::create([
            'user_id' => User::factory()->create()->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'female',
            'personality' => $this->faker->text(100),
        ]);

        $foreignChat = Chat::create([
            'profile_id' => $otherProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT . '/' . $profile->id . '/messages', [
                'message' => 'Hello!',
                'chat_id' => $foreignChat->id,
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Chat not found.');
    }

    public function test_user_can_send_message_and_new_chat_is_created_when_missing(): void
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
            answer: 'Here is your answer.',
            status: 'success'
        );

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->withArgs(function (Profile $boundProfile, string $message) use ($profile) {
                return $boundProfile->is($profile) && $message === 'Start conversation';
            })
            ->once()
            ->andReturn($chatAiAnswer);

        $this->instance(ChatAIClient::class, $chatAiClient);
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT . '/' . $profile->id . '/messages', [
                'message' => 'Start conversation',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.text', 'Here is your answer.');
        $chatId = $response->json('data.chat_id');

        $this->assertNotNull($chatId);
        $this->assertTrue(Chat::where('id', $chatId)->where('profile_id', $profile->id)->exists());

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chatId,
            'profile_id' => $profile->id,
            'type' => 'question',
            'text' => 'Start conversation',
            'audio' => null,
        ]);

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chatId,
            'profile_id' => $profile->id,
            'type' => 'answer',
            'audio' => null,
        ]);

        $this->assertSame(1, Message::where('chat_id', $chatId)->where('type', 'answer')->count());
    }
}
