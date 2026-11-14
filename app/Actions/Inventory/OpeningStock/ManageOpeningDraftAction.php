<?php

namespace App\Actions\Inventory\OpeningStock;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Models\AuditEvent;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ManageOpeningDraftAction
{
    /** Missing accounts are created only during approval; reading this status never writes. */
    public function accountIssues(): array
    {
        return FinancialAccount::whereIn('code', ['inventory', 'opening_equity'])->get()
            ->filter(fn ($account): bool => ! $account->is_active || $account->is_posting === false || $account->branch_id !== null)
            ->map(fn ($account): string => 'الحساب '.$account->code.' غير فعال أو غير قابل للترحيل أو مرتبط بفرع.')->values()->all();
    }

    public function approvalIssues(OpeningStockEntry $entry): array
    {
        $issues = $this->accountIssues();
        if ($this->hasJournal($entry)) {
            $issues[] = 'المسودة مرتبطة بقيد سابق؛ يلزم مراجعة المسؤول دون إعادة اعتماد تلقائي.';
        }
        try {
            app(PrepareOpeningStockAction::class)->execute($this->data($entry, $entry->items()->with('productVariant.product')->orderBy('id')->get()));
        } catch (ValidationException $error) {
            $issues = array_merge($issues, array_merge(...array_values($error->errors())));
        } catch (\Throwable) {
            $issues[] = 'تعذر التحقق من جميع بيانات المسودة؛ راجع مسؤول النظام قبل الاعتماد.';
        }

        return $issues;
    }

    private function data(OpeningStockEntry $entry, Collection $items): array
    {
        $data = ['branch_id' => $entry->branch_id, 'opening_date' => $entry->opening_date->toDateString(),
            'rows' => $items->map(fn ($item): array => ['name' => $item->product_name, 'code' => $item->product_code,
                'category_id' => $item->productVariant->product->category_id, 'color_id' => $item->productVariant->color_id,
                'size_id' => $item->productVariant->size_id, 'qty' => $item->quantity, 'cost' => $item->unit_cost, 'price' => $item->selling_price])->all()];
        $declaration = AuditEvent::where('subject_type', $entry->getMorphClass())->where('subject_id', $entry->id)
            ->where('action', 'operation.opening_snapshot_recorded')->latest('id')->first()?->data['declaration'] ?? null;
        if ($declaration) {
            $data['progressive_opening'] = $declaration;
        }

        return $data;
    }

    private function hasJournal(OpeningStockEntry $entry): bool
    {
        return JournalEntry::where('source_type', $entry->getMorphClass())->where('source_id', $entry->id)->where('event', 'opening')->exists();
    }

