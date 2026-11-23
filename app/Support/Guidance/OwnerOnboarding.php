<?php

namespace App\Support\Guidance;

use App\Actions\Access\FirstRun\InstallationReadiness;
use App\Actions\Inventory\ProgressiveOperation;
use App\Models\Branch;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\StoreSetting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\SalesAccess;

class OwnerOnboarding
{
    public function __construct(private InstallationReadiness $installation, private ProgressiveOperation $operation, private GuidanceKnowledgeBase $knowledge) {}

    /** @return array<string, mixed>|null */
    public function build(User $actor, ?Branch $branch): ?array
    {
        if (! AccessPermissions::canViewOperation($actor) || ! $branch) {
            return null;
        }
        abort_unless(SalesAccess::allows($actor, 'sales.all_branches') || (int) $actor->branch_id === $branch->id, 403);
        $installation = $this->installation->state();
        if (! $installation || $installation->status !== 'completed' || $installation->first_branch_required) {
            return null;
        }
        $report = $this->operation->report($branch);
        $topics = $this->knowledge->allowed($actor);
        $link = function (string $topic) use ($topics, $branch): ?string {
            $definition = $topics[$topic] ?? null;

            return $definition ? route($definition['route'], ['branch_id' => $branch->id] + ($definition['parameters'] ?? []), false) : null;
        };
        $cashierReady = $report['blockers'] === [] && $report['state'] !== 'setup' && $branch->is_active;
        $hasSale = $actor->can('sales.view') && SaleInvoice::where('branch_id', $branch->id)->where('status', 'approved')->exists();
        $hasProducts = isset($topics['inventory']) && Product::where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereHas('variants', fn ($query) => $query->where('is_active', true)
                ->whereHas('color', fn ($query) => $query->where('is_active', true))->whereHas('size', fn ($query) => $query->where('is_active', true)))->exists();
        $store = $actor->can('store.manage') ? StoreSetting::find(1) : null;
        $steps = [
            ['key' => 'store', 'title' => 'بيانات المتجر', 'complete' => $store && filled($store->name) && filled($store->phone), 'optional' => true,
                'description' => 'اسم المتجر والتواصل للمتجر الإلكتروني. هذه الخطوة اختيارية ولا تمنع بيع الكاشير.',
                'url' => $actor->can('store.manage') ? route('store.admin.content.create', ['type' => 'settings'], false) : null],
            ['key' => 'branch', 'title' => 'الفرع', 'complete' => $branch->is_active && filled($branch->name), 'optional' => false,
                'description' => 'تأكد من الفرع الذي تعمل عليه؛ الكميات والحسابات والبيع تتبع الفرع المحدد.', 'url' => $link('branches')],
            ['key' => 'accounts', 'title' => 'الحسابات المالية', 'complete' => collect($report['accounts'])->contains('ready', true), 'optional' => false,
                'description' => 'اعتمد رصيد بداية حساب قبض فعّال، حتى لو كان صفرًا. رصيد الدفتر وحده لا يثبت البداية.', 'url' => $link('accounts')],
            ['key' => 'suppliers', 'title' => 'الموردون', 'complete' => isset($topics['suppliers']) && Supplier::exists(), 'optional' => true,
                'description' => 'اختياري للبضاعة الموجودة أول المدة؛ أضف المورد قبل فاتورة شراء جديدة. الموردون سجل مشترك بين الفروع.', 'url' => $link('suppliers')],
            ['key' => 'products', 'title' => 'المنتجات والألوان والمقاسات', 'complete' => $hasProducts, 'optional' => false,
                'description' => 'عرّف المنتج واللون والمقاس أثناء إدخال أول المدة؛ حفظ المسودة يجهّز الكتالوج دون زيادة المخزون. للبضاعة الجديدة استخدم فاتورة الشراء.',
                'url' => $branch->opening_stock_closed_at ? $link('purchases') : ($link('opening') ?? $link('inventory'))],
            ['key' => 'stock', 'title' => 'المخزون الافتتاحي', 'complete' => $report['ready_count'] > 0, 'optional' => false,
                'description' => 'للبضاعة الموجودة: أدخل مستندًا واعتمده ماليًا. المسودة لا تزيد المخزون. مشتريات جديدة معتمدة تغني عن الافتتاح؛ لا تُدخل البضاعة مرتين.',
                'url' => $branch->opening_stock_closed_at ? $link('stock_review') : $link('opening')],
            ['key' => 'cashier', 'title' => 'جاهزية الكاشير', 'complete' => $cashierReady, 'optional' => false,
                'description' => 'من حالة تشغيل المحل: أكمل إعداد المبيعات، ثم فعّل التشغيل التدريجي صراحةً أو أنهِ الإعداد بعد المطابقة. لا تفعيل تلقائي.', 'url' => $link('readiness')],
            ['key' => 'sale', 'title' => 'أول عملية بيع', 'complete' => $hasSale, 'optional' => false,
                'description' => 'الفاتورة المعتمدة وحدها تُكمل هذه الخطوة؛ فتح الكاشير أو حفظ مسودة لا يكفي.', 'url' => $cashierReady ? $link('cashier') : null],
        ];
        $required = collect($steps)->where('optional', false);
        $complete = $required->where('complete', true)->count();
        $blockers = $report['blockers'];
        if ($report['state'] === 'setup') {
            $blockers[] = 'الفرع ما زال في وضع الإعداد فقط. راجع الجاهزية ثم اختر التشغيل التدريجي أو إنهاء الإعداد بإجراء صريح.';
        }

        return ['branch_id' => $branch->id, 'branch' => $branch->name, 'steps' => $steps, 'complete' => $complete,
            'total' => $required->count(), 'percent' => intdiv($complete * 100, $required->count()), 'summary' => $hasSale,
            'ready' => $cashierReady, 'blockers' => $blockers, 'state' => $report['state'],
            'next' => $required->firstWhere('complete', false), 'help_url' => route('assistant.index', ['branch_id' => $branch->id, 'topic' => 'readiness'], false)];
    }
}
