<?php

namespace App\Actions\Notifications;

use App\Models\AppAlert;
use App\Models\AppAlertEvent;
use App\Models\Expense;
use App\Models\Inventory;
use App\Models\OpeningStockEntry;
use App\Models\PurchaseInvoice;
use App\Models\StockMovement;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DispatchAppAlerts
{
    public static function installed(): bool
    {
        return Schema::hasTable('app_alert_events') && Schema::hasTable('app_alerts');
    }

    /** @return list<string> */
    public static function abilities(string $type): array
    {
        return match ($type) {
            'store_order' => ['store.orders.view'],
            'minimum_price' => ['notifications.price_alerts', 'sales.view'],
            'customer_refund' => ['sales.manage_accounts', 'sales.customers', 'sales.collect'],
            'stock_low', 'stock_empty' => ['inventory.view'],
            'inventory_review', 'return_review' => ['inventory.valuation_review', 'sales.view_cost'],
            'opening_review' => ['finance.transition_review', 'inventory.valuation_review', 'sales.view_cost'],
            'purchase_review', 'payment_review' => ['finance.transition_review', 'sales.view_cost'],
            'expense_review', 'expense_due' => ['finance.view', 'finance.expenses.approve'],
            default => [],
        };
    }

    private function receives(User $user, string $type): bool
    {
        $abilities = self::abilities($type);

        return $abilities !== [] && collect($abilities)->every(fn (string $ability): bool => $user->can($ability));
    }

    public function stock(Inventory $stock): void
    {
        $threshold = max(0, (int) config('inventory.low_stock_threshold', 5));
        if ((int) $stock->quantity > $threshold || ! self::installed()) {
            return;
        }
        $quantity = $lastHealthy = $lastPositive = 0;
        foreach (StockMovement::where('branch_id', $stock->branch_id)->where('product_variant_id', $stock->product_variant_id)
            ->where('stock_bucket', 'available')->orderBy('id')->get(['id', 'quantity_change']) as $movement) {
            $quantity += (int) $movement->quantity_change;
            if ($quantity > $threshold) {
                $lastHealthy = $movement->id;
            }
            if ($quantity > 0) {
                $lastPositive = $movement->id;
            }
        }
        $stock->loadMissing('productVariant.product', 'productVariant.color', 'productVariant.size');
        $variant = $stock->productVariant;
        $name = $variant?->product?->name.' — '.$variant?->sku;
        if ((int) $stock->quantity === 0) {
            $this->record('stock-empty:'.$stock->id.':'.$lastPositive, 'stock_empty', $stock->branch_id, $stock->product_variant_id,
                'نفاد صنف من المخزون', $name.' — الكمية صفر.');
        } else {
            $this->record('stock-low:'.$stock->id.':'.$lastHealthy, 'stock_low', $stock->branch_id, $stock->product_variant_id,
                'انخفاض مخزون صنف', $name.' — الكمية '.$stock->quantity.'، حد التنبيه '.$threshold.'.');
        }
    }

    public function scan(): void
    {
        if (! self::installed()) {
            return;
        }
        Inventory::where('quantity', '<=', max(0, (int) config('inventory.low_stock_threshold', 5)))->chunkById(100, function ($stocks): void {
            foreach ($stocks as $stock) {
                $this->stock($stock);
            }
        });
        Inventory::where(fn ($query) => $query->whereNull('value_minor')->orWhere(fn ($cost) => $cost->where('quantity', '>', 0)->whereNull('average_cost'))->orWhere('quarantined_quantity', '>', 0))->chunkById(100, function ($stocks): void {
            foreach ($stocks as $stock) {
                $unproven = $stock->value_minor === null || ((int) $stock->quantity > 0 && $stock->average_cost === null);
                $type = $unproven ? 'inventory_review' : 'return_review';
                $this->record($type.':'.$stock->id, $type, $stock->branch_id, $stock->product_variant_id,
                    'مخزون يحتاج مراجعة', $unproven ? 'قيمة أو تكلفة رصيد صنف غير معتمدة؛ راجع جاهزية المخزون.' : 'بضاعة قيد الفحص تحتاج قرارًا موثقًا؛ راجع المخزون.');
            }
        });
        foreach ([PurchaseInvoice::class => 'purchase_review', SupplierPayment::class => 'payment_review'] as $model => $type) {
            if (! Schema::hasColumn((new $model)->getTable(), 'financial_posted_at')) {
                continue;
            }
            $model::where('status', 'approved')->whereNull('financial_posted_at')->chunkById(100, function ($documents) use ($type): void {
                foreach ($documents as $document) {
                    $this->record($type.':'.$document->id, $type, $document->branch_id, $document->id, 'مستند يحتاج مطابقة مالية', 'يوجد مستند معتمد بلا إثبات مالي؛ راجع الانتقال التاريخي دون إعادة إدخال البضاعة أو الدفع.');
                }
            });
        }
        OpeningStockEntry::where('status', 'approved')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('journal_entries as proof')
            ->where('proof.source_type', OpeningStockEntry::class)->where('proof.event', 'opening')
            ->whereColumn('proof.source_id', 'opening_stock_entries.id')->whereColumn('proof.branch_id', 'opening_stock_entries.branch_id')
            ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')->from('journal_entries as reversed')->whereColumn('reversed.reversal_of_id', 'proof.id')))
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $document) {
                    $this->record('opening-review:'.$document->id, 'opening_review', $document->branch_id, $document->id, 'افتتاح يحتاج إثباتًا ماليًا', $document->number.' يحتاج مراجعة مصدر القيد قبل التشغيل.');
                }
            });
        Expense::where('status', 'draft')->chunkById(100, function ($expenses): void {
            foreach ($expenses as $expense) {
                $this->record('expense-review:'.$expense->id, 'expense_review', $expense->branch_id, $expense->id, 'مصروف ينتظر الاعتماد', $expense->title.' — راجع المسودة قبل الاعتماد.');
            }
        });
        if (Schema::hasTable('expense_allocations')) {
            DB::table('expense_allocations as a')->join('expenses as e', 'e.id', '=', 'a.expense_id')->where('e.status', 'approved')
                ->whereNull('a.posted_at')->where('a.due_date', '<=', now()->toDateString())->select('a.id', 'e.id as expense_id', 'e.branch_id')->orderBy('a.id')
                ->chunk(100, function ($rows): void {
                    foreach ($rows as $row) {
                        $this->record('expense-due:'.$row->id, 'expense_due', $row->branch_id, $row->expense_id, 'حصة مصروف مستحقة', 'توجد حصة شهرية مستحقة لم تُرحّل؛ راجع جدول المصروف.');
                    }
                });
        }
    }

    public function record(string $key, string $type, int $branch, int $subject, string $title, string $message): void
    {
        abort_unless(Schema::hasTable('app_alert_events'), 503, 'يلزم تحديث الإشعارات قبل اعتماد العملية.');
        AppAlertEvent::firstOrCreate(['event_key' => $key], ['type' => $type, 'branch_id' => $branch, 'subject_id' => $subject, 'title' => $title, 'message' => $message]);
        DB::afterCommit(function (): void {
            try {
                $this->pending();
            } catch (\Throwable) {
                logger()->warning('App alerts remain pending; run app:alerts-dispatch.');
            }
        });
    }

    public function pending(): int
    {
        if (! Schema::hasTable('app_alert_events')) {
            return 0;
        }
        $count = 0;
        foreach (AppAlertEvent::whereNull('dispatched_at')->orderBy('id')->limit(100)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $event = AppAlertEvent::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($event->dispatched_at) {
                    return;
                }
                User::with('role.permissions')->where('is_active', true)->orderBy('id')->chunkById(100, function ($users) use ($event): void {
                    foreach ($users as $user) {
                        if ($this->receives($user, $event->type) && ($user->can('sales.all_branches') || (int) $user->branch_id === (int) $event->branch_id)) {
                            AppAlert::firstOrCreate(['user_id' => $user->id, 'app_alert_event_id' => $event->id]);
                        }
                    }
                });
                $event->update(['dispatched_at' => now()]);
                $count++;
            });
        }

        return $count;
    }

    public function visible(User $actor): Builder
    {
        return AppAlert::where('user_id', $actor->id)->whereHas('event', function (Builder $q) use ($actor): void {
            if (! $actor->can('sales.all_branches')) {
                $q->where('branch_id', $actor->branch_id ?? 0);
            }
            $q->where(function (Builder $types) use ($actor): void {
                $types->whereRaw('1=0');
                foreach (['store_order', 'minimum_price', 'customer_refund', 'stock_low', 'stock_empty', 'inventory_review', 'return_review', 'opening_review', 'purchase_review', 'payment_review', 'expense_review', 'expense_due'] as $type) {
                    if ($this->receives($actor, $type)) {
                        $types->orWhere('type', $type);
                    }
                }
            });
        });
    }
}
