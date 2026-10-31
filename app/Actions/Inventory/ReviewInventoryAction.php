<?php

namespace App\Actions\Inventory;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewInventoryAction
{
    public function query(): Builder
    {
        $history = DB::table('stock_movements')->select('branch_id', 'product_variant_id')
            ->selectRaw("SUM(CASE WHEN stock_bucket = 'available' THEN quantity_change ELSE 0 END) AS history_quantity")
            ->selectRaw("SUM(CASE WHEN stock_bucket = 'available' THEN value_change_minor ELSE 0 END) AS history_value_minor")
            ->selectRaw("SUM(CASE WHEN stock_bucket = 'available' AND value_change_minor IS NULL THEN 1 ELSE 0 END) AS missing_values")
            ->selectRaw('SUM(CASE WHEN unit_cost IS NULL THEN 1 ELSE 0 END) AS missing_costs')->groupBy('branch_id', 'product_variant_id');

        return Inventory::query()->leftJoinSub($history, 'history', fn ($join) => $join->on('inventories.branch_id', '=', 'history.branch_id')->on('inventories.product_variant_id', '=', 'history.product_variant_id'))
            ->select('inventories.*', 'history.history_quantity', 'history.missing_costs', 'history.history_value_minor', 'history.missing_values')->with(['branch', 'productVariant.product' => fn ($query) => $query->withTrashed(), 'productVariant.color', 'productVariant.size']);
    }

    public function baseline(Inventory $inventory, array $data, User $actor): Inventory
    {
        return OperationContext::run(function () use ($inventory, $data, $actor): Inventory {
            $branch = OperationContext::branch($actor, $inventory->branch_id, 'inventory.valuation_review');
            $this->assertSetupOpen($branch);
            OperationContext::date($branch);
            $current = Inventory::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $hash = OperationContext::hash($data);
            if ($current->value_minor !== null) {
                $event = AuditEvent::where('subject_type', $current->getMorphClass())->where('subject_id', $current->id)
                    ->where('action', 'inventory.valuation_reviewed')->latest('id')->first();
                abort_unless($event && hash_equals($event->data['request_hash'] ?? '', $hash), 409, 'القيمة معتمدة بالفعل؛ أي تغيير لاحق يحتاج مستند مصدر مناسب.');

                return $current;
            }
            $current->load(['productVariant.product' => fn ($query) => $query->withTrashed()]);
            if (! hash_equals(InventoryState::revision($current, $current->productVariant, $current->productVariant->product), $data['revision'])) {
                abort(409, 'تغير الرصيد منذ فتح المراجعة. حدّث الصفحة.');
            }
            $report = app(InventoryReadinessReport::class)->annotate(collect([clone $current]))->first()->readiness;
            if ($report['difference'] !== 0 || ! $report['history_value_complete'] || $report['state'] === 'value_conflict') {
                throw ValidationException::withMessages(['inventory' => 'فرق الكمية أو الدليل غير المكتمل يحتاج تصحيحًا موثقًا من المستند المصدر قبل اعتماد القيمة.']);
            }
            $value = Money::minor($data['approved_value']);
            if ($report['reconstructed_value_minor'] !== $value) {
                throw ValidationException::withMessages(['approved_value' => 'المبلغ لا يطابق قيمة الحركات والمستندات. لا يمكن تغيير القيمة لإخفاء فرق؛ يلزم تصحيح المصدر.']);
            }
            $before = ['quantity' => (int) $current->quantity, 'value_minor' => $current->value_minor, 'average_cost' => (string) $current->average_cost];
            $movementChanges = [];
            foreach ($report['sources'] as $source) {
                if ($source['recorded_value_minor'] === null && $source['evidence_value_minor'] !== null && $source['issues'] === []) {
                    $movement = StockMovement::whereKey($source['movement_id'])->lockForUpdate()->firstOrFail();
                    $beforeMovement = $movement->only(['value_change_minor', 'unit_cost']);
                    $changes = ['value_change_minor' => $source['evidence_value_minor']];
                    if ($movement->unit_cost === null && (int) $movement->quantity_change > 0) {
                        $changes['unit_cost'] = Money::format(Money::ratio($source['evidence_value_minor'], 1, (int) $movement->quantity_change));
                    }
                    $movement->update($changes);
                    $movementChanges[] = ['id' => $movement->id, 'before' => $beforeMovement, 'after' => $movement->only(['value_change_minor', 'unit_cost'])];
                }
            }
            $current->update(['value_minor' => $value,
                'average_cost' => Money::format($current->quantity ? Money::ratio($value, 1, (int) $current->quantity) : 0)]);
            OperationContext::audit($current, $actor, 'inventory.valuation_reviewed', $data['reason'], [
                'request_hash' => $hash, 'before' => $before,
                'after' => ['quantity' => (int) $current->quantity, 'value_minor' => $value, 'average_cost' => (string) $current->average_cost],
                'basis' => $data['basis'], 'evidence' => $data['evidence'], 'movement_changes' => $movementChanges,
                'financial_transition_required' => true, 'financial_posting_performed' => false,
            ]);

            return $current;
        });
    }

