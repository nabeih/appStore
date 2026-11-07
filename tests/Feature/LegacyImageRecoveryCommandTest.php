<?php

namespace Tests\Feature;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyImageRecoveryCommandTest extends TestCase
{
    private string $legacyRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyRoot = storage_path('app/public/testing/legacy-image-roots/test-assets');
        config(['app.legacy_image_roots' => [$this->legacyRoot]]);

        Schema::create('colors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('color_id');
            $table->string('image_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->timestamps();
        });

        $this->ensureLegacyFixtures();
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $image = ProductImage::create([
            'product_id' => 1,
            'color_id' => 1,
            'image_path' => 'missing-assets/alpha.png',
            'thumbnail_path' => 'missing-assets/alpha-thumb.png',
        ]);

        $this->ensureFile('products/colors/legacy_raw_images/alpha.png');
        $this->ensureFile('products/colors/legacy_raw_images/alpha-thumb.png');

        $before = $image->getAttributes();

        $exitCode = Artisan::call('images:recover-legacy', ['--image-id' => $image->id]);

        $this->assertSame(0, $exitCode);
        $image->refresh();
        $this->assertSame($before['image_path'], $image->image_path);
        $this->assertSame($before['thumbnail_path'], $image->thumbnail_path);
        $output = Artisan::output();
        $this->assertStringContainsString('image_id='.$image->id, $output);
    }

    public function test_image_id_option_limits_scan_to_exact_record(): void
    {
        $imageOne = ProductImage::create([
            'product_id' => 1,
            'color_id' => 1,
            'image_path' => 'missing-assets/alpha.png',
            'thumbnail_path' => null,
        ]);

        $imageTwo = ProductImage::create([
            'product_id' => 1,
            'color_id' => 2,
            'image_path' => 'missing-assets/beta.png',
            'thumbnail_path' => null,
        ]);

        $this->ensureFile('products/colors/legacy_raw_images/alpha.png');
        $this->ensureFile('products/colors/legacy_raw_images/beta.png');

        Artisan::call('images:recover-legacy', ['--image-id' => $imageOne->id]);

        $output = Artisan::output();
        $this->assertStringContainsString('image_id='.$imageOne->id, $output);
        $this->assertStringNotContainsString('image_id='.$imageTwo->id, $output);
    }

    public function test_literal_match_resolves_correct_file(): void
    {
        $this->ensureFile('products/colors/legacy_raw_images/Logo.png');

        $image = ProductImage::create([
            'product_id' => 1,
            'color_id' => 1,
            'image_path' => 'missing-assets/logo.png',
            'thumbnail_path' => null,
        ]);

        $exitCode = Artisan::call('images:recover-legacy', ['--image-id' => $image->id, '--limit' => 1, '--apply' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            'testing/legacy-image-roots/test-assets/products/colors/legacy_raw_images/Logo.png',
            ProductImage::find($image->id)->image_path
        );
    }

    public function test_case_mismatch_is_handled_safely(): void
    {
        $this->ensureFile('products/colors/legacy_raw_images/CaseLogo.png');

        $image = ProductImage::create([
            'product_id' => 1,
            'color_id' => 1,
            'image_path' => 'missing-assets/caselogo.png',
            'thumbnail_path' => null,
        ]);

        $exitCode = Artisan::call('images:recover-legacy', ['--image-id' => $image->id, '--apply' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            'testing/legacy-image-roots/test-assets/products/colors/legacy_raw_images/CaseLogo.png',
            ProductImage::find($image->id)->image_path
        );
    }

    public function test_ambiguous_match_is_left_unchanged(): void
    {
        $this->ensureFile('products/colors/legacy_raw_images/ambiguous.png');
        $this->ensureFile('products/colors/legacy_raw_images/duplicate/ambiguous.png');

        $image = ProductImage::create([
            'product_id' => 1,
            'color_id' => 1,
            'image_path' => 'missing-assets/ambiguous.png',
            'thumbnail_path' => null,
        ]);

        Artisan::call('images:recover-legacy', ['--image-id' => $image->id]);

        $this->assertStringContainsString('status=ambiguous', Artisan::output());
        $this->assertSame('missing-assets/ambiguous.png', ProductImage::find($image->id)->image_path);
    }

    public function test_valid_paths_are_left_unchanged(): void
    {
        $this->ensureFile('products/colors/legacy_raw_images/valid.png');

        $image = ProductImage::create([
            'product_id' => 1,
            'color_id' => 1,
            'image_path' => 'products/colors/legacy_raw_images/valid.png',
            'thumbnail_path' => 'products/colors/legacy_raw_images/valid-thumb.png',
        ]);

        $this->ensureFile('products/colors/legacy_raw_images/valid-thumb.png');

        Artisan::call('images:recover-legacy', ['--image-id' => $image->id]);

        $this->assertSame('products/colors/legacy_raw_images/valid.png', ProductImage::find($image->id)->image_path);
        $this->assertSame('products/colors/legacy_raw_images/valid-thumb.png', ProductImage::find($image->id)->thumbnail_path);
    }

    public function test_apply_updates_only_matching_records(): void
    {
        $this->ensureFile('products/colors/legacy_raw_images/only.png');
        $this->ensureFile('products/colors/legacy_raw_images/keep.png');

        $match = ProductImage::create([
            'product_id' => 1,
            'color_id' => 1,
            'image_path' => 'missing-assets/only.png',
            'thumbnail_path' => null,
        ]);

        $keep = ProductImage::create([
            'product_id' => 1,
            'color_id' => 2,
            'image_path' => 'products/colors/legacy_raw_images/keep.png',
            'thumbnail_path' => null,
        ]);

        $exitCode = Artisan::call('images:recover-legacy', ['--apply' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('products/colors/legacy_raw_images/only.png', ProductImage::find($match->id)->image_path);
        $this->assertSame('products/colors/legacy_raw_images/keep.png', ProductImage::find($keep->id)->image_path);
    }

    public function test_second_apply_run_is_idempotent(): void
    {
        $this->ensureFile('products/colors/legacy_raw_images/idempotent.png');

        $image = ProductImage::create([
            'product_id' => 1,
            'color_id' => 1,
            'image_path' => 'missing-assets/idempotent.png',
            'thumbnail_path' => null,
        ]);

        Artisan::call('images:recover-legacy', ['--apply' => true]);
        $firstValue = ProductImage::find($image->id)->image_path;

        Artisan::call('images:recover-legacy', ['--apply' => true]);

        $this->assertSame($firstValue, ProductImage::find($image->id)->image_path);
    }

    protected function ensureLegacyFixtures(): void
    {
        DB::table('colors')->insert(['id' => 1, 'name' => 'Red']);
        DB::table('colors')->insert(['id' => 2, 'name' => 'Blue']);
        DB::table('products')->insert(['id' => 1, 'name' => 'Fixture Product']);
    }

    protected function tearDown(): void
    {
        config(['app.legacy_image_roots' => null]);
        parent::tearDown();
    }

    protected function ensureFile(string $relativePath): void
    {
        $fullPath = $this->legacyRoot.'/'.$relativePath;
        $directory = dirname($fullPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($fullPath, 'fixture-image-content');
    }
}
