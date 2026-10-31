<?php

namespace Tests\Feature\Search;

use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\SaleInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

class GlobalSearchTest extends SalesTestCase
{
    public function test_arabic_names_and_skus_are_grouped_without_financial_values(): void
    {
        $variant = $this->stock();
        $variant->product->update(['name' => 'قميص قطني أزرق']);
        $response = $this->getJson(route('search.suggestions', ['q' => 'قطني']))->assertOk();
        $entry = $response->json('groups.0.entries.0');
        $this->assertSame('products', $entry['type']);
        $this->assertSame('قميص قطني أزرق', $entry['title']);
        $this->assertSame(['id', 'type', 'title', 'detail', 'url', 'product'], array_keys($entry));
        $this->assertSame(10, $entry['product']['quantity']);
        $this->assertArrayNotHasKey('average_cost', $entry['product']);
        $this->assertArrayNotHasKey('unit_price', $entry['product']);
        $this->getJson(route('search.suggestions', ['q' => $variant->sku]))->assertOk()->assertJsonPath('groups.0.entries.0.type', 'products');
    }

    public function test_a_cashier_cannot_discover_supplier_or_financial_account_results(): void
    {
        Supplier::factory()->create(['name' => 'سري خاص بالمورد']);
        FinancialAccount::factory()->create(['name' => 'سري خاص بالحساب', 'branch_id' => $this->branchId]);
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier);
        $this->getJson(route('search.suggestions', ['q' => 'سري']))->assertOk()->assertExactJson(['q' => 'سري', 'groups' => []]);
        foreach (['suppliers', 'accounts', 'purchases', 'openings', 'supplier_payments'] as $type) {
            $this->get(route('search.index', ['type' => $type, 'q' => 'سري']))->assertForbidden();
        }
    }

    public function test_branch_restrictions_apply_to_rows_and_links(): void
    {
        $variant = $this->stock();
        $other = DB::table('branches')->insertGetId(['name' => 'الفرع المحظور']);
        Inventory::create(['branch_id' => $other, 'product_variant_id' => $variant->id, 'quantity' => 5, 'average_cost' => '20', 'value_minor' => 10000]);
        SaleInvoice::factory()->create(['branch_id' => $other, 'customer_name' => 'فاتورة سرية']);
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier);
        $response = $this->getJson(route('search.suggestions', ['q' => $variant->sku]))->assertOk();
        $products = collect($response->json('groups'))->firstWhere('type', 'products');
        $this->assertCount(1, $products['entries']);
        $this->assertStringContainsString('branch_id='.$this->branchId, $products['entries'][0]['url']);
        $this->get($products['entries'][0]['url'])->assertOk()->assertSee('value="'.$variant->sku.'"', false);
        $this->getJson(route('search.suggestions', ['q' => 'سرية']))->assertOk()->assertJsonCount(0, 'groups');
    }

    public function test_all_result_links_resolve_for_the_user_who_sees_them(): void
    {
        $variant = $this->stock();
        $this->sale($variant);
        $response = $this->getJson(route('search.suggestions', ['q' => $variant->sku]))->assertOk();
        foreach ($response->json('groups') as $group) {
            foreach ($group['entries'] as $entry) {
                $this->get($entry['url'])->assertOk();
            }
        }
    }

    public function test_suggestions_are_bounded_and_full_group_results_are_paginated(): void
    {
        Customer::factory()->count(23)->create(['name' => 'عميل مشترك']);
        $response = $this->getJson(route('search.suggestions', ['q' => 'مشترك']))->assertOk();
        $group = collect($response->json('groups'))->firstWhere('type', 'customers');
        $this->assertCount(5, $group['entries']);
        $this->assertTrue($group['more']);
        $this->get(route('search.index', ['q' => 'مشترك', 'type' => 'customers', 'page' => 2]))->assertOk()
            ->assertViewHas('results', fn ($result) => $result['groups'][0]['entries']->total() === 23 && $result['groups'][0]['entries']->count() === 3);
    }

    public function test_archived_contacts_and_literal_wildcards_are_handled_safely(): void
    {
        Customer::factory()->create(['name' => 'مؤرشف فقط'])->delete();
        $this->getJson(route('search.suggestions', ['q' => 'مؤرشف']))->assertOk()->assertJsonCount(0, 'groups');
        Customer::factory()->create(['name' => 'عميل %_! خاص']);
        Customer::factory()->create(['name' => 'عميل عادي']);
        $response = $this->getJson(route('search.suggestions', ['q' => '%_!']))->assertOk();
        $group = collect($response->json('groups'))->firstWhere('type', 'customers');
        $this->assertCount(1, $group['entries']);
    }

    public function test_empty_input_permissions_validation_and_searches_do_not_write_business_data(): void
    {
        $variant = $this->stock();
        $lock = DB::table('product_code_counters')->value('lock_version');
        $this->getJson(route('search.suggestions', ['q' => '']))->assertOk()->assertJsonCount(0, 'groups');
        $this->getJson(route('search.suggestions', ['q' => str_repeat('a', 101)]))->assertUnprocessable();
        $this->getJson(route('search.suggestions', ['q' => ['bad']]))->assertUnprocessable();
        $this->getJson(route('search.suggestions', ['q' => $variant->sku]))->assertOk();
        $this->assertSame($lock, DB::table('product_code_counters')->value('lock_version'));
        $this->actingAs(User::factory()->create());
        $this->getJson(route('search.suggestions', ['q' => 'ملابس']))->assertOk()->assertJsonCount(0, 'groups');
    }
}