    public function approve(OpeningStockEntry $entry, int $version, User $actor, array $declaration = []): OpeningStockEntry
    {
        return OperationContext::run(function () use ($entry, $version, $actor, $declaration): OpeningStockEntry {
            $branch = OperationContext::branch($actor, $entry->branch_id, 'inventory.correct_setup');
            $current = OpeningStockEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'approved') {
                abort_unless($current->version === $version + 1, 409, 'اعتماد سابق يخص إصدارًا آخر.');

                return $current;
            }
            abort_unless($current->status === 'draft' && $current->version === $version, 409, 'المسودة تغيرت أو ألغيت.');
            abort_if($this->hasJournal($current), 409, 'المسودة مرتبطة بقيد سابق؛ أوقف الاعتماد وراجع المسؤول.');
            if (FinancialAccount::whereIn('code', array_keys(ManageFinancialAccountsAction::SYSTEM))->count() !== count(ManageFinancialAccountsAction::SYSTEM)) {
                ManageFinancialAccountsAction::provision();
            }
            if ($this->accountIssues() !== []) {
                throw ValidationException::withMessages(['account' => 'البضاعة محفوظة كمسودة. يلزم إكمال تجهيز الحسابات لاعتمادها.']);
            }
            $items = $current->items()->with('productVariant.product')->orderBy('id')->get();
            $data = $this->data($current, $items);
            if ($declaration !== []) {
                $data['progressive_opening'] = $declaration;
            }
            $plan = app(PrepareOpeningStockAction::class)->execute($data, true, $branch);
            if ($plan['total_quantity'] !== (int) $current->total_quantity || Money::minor($plan['total_cost']) !== Money::minor($current->total_cost)
                || $items->contains(fn ($item): bool => Money::minor($item->line_total) !== Money::multiply(Money::minor($item->unit_cost), (int) $item->quantity))) {
                throw ValidationException::withMessages(['rows' => 'إجماليات المسودة لا تطابق أصنافها؛ راجع الكميات والتكاليف قبل الاعتماد.']);
            }
            if (isset($data['progressive_opening'])) {
                OperationContext::audit($current, $actor, 'operation.opening_snapshot_recorded', $data['progressive_opening']['evidence'], ['declaration' => $data['progressive_opening']]);
            }
            foreach ($items as $item) {
                $value = Money::minor($item->line_total);
                Inventory::create(['branch_id' => $branch->id, 'product_variant_id' => $item->product_variant_id, 'quantity' => $item->quantity,
                    'average_cost' => $item->unit_cost, 'value_minor' => $value]);
                StockMovement::create(['branch_id' => $branch->id, 'product_variant_id' => $item->product_variant_id, 'user_id' => $actor->id,
                    'movement_type' => 'opening_stock', 'quantity_change' => $item->quantity, 'unit_cost' => $item->unit_cost,
                    'value_change_minor' => $value, 'stock_bucket' => 'available', 'effective_date' => $data['opening_date'],
                    'source_type' => $item->getMorphClass(), 'source_id' => $item->id, 'note' => $current->number]);
            }
            app(PostJournalAction::class)->execute($current, 'opening', $branch->id, $actor, $data['opening_date'], [
                ['account' => 'inventory', 'amount' => Money::minor($current->total_cost)],
                ['account' => 'opening_equity', 'amount' => -Money::minor($current->total_cost)],
            ], $current->number);
            $current->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id, 'version' => $version + 1]);
            OperationContext::audit($current, $actor, 'opening.approved');

            return $current;
        });
    }

    public function update(OpeningStockEntry $entry, array $data, User $actor): OpeningStockEntry
    {
        return OperationContext::run(function () use ($entry, $data, $actor): OpeningStockEntry {
            $branch = OperationContext::branch($actor, $entry->branch_id, 'inventory.correct_setup');
            if ($branch->opening_stock_closed_at) {
                throw ValidationException::withMessages(['opening' => 'أُغلق إعداد أول المدة.']);
            }
            $current = OpeningStockEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'draft' && $current->version === (int) $data['version'], 409, 'المسودة تغيرت أو رُحّلت.');
            if (($data['action'] ?? '') === 'cancel') {
                $current->update(['status' => 'cancelled', 'version' => $current->version + 1]);
            } else {
                $items = $current->items()->get()->keyBy('id');
                if (count($data['rows']) !== $items->count()) {
                    throw ValidationException::withMessages(['rows' => 'أرسل جميع سطور المسودة.']);
                }
                $quantity = $total = 0;
                foreach ($data['rows'] as $row) {
                    $item = $items->get($row['id']);
                    if (! $item) {
                        throw ValidationException::withMessages(['rows' => 'سطر غير تابع للمسودة.']);
                    }
                    $cost = Money::minor($row['cost']);
                    $count = (int) $row['quantity'];
                    $item->update(['quantity' => $count, 'unit_cost' => Money::format($cost), 'line_total' => Money::format(Money::multiply($cost, $count))]);
                    $quantity += $count;
                    $total += Money::multiply($cost, $count);
                }
                $current->update(['total_quantity' => $quantity, 'total_cost' => Money::format($total), 'version' => $current->version + 1]);
            }
            OperationContext::audit($current, $actor, 'opening.draft_changed', $data['reason'] ?? null);

            return $current;
        });
    }
}
