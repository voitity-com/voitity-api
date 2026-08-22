<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\BusinessDecisionAI\BusinessDecisionAI;
use App\Classes\BusinessDecisionAI\BusinessDecisionOutcome;
use App\Classes\BusinessDecisionAI\BusinessDecisionResult;
use App\Classes\BusinessInstructionAI\BusinessInstructionAI;
use App\Classes\BusinessInstructionAI\BusinessInstructionResult;
use App\Classes\EmbeddingService\EmbeddingClient;
use App\Mail\BusinessLeadNotification;
use App\Models\Business;
use App\Models\BusinessApiClient;
use App\Models\BusinessNodeExecution;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\Features\FeatureService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingClient;

class BusinessControllerTest extends TestAPI
{
    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('business-ai.decision.driver', 'local');
        $dimensions = (int) config('ai-knowledge.embedding.dimensions', 1536);
        app()->instance(EmbeddingClient::class, new FakeEmbeddingClient(function (string $input) use ($dimensions): array {
            $normalized = mb_strtolower($input);
            $unrelated = str_contains($normalized, 'fiesta') || str_contains($normalized, 'cumpleaños');
            $vector = array_fill(0, $dimensions, 0.0);
            $vector[$unrelated ? 1 : 0] = 1.0;

            return $vector;
        }));
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
        $this->assertCount(13, $business->flow->draftVersion->nodes);
        $this->assertCount(15, $business->flow->draftVersion->edges);

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

    public function test_legacy_business_decision_can_be_upgraded_and_published_with_fixed_yes_no_branches(): void
    {
        $business = Business::query()->findOrFail(
            $this->withToken($this->token)->postJson('/api/businesses', [
                'name' => 'Flow heredado',
                'description' => 'Servicios de software y datos.',
            ])->assertCreated()->json('data.id'),
        );
        $draft = $business->flow->draftVersion;
        $decision = $draft->nodes()->where('node_key', 'qualify')->firstOrFail();
        $decision->update([
            'title' => '¿Este problema lo puede solucionar el negocio?',
            'config' => [
                ...$decision->config,
                'mode' => 'technology_interest',
                'branches' => ['technology', 'other'],
            ],
        ]);
        $draft->edges()->where('source_node_key', 'qualify')->where('source_handle', 'yes')
            ->update(['source_handle' => 'technology', 'label' => 'Tecnología']);
        $draft->edges()->where('source_node_key', 'qualify')->where('source_handle', 'no')
            ->update(['source_handle' => 'other', 'label' => 'Otro']);

        $this->artisan('business:upgrade-flow-decisions', [
            'business' => $business->id,
            '--publish' => true,
        ])->assertExitCode(0);

        $published = $business->flow->fresh()->publishedVersion;
        $upgraded = $published->nodes()->where('node_key', 'qualify')->firstOrFail();
        $this->assertSame('Calificar problema con fuentes', $upgraded->title);
        $this->assertSame('knowledge_yes_no', $upgraded->config['mode']);
        $this->assertSame('¿Este problema lo puede solucionar el negocio?', $upgraded->config['questions']['es']);
        $this->assertSame(['yes', 'no'], $upgraded->config['branches']);
        $this->assertTrue($upgraded->config['use_business_description']);
        $this->assertTrue($upgraded->config['use_sources']);
        $this->assertDatabaseHas('business_flow_edges', [
            'business_flow_version_id' => $published->id,
            'source_node_key' => 'qualify',
            'source_handle' => 'yes',
            'target_node_key' => 'capture_problem',
        ]);
        $this->assertDatabaseHas('business_flow_edges', [
            'business_flow_version_id' => $published->id,
            'source_node_key' => 'qualify',
            'source_handle' => 'no',
            'target_node_key' => 'redirect',
        ]);
    }

