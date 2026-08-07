<?php

declare(strict_types=1);

namespace Tests\Feature\ProfileKnowledge;

use App\Enums\ProfileFactVisibility;
use App\Enums\ProfileSourceStatus;
use App\Models\Profile;
use App\Models\ProfileFact;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\ProfileProduct;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use App\Models\User;
use App\Services\ProfileKnowledge\ProfileKnowledgeDocumentBuilder;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileKnowledgeDocumentBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_documents_for_every_conversation_knowledge_source_even_when_features_are_off(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create([
            'data' => ['work' => [['company' => 'Acme', 'role' => 'Engineer']]],
            'networks' => ['instagram' => 'https://instagram.com/acme'],
            'product_recommendation_guidance' => 'Offer the field guide for architecture questions.',
        ]);
        $source = ProfileSource::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'cv',
            'name' => 'Approved CV',
            'status' => ProfileSourceStatus::Indexed,
            'approved_at' => now(),
        ]);
        $item = ProfileSourceItem::query()->create([
            'profile_source_id' => $source->id,
            'profile_id' => $profile->id,
            'type' => 'experience',
            'title' => 'Backend work',
            'content' => 'Built resilient APIs.',
            'approved' => true,
        ]);
        ProfileFact::query()->create([
            'profile_id' => $profile->id,
            'profile_source_id' => $source->id,
            'profile_source_item_id' => $item->id,
            'category' => 'skills',
            'text' => 'Uses PostgreSQL.',
            'visibility' => ProfileFactVisibility::Public,
            'approved' => true,
        ]);
        $integration = ProfileIntegration::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_user_id' => 'other-'.$profile->id,
            'username' => 'Other',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        ProfileIntegrationMedia::query()->create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_media_id' => 'other-media',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/image.jpg',
            'permalink' => 'https://blog.example.com/article',
            'caption' => 'Architecture article',
            'observation' => 'Show this for architecture questions.',
            'selected' => true,
            'metadata' => ['destination_type' => 'blog'],
        ]);
        ProfileProduct::query()->create([
            'public_id' => (string) Str::uuid(),
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'slug' => 'field-guide',
            'name' => 'Field Guide',
            'description' => 'A practical reference.',
            'image_source' => 'remote',
            'image_url' => 'https://cdn.example.com/product.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/guide',
            'status' => 'published',
            'fingerprint' => hash('sha256', 'field-guide'),
            'published_at' => now(),
        ]);

        $documents = collect(app(ProfileKnowledgeDocumentBuilder::class)->build($profile->fresh()));
        $types = $documents->pluck('sourceType')->unique()->all();

        $this->assertContains('profile_identity', $types);
        $this->assertContains('profile_data', $types);
        $this->assertContains('profile_source_item', $types);
        $this->assertContains('profile_fact', $types);
        $this->assertContains('social_link', $types);
        $this->assertContains('integration_media', $types);
        $this->assertContains('product', $types);
        $this->assertContains('product_guidance', $types);
        $this->assertTrue($documents->firstWhere('sourceType', 'integration_media')->active);
        $this->assertTrue($documents->firstWhere('sourceType', 'product')->active);
    }

    #[Test]
    public function it_splits_long_public_facts_without_exceeding_the_configured_chunk_size(): void
    {
        config()->set('ai-knowledge.indexing.raw_source_chunk_characters', 500);
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $source = ProfileSource::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'manual',
            'name' => 'Long source',
            'status' => ProfileSourceStatus::Approved,
            'approved_at' => now(),
        ]);
        $fact = ProfileFact::query()->create([
            'profile_id' => $profile->id,
            'profile_source_id' => $source->id,
            'category' => 'strategy',
            'text' => implode("\n\n", array_fill(0, 8, str_repeat('Paragraph sentence. ', 8))),
            'visibility' => ProfileFactVisibility::Public,
            'approved' => true,
        ]);

        $documents = collect(app(ProfileKnowledgeDocumentBuilder::class)->build($profile->fresh()))
            ->where('sourceType', 'profile_fact')
            ->values();

        $this->assertGreaterThan(1, $documents->count());
        $this->assertTrue($documents->every(fn ($document): bool => mb_strlen($document->content) <= 500));
        $this->assertSame(
            ["fact.{$fact->id}.0", "fact.{$fact->id}.1"],
            $documents->take(2)->pluck('key')->all(),
        );
    }
}
