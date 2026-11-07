<?php

namespace App\Actions\Inventory;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProgressiveOperation
{
    public const WARNING = 'التشغيل تدريجي؛ بعض أرصدة البداية لم تُعتمد، لذلك التقارير الشاملة للمحل غير مكتملة.';

    public static function openingRules(): array
    {
        return ['progressive_opening' => ['sometimes', 'array:counted_at,evidence,confirmed'],
            'progressive_opening.counted_at' => ['required_with:progressive_opening', 'date'],
            'progressive_opening.evidence' => ['required_with:progressive_opening', 'string', 'min:5', 'max:2000'],
            'progressive_opening.confirmed' => ['sometimes', 'required_with:progressive_opening', 'accepted']];
    }

    public function activation(int $branchId): ?AuditEvent
    {
        return AuditEvent::where('subject_type', Branch::class)->where('subject_id', $branchId)
            ->where('action', 'operation.progressive_started')->latest('id')->first();
    }

    public function state(Branch $branch): string
    {
        return $branch->opening_stock_closed_at ? 'full' : ($this->activation($branch->id) ? 'progressive' : 'setup');
    }

    public function sharedBlockers(): array
    {
        $blockers = [];
        if (! FinanceContext::installed()) {
            return ['لم يكتمل تحديث البنية المالية.'];
        }
        if (! DB::table('sales_controls')->where('id', 1)->whereNotNull('activated_at')->exists()) {
            $blockers[] = 'تهيئة المبيعات المركزية غير معتمدة؛ استكمل مسار التهيئة الحالي قبل تفعيل الفرع.';
        }
        $accounts = FinancialAccount::whereIn('code', array_keys(ManageFinancialAccountsAction::SYSTEM))->get()->keyBy('code');
        foreach (ManageFinancialAccountsAction::SYSTEM as $code => $name) {
            $account = $accounts->get($code);
            if (! $account || ! $account->is_active || $account->is_posting === false || $account->branch_id !== null) {
                $blockers[] = 'حساب مشترك غير جاهز: '.$name;
            }
        }
        if (DB::table('journal_lines')->select('journal_entry_id')->groupBy('journal_entry_id')->havingRaw('SUM(debit_minor) <> SUM(credit_minor)')->exists()) {
            $blockers[] = 'يوجد قيد مركزي غير متوازن؛ لا يمكن تجاوز سلامة الحسابات المشتركة.';
        }

        return $blockers;
    }

    public function accountRows(int $branchId): array
    {
        $accounts = FinancialAccount::where('branch_id', $branchId)->whereIn('type', ['cash', 'bank', 'wallet'])->with('lines.entry')->get();
        $openings = JournalEntry::where('source_type', FinancialAccount::class)->whereIn('source_id', $accounts->modelKeys())
            ->where('event', 'opening')->whereDoesntHave('reversals')->get()->groupBy('source_id');
        $result = [];
        foreach ($accounts as $account) {
            $reasons = [];
            $proof = $openings->get($account->id, collect());
            $opening = $proof->count() === 1 ? $proof->first() : null;
            if (! $account->is_active || $account->is_posting === false) {
                $reasons[] = 'الحساب غير فعال أو غير قابل للترحيل.';
            }
            if (! $opening || (int) $opening->branch_id !== $branchId || $opening->transaction_date->toDateString() > OperationContext::today()) {
                $reasons[] = 'رصيد بداية الحساب غير معتمد، حتى لو كان الدفتر يعرض صفرًا.';
            }
            if ($account->lines->contains(fn ($line) => (int) $line->entry->branch_id !== $branchId
                || ($opening && $line->entry->transaction_date < $opening->transaction_date))) {
                $reasons[] = 'حركات الحساب تسبق اعتماد بدايته أو تتبع فرعًا آخر.';
            }
            $balance = Money::sum($account->lines->map(fn ($line) => Money::add((int) $line->debit_minor, -(int) $line->credit_minor))->all());
            if ($balance < 0) {
                $reasons[] = 'رصيد الحساب سالب؛ لا توجد سياسة معتمدة للسحب المكشوف.';
            }
            $result[$account->id] = ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'ready' => $reasons === [],
                'opening_date' => $opening?->transaction_date->toDateString(), 'balance_minor' => $balance, 'reasons' => $reasons];
        }