    public function close(Branch $branch, array $data, User $actor): void
    {
        OperationContext::run(function () use ($branch, $data, $actor): void {
            OperationContext::branch($actor, $branch->id, 'inventory.close_setup');
            $current = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $hash = OperationContext::hash($data);
            if ($current->opening_stock_closed_at) {
                $event = AuditEvent::where('subject_type', $current->getMorphClass())->where('subject_id', $current->id)
                    ->where('action', 'inventory.setup_closed')->latest('id')->first();
                abort_unless($event && hash_equals($event->data['request_hash'] ?? '', $hash), 409, 'الإعداد مغلق بالفعل؛ لا يعاد فتحه أو تغيير تاريخ بدئه بهذا الطلب.');

                return;
            }
            $date = OperationContext::date($current, $data['start_date'], false);
            $report = app(SetupReadinessReport::class)->build($current);
            abort_unless(hash_equals($report['revision'], $data['revision']), 409, 'تغيرت الأرصدة أو المستندات منذ المعاينة. حدّث تقرير الإنهاء.');
            if (! $report['can_close']) {
                throw ValidationException::withMessages(['branch' => array_column($report['blockers'], 'message')]);
            }
            foreach (['confirmed', 'accounts_confirmed', 'parties_confirmed', 'account_scope_confirmed'] as $confirmation) {
                if (! in_array($data[$confirmation] ?? null, [true, 1, '1', 'yes', 'on'], true)) {
                    throw ValidationException::withMessages([$confirmation => 'الموافقة الصريحة على الأرصدة وشمول الحسابات والذمم مطلوبة.']);
                }
            }
            $latestJournalDate = DB::table('journal_entries')->where('branch_id', $branch->id)->max('transaction_date');
            $latestStockDate = StockMovement::where('branch_id', $branch->id)->selectRaw('MAX(COALESCE(effective_date, DATE(created_at))) AS latest')->value('latest');
            if (($latestJournalDate && substr($latestJournalDate, 0, 10) > $date) || ($latestStockDate && substr($latestStockDate, 0, 10) > $date)) {
                throw ValidationException::withMessages(['start_date' => 'تاريخ بدء التشغيل يسبق حركات ضمن الأرصدة المعروضة. حدد تاريخًا يشمل الأرصدة التي راجعتها.']);
            }
            $before = ['opening_stock_closed_at' => null, 'opening_stock_closed_by' => null];
            $current->forceFill(['opening_stock_closed_at' => now(), 'opening_stock_closed_by' => $actor->id])->save();
            OperationContext::audit($current, $actor, 'inventory.setup_closed', $data['reason'], [
                'request_hash' => $hash, 'start_date' => $date, 'evidence' => $data['evidence'],
                'before' => $before, 'after' => $current->only(['opening_stock_closed_at', 'opening_stock_closed_by']),
                'approved_balances' => $report, 'accounts_confirmed' => true, 'parties_confirmed' => true,
                'account_scope_confirmed' => true, 'stock_or_journal_posted' => false,
            ]);
        });
    }

    private function assertSetupOpen(Branch $branch): void
    {
        if ($branch->opening_stock_closed_at) {
            throw ValidationException::withMessages(['inventory' => 'انتهى الإعداد؛ التصحيح يكون بمستند المصدر أو عكسه ضمن ضوابطه، وليس بتعديل المخزون مباشرة.']);
        }
    }
}
