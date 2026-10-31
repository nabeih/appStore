<?php

namespace App\Actions\Finance;

use App\Models\AuditEvent;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacyFinanceTransitionAction
{
    public function __construct(private PostJournalAction $journal) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(array $data, User $actor): array
    {
        FinanceContext::ready();
        $branch = OperationContext::branch($actor, (int) $data['branch_id'], 'finance.transition_review');
        $source = $this->source($data);
        abort_unless((int) $source->branch_id === $branch->id, 403);
        $ability = match ($data['source_kind']) {
            'opening' => 'inventory.valuation_review', 'purchase' => 'finance.purchase_post', 'payment' => 'finance.supplier_pay',
        };
        abort_unless($actor->can($ability) && $actor->can('sales.view_cost'), 403);
        $event = match ($data['source_kind']) {
            'opening' => 'opening', 'purchase' => 'purchase', 'payment' => 'supplier_payment',
        };
        $date = $data['posting_date'];
        $sourceDate = $data['source_kind'] === 'opening' ? $source->opening_date : ($data['source_kind'] === 'purchase' ? $source->invoice_date : $source->payment_date);
        $issues = [];
        if ($branch->opening_stock_closed_at) {
            $issues[] = 'انتهى الإعداد؛ الانتقال القديم يحتاج مراجعة مستقلة قبل التشغيل.';
        }
        if ($date !== $sourceDate?->toDateString()) {
            $issues[] = 'المسار تاريخي فقط؛ يجب مطابقة تاريخ القيد مع المستند المثبت.';
        }
        try {
            OperationContext::date($branch, $date, false);
        } catch (ValidationException $exception) {
            $issues = array_merge($issues, $exception->validator->errors()->all());
        }
        if (mb_strlen(trim($data['evidence'] ?? '')) < 5) {
            $issues[] = 'مرجع الدليل والموافقة المحاسبية مطلوبان؛ لا يكفي وصف طريقة الدفع.';
        }
        if (($source->status ?? 'approved') !== 'approved') {
            $issues[] = 'المستند غير معتمد.';
        }
        $lines = [];
        $basis = [$source->getAttributes()];
        if ($data['source_kind'] === 'payment') {
            $invoice = $source->invoice;
            if (! $invoice || $invoice->status !== 'approved' || $invoice->branch_id !== $source->branch_id || $invoice->supplier_id !== $source->supplier_id
                || ! $invoice->financial_posted_at || ! $this->entry($invoice, 'purchase')?->reversals()->doesntExist()) {
                $issues[] = 'يجب ترحيل فاتورة المورد ومطابقة قيدها أولًا.';
            }
            if ((int) $source->amount_minor <= 0) {
                $issues[] = 'مبلغ سند السداد يجب أن يكون موجبًا.';
            }
            if ($invoice && ($parent = $this->entry($invoice, 'purchase'))) {
                $dimensions = ['supplier_id' => $invoice->supplier_id, 'purchase_invoice_id' => $invoice->id];
                $parentLines = [['account' => 'inventory', 'amount' => (int) $invoice->total_minor] + $dimensions,
                    ['account' => 'supplier_payables', 'amount' => -(int) $invoice->total_minor] + $dimensions];
                if ((int) $parent->branch_id !== (int) $branch->id || ! $this->matches($parent, $parentLines,
                    FinancialAccount::where('type', 'system')->pluck('id', 'code')->all(), $invoice->invoice_date->toDateString())) {
                    $issues[] = 'قيد فاتورة المورد لا يطابق قيمتها وأطرافها وتاريخها.';
                }
                $basis[] = $parent->lines->toArray();
            }
            if ($invoice && $date < $invoice->invoice_date->toDateString()) {
                $issues[] = 'السداد يسبق تاريخ فاتورة المورد.';
            }
            $account = FinancialAccount::whereKey($data['account_id'] ?? 0)->where('branch_id', $branch->id)
                ->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->first();
            if (! $account || empty($data['funding_confirmed']) || ($data['opening_inclusion'] ?? '') !== 'excluded'
                || mb_strlen(trim($data['funding_evidence'] ?? '')) < 5) {
                $issues[] = 'أثبت الحساب ومصدر التمويل وأن الدفعة لم تخصم ضمن رصيد انتقال آخر.';
            }
            if ($account) {
                $opening = JournalEntry::where('source_type', $account->getMorphClass())->where('source_id', $account->id)
                    ->where('event', 'opening')->whereDoesntHave('reversals')->where('transaction_date', '<=', $date)->exists();
                if (! $opening) {
                    $issues[] = 'يلزم افتتاح حساب موثق بتاريخ يشمل السداد؛ المجهول ليس صفرًا معتمدًا.';
                }
                $cashDays = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                    ->where('l.financial_account_id', $account->id)->groupBy('e.transaction_date')->orderBy('e.transaction_date')
                    ->select('e.transaction_date')->selectRaw('SUM(l.debit_minor)-SUM(l.credit_minor) AS delta')->get();
                $basis[] = $cashDays->toArray();
                $balance = 0;
                $existingPayment = $this->entry($source, $event);
                $deltas = $cashDays->pluck('delta', 'transaction_date')->all();
                $deltas[$date] ??= 0;
                ksort($deltas);
                foreach ($deltas as $day => $delta) {
                    $balance = Money::add($balance, (int) $delta);
                    if ($day >= $date && ! $existingPayment && $balance < (int) $source->amount_minor) {
                        $issues[] = 'الترحيل يجعل رصيد الحساب سالبًا في تاريخ السداد أو بعده؛ راجع التمويل.';
                        break;
                    }
                }
                $lines = [
                    ['account' => 'supplier_payables', 'amount' => (int) $source->amount_minor, 'supplier_id' => $source->supplier_id, 'purchase_invoice_id' => $source->purchase_invoice_id],
                    ['account' => (int) $account->id, 'amount' => -(int) $source->amount_minor],
                ];
            }
            if ($invoice) {
                $paid = (int) $invoice->payments()->where('status', 'approved')->sum('amount_minor');
                if ($paid !== (int) $invoice->paid_minor || $paid > (int) $invoice->total_minor) {
                    $issues[] = 'سندات السداد لا تطابق مدفوع الفاتورة أو تتجاوز قيمتها.';
                }
                $basis[] = $invoice->getAttributes();
                $basis[] = $invoice->payments()->orderBy('id')->get()->toArray();
            }
        } else {
            $items = $source->items()->orderBy('id')->get();
            $movementGroups = DB::table('stock_movements')->where('source_type', $items->first()?->getMorphClass() ?? '')
                ->whereIn('source_id', $items->modelKeys())->where('branch_id', $branch->id)->orderBy('id')->get()->groupBy('source_id');
            $amount = 0;
            foreach ($items as $item) {
                $itemValue = $data['source_kind'] === 'opening' ? Money::minor((string) $item->line_total) : (int) $item->line_total_minor;
                $amount = Money::add($amount, $itemValue);
                $moves = $movementGroups->get($item->id, collect());
                if ($moves->count() !== 1 || (int) $moves->first()?->quantity_change !== (int) $item->quantity
                    || (int) $moves->first()?->product_variant_id !== (int) $item->product_variant_id
                    || $moves->first()?->stock_bucket !== 'available'
                    || ($moves->first()?->value_change_minor !== null && (int) $moves->first()->value_change_minor !== $itemValue)) {
                    $issues[] = 'حركة استلام البند #'.$item->id.' مفقودة أو متعارضة؛ لا تعاد إضافة البضاعة.';
                }
                $basis[] = [$item->getAttributes(), $moves->toArray()];
            }
            $expected = $data['source_kind'] === 'opening' ? Money::minor((string) $source->total_cost) : (int) $source->total_minor;
            if ($items->isEmpty() || $amount !== $expected || (int) $items->sum('quantity') !== (int) $source->total_quantity) {
                $issues[] = 'إجمالي المستند لا يطابق البنود.';
            }
            $lines = [
                ['account' => 'inventory', 'amount' => $amount],
                ['account' => $data['source_kind'] === 'opening' ? 'opening_equity' : 'supplier_payables', 'amount' => -$amount],
            ];
            if ($data['source_kind'] === 'purchase') {
                foreach ($lines as &$line) {
                    $line += ['supplier_id' => $source->supplier_id, 'purchase_invoice_id' => $source->id];
                }
                unset($line);
            }
        }
        $codes = array_values(array_filter(array_column($lines, 'account'), 'is_string'));
        $accounts = FinancialAccount::whereIn('code', $codes)->get()->keyBy('code');
        if (count($codes) !== $accounts->count()) {
            $issues[] = 'تعريف الحسابات النظامية غير مكتمل؛ المعاينة لا تهيئ حسابات.';
        }
        $entry = $this->entry($source, $event);
        $status = 'eligible';
        if ($entry) {
            if ($entry->reversals()->exists()) {
                $issues[] = 'قيد المصدر معكوس؛ لا يعاد ترحيله من مسار البداية نفسه.';
            } elseif ((int) $entry->branch_id !== (int) $branch->id || ! $this->matches($entry, $lines, $accounts->pluck('id', 'code')->all(), $date)) {
                $issues[] = 'يوجد قيد للمصدر لا يطابق الأثر المقترح؛ يلزم مراجعة مستقلة.';
            } else {
                $status = $data['source_kind'] !== 'opening' && ! $source->financial_posted_at ? 'eligible' : 'already_posted';
            }
        } elseif ($data['source_kind'] !== 'opening' && $source->financial_posted_at) {
            $issues[] = 'المصدر معلّم مرحلًا لكن القيد الداعم مفقود.';
        }
        $plan = ['source_kind' => $data['source_kind'], 'source_id' => $source->id, 'branch_id' => $branch->id,
            'posting_date' => $date, 'status' => $issues === [] ? $status : 'rejected', 'issues' => array_values(array_unique($issues)),
            'lines' => $lines, 'journal_id' => $entry?->id, 'evidence' => $data['evidence'] ?? '',
            'account_id' => $data['account_id'] ?? null, 'funding_evidence' => $data['funding_evidence'] ?? '',
            'funding_confirmed' => (bool) ($data['funding_confirmed'] ?? false), 'opening_inclusion' => $data['opening_inclusion'] ?? null];
        $plan['amount_text'] = $lines === [] ? null : Money::format(abs((int) $lines[0]['amount']));
        $plan['effect_text'] = match ($data['source_kind']) {
            'opening' => 'إثبات قيمة أول المدة في الدفتر مقابل الافتتاح، دون إضافة بضاعة.',
            'purchase' => 'إثبات قيمة الشراء في الدفتر وزيادة مستحق المورد، دون استلام البضاعة مرة ثانية.',
            'payment' => 'تخفيض مستحق المورد ورصيد حساب السداد بالمبلغ نفسه، دون إنشاء دفعة أخرى.',
        };
        $plan['revision'] = OperationContext::hash([$plan, $basis, $accounts->toArray(), $entry?->lines->toArray()]);

        return $plan;
    }

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): JournalEntry
    {
        abort_unless(config('finance.legacy_transition_enabled'), 403, 'اعتماد الانتقال غير مفعّل؛ المعاينة متاحة دون تعديل.');

        return OperationContext::run(function () use ($data, $actor): JournalEntry {
            $source = $this->source($data, true);
            $plan = $this->preview($data, $actor);
            if (empty($data['confirmed']) || mb_strlen(trim($data['reason'] ?? '')) < 3) {
                FinanceContext::fail('تأكيد صريح وسبب موثق للانتقال مطلوبان.');
            }
            $hash = OperationContext::hash(array_intersect_key($data, array_flip([
                'branch_id', 'source_kind', 'source_id', 'posting_date', 'account_id', 'evidence', 'funding_evidence', 'opening_inclusion', 'funding_confirmed',
            ])));
            if ($plan['status'] === 'already_posted') {
                $audit = AuditEvent::where('subject_type', $source->getMorphClass())->where('subject_id', $source->id)
                    ->where('action', 'finance.legacy_posted')->latest('id')->first();
                if ($audit) {
                    abort_unless(hash_equals($audit->data['request_hash'], $hash), 409, 'اعتمد المصدر بأدلة مختلفة؛ لا يعاد القرار.');
                }

                return JournalEntry::findOrFail($plan['journal_id']);
            }
            if ($plan['status'] === 'rejected') {
                throw ValidationException::withMessages(['transition' => $plan['issues']]);
            }
            abort_unless(hash_equals($plan['revision'], $data['revision'] ?? ''), 409, 'تغير المصدر أو التمويل منذ المعاينة؛ أعد المراجعة.');
            $entry = $this->journal->execute($source, match ($data['source_kind']) {
                'opening' => 'opening', 'purchase' => 'purchase', 'payment' => 'supplier_payment',
            }, $plan['branch_id'], $actor, $plan['posting_date'], $plan['lines'], 'انتقال تاريخي موثق: '.$data['reason']);
            $before = $source->getAttributes();
            if ($data['source_kind'] !== 'opening') {
                $source->update(['financial_posted_at' => now()] + ($data['source_kind'] === 'payment' ? ['financial_account_id' => $data['account_id']] : []));
            }
            OperationContext::audit($source, $actor, 'finance.legacy_posted', $data['reason'], [
                'journal_id' => $entry->id, 'request_hash' => $hash, 'preview' => $plan, 'before' => $before,
                'after' => $source->getAttributes(), 'stock_changed' => false, 'paid_changed' => false,
            ]);

            return $entry;
        });
    }

    public function reverse(JournalEntry $entry, User $actor, string $reason): JournalEntry
    {
        abort_unless(config('finance.legacy_transition_enabled'), 403, 'عكس الانتقال غير مفعّل.');

        return OperationContext::run(function () use ($entry, $actor, $reason): JournalEntry {
            FinanceContext::ready();
            $branch = OperationContext::branch($actor, $entry->branch_id, 'finance.reverse');
            abort_unless($actor->can('finance.transition_review'), 403);
            if ($branch->opening_stock_closed_at || mb_strlen(trim($reason)) < 5) {
                FinanceContext::fail('عكس الانتقال متاح أثناء الإعداد وبسبب موثق فقط.');
            }
            $current = JournalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $audit = AuditEvent::where('action', 'finance.legacy_posted')->where('data->journal_id', $current->id)->first();
            abort_unless($audit, 422, 'القيد ليس انتقالًا صادرًا عن هذه الآلية.');
            if ($reversal = $current->reversals()->first()) {
                return $reversal;
            }
            $accounts = FinancialAccount::where('type', 'system')->pluck('id', 'code')->all();
            if (! $this->matches($current, $audit->data['preview']['lines'], $accounts, $audit->data['preview']['posting_date'])) {
                FinanceContext::fail('تغير القيد منذ اعتماد الانتقال؛ يلزم تصحيح مستقل بدل عكس أثر مختلف.');
            }
            if (JournalEntry::where('branch_id', $branch->id)->where('id', '>', $current->id)->whereNull('reversal_of_id')
                ->whereDoesntHave('reversals')->exists()) {
                FinanceContext::fail('توجد قيود لاحقة؛ اعكس الاعتمادات التابعة أولًا أو راجع التصحيح المستقل.');
            }
            $date = OperationContext::date($branch);
            $reversal = $this->journal->reverse($current, $actor, $reason, $date);
            $source = $this->source(['source_kind' => $audit->data['preview']['source_kind'], 'source_id' => $current->source_id], true);
            if (! $source instanceof OpeningStockEntry) {
                $source->update(['financial_posted_at' => $audit->data['before']['financial_posted_at'] ?? null]
                    + ($source instanceof SupplierPayment ? ['financial_account_id' => $audit->data['before']['financial_account_id'] ?? null] : []));
            }
            OperationContext::audit($source, $actor, 'finance.legacy_reversed', $reason, ['journal_id' => $current->id, 'reversal_id' => $reversal->id, 'stock_changed' => false]);

            return $reversal;
        });
    }

    /** @param array<string, mixed> $data */
    private function source(array $data, bool $lock = false): Model
    {
        $class = match ($data['source_kind']) {
            'opening' => OpeningStockEntry::class, 'purchase' => PurchaseInvoice::class, 'payment' => SupplierPayment::class,
            default => abort(422, 'نوع مصدر غير مسموح.'),
        };

        return $class::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($data['source_id']);
    }

    private function entry(Model $source, string $event): ?JournalEntry
    {
        return JournalEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)->where('event', $event)->first();
    }

    /** @param list<array<string, mixed>> $lines
     * @param  array<string, int>  $accounts
     */
    private function matches(JournalEntry $entry, array $lines, array $accounts, string $date): bool
    {
        $expected = [];
        foreach ($lines as $line) {
            if ($line['amount'] === 0) {
                continue;
            }
            $expected[] = [(int) (is_int($line['account']) ? $line['account'] : ($accounts[$line['account']] ?? 0)),
                (int) $line['amount'], $line['supplier_id'] ?? null, $line['purchase_invoice_id'] ?? null];
        }
        $actual = $entry->lines->map(fn ($line): array => [(int) $line->financial_account_id,
            (int) $line->debit_minor - (int) $line->credit_minor, $line->supplier_id, $line->purchase_invoice_id])->all();
        sort($expected);
        sort($actual);

        return $entry->transaction_date->toDateString() === $date && $expected === $actual;
    }
}
