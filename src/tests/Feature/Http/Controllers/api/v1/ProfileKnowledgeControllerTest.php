<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileFactVisibility;
use App\Enums\ProfileSourceStatus;
use App\Models\Profile;
use App\Models\ProfileFact;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileKnowledgeControllerTest extends TestAPI
{
    private const ENDPOINT_PROFILE = '/api/profile';

    public function test_user_can_list_profile_professions(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/professions');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Profile professions retrieved successfully.');
        $response->assertJsonPath('data.default', 'custom');

        $professionKeys = collect($response->json('data.professions'))->pluck('key')->all();

        $this->assertContains('developer', $professionKeys);
        $this->assertContains('model', $professionKeys);
    }

    public function test_user_without_profile_read_ability_can_not_list_professions(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/professions');

        $response->assertStatus(403);
    }

    public function test_user_can_import_cv_source_and_list_sources_and_facts(): void
    {
        $this->fakeProfileSourcesDisk();

        $user = User::factory()->create(['role' => 'user', 'password' => Hash::make('test123')]);
        $profile = Profile::factory()->for($user)->create(['profession_key' => 'developer']);
        $token = $user->createToken('test-token', ['profile:read', 'profile:write'])->plainTextToken;
        $cvText = implode("\n", [
            'Experience',
            'Senior developer at Acme building Laravel and React platforms.',
            'Projects',
            'Voitity admin profile system with CV imports and quality checks.',
            'Skills',
            'PHP, Laravel, React, TypeScript, PostgreSQL.',
            'Education',
            'Computer Science degree.',
        ]);
        $file = UploadedFile::fake()->create('abel-cv.txt', 1, 'text/plain');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'file' => $file,
                'name' => 'LinkedIn CV',
                'text' => $cvText,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'CV source imported successfully.');
        $response->assertJsonPath('data.type', 'cv');
        $response->assertJsonPath('data.status', ProfileSourceStatus::Parsed->value);
        $response->assertJsonPath('data.name', 'LinkedIn CV');
        $this->assertContains('experience', collect($response->json('data.items'))->pluck('type')->all());

        $storagePath = $response->json('data.storage_path');
        $this->assertNotEmpty($storagePath);
        $this->assertStringStartsWith('sources/'.$profile->id.'/', $storagePath);
        $response->assertJsonPath('data.file.available', true);
        Storage::disk('profiles')->assertExists($storagePath);

        $sourcesResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources');

        $sourcesResponse->assertStatus(200);
        $sourcesResponse->assertJsonPath('data.pagination.total', 1);
        $sourcesResponse->assertJsonPath('data.sources.0.name', 'LinkedIn CV');

        $factsResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/facts?category=projects');

        $factsResponse->assertStatus(200);
        $factsResponse->assertJsonPath('message', 'Profile facts retrieved successfully.');
        $factsResponse->assertJsonPath('data.pagination.total', 1);
        $factsResponse->assertJsonPath('data.facts.0.category', 'projects');
        $factsResponse->assertJsonPath('data.facts.0.approved', false);
    }

    public function test_user_can_import_pdf_cv_without_pasting_text(): void
    {
        $this->fakeProfileSourcesDisk();

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['profession_key' => 'developer']);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;
        $file = UploadedFile::fake()->createWithContent('developer-cv.pdf', $this->samplePdfCvContent());

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'file' => $file,
                'name' => 'Developer PDF CV',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', ProfileSourceStatus::Parsed->value);

        $categories = collect($response->json('data.items'))->pluck('type')->all();

        $this->assertContains('experience', $categories);
        $this->assertContains('skills', $categories);
        $this->assertContains('projects', $categories);
        Storage::disk('profiles')->assertExists($response->json('data.storage_path'));
    }

    public function test_user_can_preview_imported_source_file(): void
    {
        $this->fakeProfileSourcesDisk();

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['profession_key' => 'developer']);
        $token = $user->createToken('test-token', ['profile:read', 'profile:write'])->plainTextToken;
        $file = UploadedFile::fake()->createWithContent('developer-cv.txt', 'Source CV file contents');

        $importResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'file' => $file,
                'text' => implode("\n", [
                    'Experience',
                    'Senior developer building Laravel APIs.',
                    'Skills',
                    'PHP, Laravel, React.',
                ]),
            ]);

        $importResponse->assertStatus(201);

        $fileResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/'.$importResponse->json('data.id').'/file');

        $fileResponse->assertStatus(200);
        $this->assertStringContainsString(
            'developer-cv.txt',
            (string) $fileResponse->headers->get('content-disposition')
        );
        $this->assertStringContainsString('Source CV file contents', $fileResponse->streamedContent());
    }

    public function test_text_only_source_is_stored_as_txt_and_can_be_previewed(): void
    {
        $this->fakeProfileSourcesDisk();

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['profession_key' => 'developer']);
        $token = $user->createToken('test-token', ['profile:read', 'profile:write'])->plainTextToken;
        $sourceText = "Profile summary\nBuilds Laravel APIs and React applications.";

        $importResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'name' => 'Profile notes',
                'text' => $sourceText,
            ]);

        $importResponse->assertStatus(201);
        $importResponse->assertJsonPath('data.original_filename', 'Profile notes.txt');
        $importResponse->assertJsonPath('data.mime_type', 'text/plain');
        $importResponse->assertJsonPath('data.file.available', true);
        $importResponse->assertJsonPath('data.file.name', 'Profile notes.txt');
        $importResponse->assertJsonPath('data.file.size', strlen($sourceText));

        $storagePath = $importResponse->json('data.storage_path');
        $this->assertStringStartsWith('sources/'.$profile->id.'/', $storagePath);
        $this->assertStringEndsWith('.txt', $storagePath);
        Storage::disk('profiles')->assertExists($storagePath);
        $this->assertSame($sourceText, Storage::disk('profiles')->get($storagePath));

        $fileResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/'.$importResponse->json('data.id').'/file');

        $fileResponse->assertStatus(200);
        $this->assertStringContainsString(
            'Profile notes.txt',
            (string) $fileResponse->headers->get('content-disposition')
        );
        $this->assertStringStartsWith('text/plain', (string) $fileResponse->headers->get('content-type'));
        $this->assertSame($sourceText, $fileResponse->streamedContent());
    }

    public function test_cv_import_validation_requires_file_or_text(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file', 'text']);
    }

    public function test_user_without_profile_write_ability_can_not_import_cv_source(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'text' => 'Experience building APIs.',
            ]);

        $response->assertStatus(403);
    }

    public function test_non_owner_can_not_import_cv_source(): void
    {
        $reader = User::factory()->create(['role' => 'user']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($owner)->create();
        $token = $reader->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'text' => 'Experience building APIs.',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_admin_can_import_cv_for_another_user_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($owner)->create();
        $token = $admin->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'text' => "Experience\nBackend developer.",
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.profile_id', $profile->id);
        $response->assertJsonPath('data.user_id', $admin->id);
    }

    public function test_user_can_approve_source_and_quality_uses_approved_facts(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create([
            'profession_key' => 'developer',
            'description' => str_repeat('Technical summary with Laravel, React, APIs, testing, delivery, and production systems. ', 2),
        ]);
        $token = $user->createToken('test-token', ['profile:read', 'profile:write'])->plainTextToken;

        $importResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'text' => implode("\n", [
                    'Experience',
                    'Built and operated production Laravel APIs.',
                    'Projects',
                    'Created an admin dashboard for profile knowledge.',
                    'Skills',
                    'Laravel, React, PostgreSQL.',
                ]),
            ]);

        $sourceId = $importResponse->json('data.id');

        $qualityBeforeApproval = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/quality');

        $qualityBeforeApproval->assertStatus(200);
        $this->assertSame(0, $qualityBeforeApproval->json('data.counts.approved_facts'));

        $approveResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/'.$sourceId.'/approve');

        $approveResponse->assertStatus(200);
        $approveResponse->assertJsonPath('message', 'Profile source approved successfully.');
        $approveResponse->assertJsonPath('data.status', ProfileSourceStatus::Approved->value);
        $approveResponse->assertJsonPath('data.items.0.approved', true);
        $approveResponse->assertJsonPath('data.items.0.indexed', false);

        $profile->refresh();
        $this->assertStringContainsString('Built and operated production Laravel APIs.', $profile->data['work'][0]['description']);
        $this->assertSame('Created an admin dashboard for profile knowledge', $profile->data['projects'][0]['name']);
        $this->assertSame('Laravel', $profile->data['skills'][0]['name']);
        $this->assertSame('React', $profile->data['skills'][1]['name']);

        $qualityAfterApproval = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/quality');

        $qualityAfterApproval->assertStatus(200);
        $this->assertGreaterThan($qualityBeforeApproval->json('data.score'), $qualityAfterApproval->json('data.score'));
        $this->assertGreaterThanOrEqual(3, $qualityAfterApproval->json('data.counts.approved_facts'));
    }

    public function test_cv_sync_splits_structured_work_experience_into_multiple_profile_data_items(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['profession_key' => 'developer']);
        $token = $user->createToken('test-token', ['profile:read', 'profile:write'])->plainTextToken;

        $importResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/cv', [
                'text' => $this->abelWorkExperienceText(),
            ]);

        $importResponse->assertStatus(201);
        $sourceId = $importResponse->json('data.id');

        $experienceItems = collect($importResponse->json('data.items'))
            ->where('type', 'experience')
            ->values();

        $this->assertCount(6, $experienceItems);
        $this->assertSame('Freelance - Software Developer', $experienceItems[0]['title']);
        $this->assertSame('Nu Image Medical - PHP Software Developer', $experienceItems[1]['title']);

        $approveResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/'.$sourceId.'/approve');

        $approveResponse->assertStatus(200);

        $profile->refresh();
        $work = $profile->data['work'];

        $this->assertCount(6, $work);
        $this->assertSame('Freelance', $work[0]['company']);
        $this->assertSame($sourceId, $work[0]['source_id']);
        $this->assertSame('Nu Image Medical', $work[1]['company']);
        $this->assertSame('World Food Programme - UN', $work[5]['company']);
        $this->assertStringContainsString('Nutrifami', $work[5]['description']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/'.$sourceId.'/approve')
            ->assertStatus(200);

        $profile->refresh();
        $this->assertCount(6, $profile->data['work']);
    }

    public function test_cv_sync_splits_legacy_experience_item_into_multiple_profile_data_items(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['profession_key' => 'developer']);
        $source = ProfileSource::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'cv',
            'name' => 'Legacy CV',
            'status' => ProfileSourceStatus::Parsed,
            'extracted_text' => $this->abelWorkExperienceText(),
        ]);
        ProfileSourceItem::create([
            'profile_source_id' => $source->id,
            'profile_id' => $profile->id,
            'type' => 'experience',
            'title' => 'Experience',
            'content' => str_replace("LAST WORK EXPERIENCE\n", '', $this->abelWorkExperienceText()),
            'confidence' => 0.75,
        ]);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/sources/'.$source->id.'/approve')
            ->assertStatus(200);

        $profile->refresh();

        $this->assertCount(6, $profile->data['work']);
        $this->assertSame('Freelance', $profile->data['work'][0]['company']);
        $this->assertSame('World Food Programme - UN', $profile->data['work'][5]['company']);
    }

    public function test_user_can_update_profile_fact(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $source = ProfileSource::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'cv',
            'name' => 'CV',
            'status' => ProfileSourceStatus::Parsed,
        ]);
        $fact = ProfileFact::create([
            'profile_id' => $profile->id,
            'profile_source_id' => $source->id,
            'category' => 'summary',
            'text' => 'Old text',
            'visibility' => ProfileFactVisibility::Public,
            'approved' => false,
            'indexed' => false,
        ]);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/facts/'.$fact->id, [
                'text' => 'Updated public fact',
                'visibility' => ProfileFactVisibility::Internal->value,
                'indexed' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.text', 'Updated public fact');
        $response->assertJsonPath('data.visibility', ProfileFactVisibility::Internal->value);
        $response->assertJsonPath('data.approved', true);
        $response->assertJsonPath('data.indexed', true);
    }

    public function test_facts_endpoint_paginates_twenty_by_default(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        for ($index = 1; $index <= 25; $index++) {
            ProfileFact::create([
                'profile_id' => $profile->id,
                'category' => 'summary',
                'text' => 'Fact '.$index,
                'visibility' => ProfileFactVisibility::Public,
                'approved' => true,
                'indexed' => true,
                'created_at' => now()->addSeconds($index),
                'updated_at' => now()->addSeconds($index),
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/facts?page=2');

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.current_page', 2);
        $response->assertJsonPath('data.pagination.per_page', 20);
        $response->assertJsonPath('data.pagination.total', 25);
        $response->assertJsonCount(5, 'data.facts');
    }

    private function samplePdfCvContent(): string
    {
        return base64_decode(
            'JVBERi0xLjMKJZOMi54gUmVwb3J0TGFiIEdlbmVyYXRlZCBQREYgZG9jdW1lbnQgKG9wZW5zb3VyY2UpCjEgMCBvYmoKPDwKL0YxIDIgMCBSCj4+CmVuZG9iagoyIDAgb2JqCjw8Ci9CYXNlRm9udCAvSGVsdmV0aWNhIC9FbmNvZGluZyAvV2luQW5zaUVuY29kaW5nIC9OYW1lIC9GMSAvU3VidHlwZSAvVHlwZTEgL1R5cGUgL0ZvbnQKPj4KZW5kb2JqCjMgMCBvYmoKPDwKL0NvbnRlbnRzIDcgMCBSIC9NZWRpYUJveCBbIDAgMCA1OTUuMjc1NiA4NDEuODg5OCBdIC9QYXJlbnQgNiAwIFIgL1Jlc291cmNlcyA8PAovRm9udCAxIDAgUiAvUHJvY1NldCBbIC9QREYgL1RleHQgL0ltYWdlQiAvSW1hZ2VDIC9JbWFnZUkgXQo+PiAvUm90YXRlIDAgL1RyYW5zIDw8Cgo+PiAKICAvVHlwZSAvUGFnZQo+PgplbmRvYmoKNCAwIG9iago8PAovUGFnZU1vZGUgL1VzZU5vbmUgL1BhZ2VzIDYgMCBSIC9UeXBlIC9DYXRhbG9nCj4+CmVuZG9iago1IDAgb2JqCjw8Ci9BdXRob3IgKGFub255bW91cykgL0NyZWF0aW9uRGF0ZSAoRDoyMDI2MDcwNjIwMDM0Ni0wNScwMCcpIC9DcmVhdG9yIChhbm9ueW1vdXMpIC9LZXl3b3JkcyAoKSAvTW9kRGF0ZSAoRDoyMDI2MDcwNjIwMDM0Ni0wNScwMCcpIC9Qcm9kdWNlciAoUmVwb3J0TGFiIFBERiBMaWJyYXJ5IC0gXChvcGVuc291cmNlXCkpIAogIC9TdWJqZWN0ICh1bnNwZWNpZmllZCkgL1RpdGxlICh1bnRpdGxlZCkgL1RyYXBwZWQgL0ZhbHNlCj4+CmVuZG9iago2IDAgb2JqCjw8Ci9Db3VudCAxIC9LaWRzIFsgMyAwIFIgXSAvVHlwZSAvUGFnZXMKPj4KZW5kb2JqCjcgMCBvYmoKPDwKL0ZpbHRlciBbIC9BU0NJSTg1RGVjb2RlIC9GbGF0ZURlY29kZSBdIC9MZW5ndGggMjc2Cj4+CnN0cmVhbQpHYXJvPDV1Myt1JjtCVE8oJTgoQi5TRT11US4iMXEkK24mMkhKUlJPZiJkUyZYJUU/KTchZT5GUjpIZWttc1VCXkpTNXRsXTowa28mYWdfRTZrJnBCOENlKkdKPkNfOyhLWVx0QSVSUl80YDNmJkxKZWxQLkA5XVprUyYoNCYiTGtKNSVrWU1SckBea1oiWDonMTJ1Jm8zcz1kPShnPjhZOTxtaT9CMj1aQEtORC86NmJbODtqZyora0VbW0FEXS5fbkJVY0tUK2djR3JAbz51LmExQ1VLP0U5ZzYmWShbZUhWPjVHYltzPT9oRWR1OzJiI1B1Rz85Pi8/QSlNZmhGS0tdMEllclVfS0BMTCtVTEVyfj5lbmRzdHJlYW0KZW5kb2JqCnhyZWYKMCA4CjAwMDAwMDAwMDAgNjU1MzUgZiAKMDAwMDAwMDA2MSAwMDAwMCBuIAowMDAwMDAwMDkyIDAwMDAwIG4gCjAwMDAwMDAxOTkgMDAwMDAgbiAKMDAwMDAwMDQwMiAwMDAwMCBuIAowMDAwMDAwNDcwIDAwMDAwIG4gCjAwMDAwMDA3MzEgMDAwMDAgbiAKMDAwMDAwMDc5MCAwMDAwMCBuIAp0cmFpbGVyCjw8Ci9JRCAKWzxlYzE0NDA2MDM5MTA4ZGEwMWY0MjZhODkwMzU4NmQxMj48ZWMxNDQwNjAzOTEwOGRhMDFmNDI2YTg5MDM1ODZkMTI+XQolIFJlcG9ydExhYiBnZW5lcmF0ZWQgUERGIGRvY3VtZW50IC0tIGRpZ2VzdCAob3BlbnNvdXJjZSkKCi9JbmZvIDUgMCBSCi9Sb290IDQgMCBSCi9TaXplIDgKPj4Kc3RhcnR4cmVmCjExNTYKJSVFT0YK',
            true
        );
    }

    private function fakeProfileSourcesDisk(): void
    {
        Storage::fake('profiles');
        config()->set('profile-knowledge-ai.sources.disk', 'profiles');
        config()->set('profile-knowledge-ai.sources.folder', 'sources');
        config()->set('profile-knowledge-ai.sources.visibility', 'private');
    }

    private function abelWorkExperienceText(): string
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
            'I served as a Senior PHP Developer, focusing primarily on backend development and integrations.',
            'Bl3ndlabs Website',
            'SENIOR PHP DEVELOPER',
            'less than 2 years',
            'Nov 2021 - Jul 2023',
            'Remote',
            'I built Laravel APIs for web and mobile applications with Twilio and payment solutions.',
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
