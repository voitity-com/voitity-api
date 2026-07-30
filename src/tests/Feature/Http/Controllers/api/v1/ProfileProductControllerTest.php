<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileProductStatus;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\ProfileProduct;
use App\Models\User;
use App\Services\Products\ProfileProductPromptService;
use App\Services\Products\ProfileProductService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ProfileProductControllerTest extends TestAPI
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->app->forgetInstance('encrypter');
        Storage::fake('profiles');
        config([
            'products.disk' => 'profiles',
            'products.folder' => 'products',
            'products.max_products' => 15,
            'products.public_base_url' => 'https://bigmelo.com/p',
        ]);
    }

    public function test_product_abilities_are_configured_by_role(): void
    {
        foreach (['admin', 'user', 'profile'] as $role) {
            $this->assertContains('products:read', config("roles.{$role}.abilities"));
            $this->assertContains('products:write', config("roles.{$role}.abilities"));
            $this->assertContains('products:publish', config("roles.{$role}.abilities"));
            $this->assertContains('products:import', config("roles.{$role}.abilities"));
        }

        $this->assertContains('products:read', config('roles.api.abilities'));
        $this->assertNotContains('products:write', config('roles.api.abilities'));
    }

    public function test_owner_can_create_list_and_enable_whatsapp_product(): void
    {
        [$user, $profile, $token] = $this->ownerContext();
        $response = $this->withToken($token)->post("/api/profile/{$profile->id}/products", [
            'name' => 'Proteína Whey',
            'description' => 'Apoya la recuperación después del entrenamiento.',
            'image' => UploadedFile::fake()->create('protein.jpg', 10, 'image/jpeg'),
            'destination_type' => 'whatsapp',
            'country_code' => '+57',
            'phone_number' => '300 123 4567',
            'status' => 'published',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Proteína Whey')
            ->assertJsonPath('data.destination_type', 'whatsapp')
            ->assertJsonPath('data.status', 'published');
        $product = ProfileProduct::query()->firstOrFail();
        Storage::disk('profiles')->assertExists($product->storage_path);
        $this->assertStringStartsWith('https://wa.me/573001234567?text=', $response->json('data.action_url'));
        $this->assertStringContainsString(
            rawurlencode('Hola, estoy interesado en "Proteína Whey".'),
            $response->json('data.action_url')
        );
        $expectedPublicUrl = 'https://bigmelo.com/p/'.$profile->alias
            .'/productos/proteina-whey?v='.$product->updated_at->getTimestamp();
        $this->assertSame($expectedPublicUrl, $response->json('data.public_url'));
        parse_str((string) parse_url($response->json('data.action_url'), PHP_URL_QUERY), $whatsAppQuery);
        $this->assertSame(
            "Hola, estoy interesado en \"Proteína Whey\".\n\n"
            ."Apoya la recuperación después del entrenamiento.\n\n"
            .$response->json('data.public_url'),
            $whatsAppQuery['text']
        );

        $this->withToken($token)
            ->patchJson("/api/profile/{$profile->id}/products/settings", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.products_enabled', true);
        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/products")
            ->assertOk()
            ->assertJsonPath('data.products_enabled', true)
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonPath('data.available_slots', 14);
    }

    public function test_telegram_product_uses_profile_language_and_encoded_public_url(): void
    {
        [$user, $profile] = $this->ownerContext('en');
        $product = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Creatine',
            'description' => 'Daily creatine supplement.',
            'image_url' => 'https://images.example.com/creatine.jpg',
            'destination_type' => 'telegram',
            'country_code' => '1',
            'phone_number' => '(305) 555-0123',
            'status' => 'published',
        ]);
        $payload = app(\App\Http\Responses\Products\ProfileProductResponse::class, ['product' => $product->load('profile')])
            ->toArray();

        $this->assertStringStartsWith('https://t.me/+13055550123?text=', $payload['action_url']);
        $this->assertStringContainsString(rawurlencode('Hi, I\'m interested in "Creatine".'), $payload['action_url']);
        $this->assertStringContainsString(rawurlencode($payload['public_url']), $payload['action_url']);
    }

    public function test_external_product_public_page_has_open_graph_metadata_and_destination(): void
    {
        [$user, $profile] = $this->ownerContext();
        $profile->forceFill(['active' => true, 'status' => ProfileStatus::Published])->save();
        $product = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Omega 3',
            'description' => 'Producto disponible en nuestra tienda virtual.',
            'image_url' => 'https://images.example.com/omega.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/products/omega-3',
            'status' => 'published',
        ]);

        $this->get("/p/{$profile->alias}/productos/{$product->slug}")
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Omega 3">', false)
            ->assertSee('<meta property="og:image" content="https://images.example.com/omega.jpg">', false)
            ->assertSee(
                '<meta property="og:url" content="'
                .app(\App\Services\Products\ProfileProductLinkService::class)->publicUrl($product)
                .'">',
                false
            )
            ->assertSee('href="https://shop.example.com/products/omega-3"', false);

        $product->forceFill(['status' => ProfileProductStatus::Draft])->save();
        $this->get("/p/{$profile->alias}/productos/{$product->slug}")->assertNotFound();
    }

    public function test_uploaded_product_urls_are_resolved_from_storage_instead_of_the_persisted_host(): void
    {
        [$user, $profile] = $this->ownerContext();
        $profile->forceFill([
            'active' => true,
            'products_enabled' => true,
            'status' => ProfileStatus::Published,
        ])->save();
        $product = app(ProfileProductService::class)->create(
            $profile,
            $user,
            [
                'name' => 'Creatina Monohidrato',
                'description' => 'Creatina monohidratada de alta pureza.',
                'destination_type' => 'whatsapp',
                'country_code' => '57',
                'phone_number' => '3001234567',
                'status' => 'published',
            ],
            $this->validWebpUpload()
        );
        $this->assertNotNull($product->social_storage_path);
        Storage::disk('profiles')->assertExists($product->social_storage_path);
        $product->forceFill([
            'image_url' => 'http://localhost:8000/storage/'.$product->storage_path,
        ])->save();
        config(['filesystems.disks.profiles.url' => 'https://media.bigmelo.com']);
        Storage::forgetDisk('profiles');
        $expectedImageUrl = 'https://media.bigmelo.com/'.$product->storage_path;
        $expectedSocialImageUrl = 'https://media.bigmelo.com/'.$product->social_storage_path;

        $payload = (new \App\Http\Responses\Products\ProfileProductResponse($product->fresh('profile')))->toArray();

        $this->assertSame($expectedImageUrl, $payload['image_url']);
        $this->assertStringContainsString('?v='.$product->updated_at->getTimestamp(), $payload['public_url']);
        $this->assertSame(
            $expectedImageUrl,
            app(ProfileProductPromptService::class)->productsForPrompt($profile->fresh())[0]['image_url']
        );
        $this->get("/p/{$profile->alias}/productos/{$product->slug}")
            ->assertOk()
            ->assertSee('<meta property="og:image" content="'.$expectedSocialImageUrl.'">', false)
            ->assertSee('<meta property="og:image:secure_url" content="'.$expectedSocialImageUrl.'">', false)
            ->assertSee('<meta property="og:image:type" content="image/jpeg">', false)
            ->assertSee('<meta property="og:image:width" content="1200">', false)
            ->assertSee('<meta property="og:image:height" content="630">', false)
            ->assertDontSee('http://localhost:8000/storage/', false);
    }

    public function test_social_preview_command_backfills_existing_uploaded_products(): void
    {
        [$user, $profile] = $this->ownerContext();
        $product = app(ProfileProductService::class)->create(
            $profile,
            $user,
            [
                'name' => 'Creatina existente',
                'description' => 'Producto creado antes de habilitar previews sociales.',
                'destination_type' => 'external_url',
                'destination_url' => 'https://shop.example.com/creatine',
                'status' => 'published',
            ],
            $this->validWebpUpload()
        );
        Storage::disk('profiles')->delete($product->social_storage_path);
        $product->forceFill([
            'social_storage_path' => null,
            'social_image_mime_type' => null,
            'social_image_width' => null,
            'social_image_height' => null,
        ])->save();

        $this->artisan('products:refresh-social-images')
            ->expectsOutput('Product social images generated: 1. Skipped: 0.')
            ->assertSuccessful();

        $product->refresh();
        Storage::disk('profiles')->assertExists($product->social_storage_path);
        $this->assertSame('image/jpeg', $product->social_image_mime_type);
        $this->assertSame(1200, $product->social_image_width);
        $this->assertSame(630, $product->social_image_height);
    }

    public function test_switching_an_uploaded_product_to_a_remote_image_removes_stored_files(): void
    {
        [$user, $profile] = $this->ownerContext();
        $service = app(ProfileProductService::class);
        $product = $service->create(
            $profile,
            $user,
            [
                'name' => 'Creatina',
                'description' => 'Descripción original.',
                'destination_type' => 'external_url',
                'destination_url' => 'https://shop.example.com/creatine',
                'status' => 'published',
            ],
            $this->validWebpUpload()
        );
        $originalPath = $product->storage_path;
        $socialPath = $product->social_storage_path;

        $updated = $service->update($product, [
            'image_source' => 'remote',
            'image_url' => 'https://images.example.com/creatine-new.jpg',
        ]);

        Storage::disk('profiles')->assertMissing($originalPath);
        Storage::disk('profiles')->assertMissing($socialPath);
        $this->assertSame('remote', $updated->image_source);
        $this->assertSame('https://images.example.com/creatine-new.jpg', $updated->image_url);
        $this->assertNull($updated->storage_disk);
        $this->assertNull($updated->storage_path);
        $this->assertNull($updated->social_storage_path);
    }

    public function test_replacing_an_uploaded_image_changes_its_public_urls_and_removes_old_variants(): void
    {
        [$user, $profile] = $this->ownerContext();
        $service = app(ProfileProductService::class);
        $product = $service->create(
            $profile,
            $user,
            [
                'name' => 'Creatina',
                'description' => 'Descripción original.',
                'destination_type' => 'external_url',
                'destination_url' => 'https://shop.example.com/creatine',
                'status' => 'published',
            ],
            $this->validWebpUpload([36, 112, 88])
        );
        $originalPath = $product->storage_path;
        $socialPath = $product->social_storage_path;

        $updated = $service->update($product, [], $this->validWebpUpload([180, 45, 62]));

        $this->assertNotSame($originalPath, $updated->storage_path);
        $this->assertNotSame($socialPath, $updated->social_storage_path);
        Storage::disk('profiles')->assertMissing($originalPath);
        Storage::disk('profiles')->assertMissing($socialPath);
        Storage::disk('profiles')->assertExists($updated->storage_path);
        Storage::disk('profiles')->assertExists($updated->social_storage_path);
    }

    public function test_profile_cannot_create_more_products_than_its_plan_allows(): void
    {
        config(['subscriptions.plans.starter.capabilities.products_per_profile' => 2]);
        [$user, $profile, $token] = $this->ownerContext();

        for ($index = 1; $index <= 2; $index++) {
            $this->createRemoteProduct($profile, $user, "Producto {$index}");
        }

        $this->withToken($token)->post("/api/profile/{$profile->id}/products", [
            'name' => 'Producto 3',
            'description' => 'No debe crearse.',
            'image' => UploadedFile::fake()->create('product.jpg', 10, 'image/jpeg'),
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/product-3',
            'status' => 'draft',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'A profile can have up to 2 products.');

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/products")
            ->assertOk()
            ->assertJsonPath('data.max_products', 2)
            ->assertJsonPath('data.available_slots', 0);
    }

    public function test_manual_products_without_external_ids_can_share_the_same_name(): void
    {
        [, $profile, $token] = $this->ownerContext();

        foreach (['Tapa dura A5 de 120 hojas.', 'Tapa flexible A4 de 80 hojas.'] as $index => $description) {
            $this->withToken($token)->post("/api/profile/{$profile->id}/products", [
                'name' => 'Cuaderno Hulk',
                'description' => $description,
                'image' => UploadedFile::fake()->create("notebook-{$index}.jpg", 10, 'image/jpeg'),
                'destination_type' => 'external_url',
                'destination_url' => "https://shop.example.com/notebook-{$index}",
                'status' => 'draft',
            ])->assertCreated();
        }

        $products = ProfileProduct::query()->where('profile_id', $profile->id)->orderBy('id')->get();

        $this->assertCount(2, $products);
        $this->assertNull($products[0]->external_id);
        $this->assertNull($products[1]->external_id);
        $this->assertSame('cuaderno-hulk', $products[0]->slug);
        $this->assertSame('cuaderno-hulk-2', $products[1]->slug);
    }

    public function test_non_empty_external_id_remains_unique_per_profile(): void
    {
        [$user, $profile] = $this->ownerContext();
        $service = app(ProfileProductService::class);
        $service->create($profile, $user, [
            'external_id' => 'NOTEBOOK-001',
            'name' => 'Cuaderno A',
            'description' => 'Primera versión.',
            'image_url' => 'https://images.example.com/notebook-a.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/notebook-a',
            'status' => 'draft',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A product with the same external ID already exists.');

        $service->create($profile, $user, [
            'external_id' => 'notebook-001',
            'name' => 'Cuaderno B',
            'description' => 'Segunda versión.',
            'image_url' => 'https://images.example.com/notebook-b.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/notebook-b',
            'status' => 'draft',
        ]);
    }

    public function test_csv_preview_reports_duplicates_and_applies_selected_resolutions(): void
    {
        [$user, $profile, $token] = $this->ownerContext();
        $existing = $this->createRemoteProduct($profile, $user, 'Proteína Whey', 'Descripción anterior.');
        $csv = implode("\n", [
            'nombre,descripcion,imagen,link',
            'Proteína Whey,Descripción anterior.,https://images.example.com/proteina-whey.jpg,https://shop.example.com/proteina-whey',
            'Proteína Whey,Presentación de 2 kg.,https://images.example.com/protein-2kg.jpg,https://shop.example.com/protein-2kg',
            'Proteína Whey,Presentación de 2 kg.,https://images.example.com/protein-2kg.jpg,https://shop.example.com/protein-2kg',
            'Sin imagen,No debe importar,,https://shop.example.com/invalid',
        ]);
        $preview = $this->withToken($token)->post("/api/profile/{$profile->id}/products/imports/preview", [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ]);

        $preview->assertCreated()
            ->assertJsonPath('data.total_rows', 4)
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.invalid_rows', 1)
            ->assertJsonPath('data.duplicate_rows', 2)
            ->assertJsonPath('data.summary.current_products', 1);
        $rows = collect($preview->json('data.rows'))->keyBy('status');
        $this->assertSame($existing->id, $rows['duplicate_existing']['duplicate_product']['id']);
        $this->assertNotNull($rows['duplicate_file']['duplicate_row_id']);

        $decisions = $rows->map(fn (array $row, string $status): array => [
            'id' => $row['id'],
            'action' => match ($status) {
                'duplicate_existing' => 'replace',
                'valid' => 'import',
                default => 'skip',
            },
        ])->values()->all();
        $applyResponse = $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/products/imports/{$preview->json('data.id')}/apply", [
                'rows' => $decisions,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.replaced', 1)
            ->assertJsonPath('data.product_count', 2);
        $this->assertDatabaseCount('profile_products', 2);
        $this->assertDatabaseHas('profile_products', [
            'id' => $existing->id,
            'description' => 'Descripción anterior.',
            'image_source' => 'remote',
            'storage_path' => null,
        ]);
        $this->assertDatabaseHas('profile_products', [
            'name' => 'Proteína Whey',
            'description' => 'Presentación de 2 kg.',
            'slug' => 'proteina-whey-2',
        ]);

        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/products/imports/{$preview->json('data.id')}/apply", [
                'rows' => $decisions,
            ])
            ->assertOk()
            ->assertJsonPath('data', $applyResponse->json('data'));
        $this->assertDatabaseCount('profile_products', 2);
    }

    public function test_csv_capacity_requires_user_to_choose_which_rows_remain(): void
    {
        [$user, $profile, $token] = $this->ownerContext();

        for ($index = 1; $index <= 14; $index++) {
            $this->createRemoteProduct($profile, $user, "Existente {$index}");
        }

        $csv = implode("\n", [
            'name,description,image,link',
            'Nuevo A,Producto A,https://images.example.com/a.jpg,https://shop.example.com/a',
            'Nuevo B,Producto B,https://images.example.com/b.jpg,https://shop.example.com/b',
            'Nuevo C,Producto C,https://images.example.com/c.jpg,https://shop.example.com/c',
        ]);
        $preview = $this->withToken($token)->post("/api/profile/{$profile->id}/products/imports/preview", [
            'file' => UploadedFile::fake()->createWithContent('capacity.csv', $csv),
        ])->assertCreated();
        $this->assertSame(1, $preview->json('data.summary.available_slots'));
        $rows = collect($preview->json('data.rows'));

        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/products/imports/{$preview->json('data.id')}/apply", [
                'rows' => $rows->map(fn (array $row): array => ['id' => $row['id'], 'action' => 'import'])->all(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only 1 additional products can be imported. Select which products to keep.');

        $decisions = $rows->map(fn (array $row, int $index): array => [
            'id' => $row['id'],
            'action' => $index === 0 ? 'import' : 'skip',
        ])->values()->all();
        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/products/imports/{$preview->json('data.id')}/apply", [
                'rows' => $decisions,
            ])
            ->assertOk()
            ->assertJsonPath('data.product_count', 15);
    }

    public function test_csv_template_contains_only_the_four_required_fields(): void
    {
        [, $profile, $token] = $this->ownerContext();

        $response = $this->withToken($token)
            ->get("/api/profile/{$profile->id}/products/imports/template")
            ->assertOk();

        $lines = preg_split('/\r\n|\r|\n/', trim($response->streamedContent()));

        $this->assertSame('name,description,image,link', $lines[0]);
        $this->assertCount(4, str_getcsv($lines[1]));
    }

    public function test_products_require_abilities_and_profile_ownership_but_allow_admin(): void
    {
        [$owner, $profile] = $this->ownerContext();
        $legacyReadToken = $owner->createToken('legacy-read', ['profile:read'])->plainTextToken;
        $this->withToken($legacyReadToken)
            ->getJson("/api/profile/{$profile->id}/products")
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $missingAbilityToken = $owner->createToken('missing', ['chat:read'])->plainTextToken;
        $this->withToken($missingAbilityToken)
            ->getJson("/api/profile/{$profile->id}/products")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $other = User::factory()->create();
        $readerToken = $other->createToken('reader', ['products:read'])->plainTextToken;
        $this->withToken($readerToken)
            ->getJson("/api/profile/{$profile->id}/products")
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $admin = User::factory()->create(['role' => 'admin']);
        $adminToken = $admin->createToken('admin', ['products:read'])->plainTextToken;
        $this->withToken($adminToken)
            ->getJson("/api/profile/{$profile->id}/products")
            ->assertOk();
    }

    public function test_only_enabled_published_products_are_available_to_chat(): void
    {
        [$user, $profile] = $this->ownerContext();
        $published = $this->createRemoteProduct($profile, $user, 'Proteína');
        $this->createRemoteProduct($profile, $user, 'Borrador')->forceFill(['status' => ProfileProductStatus::Draft])->save();
        $service = app(ProfileProductPromptService::class);

        $this->assertSame([], $service->productsForPrompt($profile));
        $profile->forceFill(['products_enabled' => true])->save();
        $products = $service->productsForPrompt($profile->fresh());
        $this->assertCount(1, $products);
        $this->assertSame($published->id, $products[0]['id']);
        $this->assertSame('Proteína', $products[0]['name']);
    }

    /**
     * @return array{User, Profile, string}
     */
    private function ownerContext(string $locale = 'es'): array
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['locale' => $locale]);
        $token = $user->createToken('products', [
            'products:read',
            'products:write',
            'products:publish',
            'products:import',
        ])->plainTextToken;

        return [$user, $profile, $token];
    }

    private function createRemoteProduct(
        Profile $profile,
        User $user,
        string $name,
        string $description = 'Descripción del producto.'
    ): ProfileProduct {
        return app(ProfileProductService::class)->create($profile, $user, [
            'name' => $name,
            'description' => $description,
            'image_url' => 'https://images.example.com/'.str($name)->slug().'.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/'.str($name)->slug(),
            'status' => 'published',
        ]);
    }

    /**
     * @param  array{int, int, int}  $color
     */
    private function validWebpUpload(array $color = [36, 112, 88]): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'product-webp-');
        $image = imagecreatetruecolor(800, 800);
        $background = imagecolorallocate($image, $color[0], $color[1], $color[2]);
        imagefill($image, 0, 0, $background);
        imagewebp($image, $path, 90);
        imagedestroy($image);

        return new UploadedFile($path, 'creatine.webp', 'image/webp', null, true);
    }
}
