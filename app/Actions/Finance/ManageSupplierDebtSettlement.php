<?php

namespace App\Actions\Finance;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierDebtSettlement;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageSupplierDebtSettlement
{
    public function installed(): bool
    {
        return Schema::hasTable('supplier_debt_settlements') && Schema::hasColumn('supplier_payments', 'supplier_debt_settlement_id');
    }

    public function cutoff(int $branchId): string
    {
        $branch = Branch::findOrFail($branchId);
        $dates = [OperationContext::today(), $branch->created_at
            ?? DB::table('sales_controls')->where('id', 1)->value('activated_at')
            ?? DB::table('application_installations')->where('id', 1)->value('completed_at'),
            PurchaseInvoice::where('branch_id', $branchId)->where('status', 'approved')->min('invoice_date'),
            JournalEntry::where('branch_id', $branchId)->whereHas('lines', fn ($q) => $q->whereNotNull('supplier_id'))->min('transaction_date')];

        return min(array_map(fn ($date): string => CarbonImmutable::parse($date)->toDateString(), array_filter($dates)));
    }

    /** @param array<string, mixed> $data */
    public function save(Supplier $supplier, array $data, ?UploadedFile $evidence, User $actor): SupplierDebtSettlement
    {
        OperationContext::branch($actor, (int) $data['branch_id'], 'finance.transition_review');
        abort_unless($this->installed(), 503, 'يلزم تطبيق تحديث تسوية مديونية المورد على هذه البيئة أولًا.');
        if ($evidence === null && collect(Schema::getColumns('supplier_debt_settlements'))
            ->whereIn('name', ['evidence_path', 'evidence_name', 'evidence_sha256'])->contains('nullable', false)) {
            FinanceContext::fail('يلزم تطبيق تحديث المرفق الاختياري قبل حفظ مديونية دون ملف. راجع مسؤول النظام؛ لم تُسجل أي مديونية.');
        }
        $digest = $evidence === null ? null : hash_file('sha256', $evidence->getRealPath());
        unset($data['evidence']);
        $hash = OperationContext::hash([$supplier->id, $data, $digest]);
        $path = null;
        try {
            $document = OperationContext::run(function () use ($supplier, $data, $evidence, $actor, $digest, $hash, &$path): SupplierDebtSettlement {
                $current = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                FinanceContext::authorize($actor, (int) $data['branch_id'], 'finance.transition_review', $data['posting_date'], false);
                if ($existing = SupplierDebtSettlement::where('client_uuid', $data['client_uuid'])->first()) {
                    abort_unless($existing->supplier_id === $current->id && $existing->branch_id === (int) $data['branch_id'], 409);

                    return OperationContext::replay($existing, $hash);
                }
                $fingerprint = OperationContext::hash([$current->id, (int) $data['branch_id'], mb_strtolower(trim($data['reference']))]);
                if (SupplierDebtSettlement::where('origin_fingerprint', $fingerprint)->exists()) {
                    FinanceContext::fail('هذا المرجع مسجل للمورد والفرع بالفعل؛ افتح المستند السابق بدل إنشاء دين مكرر.');
                }
                if ($data['original_date'] >= $data['tracking_started_on']) {
                    throw ValidationException::withMessages(['original_date' => "تاريخ نشوء الدين ({$data['original_date']}) يجب أن يسبق بداية تتبع التزامات الموردين ({$data['tracking_started_on']}). راجع التاريخين وفق المستندات الفعلية؛ الالتزامات الجديدة تُسجل بمستندها الصحيح."]);
                }
                $cutoff = $this->cutoff((int) $data['branch_id']);
                if ($data['tracking_started_on'] > $cutoff) {
                    throw ValidationException::withMessages(['tracking_started_on' => "بداية تتبع التزامات الموردين ({$data['tracking_started_on']}) تأتي بعد أول دليل مسجل للفرع ({$cutoff}). اختر بداية التتبع الفعلية في هذا التاريخ أو قبله، دون تغيير تاريخ نشوء الدين الحقيقي."]);
                }
                if ($data['posting_date'] < $data['tracking_started_on']) {
                    throw ValidationException::withMessages(['posting_date' => "تاريخ تسجيل القيد المحاسبي ({$data['posting_date']}) يجب أن يوافق بداية تتبع التزامات الموردين ({$data['tracking_started_on']}) أو يأتي بعدها، ضمن فترة مفتوحة."]);
                }
                if ($path !== null) {
                    Storage::disk('local')->delete($path);
                }
                $path = $evidence?->store('supplier-debt-evidence', 'local');
                if ($evidence !== null && ! $path) {
                    throw new \RuntimeException('تعذر حفظ المستند الداعم؛ لم تُسجل المديونية.');
                }
                $document = SupplierDebtSettlement::create(array_intersect_key($data, array_flip(['client_uuid', 'branch_id', 'original_date', 'tracking_started_on', 'posting_date', 'reference', 'notes'])) + [
                    'supplier_id' => $current->id, 'created_by' => $actor->id, 'request_hash' => $hash, 'origin_fingerprint' => $fingerprint,
                    'amount_minor' => FinanceContext::amount($data['amount']), 'evidence_path' => $path, 'evidence_name' => $evidence?->getClientOriginalName(), 'evidence_sha256' => $digest,
                ]);
                OperationContext::audit($document, $actor, 'supplier_debt.draft', $data['notes'], ['evidence_sha256' => $digest, 'evidence_attached' => $evidence !== null]);

                return $document;
            });
            if ($path !== null && $document->evidence_path !== $path) {
                Storage::disk('local')->delete($path);
            }

            return $document;
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    /** @return array{revision: string, issues: list<string>, reconciliation: array<string, mixed>} */
    public function review(SupplierDebtSettlement $document, User $actor): array
    {
        $document = $document->fresh();
        OperationContext::branch($actor, $document->branch_id, 'purchases.view', false);
        $invoices = PurchaseInvoice::where('supplier_id', $document->supplier_id)->where('branch_id', $document->branch_id)->where('status', 'approved')->with('corrections')->orderBy('id')->get();
        $lines = JournalLine::where('supplier_id', $document->supplier_id)->whereHas('entry', fn ($q) => $q->where('branch_id', $document->branch_id))->with(['entry', 'account'])->orderBy('id')->get();
        $others = SupplierDebtSettlement::where('supplier_id', $document->supplier_id)->where('branch_id', $document->branch_id)->whereKeyNot($document->id)->orderBy('id')->get();
        $reconciliation = app(FinanceReports::class)->supplier($document->supplier_id, $document->branch_id);
        $issues = [];
        if ($document->tracking_started_on->toDateString() > $this->cutoff($document->branch_id)) {
            $issues[] = 'تاريخ بداية التتبع المصرح به متأخر عن سجل النظام؛ راجع تاريخ المديونية ودليلها.';
        }
        if ($invoices->contains(fn ($invoice): bool => $invoice->invoice_date < $document->tracking_started_on
            || mb_strtolower(trim($invoice->supplier_invoice_number ?? '')) === mb_strtolower(trim($document->reference)) || $invoice->effective_total_minor === $document->amount_minor)
            || $lines->contains(fn ($line): bool => $line->account->code === 'supplier_payables'
                && ($line->entry->transaction_date < $document->tracking_started_on || (int) $line->credit_minor === $document->amount_minor))) {
            $issues[] = 'توجد فاتورة أو حركة ذمة قد تثبت هذا الدين بالفعل؛ راجع كشف المورد والدفتر قبل أي إثبات جديد.';
        }
        if ($others->contains('status', 'approved')) {
            $issues[] = 'يوجد رصيد سابق معتمد لهذا المورد والفرع؛ لا تعتمد رصيدًا ثانيًا. التصحيح يبدأ بعكس المستند السابق بعد عكس دفعاته.';
        }
        if ($reconciliation['difference_minor'] !== 0 || $reconciliation['payment_cache_drift'] !== [] || ($reconciliation['settlement_payment_cache_drift'] ?? []) !== []) {
            $issues[] = 'كشف المورد غير مطابق للدفتر أو لسندات السداد؛ عالج سبب الاختلاف من المراجعة المالية أولًا.';
        }
        $evidenceRecorded = $document->evidence_path !== null || $document->evidence_name !== null || $document->evidence_sha256 !== null;
        if ($evidenceRecorded && (! $document->hasEvidence() || ! Storage::disk('local')->exists($document->evidence_path)
            || hash('sha256', Storage::disk('local')->get($document->evidence_path)) !== $document->evidence_sha256)) {
            $issues[] = 'المستند الداعم مفقود أو تغير؛ لا يمكن اعتماد المديونية.';
        }

        return ['revision' => OperationContext::hash([$document->getAttributes(), $invoices->toArray(), $lines->toArray(), $others->toArray(), $issues, $reconciliation]),
            'issues' => $issues, 'reconciliation' => $reconciliation];
    }

    /** @param array<string, mixed> $data */
    public function approve(SupplierDebtSettlement $document, array $data, User $actor): SupplierDebtSettlement
    {
        return OperationContext::run(function () use ($document, $data, $actor): SupplierDebtSettlement {
            $current = SupplierDebtSettlement::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $date = FinanceContext::authorize($actor, $current->branch_id, 'finance.transition_review', $current->posting_date->toDateString(), false);
            $supplier = Supplier::withTrashed()->whereKey($current->supplier_id)->lockForUpdate()->firstOrFail();
            if ($supplier->trashed()) {
                FinanceContext::fail('المورد مؤرشف؛ استعده من صفحة المورد قبل اعتماد مديونية جديدة.');
            }
            $branch = Branch::whereKey($current->branch_id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'approved') {
                if (filter_var($data['opening_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true || filter_var($data['unrecorded_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
                    FinanceContext::fail('يلزم التأكيد الصريح حتى عند إعادة الطلب.');
                }
                $approval = $current->approval_scope;
                abort_unless($approval === OperationContext::hash([$current->supplier_id, $current->branch_id]), 409);
                $audit = AuditEvent::where('subject_type', $current->getMorphClass())->where('subject_id', $current->id)->where('action', 'supplier_debt.approved')->firstOrFail();
                abort_unless(hash_equals($audit->data['revision'], $data['revision'] ?? ''), 409, 'هذا المستند معتمد بمعاينة مختلفة.');

                return $current;
            }
            abort_unless($current->status === 'draft', 409, 'المستند معكوس؛ لا يمكن إعادة اعتماده.');
            if (filter_var($data['opening_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true || filter_var($data['unrecorded_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
                FinanceContext::fail('أكد صراحة أن الدين افتتاحي سابق لبداية التتبع، وأنه غير مسجل في أي فاتورة أو تسوية أو قيد. إن لم تتضح المعالجة، اطلب مراجعة محاسبية.');
            }
            $review = $this->review($current, $actor);
            abort_unless(hash_equals($review['revision'], $data['revision'] ?? ''), 409, 'تغير المستند أو كشف المورد؛ أعد فتح المعاينة والتأكيد.');
            if ($review['issues'] !== []) {
                FinanceContext::fail(implode(' ', $review['issues']));
            }
            OperationContext::date($branch, $date, false);
            $current->update(['status' => 'approved', 'approved_by' => $actor->id, 'financial_posted_at' => now(), 'approval_scope' => OperationContext::hash([$current->supplier_id, $current->branch_id])]);
            app(PostJournalAction::class)->execute($current, 'supplier_debt_settlement', $current->branch_id, $actor, $date, [
                ['account' => 'opening_equity', 'amount' => $current->amount_minor, 'supplier_id' => $current->supplier_id],
                ['account' => 'supplier_payables', 'amount' => -$current->amount_minor, 'supplier_id' => $current->supplier_id],
            ], 'تسوية مديونية مورد سابقة '.$current->number.' — '.$current->reference);
            OperationContext::audit($current, $actor, 'supplier_debt.approved', $current->notes, ['revision' => $data['revision'], 'opening_confirmed' => true, 'unrecorded_confirmed' => true, 'evidence_sha256' => $current->evidence_sha256, 'evidence_attached' => $current->hasEvidence()]);

            return $current;
        });
    }

    public function assertFinancialSupport(SupplierDebtSettlement $document): JournalEntry
    {
        $entry = JournalEntry::where('source_type', $document->getMorphClass())->where('source_id', $document->id)->where('event', 'supplier_debt_settlement')->where('branch_id', $document->branch_id)->whereDoesntHave('reversals')->with('lines.account')->first();
        if (! $entry || $entry->lines->count() !== 2 || (int) $entry->lines->sum('debit_minor') !== $document->amount_minor || (int) $entry->lines->sum('credit_minor') !== $document->amount_minor
            || $entry->lines->where('account.code', 'supplier_payables')->where('supplier_id', $document->supplier_id)->sum('credit_minor') !== $document->amount_minor
            || $entry->lines->where('account.code', 'opening_equity')->sum('debit_minor') !== $document->amount_minor) {
            FinanceContext::fail('لم يثبت القيد الداعم لتسوية المورد؛ راجع الدفتر قبل السداد أو العكس.');
        }

        return $entry;
    }

    public function reverse(SupplierDebtSettlement $document, User $actor, string $reason): SupplierDebtSettlement
    {
        return OperationContext::run(function () use ($document, $actor, $reason): SupplierDebtSettlement {
            $date = FinanceContext::authorize($actor, $document->branch_id, 'finance.reverse');
            OperationContext::branch($actor, $document->branch_id, 'finance.transition_review');
            $current = SupplierDebtSettlement::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $branch = Branch::whereKey($current->branch_id)->lockForUpdate()->firstOrFail();
            OperationContext::date($branch, $date);
            if ($current->status === 'reversed') {
                return $current;
            }
            abort_unless($current->status === 'approved', 409, 'لا يمكن عكس مستند غير معتمد.');
            if (mb_strlen(trim($reason)) < 3 || $current->paid_minor !== 0 || $current->payments()->where('status', 'approved')->exists()) {
                FinanceContext::fail('أدخل سببًا واضحًا، واعكس سندات السداد أولًا قبل عكس المديونية.');
            }
            app(PostJournalAction::class)->reverse($this->assertFinancialSupport($current), $actor, $reason, $date);
            $current->update(['status' => 'reversed', 'approval_scope' => null, 'reversed_at' => now()]);
            OperationContext::audit($current, $actor, 'supplier_debt.reversed', $reason);

            return $current;
        });
    }
}