        return $result;
    }

    public function report(Branch $branch): array
    {
        $proof = app(ProgressiveStockProof::class)->build($branch->id);
        $accounts = $this->accountRows($branch->id);
        $blockers = $this->sharedBlockers();
        if (! $branch->is_active || ($branch->financial_closed_through && substr((string) $branch->financial_closed_through, 0, 10) >= OperationContext::today())) {
            $blockers[] = 'الفرع غير فعال أو الفترة المالية الحالية مغلقة.';
        }
        if ($proof['unexplained_financial_minor'] !== 0 || $proof['orphan_movements'] !== 0) {
            $blockers[] = 'يوجد أثر مالي للمخزون لا يمكن عزله إلى حركات مثبتة، أو حركات بلا رصيد مقابل؛ يلزم مراجعة المصدر.';
        }
        $ready = collect($proof['rows'])->where('ready', true)->filter(fn ($row) => $row['available'] > 0)->count();
        if ($ready === 0) {
            $blockers[] = 'لا يوجد صنف متاح ثبتت جاهزيته بعد.';
        }
        if (! collect($accounts)->contains('ready', true)) {
            $blockers[] = 'لا يوجد حساب قبض جاهز في الفرع.';
        }
        $activation = $this->activation($branch->id);
        $partyApprovals = AuditEvent::whereIn('subject_type', [Customer::class, Supplier::class])->where('action', 'operation.party_zero_confirmed')->get()->groupBy('subject_type');
        $parties = [];
        foreach (['customer' => Customer::class, 'supplier' => Supplier::class] as $kind => $class) {
            $approvedIds = $partyApprovals->get($class, collect())->pluck('subject_id')->all();
            $parties[$kind] = $class::orderBy('id')->get(['id', 'name'])->map(fn ($party) => ['id' => $party->id, 'name' => $party->name, 'ready' => in_array($party->id, $approvedIds, true)])->all();
        }
        $report = ['branch_id' => $branch->id, 'branch' => $branch->name, 'state' => $this->state($branch),
            'activation' => $activation ? ['at' => $activation->data['cutover_at'], 'by' => $activation->user_id] : null,
            'stocks' => $proof['rows'], 'stock_proof' => $proof, 'ready_count' => $ready, 'parties' => $parties,
            'not_ready_count' => count($proof['rows']) - $ready, 'accounts' => $accounts,
            'blockers' => $blockers, 'can_activate' => $blockers === [] && ! $branch->opening_stock_closed_at];

        return $report + ['revision' => OperationContext::hash($report)];
    }

    public function activate(Branch $branch, array $data, User $actor): AuditEvent
    {
        $this->authorizeOwner($actor, $branch->id);

        return OperationContext::run(function () use ($branch, $data, $actor): AuditEvent {
            $current = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $hash = OperationContext::hash($data);
            if ($existing = $this->activation($branch->id)) {
                abort_unless(hash_equals($existing->data['request_hash'], $hash), 409, 'التشغيل التدريجي معتمد بالفعل؛ لا يعاد تاريخ الانتقال.');

                return $existing;
            }
            abort_if($current->opening_stock_closed_at, 409, 'الفرع في التشغيل الكامل بالفعل.');
            $report = $this->report($current);
            abort_unless(hash_equals($report['revision'], $data['revision']), 409, 'تغيرت الجاهزية؛ أعد الفحص قبل الاعتماد.');
            if (! $report['can_activate'] || empty($data['confirmed']) || empty($data['external_movements_confirmed'])) {
                $this->fail($report['blockers'] ?: ['يلزم تأكيد مراجعة الجاهزية وشمول الحركات الخارجية.']);
            }
            OperationContext::date($current);

            return OperationContext::audit($current, $actor, 'operation.progressive_started', $data['reason'],
                ['request_hash' => $hash, 'cutover_at' => now()->toIso8601String(), 'readiness' => $report, 'external_movements_confirmed' => true]);
        });
    }

    public function assertBranch(Branch $branch): void
    {
        if ($branch->opening_stock_closed_at) {
            return;
        }
        if (! $this->activation($branch->id)) {
            throw ValidationException::withMessages(['setup' => 'البيع ممنوع: فترة الإعداد الافتتاحي ما زالت مفتوحة (إعداد فقط). يلزم اعتماد التشغيل التدريجي أو إنهاء الإعداد من المالك.']);
        }
        if ($blockers = $this->sharedBlockers()) {
            $this->fail(['الجاهزية المركزية غير مكتملة؛ يلزم مراجعة المالك.']);
        }
    }

    public function assertStocks(int $branchId, array $variantIds): void
    {
        $branch = Branch::findOrFail($branchId);
        if ($this->state($branch) !== 'progressive') {
            return;
        }
        $this->assertBranch($branch);
        $proof = app(ProgressiveStockProof::class)->build($branchId);
        if ($proof['unexplained_financial_minor'] !== 0 || $proof['orphan_movements'] > 0) {
            $this->fail(['تغيرت المطابقة المالية للمخزون ولا يمكن عزل أثرها؛ أعد مراجعة الجاهزية.']);
        }
        foreach ($variantIds as $id) {
            $row = $proof['rows'][$id] ?? null;
            if (! $row || ! $row['ready']) {
                $this->fail(['الصنف #'.$id.': '.($row ? implode(' ', array_column($row['reasons'], 'message')) : 'لا يوجد رصيد معتمد لهذا الفرع.')]);
            }
        }
    }

    public function assertAccounts(int $branchId, array $ids): void
    {
        if ($ids === [] || ! $this->activation($branchId)) {
            return;
        }
        $rows = $this->accountRows($branchId);
        foreach ($ids as $id) {
            if (! isset($rows[$id]) || ! $rows[$id]['ready']) {
                $this->fail(['حساب القبض أو الصرف #'.$id.' غير جاهز؛ اعتمد بداية الحساب وراجع حركاته.']);
            }
        }
    }

    public function assertParty(int $branchId, string $kind, int $id): void
    {
        if ($this->state(Branch::findOrFail($branchId)) !== 'progressive') {
            return;
        }
        $class = $kind === 'customer' ? Customer::class : Supplier::class;
        if (! AuditEvent::where('subject_type', $class)->where('subject_id', $id)->where('action', 'operation.party_zero_confirmed')->exists()) {
            $this->fail([$kind === 'customer' ? 'ذمة العميل غير معتمدة؛ يمكن البيع النقدي العابر دون استخدام ذمته.' : 'بداية ذمة المورد غير معتمدة؛ راجع الذمة قبل اعتماد شراء أو سداد.']);
        }
    }

    public function assertOriginalSale(SaleInvoice $invoice): void
    {
        if ($this->state(Branch::findOrFail($invoice->branch_id)) !== 'progressive') {
            return;
        }
        $journals = JournalEntry::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)
            ->where('event', 'sale')->where('branch_id', $invoice->branch_id)->whereDoesntHave('reversals')->with('lines.account')->get();
        $journal = $journals->count() === 1 ? $journals->first() : null;
        if (! $journal || $journal->lines->sum('debit_minor') !== $journal->lines->sum('credit_minor')
            || (int) $journal->lines->filter(fn ($line) => $line->account?->code === 'cogs')->sum(fn ($line) => $line->debit_minor - $line->credit_minor) !== (int) $invoice->cost_minor) {
            $this->fail(['لم يثبت القيد الأصلي للفاتورة؛ مرتجعات المبيعات القديمة غير الموثقة تحتاج مسار مراجعة مستقل.']);
        }
    }

    public function confirmZero(Branch $branch, string $kind, int $id, array $data, User $actor): void
    {
        $this->authorizeOwner($actor, $branch->id);
        if (empty($data['confirmed']) || mb_strlen(trim($data['reason'] ?? '')) < 5 || mb_strlen(trim($data['evidence'] ?? '')) < 5) {
            $this->fail(['تأكيد المالك وسبب ودليل اعتماد الصفر مطلوبة.']);
        }
        OperationContext::run(function () use ($branch, $kind, $id, $data, $actor): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $hash = OperationContext::hash($data);
            if (in_array($kind, ['customer', 'supplier'], true)) {
                abort_unless($actor->can('sales.all_branches'), 403);
                $class = $kind === 'customer' ? Customer::class : Supplier::class;
                $subject = $class::whereKey($id)->lockForUpdate()->firstOrFail();
                $action = 'operation.party_zero_confirmed';
                $hasHistory = JournalLine::where($kind.'_id', $id)->exists()
                    || ($kind === 'customer' ? SaleInvoice::where('customer_id', $id) : PurchaseInvoice::where('supplier_id', $id))->whereIn('status', ['approved', 'reversed'])->exists();
            } else {
                abort_unless(in_array($kind, ['zero_opening', 'zero_cost'], true), 404);
                $subject = Inventory::where('branch_id', $branch->id)->whereKey($id)->lockForUpdate()->firstOrFail();
                $action = 'operation.'.$kind.'_confirmed';
                $hasHistory = false;
            }
            $existing = AuditEvent::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->id)->where('action', $action)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->data['request_hash'], $hash), 409, 'الاعتماد موجود بالفعل بدليل مختلف.');

                return;
            }
            if ($hasHistory) {
                $this->fail(['للجهة معاملات سابقة؛ لا يمكن اعتماد صفر لإخفاء ذمة. يلزم مسار انتقال موثق للأرصدة غير الصفرية.']);
            }
            if ($subject instanceof Inventory) {
                $row = app(ProgressiveStockProof::class)->build($branch->id)['rows'][$subject->product_variant_id];
                abort_unless(hash_equals(OperationContext::hash($row), $data['revision']), 409, 'تغير رصيد الصنف؛ أعد المراجعة.');
                $allowed = $kind === 'zero_opening' ? ['opening_unknown', 'zero_cost_unconfirmed'] : ['zero_cost_unconfirmed'];
                if (array_diff(array_column($row['reasons'], 'code'), $allowed)
                    || ($kind === 'zero_cost' && ((int) $subject->value_minor !== 0 || $subject->value_minor === null))) {
                    $this->fail(['لا يمكن استخدام اعتماد الصفر لتجاوز فرق أو مصدر غير مثبت.']);
                }
                if ($kind === 'zero_opening' && StockMovement::where('branch_id', $branch->id)->where('product_variant_id', $subject->product_variant_id)->where('movement_type', '!=', 'purchase')->exists()) {
                    $this->fail(['بداية الصفر متاحة فقط قبل أول استلام موثق، ولا تحل محل افتتاح أو حركة سابقة.']);
                }
            }
            OperationContext::audit($subject, $actor, $action, $data['reason'], ['request_hash' => $hash, 'evidence' => $data['evidence'],
                'scope' => $subject instanceof Inventory ? 'before_first_documented_receipt' : 'shared_party_before_first_financial_document', 'amount_minor' => 0]);
        });
    }

    public function guardCorrection(int $branchId): void
    {
        if ($this->activation($branchId)) {
            $this->fail(['بدأ التشغيل التدريجي؛ لا تعديل أو حذف مباشر لأرصدة البداية. استخدم مستند المصدر الآمن، أو اطلب مراجعة إذا لم يوجد مسار تصحيح.']);
        }
    }

    public function closureBlockers(Branch $branch): array
    {
        if (! $this->activation($branch->id)) {
            return [];
        }
        $report = $this->report($branch);
        $reasons = $this->sharedBlockers();
        if (collect($report['stocks'])->contains('ready', false)) {
            $reasons[] = 'بعض الأصناف لم تستكمل دليلها المخزني والمالي بعد التشغيل التدريجي.';
        }
        if (collect($report['accounts'])->contains('ready', false)) {
            $reasons[] = 'بعض حسابات القبض لم تعتمد بدايتها أو حركاتها.';
        }
        if (collect($report['parties'])->flatten(1)->contains('ready', false)) {
            $reasons[] = 'أرصدة بداية بعض العملاء أو الموردين لم تعتمد؛ لا تُنه الإعداد بصفر مجهول.';
        }
        if ($report['stock_proof']['unexplained_financial_minor'] !== 0 || $report['stock_proof']['orphan_movements'] > 0) {
            $reasons[] = 'توجد آثار مخزنية أو مالية غير مفسرة.';
        }

        return $reasons;
    }

    public function guardLateOpening(Branch $branch, array $data): void
    {
        $activation = $this->activation($branch->id);
        if (! $activation) {
            return;
        }
        Validator::make($data, self::openingRules())->validate();
        $declaration = $data['progressive_opening'] ?? [];
        $cutover = CarbonImmutable::parse($activation->data['cutover_at']);
        if (empty($declaration['confirmed']) || empty($declaration['counted_at']) || mb_strlen(trim($declaration['evidence'] ?? '')) < 5
            || CarbonImmutable::parse($declaration['counted_at'])->greaterThan($cutover)
            || $data['opening_date'] > $cutover->setTimezone(config('sales.timezone', 'Asia/Hebron'))->toDateString()
            || CarbonImmutable::parse($declaration['counted_at'])->setTimezone(config('sales.timezone', 'Asia/Hebron'))->toDateString() !== $data['opening_date']) {
            $this->fail(['الجرد المتأخر يحتاج لقطة موثقة عند الانتقال أو قبله، وتأكيد عدم وجود حركات خارجية ناقصة. جرد اليوم ليس رصيد البداية؛ استخدم الشراء للبضاعة الجديدة.']);
        }
    }

    private function authorizeOwner(User $actor, int $branchId): void
    {
        abort_unless(AccessPermissions::isOwner($actor), 403);
        OperationContext::branch($actor, $branchId, 'inventory.close_setup');
        abort_unless($actor->can('sales.view_cost'), 403);
    }

    private function fail(array $messages): never
    {
        throw ValidationException::withMessages(['readiness' => $messages]);
    }
}
