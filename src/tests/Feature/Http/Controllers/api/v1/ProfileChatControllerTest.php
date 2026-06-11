<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfileChatControllerTest extends TestAPI
{
    private const ENDPOINT_PROFILE = '/api/profile';

    private const ENDPOINT_PROFILE_CHATS = '/api/profile/chats';

    private const ENDPOINT_CHAT_MESSAGES = '/api/profile/chats/messages';

    public function test_unauthorized_user_can_not_list_profile_chats(): void
    {
        $profile = $this->createProfileFor(User::factory()->create());

        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/chats');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_without_chat_read_ability_can_not_list_profile_chats(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/chats');

        $response->assertStatus(403);
    }

    public function test_user_can_list_own_profile_chats_ordered_by_updated_at_desc(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileFor($user);
        $otherProfile = $this->createProfileFor(User::factory()->create());

        $oldChat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');
        $newChat = $this->createChat($profile, '2026-06-03 10:00:00', '2026-06-05 10:00:00');
        $middleChat = $this->createChat($profile, '2026-06-02 10:00:00', '2026-06-04 10:00:00');
        $foreignChat = $this->createChat($otherProfile, '2026-06-04 10:00:00', '2026-06-06 10:00:00');
        $this->createMessage($profile, $newChat, 'API question', 'question', '2026-06-03 10:00:00', 'api');
        $this->createMessage($profile, $newChat, 'API follow up', 'question', '2026-06-03 10:01:00', 'api');
        $lastOpenAiMessage = $this->createMessage($profile, $newChat, 'OpenAI answer', 'answer', '2026-06-03 10:02:00', 'openai');
        $this->createMessage($otherProfile, $foreignChat, 'Foreign question', 'question', '2026-06-06 10:00:00', 'api');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/chats');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Chats retrieved successfully.');
        $response->assertJsonPath('data.pagination.current_page', 1);
        $response->assertJsonPath('data.pagination.per_page', 20);
        $response->assertJsonPath('data.pagination.total', 3);
        $response->assertJsonCount(3, 'data.chats');

        $chatIds = collect($response->json('data.chats'))->pluck('id')->all();

        $this->assertSame([$newChat->id, $middleChat->id, $oldChat->id], $chatIds);
        $this->assertNotContains($foreignChat->id, $chatIds);
        $this->assertSame([
            'id',
            'created_at',
            'updated_at',
            'api_messages_count',
            'openai_messages_count',
            'last_message_at',
        ], array_keys($response->json('data.chats.0')));
        $this->assertSame($newChat->created_at->toJSON(), $response->json('data.chats.0.created_at'));
        $this->assertSame($newChat->updated_at->toJSON(), $response->json('data.chats.0.updated_at'));
        $this->assertSame(2, $response->json('data.chats.0.api_messages_count'));
        $this->assertSame(1, $response->json('data.chats.0.openai_messages_count'));
        $this->assertSame($lastOpenAiMessage->created_at->toJSON(), $response->json('data.chats.0.last_message_at'));
        $this->assertSame(0, $response->json('data.chats.1.api_messages_count'));
        $this->assertSame(0, $response->json('data.chats.1.openai_messages_count'));
        $this->assertNull($response->json('data.chats.1.last_message_at'));
    }

    public function test_user_can_list_profile_chats_with_profile_id_query_parameter(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileFor($user);
        $chat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->getJson(self::ENDPOINT_PROFILE_CHATS.'?profile_id='.$profile->id);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Chats retrieved successfully.');
        $response->assertJsonPath('data.pagination.total', 1);
        $response->assertJsonPath('data.chats.0.id', $chat->id);
    }

    public function test_query_endpoint_requires_profile_id(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->getJson(self::ENDPOINT_PROFILE_CHATS);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_id']);
    }

    public function test_admin_can_list_chats_for_any_profile(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $chat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($admin->email, 'test123'))
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/chats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 1);
        $response->assertJsonPath('data.chats.0.id', $chat->id);
    }

    public function test_non_admin_can_not_list_chats_for_foreign_profile(): void
    {
        $reader = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($reader->email, 'test123'))
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/chats');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_list_chats_paginates_twenty_by_default_and_accepts_page_query_parameter(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileFor($user);
        $chats = collect();

        for ($index = 1; $index <= 25; $index++) {
            $chats->push($this->createChat(
                $profile,
                sprintf('2026-05-%02d 10:00:00', $index),
                sprintf('2026-06-%02d 10:00:00', $index)
            ));
        }

        $token = $this->getToken($user->email, 'test123');
        $expectedPageTwoIds = $chats
            ->sortByDesc(fn (Chat $chat) => $chat->updated_at->timestamp)
            ->slice(20)
            ->pluck('id')
            ->values()
            ->all();

        $firstPageResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/chats');

        $firstPageResponse->assertStatus(200);
        $firstPageResponse->assertJsonCount(20, 'data.chats');
        $firstPageResponse->assertJsonPath('data.pagination.current_page', 1);
        $firstPageResponse->assertJsonPath('data.pagination.per_page', 20);
        $firstPageResponse->assertJsonPath('data.pagination.last_page', 2);
        $firstPageResponse->assertJsonPath('data.pagination.total', 25);

        $secondPageResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/chats?page=2');

        $secondPageResponse->assertStatus(200);
        $secondPageResponse->assertJsonCount(5, 'data.chats');
        $secondPageResponse->assertJsonPath('data.pagination.current_page', 2);
        $this->assertSame($expectedPageTwoIds, collect($secondPageResponse->json('data.chats'))->pluck('id')->all());
    }

    public function test_list_chats_validates_page_query_parameter(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/chats?page=0');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page']);
    }

    public function test_unauthorized_user_can_not_list_chat_messages(): void
    {
        $profile = $this->createProfileFor(User::factory()->create());
        $chat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?profile_id='.$profile->id.'&chat_id='.$chat->id);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_without_chat_read_ability_can_not_list_chat_messages(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $chat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?profile_id='.$profile->id.'&chat_id='.$chat->id);

        $response->assertStatus(403);
    }

    public function test_user_can_list_chat_messages_for_own_profile_ordered_chronologically(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileFor($user);
        $chat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');

        $lastMessage = $this->createMessage($profile, $chat, 'Last message', 'answer', '2026-06-03 10:00:00');
        $firstMessage = $this->createMessage($profile, $chat, 'First message', 'question', '2026-06-01 10:00:00');
        $middleMessage = $this->createMessage($profile, $chat, 'Middle message', 'answer', '2026-06-02 10:00:00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?profile_id='.$profile->id.'&chat_id='.$chat->id);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Messages retrieved successfully.');
        $response->assertJsonPath('data.pagination.current_page', 1);
        $response->assertJsonPath('data.pagination.per_page', 20);
        $response->assertJsonPath('data.pagination.total', 3);
        $response->assertJsonCount(3, 'data.messages');

        $messageIds = collect($response->json('data.messages'))->pluck('id')->all();

        $this->assertSame([$firstMessage->id, $middleMessage->id, $lastMessage->id], $messageIds);
        $this->assertSame('First message', $response->json('data.messages.0.text'));
        $this->assertSame('question', $response->json('data.messages.0.type'));
        $this->assertSame('api', $response->json('data.messages.0.source'));
        $this->assertSame(['meta' => 'value'], $response->json('data.messages.0.data'));
        $this->assertSame($firstMessage->created_at->toJSON(), $response->json('data.messages.0.created_at'));
    }

    public function test_admin_can_list_messages_for_any_profile_chat(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $chat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');
        $message = $this->createMessage($profile, $chat, 'Admin readable', 'question', '2026-06-01 10:00:00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($admin->email, 'test123'))
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?profile_id='.$profile->id.'&chat_id='.$chat->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 1);
        $response->assertJsonPath('data.messages.0.id', $message->id);
    }

    public function test_non_admin_can_not_list_messages_for_foreign_profile(): void
    {
        $reader = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $chat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');
        $this->createMessage($profile, $chat, 'Private message', 'question', '2026-06-01 10:00:00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($reader->email, 'test123'))
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?profile_id='.$profile->id.'&chat_id='.$chat->id);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_list_chat_messages_fails_when_chat_does_not_belong_to_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileFor($user);
        $otherProfile = $this->createProfileFor($user);
        $foreignChat = $this->createChat($otherProfile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?profile_id='.$profile->id.'&chat_id='.$foreignChat->id);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Chat not found.');
    }

    public function test_list_chat_messages_paginates_twenty_by_default_and_accepts_page_query_parameter(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileFor($user);
        $chat = $this->createChat($profile, '2026-06-01 10:00:00', '2026-06-02 10:00:00');
        $messages = collect();

        for ($index = 1; $index <= 25; $index++) {
            $messages->push($this->createMessage(
                $profile,
                $chat,
                'Message '.$index,
                $index % 2 === 0 ? 'answer' : 'question',
                sprintf('2026-06-%02d 10:00:00', $index)
            ));
        }

        $token = $this->getToken($user->email, 'test123');
        $expectedPageTwoIds = $messages
            ->sortBy(fn (Message $message) => [$message->created_at->timestamp, $message->id])
            ->slice(20)
            ->pluck('id')
            ->values()
            ->all();

        $firstPageResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?profile_id='.$profile->id.'&chat_id='.$chat->id);

        $firstPageResponse->assertStatus(200);
        $firstPageResponse->assertJsonCount(20, 'data.messages');
        $firstPageResponse->assertJsonPath('data.pagination.current_page', 1);
        $firstPageResponse->assertJsonPath('data.pagination.per_page', 20);
        $firstPageResponse->assertJsonPath('data.pagination.last_page', 2);
        $firstPageResponse->assertJsonPath('data.pagination.total', 25);

        $secondPageResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?profile_id='.$profile->id.'&chat_id='.$chat->id.'&page=2');

        $secondPageResponse->assertStatus(200);
        $secondPageResponse->assertJsonCount(5, 'data.messages');
        $secondPageResponse->assertJsonPath('data.pagination.current_page', 2);
        $this->assertSame($expectedPageTwoIds, collect($secondPageResponse->json('data.messages'))->pluck('id')->all());
    }

    public function test_list_chat_messages_validates_required_fields_and_page(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->getJson(self::ENDPOINT_CHAT_MESSAGES.'?page=0');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_id', 'chat_id', 'page']);
    }

    private function createProfileFor(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
    }

    private function createChat(Profile $profile, string $createdAt, string $updatedAt): Chat
    {
        $chat = Chat::create([
            'profile_id' => $profile->id,
        ]);

        DB::table('chats')
            ->where('id', $chat->id)
            ->update([
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

        return $chat->fresh();
    }

    private function createMessage(
        Profile $profile,
        Chat $chat,
        string $text,
        string $type,
        string $createdAt,
        string $source = 'api'
    ): Message {
        $message = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => $text,
            'type' => $type,
            'source' => $source,
            'audio' => null,
            'data' => ['meta' => 'value'],
        ]);

        DB::table('messages')
            ->where('id', $message->id)
            ->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

        return $message->fresh();
    }
}