    public function test_admin_can_list_businesses_and_index_a_text_source(): void
    {
        Storage::fake('profiles');
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

        $sourceText = 'Construimos software, automatizaciones e implementaciones de inteligencia artificial.';
        $sourceResponse = $this->withToken($this->token)
            ->postJson("/api/businesses/{$business->id}/sources", [
                'name' => 'Servicios',
                'content' => $sourceText,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'indexed')
            ->assertJsonPath('data.original_filename', 'Servicios.txt')
            ->assertJsonPath('data.mime_type', 'text/plain')
            ->assertJsonPath('data.download_available', true)
            ->assertJsonPath('data.download_filename', 'Servicios.txt');

        $source = $business->sources()->findOrFail($sourceResponse->json('data.id'));
        Storage::disk('profiles')->assertExists($source->storage_path);
        $this->assertSame($sourceText, Storage::disk('profiles')->get($source->storage_path));

        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/sources")
            ->assertOk()
            ->assertJsonPath('data.0.download_available', true)
            ->assertJsonPath('data.0.download_filename', 'Servicios.txt');

        $download = $this->withToken($this->token)
            ->get("/api/businesses/{$business->id}/sources/{$source->id}/file")
            ->assertOk()
            ->assertDownload('Servicios.txt');
        $this->assertSame($sourceText, $download->streamedContent());

        $this->assertDatabaseHas('business_sources', [
            'business_id' => $business->id,
            'name' => 'Servicios',
            'status' => 'indexed',
        ]);
        $this->assertDatabaseHas('business_knowledge_chunks', ['business_id' => $business->id]);
        $chunk = $source->chunks()->firstOrFail();
        $this->assertNotNull($chunk->content_hash);
        $this->assertSame(config('ai-knowledge.embedding.model'), $chunk->embedding_model);
        $this->assertNotNull($chunk->embedded_at);
        $this->assertNotNull($chunk->getAttribute('embedding'));
        $this->assertDatabaseHas('business_usage_events', [
            'business_id' => $business->id,
            'event_type' => 'source_indexed',
        ]);
    }

    public function test_admin_can_download_legacy_text_source_without_a_stored_file(): void
    {
        Storage::fake('profiles');
        $business = Business::query()->findOrFail(
            $this->withToken($this->token)->postJson('/api/businesses', [
                'name' => 'Fuentes históricas',
                'description' => 'Negocio con fuentes creadas antes de almacenar archivos TXT.',
            ])->assertCreated()->json('data.id'),
        );
        $legacyText = "Servicios heredados\nDesarrollo de software y automatización.";
        $source = $business->sources()->create([
            'user_id' => $this->admin->id,
            'type' => 'text',
            'name' => 'Legado / servicios',
            'status' => 'indexed',
            'extracted_text' => $legacyText,
            'token_count' => 12,
            'indexed_at' => now(),
        ]);

        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/sources")
            ->assertOk()
            ->assertJsonPath('data.0.download_available', true)
            ->assertJsonPath('data.0.download_filename', 'Legado - servicios.txt');

        $download = $this->withToken($this->token)
            ->get("/api/businesses/{$business->id}/sources/{$source->id}/file")
            ->assertOk()
            ->assertDownload('Legado - servicios.txt')
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame($legacyText, $download->streamedContent());
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
            ->assertCreated()
            ->assertJsonPath('data.download_available', true)
            ->assertJsonPath('data.download_filename', 'servicios.txt');

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
        $configuration = $this->withToken($this->token)->patchJson("/api/businesses/{$business->id}/configuration", [
            'lead_recipient_email' => 'leads@example.com',
            'widget_enabled' => true,
        ])->assertOk();
        $this->assertArrayNotHasKey('sender_email', $configuration->json('data'));
        $this->assertArrayNotHasKey('sender_name', $configuration->json('data'));
        $this->assertArrayNotHasKey('reply_to_email', $configuration->json('data'));
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
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.required_fields', ['project_summary']);

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
            ->assertJsonPath('data.messages.0.role', 'assistant')
            ->assertJsonPath('data.messages.0.required_fields', ['full_name', 'email', 'phone', 'whatsapp'])
            ->assertJsonPath('data.messages.0.optional_fields', ['company', 'website']);

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Me llamo Ana Pérez, email ana@example.com, teléfono +57 300 123 4567, empresa: Acme, sitio web acme.com.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.finished', false)
            ->assertJsonPath('data.messages.0.required_fields', ['whatsapp'])
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
            $envelope = $mail->envelope();

            return str_contains($html, '+573017654321')
                && str_contains($html, 'acme.com')
                && str_contains($html, 'Problema descrito por el cliente')
                && str_contains($html, 'Posible solución planteada por la IA')
                && $envelope->from?->address === 'business@bigmelo.com'
                && $envelope->from?->name === 'Bigmelo Business'
                && $envelope->subject === 'Bigmelo Labs - New Lead'
                && $envelope->replyTo === [];
        });
        Mail::assertQueuedCount(1);

        $this->assertDatabaseHas('business_lead_status_histories', [
            'business_lead_id' => $lead->id,
            'from_status' => null,
            'to_status' => 'created',
        ]);

