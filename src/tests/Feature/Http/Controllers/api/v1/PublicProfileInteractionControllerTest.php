<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileProductDestinationType;
use App\Enums\ProfileProductStatus;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\ProfileProduct;
use App\Models\User;
use Illuminate\Support\Str;

class PublicProfileInteractionControllerTest extends TestAPI
{
    public function test_public_profile_view_is_idempotent_and_visitor_identifier_is_hashed(): void
    {
        $profile = Profile::factory()->for(User::factory())->create([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $eventId = (string) Str::uuid();
        $visitorId = (string) Str::uuid();
        $payload = [
            'event_id' => $eventId,
            'visitor_id' => $visitorId,
            'event_type' => 'profile_viewed',
            'surface' => 'profile_page',
        ];

        $this->postJson("/api/public/profiles/{$profile->id}/interactions", $payload)->assertCreated();
        $this->postJson("/api/public/profiles/{$profile->id}/interactions", $payload)
            ->assertOk()
            ->assertJsonPath('data.recorded', false);

        $this->assertDatabaseCount('profile_interaction_events', 1);
        $this->assertDatabaseMissing('profile_interaction_events', ['visitor_id_hash' => $visitorId]);
    }

    public function test_client_cannot_submit_server_owned_media_shown_event(): void
    {
        $profile = Profile::factory()->for(User::factory())->create([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);

        $this->postJson("/api/public/profiles/{$profile->id}/interactions", [
            'event_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
            'event_type' => 'media_shown',
            'surface' => 'chat_media_card',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_type');

        $this->postJson("/api/public/profiles/{$profile->id}/interactions", [
            'event_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
            'event_type' => 'product_shown',
            'surface' => 'product_button',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_type');
    }

    public function test_product_click_uses_an_authoritative_product_snapshot(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $product = ProfileProduct::query()->create([
            'public_id' => (string) Str::uuid(),
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'slug' => 'snapshot-product',
            'name' => 'Snapshot product',
            'description' => 'Test product.',
            'image_source' => 'url',
            'image_url' => 'https://example.com/product.jpg',
            'destination_type' => ProfileProductDestinationType::WhatsApp,
            'country_code' => '57',
            'phone_number' => '3001112233',
            'status' => ProfileProductStatus::Published,
            'fingerprint' => hash('sha256', 'snapshot-product'),
            'published_at' => now(),
        ]);

        $this->postJson("/api/public/profiles/{$profile->id}/interactions", [
            'event_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
            'event_type' => 'product_clicked',
            'subject_id' => (string) $product->id,
            'surface' => 'product_button',
            'metadata' => ['destination_type' => 'external_url'],
        ])->assertCreated();

        $this->assertDatabaseHas('profile_interaction_events', [
            'subject_public_id' => $product->public_id,
            'subject_name' => 'Snapshot product',
            'subject_status' => 'published',
            'destination_type' => 'whatsapp',
        ]);
    }
}
