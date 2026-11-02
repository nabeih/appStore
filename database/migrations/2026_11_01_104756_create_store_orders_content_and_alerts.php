<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table): void {
            $table->unsignedInteger('reserved_quantity')->default(0);
        });
        Schema::create('store_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('branch_id')->constrained();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->text('policy');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->boolean('is_open')->default(false);
            $table->timestamps();
        });
        Schema::create('store_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('store_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained();
            $table->foreignId('store_section_id')->nullable()->constrained();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('badge', 80)->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });
        Schema::create('store_banners', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('store_section_id')->nullable()->constrained();
            $table->string('image_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('delivery_zones', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('fee_minor');
            $table->unsignedBigInteger('courier_delivery_minor')->nullable();
            $table->unsignedBigInteger('courier_refusal_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('store_offers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->unsignedInteger('percent_basis_points')->default(0);
            $table->unsignedBigInteger('minimum_minor')->default(0);
            $table->boolean('minimum_inclusive')->default(true);
            $table->json('zone_ids')->nullable();
            $table->json('product_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('store_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('delivery_zone_id')->constrained();
            $table->foreignId('sale_invoice_id')->nullable()->unique()->constrained();
            $table->foreignId('courier_expense_id')->nullable()->unique()->constrained('expenses');
            $table->uuid('sale_uuid')->unique();
            $table->string('status', 30)->default('new')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('customer_name', 150);
            $table->string('phone', 40);
            $table->text('address');
            $table->text('notes')->nullable();
            $table->string('courier_name', 150)->nullable();
            $table->unsignedBigInteger('products_minor');
            $table->unsignedBigInteger('original_delivery_minor');
            $table->unsignedBigInteger('delivery_minor');
            $table->unsignedBigInteger('courier_due_minor')->nullable();
            $table->unsignedBigInteger('courier_paid_minor')->default(0);
            $table->unsignedBigInteger('fee_due_minor')->default(0);
            $table->unsignedBigInteger('fee_paid_minor')->default(0);
            $table->unsignedBigInteger('collected_minor')->default(0);
            $table->string('collection_status', 30)->default('uncollected');
            $table->string('settlement_status', 30)->default('not_due');
            $table->json('delivery_snapshot');
            $table->text('accepted_policy');
            $table->char('policy_version', 64);
            $table->timestamp('policy_accepted_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('store_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_order_id')->constrained();
            $table->foreignId('product_variant_id')->constrained();
            $table->string('product_name');
            $table->string('sku');
            $table->string('color_name');
            $table->string('size_name');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->json('offer_snapshot')->nullable();
            $table->unique(['store_order_id', 'product_variant_id']);
            $table->timestamps();
        });
        Schema::create('store_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_order_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('product_variant_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->string('state', 20)->default('reserved');
            $table->timestamp('released_at')->nullable();
            $table->unique(['store_order_id', 'product_variant_id']);
            $table->index(['branch_id', 'product_variant_id', 'released_at'], 'store_reservation_lookup');
            $table->timestamps();
        });
        Schema::create('store_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_order_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->uuid('operation_uuid')->unique();
            $table->char('request_hash', 64);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('store_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_order_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('financial_account_id')->nullable()->constrained();
            $table->uuid('operation_uuid')->unique();
            $table->char('request_hash', 64);
            $table->string('kind', 30);
            $table->string('received_by', 30);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference')->nullable();
            $table->timestamps();
        });
        Schema::create('app_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('type', 30);
            $table->foreignId('branch_id')->constrained();
            $table->unsignedBigInteger('subject_id');
            $table->string('title');
            $table->text('message');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
        });
        Schema::create('app_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('app_alert_event_id')->constrained();
            $table->timestamp('read_at')->nullable();
            $table->unique(['user_id', 'app_alert_event_id']);
            $table->timestamps();
        });
        Schema::create('sale_price_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_invoice_id')->constrained();
            $table->foreignId('sale_item_id')->unique()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('minimum_unit_minor');
            $table->unsignedBigInteger('effective_total_minor');
            $table->timestamp('confirmed_at');
            $table->timestamps();
        });
        if (DB::getDriverName() === 'mysql') {
            $release = 'IF OLD.released_at IS NULL THEN UPDATE inventories SET reserved_quantity=reserved_quantity-OLD.quantity WHERE branch_id=OLD.branch_id AND product_variant_id=OLD.product_variant_id; END IF;';
            $reserve = "IF NEW.released_at IS NULL THEN UPDATE inventories SET reserved_quantity=reserved_quantity+NEW.quantity WHERE branch_id=NEW.branch_id AND product_variant_id=NEW.product_variant_id AND quantity-reserved_quantity>=NEW.quantity; IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Insufficient unreserved inventory'; END IF; END IF;";
            DB::unprepared("CREATE TRIGGER store_reservation_insert AFTER INSERT ON store_reservations FOR EACH ROW BEGIN {$reserve} END");
            DB::unprepared("CREATE TRIGGER store_reservation_update AFTER UPDATE ON store_reservations FOR EACH ROW BEGIN {$release} {$reserve} END");
            DB::unprepared("CREATE TRIGGER store_reservation_delete AFTER DELETE ON store_reservations FOR EACH ROW BEGIN {$release} END");
        }
    }

    public function down(): void
    {
        throw new LogicException('Order reservations, settlements and alert history require a reviewed recovery plan; automatic destructive rollback is disabled.');
    }
};