        $this->assertNull($lead->read_at);
        $today = now()->toDateString();
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}")
            ->assertOk()
            ->assertJsonPath('data.unread_leads_count', 1);
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/leads?date_field=created_at&from={$today}&to={$today}&unread_only=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lead->id)
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('meta.unread_count', 1);

        $updatedAtBeforeRead = $lead->updated_at;
        $this->withToken($this->token)
            ->postJson("/api/businesses/{$business->id}/leads/{$lead->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $lead->id)
            ->assertJsonPath('data.read_at', fn ($readAt) => is_string($readAt) && $readAt !== '');
        $this->assertTrue($updatedAtBeforeRead->equalTo($lead->fresh()->updated_at));
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}")
            ->assertOk()
            ->assertJsonPath('data.unread_leads_count', 0);
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/leads?date_field=created_at&from={$today}&to={$today}&unread_only=1")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);

        $lead->timestamps = false;
        $lead->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();
        $lead->timestamps = true;

        $this->withToken($this->token)
            ->patchJson("/api/businesses/{$business->id}/leads/{$lead->id}", [
                'status' => 'closed',
                'note' => 'El cliente decidió cerrar el proceso después de la revisión técnica.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.histories.0.from_status', null)
            ->assertJsonPath('data.histories.0.to_status', 'created')
            ->assertJsonPath('data.histories.1.from_status', 'created')
            ->assertJsonPath('data.histories.1.to_status', 'closed')
            ->assertJsonPath('data.histories.1.note', 'El cliente decidió cerrar el proceso después de la revisión técnica.')
            ->assertJsonPath('data.histories.1.changed_by.id', $this->admin->id)
            ->assertJsonStructure(['data' => ['closed_at', 'created_at', 'updated_at']]);

        $today = now()->toDateString();
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/leads?date_field=created_at&from={$today}&to={$today}&statuses[]=closed")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/leads?date_field=updated_at&from={$today}&to={$today}&statuses[]=closed&statuses[]=sale")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lead->id)
            ->assertJsonPath('data.0.status', 'closed')
            ->assertJsonCount(2, 'data.0.histories');

        $bogotaToday = now('America/Bogota')->toDateString();
        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/leads?date_field=updated_at&from={$bogotaToday}&to={$bogotaToday}&timezone=America%2FBogota&statuses[]=closed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lead->id);

        $lead->timestamps = false;
        $lead->forceFill(['created_at' => now()])->saveQuietly();
        $lead->timestamps = true;

        $this->withHeaders($headers)
            ->getJson("/api/business/conversations/{$conversation}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->withToken($this->token)
            ->getJson("/api/businesses/{$business->id}/usage")
            ->assertOk()
            ->assertJsonPath('data.leads', 1);
    }

    public function test_public_chat_clarifies_a_short_problem_before_contact_and_allows_optional_fields_to_be_blank(): void
    {
        Mail::fake();
        $business = $this->createPublishedBusiness();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Escenarios locales',
            'origins' => ['http://localhost:3000'],
        ])->assertCreated()->json('data.key');

        $start = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'X-Bigmelo-Business-Key' => $key,
        ])->postJson('/api/business/conversations', ['visitor_id' => 'short-problem'])
            ->assertCreated();
        $conversation = $start->json('data.conversation_id');
        $headers = [
            'Origin' => 'http://localhost:3000',
            'X-Bigmelo-Business-Key' => $key,
            'X-Bigmelo-Business-Session' => $start->json('data.session'),
        ];

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Quiero un chatbot',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.messages.0.content', 'Para poder ayudarte bien, cuéntanos un poco más: ¿qué situación o proceso quieres resolver, quién lo usa y qué resultado esperas obtener?')
            ->assertJsonPath('data.messages.0.required_fields', ['project_summary'])
            ->assertJsonMissingPath('data.messages.0.optional_fields');

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Queremos atender preguntas frecuentes de clientes, derivar casos complejos y recopilar sus datos de contacto.',
            ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', '¡Perfecto! Para continuar indícanos: nombre y apellido, email válido, teléfono con indicativo de país y WhatsApp con indicativo de país. También puedes indicarnos empresa y sitio web; son opcionales.')
            ->assertJsonPath('data.messages.0.required_fields', ['full_name', 'email', 'phone', 'whatsapp'])
            ->assertJsonPath('data.messages.0.optional_fields', ['company', 'website']);

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Me llamo Laura Gómez, teléfono +57 300 111 2233 y WhatsApp +57 310 555 6677.',
            ])
            ->assertOk()
            ->assertJsonPath('data.finished', false)
            ->assertJsonPath('data.messages.0.required_fields', ['email'])
            ->assertJsonPath('data.messages.0.content', 'Para continuar necesitamos: email válido.')
            ->assertJsonMissingPath('data.messages.0.optional_fields');

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Mi email es laura@example.com.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.finished', true);

        $lead = $business->leads()->where('email', 'laura@example.com')->firstOrFail();
        $this->assertNull($lead->company);
        $this->assertNull($lead->website);
        $this->assertStringStartsWith('Queremos atender preguntas frecuentes', $lead->project_summary);
    }

    public function test_public_chat_redirects_a_non_technology_request_and_recovers_when_technology_is_described(): void
    {
        $business = $this->createPublishedBusiness();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Ramas locales',
            'origins' => ['http://localhost:3000'],
        ])->assertCreated()->json('data.key');

        $start = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'X-Bigmelo-Business-Key' => $key,
        ])->postJson('/api/business/conversations', ['visitor_id' => 'redirect-recovery'])
            ->assertCreated();
        $conversation = $start->json('data.conversation_id');
        $headers = [
            'Origin' => 'http://localhost:3000',
            'X-Bigmelo-Business-Key' => $key,
            'X-Bigmelo-Business-Session' => $start->json('data.session'),
        ];

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Quiero organizar una fiesta de cumpleaños.',
            ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', 'Estamos para ayudarte con tecnología y automatización, como desarrollo de software, IA, datos e infraestructura. Cuéntanos si tienes una necesidad relacionada.')
            ->assertJsonMissingPath('data.messages.0.required_fields');

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Necesito un chatbot para recibir pedidos, consultar inventario y derivar conversaciones a una persona.',
            ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.required_fields', ['full_name', 'email', 'phone', 'whatsapp'])
            ->assertJsonPath('data.messages.0.optional_fields', ['company', 'website']);
    }

    public function test_public_chat_answers_a_configured_decision_from_vectorized_business_sources(): void
    {
        $business = $this->createPublishedBusiness();
        $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/sources", [
            'name' => 'Problemas que solucionamos',
            'content' => 'Ayudamos a las empresas a mejorar la calidad de las decisiones mediante reportes, analítica de datos e inteligencia artificial.',
        ])->assertCreated()->assertJsonPath('data.status', 'indexed');
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Decisiones con fuentes',
            'origins' => ['http://localhost:5173'],
        ])->assertCreated()->json('data.key');

        $start = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'X-Bigmelo-Business-Key' => $key,
        ])->postJson('/api/business/conversations', ['locale' => 'es'])
            ->assertCreated();
        $conversation = $start->json('data.conversation_id');

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'X-Bigmelo-Business-Key' => $key,
            'X-Bigmelo-Business-Session' => $start->json('data.session'),
        ])->postJson("/api/business/conversations/{$conversation}/messages", [
            'fields' => ['project_summary' => 'Necesito tomar mejores deciciones en mi negocio y entender qué acciones ejecutar.'],
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.messages.0.required_fields', ['full_name', 'email', 'phone', 'whatsapp']);

        $conversationId = Business::query()->findOrFail($business->id)
            ->conversations()->where('uuid', $conversation)->value('id');
        $execution = BusinessNodeExecution::query()
            ->where('business_conversation_id', $conversationId)
            ->where('node_key', 'qualify')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('yes', $execution->output['branch']);
        $this->assertNotEmpty($execution->output['retrieved_chunk_ids']);
        $this->assertDatabaseHas('business_usage_events', [
            'business_id' => $business->id,
            'business_conversation_id' => $conversationId,
            'event_type' => 'source_retrieval',
        ]);
    }

    public function test_contextual_yes_no_decision_waits_for_clarification_and_retries_the_same_node(): void
    {
        $fakeDecision = new class implements BusinessDecisionAI
        {
            /** @var array<int, array{question: string, last_assistant: string, last_visitor: string}> */
            public array $calls = [];

            public function evaluate(
                Business $business,
                string $question,
                string $lastAssistantMessage,
                string $lastVisitorMessage,
                array $conversationContext,
                ?string $problem,
                ?string $businessDescription,
                array $knowledge,
                string $locale,
            ): BusinessDecisionResult {
                $this->calls[] = [
                    'question' => $question,
                    'last_assistant' => $lastAssistantMessage,
                    'last_visitor' => $lastVisitorMessage,
                ];
                $outcome = count($this->calls) === 1
                    ? BusinessDecisionOutcome::Unclear
                    : BusinessDecisionOutcome::Yes;

                return new BusinessDecisionResult(
                    outcome: $outcome,
                    confidence: 0.96,
                    reason: $outcome === BusinessDecisionOutcome::Yes ? 'The visitor accepted.' : 'The intent is ambiguous.',
                    sourceChunkIds: [],
                    provider: 'fake',
                    model: 'contextual-test-v1',
                );
            }
        };
        app()->instance(BusinessDecisionAI::class, $fakeDecision);

        $businessId = $this->withToken($this->token)->postJson('/api/businesses', [
            'name' => 'Tarifas contextuales',
            'description' => 'Servicios tecnológicos empresariales.',
        ])->assertCreated()->json('data.id');
        $graph = [
            'nodes' => [
                ['key' => 'ask', 'type' => 'instruction', 'title' => 'Preguntar tarifas', 'x' => 0, 'y' => 0, 'config' => [
                    'start' => true,
                    'message' => '¿Deseas conocer nuestras tarifas?',
                    'messages' => ['es' => '¿Deseas conocer nuestras tarifas?', 'en' => 'Would you like to know our rates?'],
                    'wait_for_input' => true,
                ]],
                ['key' => 'decide', 'type' => 'decision', 'title' => 'Interés en tarifas', 'x' => 300, 'y' => 0, 'config' => [
                    'mode' => 'knowledge_yes_no',
                    'question' => '¿El visitante desea conocer nuestras tarifas?',
                    'questions' => ['es' => '¿El visitante desea conocer nuestras tarifas?', 'en' => 'Does the visitor want to know our rates?'],
                    'use_business_description' => false,
                    'use_sources' => false,
                    'branches' => ['yes', 'no'],
                ]],
                ['key' => 'yes', 'type' => 'instruction', 'title' => 'Tarifas', 'x' => 600, 'y' => -100, 'config' => [
                    'message' => 'Perfecto. Te compartiremos las tarifas.',
                    'wait_for_input' => false,
                    'finish_chat' => true,
                ]],
                ['key' => 'no', 'type' => 'instruction', 'title' => 'Despedida', 'x' => 600, 'y' => 100, 'config' => [
                    'message' => 'No hay problema.',
                    'wait_for_input' => false,
                    'finish_chat' => true,
                ]],
            ],
            'edges' => [
                ['key' => 'ask-decide', 'source' => 'ask', 'target' => 'decide', 'source_handle' => null, 'label' => null, 'config' => []],
                ['key' => 'decide-yes', 'source' => 'decide', 'target' => 'yes', 'source_handle' => 'yes', 'label' => 'Sí', 'config' => []],
                ['key' => 'decide-no', 'source' => 'decide', 'target' => 'no', 'source_handle' => 'no', 'label' => 'No', 'config' => []],
            ],
        ];
        $this->withToken($this->token)->putJson("/api/businesses/{$businessId}/flow", $graph)->assertOk();
        $this->withToken($this->token)->postJson("/api/businesses/{$businessId}/flow/publish")->assertOk();
        $business = Business::query()->findOrFail($businessId);
        $this->configureRequiredEmail($business);
        $this->withToken($this->token)->postJson("/api/businesses/{$businessId}/activate")->assertOk();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$businessId}/api-clients", [
            'name' => 'Contextual decision client',
            'origins' => ['http://localhost:5173'],
        ])->assertCreated()->json('data.key');
        $headers = ['Origin' => 'http://localhost:5173', 'X-Bigmelo-Business-Key' => $key];
        $start = $this->withHeaders($headers)->postJson('/api/business/conversations', ['locale' => 'es'])->assertCreated();
        $conversation = $start->json('data.conversation_id');
        $conversationHeaders = [...$headers, 'X-Bigmelo-Business-Session' => $start->json('data.session')];

        $this->withHeaders($conversationHeaders)
            ->postJson("/api/business/conversations/{$conversation}/messages", ['message' => 'Quizás'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.messages.0.content', 'No pude determinar si tu respuesta fue afirmativa o negativa. Por favor aclara tu respuesta a esta pregunta: ¿El visitante desea conocer nuestras tarifas?');
        $this->assertDatabaseHas('business_conversations', [
            'uuid' => $conversation,
            'current_node_key' => 'decide',
            'status' => 'in_progress',
        ]);

        $this->withHeaders($conversationHeaders)
            ->postJson("/api/business/conversations/{$conversation}/messages", ['message' => 'De una'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.messages.0.content', 'Perfecto. Te compartiremos las tarifas.');

        $this->assertCount(2, $fakeDecision->calls);
        $this->assertSame('¿Deseas conocer nuestras tarifas?', $fakeDecision->calls[0]['last_assistant']);
        $this->assertSame('Quizás', $fakeDecision->calls[0]['last_visitor']);
        $this->assertSame('De una', $fakeDecision->calls[1]['last_visitor']);
    }

    public function test_ai_instruction_generates_the_message_without_overwriting_manual_localizations(): void
    {
        $fakeInstruction = new class implements BusinessInstructionAI
        {
            public function generate(
                Business $business,
                string $instruction,
                string $locale,
                array $conversationContext,
                ?string $businessDescription,
                array $knowledge,
                array $leadData,
                array $requiredFields,
                array $optionalFields,
            ): BusinessInstructionResult {
                return new BusinessInstructionResult(
                    message: 'Cuéntanos en qué sistemas está la información y qué reporte necesitas obtener.',
                    sourceChunkIds: [],
                    provider: 'fake',
                    model: 'instruction-test-v1',
                    inputTokens: 25,
                    outputTokens: 16,
                );
            }
        };
        app()->instance(BusinessInstructionAI::class, $fakeInstruction);

        $businessId = $this->withToken($this->token)->postJson('/api/businesses', [
            'name' => 'Mensajes contextuales',
            'description' => 'Implementación de soluciones de datos.',
        ])->assertCreated()->json('data.id');
        $graph = [
            'nodes' => [[
                'key' => 'ai-welcome',
                'type' => 'instruction',
                'title' => 'Profundizar',
                'x' => 0,
                'y' => 0,
                'config' => [
                    'start' => true,
                    'ai_message_enabled' => true,
                    'ai_instruction' => 'Pregunta qué información concreta falta y ofrece un ejemplo relacionado.',
                    'use_business_description' => true,
                    'use_sources' => false,
                    'message' => 'Mensaje manual conservado.',
                    'messages' => ['es' => 'Mensaje manual conservado.', 'en' => 'Preserved manual message.'],
                    'wait_for_input' => false,
                    'finish_chat' => true,
                ],
            ]],
            'edges' => [],
        ];
        $this->withToken($this->token)->putJson("/api/businesses/{$businessId}/flow", $graph)->assertOk();
        $this->withToken($this->token)->postJson("/api/businesses/{$businessId}/flow/publish")->assertOk();
        $business = Business::query()->findOrFail($businessId);
        $publishedNode = $business->flow->publishedVersion->nodes()->where('node_key', 'ai-welcome')->firstOrFail();
        $this->assertSame('Mensaje manual conservado.', $publishedNode->config['messages']['es']);
        $this->assertSame('Preserved manual message.', $publishedNode->config['messages']['en']);
        $this->configureRequiredEmail($business);
        $this->withToken($this->token)->postJson("/api/businesses/{$businessId}/activate")->assertOk();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$businessId}/api-clients", [
            'name' => 'AI instruction client',
            'origins' => ['http://localhost:5173'],
        ])->assertCreated()->json('data.key');

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'X-Bigmelo-Business-Key' => $key,
        ])->postJson('/api/business/conversations', ['locale' => 'es'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.messages.0.content', 'Cuéntanos en qué sistemas está la información y qué reporte necesitas obtener.');

        $this->assertDatabaseHas('business_usage_events', [
            'business_id' => $businessId,
            'event_type' => 'instruction_generation',
            'provider' => 'fake',
            'model' => 'instruction-test-v1',
        ]);
    }

    public function test_public_chat_can_offer_only_optional_fields_when_required_data_was_provided_early(): void
    {
        Mail::fake();
        $business = $this->createPublishedBusiness();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Datos anticipados',
            'origins' => ['http://localhost:3000'],
        ])->assertCreated()->json('data.key');

        $start = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'X-Bigmelo-Business-Key' => $key,
        ])->postJson('/api/business/conversations', ['visitor_id' => 'early-fields'])
            ->assertCreated();
        $conversation = $start->json('data.conversation_id');
        $headers = [
            'Origin' => 'http://localhost:3000',
            'X-Bigmelo-Business-Key' => $key,
            'X-Bigmelo-Business-Session' => $start->json('data.session'),
        ];

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => "Problema: Necesitamos un chatbot para atender preguntas frecuentes y derivar casos complejos.\nNombre: Mario Casas\nEmail: mario@example.com\nTeléfono: +57 300 222 3344\nWhatsApp: +57 310 222 3344",
            ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', '¡Perfecto! Ya tenemos los datos obligatorios. Si quieres, también puedes indicarnos empresa y sitio web; son opcionales.')
            ->assertJsonMissingPath('data.messages.0.required_fields')
            ->assertJsonPath('data.messages.0.optional_fields', ['company', 'website']);

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'message' => 'Continuar sin datos opcionales',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.finished', true);

        $lead = $business->leads()->where('email', 'mario@example.com')->firstOrFail();
        $this->assertNull($lead->company);
        $this->assertNull($lead->website);
    }

    public function test_public_chat_uses_english_messages_and_structured_localized_fields(): void
    {
        Mail::fake();
        $business = $this->createPublishedBusiness();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'English site',
            'origins' => ['http://localhost:5173'],
        ])->assertCreated()->json('data.key');

        $start = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'X-Bigmelo-Business-Key' => $key,
        ])->postJson('/api/business/conversations', [
            'locale' => 'en',
            'visitor_id' => 'english-visitor',
        ])->assertCreated()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.messages.0.locale', 'en')
            ->assertJsonPath('data.messages.0.content', 'Hello! Tell us what problem you want to solve or how you think we could help.')
            ->assertJsonPath('data.messages.0.fields.0.key', 'project_summary')
            ->assertJsonPath('data.messages.0.fields.0.label', 'Project or problem')
            ->assertJsonPath('data.messages.0.fields.0.type', 'textarea')
            ->assertJsonPath('data.messages.0.fields.0.required', true);

        $conversation = $start->json('data.conversation_id');
        $headers = [
            'Origin' => 'http://localhost:5173',
            'X-Bigmelo-Business-Key' => $key,
            'X-Bigmelo-Business-Session' => $start->json('data.session'),
        ];

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'locale' => 'en',
                'fields' => [
                    'project_summary' => 'We need a chatbot that answers from our knowledge base and captures qualified leads for our sales team.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.messages.0.locale', 'en')
            ->assertJsonPath('data.messages.0.fields.0.label', 'Full name')
            ->assertJsonPath('data.messages.0.fields.4.label', 'Company')
            ->assertJsonPath('data.messages.0.fields.4.required', false)
            ->assertJsonPath('data.messages.0.fields.5.label', 'Website')
            ->assertJsonFragment(['content' => 'Great! To continue, please provide: full name, valid email, phone with country code and WhatsApp with country code. You may also provide company and website; these fields are optional.']);

        $this->withHeaders($headers)
            ->postJson("/api/business/conversations/{$conversation}/messages", [
                'locale' => 'en',
                'fields' => [
                    'full_name' => 'Jane Smith',
                    'email' => 'JANE@EXAMPLE.COM',
                    'phone' => '+1 202 555 0198',
                    'whatsapp' => '+1 202 555 0142',
                    'company' => 'Acme Inc',
                    'website' => 'https://acme.example',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.messages.0.locale', 'en')
            ->assertJsonPath('data.messages.0.content', 'Thank you. We will analyze the information and contact you. The goal is to have a rapid prototype in no more than two weeks, then continue improving and refining it.');

        $this->assertDatabaseHas('business_leads', [
            'business_id' => $business->id,
            'email' => 'jane@example.com',
            'full_name' => 'Jane Smith',
            'phone' => '+12025550198',
            'whatsapp' => '+12025550142',
            'company' => 'Acme Inc',
            'website' => 'https://acme.example',
        ]);
    }

    public function test_public_chat_can_switch_from_spanish_to_english_in_the_same_conversation(): void
    {
        $business = $this->createPublishedBusiness();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Bilingual site',
            'origins' => ['http://localhost:5173'],
        ])->assertCreated()->json('data.key');

        $start = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'X-Bigmelo-Business-Key' => $key,
        ])->postJson('/api/business/conversations', ['locale' => 'es'])
            ->assertCreated()
            ->assertJsonPath('data.locale', 'es');

        $conversation = $start->json('data.conversation_id');
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'X-Bigmelo-Business-Key' => $key,
            'X-Bigmelo-Business-Session' => $start->json('data.session'),
        ])->postJson("/api/business/conversations/{$conversation}/messages", [
            'locale' => 'en',
            'message' => 'We need cloud software to process customer data automatically and show operational analytics.',
        ])->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.messages.0.locale', 'en')
            ->assertJsonPath('data.messages.0.fields.0.label', 'Full name')
            ->assertJsonFragment(['content' => 'Great! To continue, please provide: full name, valid email, phone with country code and WhatsApp with country code. You may also provide company and website; these fields are optional.']);

        $this->assertDatabaseHas('business_conversations', ['uuid' => $conversation, 'locale' => 'en']);
    }

    public function test_terminal_instruction_returns_its_message_and_completes_the_conversation_without_an_output(): void
    {
        $businessId = $this->withToken($this->token)->postJson('/api/businesses', [
            'name' => 'Chat terminal',
            'description' => 'Prueba de una indicación final.',
        ])->assertCreated()->json('data.id');
        $graph = [
            'nodes' => [
                [
                    'key' => 'welcome',
                    'type' => 'instruction',
                    'title' => 'Bienvenida',
                    'x' => 0,
                    'y' => 0,
                    'config' => [
                        'start' => true,
                        'message' => 'Escribe cualquier mensaje para continuar.',
                        'messages' => ['es' => 'Escribe cualquier mensaje para continuar.', 'en' => 'Write any message to continue.'],
                        'wait_for_input' => true,
                    ],
                ],
                [
                    'key' => 'goodbye',
                    'type' => 'instruction',
                    'title' => 'Despedida',
                    'x' => 400,
                    'y' => 0,
                    'config' => [
                        'message' => 'Gracias. La conversación ha finalizado.',
                        'messages' => ['es' => 'Gracias. La conversación ha finalizado.', 'en' => 'Thank you. The conversation is complete.'],
                        'wait_for_input' => false,
                        'finish_chat' => true,
                    ],
                ],
            ],
            'edges' => [[
                'key' => 'welcome-goodbye',
                'source' => 'welcome',
                'target' => 'goodbye',
                'source_handle' => null,
                'label' => null,
                'config' => [],
            ]],
        ];

        $this->withToken($this->token)
            ->putJson("/api/businesses/{$businessId}/flow", $graph)
            ->assertOk();
        $this->withToken($this->token)
            ->postJson("/api/businesses/{$businessId}/flow/publish")
            ->assertOk();
        $business = Business::query()->findOrFail($businessId);
        $this->configureRequiredEmail($business);
        $this->withToken($this->token)
            ->postJson("/api/businesses/{$businessId}/activate")
            ->assertOk();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$businessId}/api-clients", [
            'name' => 'Terminal flow client',
            'origins' => ['http://localhost:5173'],
        ])->assertCreated()->json('data.key');

        $headers = ['Origin' => 'http://localhost:5173', 'X-Bigmelo-Business-Key' => $key];
        $start = $this->withHeaders($headers)
            ->postJson('/api/business/conversations', ['locale' => 'es'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.finished', false)
            ->assertJsonPath('data.messages.0.content', 'Escribe cualquier mensaje para continuar.');
        $conversation = $start->json('data.conversation_id');
        $conversationHeaders = [...$headers, 'X-Bigmelo-Business-Session' => $start->json('data.session')];

        $this->withHeaders($conversationHeaders)
            ->postJson("/api/business/conversations/{$conversation}/messages", ['locale' => 'es', 'message' => 'Continuar'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.messages.0.content', 'Gracias. La conversación ha finalizado.');

        $this->withHeaders($conversationHeaders)
            ->getJson("/api/business/conversations/{$conversation}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.current_node', 'goodbye')
            ->assertJsonPath('data.completed_at', fn (mixed $value): bool => is_string($value) && $value !== '');
        $this->assertDatabaseHas('business_conversations', [
            'uuid' => $conversation,
            'status' => 'completed',
            'end_reason' => 'terminal_instruction',
        ]);
        $execution = BusinessNodeExecution::query()->where('node_key', 'goodbye')->firstOrFail();
        $this->assertTrue($execution->output['finish_chat']);
        $this->assertSame('completed', $execution->output['conversation_status']);
    }

    public function test_public_chat_rejects_unsupported_locales_and_empty_structured_fields(): void
    {
        $business = $this->createPublishedBusiness();
        $key = $this->withToken($this->token)->postJson("/api/businesses/{$business->id}/api-clients", [
            'name' => 'Validated locale',
            'origins' => ['http://localhost:5173'],
        ])->assertCreated()->json('data.key');

        $headers = ['Origin' => 'http://localhost:5173', 'X-Bigmelo-Business-Key' => $key];
        $this->withHeaders($headers)
            ->postJson('/api/business/conversations', ['locale' => 'fr'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('locale');

        $start = $this->withHeaders($headers)
            ->postJson('/api/business/conversations', ['locale' => 'en'])
            ->assertCreated();

        $conversationHeaders = [...$headers, 'X-Bigmelo-Business-Session' => $start->json('data.session')];
        $conversationUrl = "/api/business/conversations/{$start->json('data.conversation_id')}/messages";

        $this->withHeaders($conversationHeaders)
            ->postJson($conversationUrl, [
                'locale' => 'en',
                'fields' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('fields');

        $this->withHeaders($conversationHeaders)
            ->postJson($conversationUrl, [
                'locale' => 'en',
                'fields' => ['phone' => '3133929826'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields.phone'])
            ->assertJsonFragment(['Phone must include + and the country code, for example +573001234567.']);

        $this->withHeaders($conversationHeaders)
            ->postJson($conversationUrl, [
                'locale' => 'es',
                'fields' => ['whatsapp' => '573133929826'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields.whatsapp'])
            ->assertJsonFragment(['WhatsApp debe incluir + y el indicativo de país, por ejemplo +573001234567.']);
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
        ])->assertOk();
    }
}
