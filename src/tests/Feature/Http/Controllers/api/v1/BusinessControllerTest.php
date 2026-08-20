<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Mail\BusinessLeadNotification;
use App\Mail\BusinessVisitorConfirmation;
use App\Models\Business;
use App\Models\BusinessApiClient;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\Features\FeatureService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class BusinessControllerTest extends TestAPI
{
    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->token = $this->admin->createToken('business', config('roles.admin.abilities'))->plainTextToken;
        FeatureFlag::query()->where('key', FeatureService::BUSINESS)->update(['enabled' => true]);
    }

    public function test_business_feature_is_admin_only_and_respects_toggle(): void
    {
        FeatureFlag::query()->where('key', FeatureService::BUSINESS)->update(['enabled' => false]);
        $this->withToken($this->token)->getJson('/api/businesses')->assertNotFound();

        FeatureFlag::query()->where('key', FeatureService::BUSINESS)->update(['enabled' => true]);
        $user = User::factory()->create(['role' => 'user']);
        $userToken = $user->createToken('business', ['business:read'])->plainTextToken;
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->withToken($userToken)->getJson('/api/businesses')->assertForbidden();
    }

    public function test_admin_business_routes_keep_the_global_admin_cors_policy(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Authorization' => "Bearer {$this->token}",
        ])->getJson('/api/businesses')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_admin_can_create_edit_publish_and_activate_business(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/businesses', [
            'name' => 'Bigmelo Labs',
            'description' => 'Automatización y desarrollo con IA.',
        ])->assertCreated()->assertJsonPath('data.status', 'draft');

        $business = Business::query()->findOrFail($response->json('data.id'));
        $this->assertCount(11, $business->flow->draftVersion->nodes);
        $this->assertCount(12, $business->flow->draftVersion->edges);

        $this->withToken($this->token)
            ->postJson("/api/businesses/{$business->id}/flow/publish")
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        $this->configureRequiredEmail($business);

        $this->withToken($this->token)
            ->postJson("/api/businesses/{$business->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_admin_can_list_businesses_and_index_a_text_source(): void
    {
        $business = Business::query()->findOrFail(
            $this->withToken($this->token)->postJson('/api/businesses', [
                'name' => 'Negocio de prueba',
                'description' => 'Base de conocimiento local.',
            ])->assertCreated()->json('data.id'),
        );

        $this->withToken($this->token)
            ->getJson('/api/businesses')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Negocio de prueba')
            ->assertJsonPath('data.0.status', 'draft')
            ->assertJsonStructure(['data' => [['updated_at']]]);

        $this->withToken($this->token)
            ->postJson("/api/businesses/{$business->id}/sources", [
                'name' => 'Servicios',
                'content' => 'Construimos software, automatizaciones e implementaciones de inteligencia artificial.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'indexed');

        $this->assertDatabaseHas('business_sources', [
            'business_id' => $business->id,
            'name' => 'Servicios',
            'status' => 'indexed',
        ]);
        $this->assertDatabaseHas('business_knowledge_chunks', ['business_id' => $business->id]);
        $this->assertDatabaseHas('business_usage_events', [
            'business_id' => $business->id,
            'event_type' => 'source_indexed',
        ]);
    }

    public function test_admin_can_download_and_delete_an_uploaded_business_source(): void
    {
        Storage::fake('profiles');
        $business = Business::query()->findOrFail(
            $this->withToken($this->token)->postJson('/api/businesses', [
                'name' => 'Fuentes descargables',
                'description' => 'Negocio para validar archivos.',
            ])->assertCreated()->json('data.id'),
        );

        $this->withToken($this->token)
            ->post("/api/businesses/{$business->id}/sources", [
                'name' => 'Servicios',
                'file' => UploadedFile::fake()->createWithContent('servicios.txt', 'Desarrollo de software e inteligencia artificial.'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $source = $business->sources()->firstOrFail();
        Storage::disk('profiles')->assertExists($source->storage_path);
        $this->withToken($this->token)
            ->get("/api/businesses/{$business->id}/sources/{$source->id}/file")
            ->assertOk()
            ->assertDownload('servicios.txt');

        $otherBusiness = Business::query()->findOrFail(
            $this->withToken($this->token)->postJson('/api/businesses', [
                'name' => 'Otro negocio',
                'description' => 'No puede acceder a la fuente.',
            ])->assertCreated()->json('data.id'),
        );
        $this->withToken($this->token)
            ->get("/api/businesses/{$otherBusiness->id}/sources/{$source->id}/file")
            ->assertNotFound();

        $this->withToken($this->token)
            ->deleteJson("/api/businesses/{$business->id}/sources/{$source->id}")
            ->assertOk();
        $this->assertModelMissing($source);
        Storage::disk('profiles')->assertMissing($source->storage_path);
    }

    public function test_business_usage_respects_and_validates_the_requested_date_range(): void
    {
        $business = Business::query()->findOrFail(
            $this->withToken($this->token)->postJson('/api/businesses', [
                'name' => 'Uso por fechas',
                'description' => 'Negocio para validar métricas.',
            ])->assertCreated()->json('data.id'),
        );
        $this->withToken($this->token)
            ->postJson("/api/businesses/{$business->id}/sources", [
                'name' => 'Contenido de hoy',
                'content' => 'Implementación de bases de datos y análisis de datos.',
            ])->assertCreated();

        $today = now()->toDateString();
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/usage?from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonPath('data.sources', 1)
            ->assertJsonPath('data.events_by_type.0.event_type', 'source_indexed');

        $future = now()->addDay()->toDateString();
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/usage?from={$future}&to={$future}")
            ->assertOk()
            ->assertJsonPath('data.sources', 0)
            ->assertJsonCount(0, 'data.events_by_type');

        $yesterday = now()->subDay()->toDateString();
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/usage?from={$today}&to={$yesterday}")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('to');
    }

    public function test_public_chat_completes_flow_creates_lead_and_reports_usage(): void
    {
        Mail::fake();
        $business = $this->createPublishedBusiness();
        $this->withToken($this->token)->patchJson("/api/businesses/{$business->id}/configuration", [
            'lead_recipient_email' => 'leads@example.com',
            'sender_email' => 'bot@example.com',
            'sender_name' => 'Bigmelo Bot',
            'widget_enabled' => true,
        ])->assertOk();
        $keyResponse = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Sitio local',
            'origins' => ['http://localhost:3000'],
        ])->assertCreated();
        $key = $keyResponse->json('data.key');

        $start = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'X-Bigmelo-Business-Key' => $key,
        ])->postJson('/api/business/conversations', ['visitor_id' => 'visitor-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonCount(1, 'data.messages');

        $conversation = $start->json('data.conversation_id');
        $session = $start->json('data.session');
        $headers = [
            'Origin' => 'http://localhost:3000',
            'X-Bigmelo-Business-Key' => $key,
            'X-Bigmelo-Business-Session' => $session,
        ];

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Necesito automatizar con IA el procesamiento de datos de mi empresa.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.messages.0.role', 'assistant');

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Me llamo Ana Pérez, email ana@example.com, teléfono +57 300 123 4567, empresa: Acme, sitio web acme.com.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.finished', false)
            ->assertJsonFragment(['content' => 'Para continuar necesitamos: WhatsApp con indicativo de país. Recuerda incluir el indicativo de país en teléfono y WhatsApp.']);

        $completed = $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Mi WhatsApp es +57 301 765 4321.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.finished', true);

        $this->assertStringNotContainsString('Posible solución interna', json_encode($completed->json('data.messages')) ?: '');

        $this->assertDatabaseHas('business_leads', [
            'business_id' => $business->id,
            'status' => 'created',
            'email' => 'ana@example.com',
            'company' => 'Acme',
            'phone' => '+573001234567',
            'whatsapp' => '+573017654321',
            'website' => 'https://acme.com',
        ]);
        $lead = $business->leads()->firstOrFail();
        $this->assertStringContainsString('automatizar con IA el procesamiento de datos', $lead->project_summary);
        $this->assertStringContainsString('Posible solución interna', $lead->ai_solution_summary);
        Mail::assertQueued(BusinessLeadNotification::class, function (BusinessLeadNotification $mail): bool {
            $html = $mail->render();

            return str_contains($html, '+573017654321')
                && str_contains($html, 'acme.com')
                && str_contains($html, 'Problema descrito por el cliente')
                && str_contains($html, 'Posible solución planteada por la IA');
        });
        Mail::assertQueued(BusinessVisitorConfirmation::class);

        $this->withHeaders($headers)
            ->getJson("/api/business/conversations/{$conversation}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/usage")
            ->assertOk()
            ->assertJsonPath('data.leads', 1);
    }

    public function test_public_api_rejects_an_unlisted_origin(): void
    {
        $business = $this->createPublishedBusiness();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Sitio local',
            'origins' => ['http://localhost:3000'],
        ])->json('data.key');

        $this->withHeaders(['Origin' => 'https://attacker.example', 'X-Bigmelo-Business-Key' => $key])
            ->postJson('/api/business/conversations')
            ->assertForbidden();
    }

    public function test_api_key_is_hashed_and_preflight_returns_the_allowed_origin(): void
    {
        $business = $this->createPublishedBusiness();
        $response = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Widget local',
            'origins' => ['http://localhost:3001'],
        ])->assertCreated();

        $plainKey = $response->json('data.key');
        $client = BusinessApiClient::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertNotSame($plainKey, $client->key_hash);
        $this->assertSame(hash('sha256', $plainKey), $client->key_hash);

        $this->withHeaders(['Origin' => 'http://localhost:3001'])
            ->options('/api/business/conversations')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3001');
    }

    private function createPublishedBusiness(): Business
    {
        $id = $this->withToken($this->token)->postJson('/api/businesses', [
            'name' => 'Bigmelo Labs', 'description' => 'Tecnología',
        ])->json('data.id');
        $this->withToken($this->token)->postJson("/api/businesses/{$id}/flow/publish")->assertOk();
        $this->configureRequiredEmail(Business::query()->findOrFail($id));
        $this->withToken($this->token)->postJson("/api/businesses/{$id}/activate")->assertOk();

        return Business::query()->findOrFail($id);
    }

    private function configureRequiredEmail(Business $business): void
    {
        $this->withToken($this->token)->patchJson("/api/businesses/{$business->id}/configuration", [
            'lead_recipient_email' => 'leads@example.com',
            'sender_email' => 'bot@example.com',
        ])->assertOk();
    }
}
