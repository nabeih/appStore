<?php

namespace Tests\Feature\Mobile;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Models\ProductColorSwatch;
use App\Models\ProductImage;
use App\Models\Supplier;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class MobileContractTest extends MobileTestCase
{
    private array $schemas;

    public function test_openapi_documents_every_mobile_route_and_matches_actual_dto_fields_types_and_dates(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi-flutter.yaml'));
        $this->schemas = $spec['components']['schemas'];
        $this->assertSame('3.1.0', $spec['openapi']);
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = '/'.substr($route->uri(), strlen('api/v1/'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertArrayHasKey(strtolower($method), $spec['paths'][$path], $path);
            }
        }
        $variant = $this->stock('35', '20', 10, 'CONTRACT');
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'صفر فعلي للاختبار'], $this->owner);
        $purchase = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'supplier_id' => Supplier::factory()->create()->id, 'invoice_date' => OperationContext::today(), 'initial_payment' => '0', 'payment_method' => 'cash',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '21']]], $this->owner->id);
        app(ApprovePurchaseInvoiceAction::class)->execute($purchase, $purchase->version, $this->owner->id);
        $this->sale($variant, ['payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '10.00']]]);
        ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id, 'image_path' => 'fixture/contract.webp']);
        ProductColorSwatch::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id, 'image_path' => 'product-swatches/contract.webp']);
        $this->mobileLogin();
        foreach (['branches' => 'Branch', 'products' => 'Product', 'variants' => 'Variant', 'stocks' => 'Stock', 'categories' => 'Category', 'colors' => 'Color',
            'sizes' => 'Size', 'customers' => 'Customer', 'customer_balances' => 'CustomerBalance', 'accounts' => 'Account', 'suppliers' => 'Supplier',
            'images' => 'Image', 'swatches' => 'Swatch', 'sales' => 'Sale', 'receipts' => 'Receipt', 'purchases' => 'Purchase', 'openings' => 'Opening'] as $entity => $name) {
            $response = $this->mobile('GET', 'data/'.$entity.'?branch_id='.$this->branchId)->assertOk();
            $this->assertNotEmpty($response->json('data'), $entity);
            foreach ($response->json('data') as $record) {
                $this->matchesSchema($record, $this->schemas['Record'.$name], $entity);
            }
        }
    }

    /** Validate the DTO subset used by this contract, not an OpenAPI meta-schema implementation. */
    private function matchesSchema(mixed $value, array $schema, string $path): void
    {
        if (isset($schema['$ref'])) {
            $this->matchesSchema($value, $this->schemas[basename($schema['$ref'])], $path);

            return;
        }
        $types = (array) ($schema['type'] ?? []);
        if ($value === null) {
            $this->assertContains('null', $types, $path);

            return;
        }
        if (in_array('object', $types, true)) {
            $this->assertIsArray($value, $path);
            foreach ($schema['required'] ?? [] as $key) {
                $this->assertArrayHasKey($key, $value, $path);
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                $this->assertSame([], array_values(array_diff(array_keys($value), array_keys($schema['properties']))), $path);
            }
            foreach ($schema['properties'] ?? [] as $key => $child) {
                if (array_key_exists($key, $value)) {
                    $this->matchesSchema($value[$key], $child, $path.'.'.$key);
                }
            }
        } elseif (in_array('array', $types, true)) {
            $this->assertIsArray($value, $path);
            $this->assertTrue(array_is_list($value), $path);
            foreach ($value as $index => $item) {
                $this->matchesSchema($item, $schema['items'], $path.'.'.$index);
            }
        } elseif (in_array('integer', $types, true)) {
            $this->assertIsInt($value, $path);
        } elseif (in_array('boolean', $types, true)) {
            $this->assertIsBool($value, $path);
        } elseif (in_array('string', $types, true)) {
            $this->assertIsString($value, $path);
            if (($schema['format'] ?? null) === 'date') {
                $this->assertMatchesRegularExpression('/\\A\\d{4}-\\d{2}-\\d{2}\\z/', $value, $path);
            }
            if (($schema['format'] ?? null) === 'date-time') {
                $this->assertMatchesRegularExpression('/T.*(?:Z|[+-]\\d{2}:\\d{2})$/', $value, $path);
            }
        }
    }
}
