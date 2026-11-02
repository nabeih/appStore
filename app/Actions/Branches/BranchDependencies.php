<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BranchDependencies
{
    /** @return list<string> */
    public function blockers(Branch $branch): array
    {
        $checks = [
            'employees' => [DB::table('users')->where('branch_id', $branch->id)->where('is_active', true)->whereNull('deleted_at'), 'يوجد مستخدمون فعّالون مرتبطون بالفرع؛ انقل ارتباطهم أولًا من إدارة الموظفين.'],
            'sessions' => [DB::table('sessions as s')->join('users as u', 'u.id', '=', 's.user_id')->where('u.branch_id', $branch->id)
                ->where('s.last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->timestamp), 'توجد جلسات دخول حديثة مرتبطة بمستخدمي الفرع.'],
            'counts' => [DB::table('cash_counts as c')->where('c.branch_id', $branch->id)->where('c.difference_minor', '!=', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('treasury_documents as t')->whereColumn('t.cash_count_id', 'c.id')->where('t.status', 'approved')), 'يوجد فرق جرد صندوق لم تُعتمد تسويته.'],
            'accounts' => [DB::table('financial_accounts')->where('branch_id', $branch->id), 'للفرع حسابات مالية؛ إيقاف فرع مالي يحتاج مراجعة وإقفالًا معتمدًا.'],
            'stock' => [DB::table('inventories')->where('branch_id', $branch->id), 'توجد أرصدة مخزون مرتبطة بالفرع، حتى إن كانت كمياتها الحالية صفرًا.'],
        ];
        foreach (['sale_invoices' => 'مستندات مبيعات', 'purchase_invoices' => 'مستندات شراء', 'opening_stock_entries' => 'مستندات أول المدة',
            'expenses' => 'مستندات مصروفات', 'supplier_payments' => 'دفعات موردين', 'customer_receipts' => 'سندات عملاء',
            'treasury_documents' => 'حركات صناديق', 'cash_counts' => 'سجلات جرد صندوق', 'journal_entries' => 'قيود مالية',
            'stock_movements' => 'حركات مخزون', 'sale_returns' => 'مرتجعات مبيعات', 'sale_exchanges' => 'استبدالات'] as $table => $label) {
            $checks[$table] = [DB::table($table)->where('branch_id', $branch->id), 'للفرع '.$label.'؛ يلزم مسار إقفال ومراجعة قبل التعطيل.'];
        }
        $query = DB::query()->useWritePdo();
        if (Schema::hasTable('store_orders')) {
            $checks['store_orders'] = [DB::table('store_orders')->where('branch_id', $branch->id), 'للفرع طلبات موقع موثقة؛ يلزم مراجعة عهدة التوصيل والتحصيل قبل تعطيله.'];
        }
        foreach ($checks as $key => [$exists]) {
            $query->selectRaw('EXISTS ('.$exists->selectRaw('1')->toSql().') AS '.$key, $exists->getBindings());
        }
        $flags = (array) $query->first();
        $messages = [];
        foreach ($checks as $key => [, $message]) {
            if ($flags[$key]) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
