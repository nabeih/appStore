<?php

namespace Tests\Feature\Suppliers;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_suppliers_can_be_created_edited_and_searched_with_escaped_output(): void
    {
        $this->postJson(route('suppliers.store'), ['name' => '  أحمد للتجارة  ', 'phone1' => '0591234567', 'is_active' => false])->assertCreated();
        $supplier = Supplier::firstOrFail();
        $this->assertSame('أحمد للتجارة', $supplier->name);
        $this->get(route('suppliers.index', ['q' => 'أحمد']))->assertOk()->assertSee('أحمد للتجارة');
        $this->putJson(route('suppliers.update', $supplier), ['name' => '<script>alert(1)</script>', 'address' => 'غزة'])->assertOk();
        $this->get(route('suppliers.show', $supplier))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_invalid_supplier_fields_are_rejected(): void
    {
        foreach ([[], ['name' => ['x']], ['name' => str_repeat('a', 256)], ['name' => 'مورد', 'phone1' => str_repeat('1', 31)]] as $data) {
            $this->postJson(route('suppliers.store'), $data)->assertUnprocessable();
        }
        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_archiving_keeps_history_and_can_be_reversed(): void
    {
        $supplier = Supplier::factory()->create();
        $invoice = PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'status' => 'approved', 'paid_minor' => 1000]);
        $this->delete(route('suppliers.archive', $supplier))->assertRedirect(route('suppliers.index'));
        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        $this->get(route('suppliers.show', $supplier))->assertOk()->assertSee('مورد مؤرشف')->assertSee($invoice->number);
        $this->getJson(route('purchases.suppliers.search'))->assertOk()->assertJsonCount(0, 'data');
        $this->post(route('suppliers.restore', $supplier))->assertRedirect(route('suppliers.index'));
        $this->assertNotSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    public function test_unpaid_supplier_cannot_be_archived(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'approved']);
        $this->deleteJson(route('suppliers.archive', $invoice->supplier_id))->assertUnprocessable();
        $this->assertNotSoftDeleted('suppliers', ['id' => $invoice->supplier_id]);
    }

    public function test_statement_totals_exclude_drafts_and_cancelled_documents(): void
    {
        $supplier = Supplier::factory()->create();
        PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'status' => 'approved', 'total_minor' => 3000, 'paid_minor' => 500]);
        PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'status' => 'draft', 'total_minor' => 99900]);
        PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'status' => 'cancelled', 'total_minor' => 99900]);
        $this->get(route('suppliers.show', $supplier))->assertOk()->assertViewHas('purchases', '30.00')->assertViewHas('paid', '5.00')->assertViewHas('balance', '25.00');
        $this->get(route('suppliers.index'))->assertOk()->assertSee('25.00');
    }

    public function test_supplier_pages_paginate_and_payment_history_loads_without_n_plus_one_queries(): void
    {
        $supplier = Supplier::factory()->create();
        SupplierPayment::factory()->create(['supplier_id' => $supplier->id]);
        $count = function () use ($supplier): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('suppliers.show', $supplier))->assertOk();
            $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $count;
        };
        $small = $count();
        SupplierPayment::factory()->count(10)->create(['supplier_id' => $supplier->id]);
        $this->assertLessThanOrEqual($small + 1, $count());
        Supplier::factory()->count(22)->create();
        $this->get(route('suppliers.index', ['page' => 2]))->assertOk()->assertViewHas('entries', fn ($entries): bool => $entries->currentPage() === 2);
    }
}
