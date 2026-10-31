<?php

namespace App\Console\Commands\Sales;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupSalesCommand extends Command
{
    protected $signature = 'app:sales-setup {--owner= : Existing owner username} {--cashier= : Optional cashier username} {--branch= : Cashier branch ID}';

    protected $description = 'Initialize sales permissions and zero-balance receiving accounts without reposting historical financial data';

    public function handle(): int
    {
        $owner = User::where('username', $this->option('owner'))->where('is_active', true)->first();
        if (! $owner) {
            $this->error('حدد اسم مستخدم المالك الفعّال عبر --owner.');

            return self::FAILURE;
        }
        OperationContext::run(function () use ($owner): void {
            ManageFinancialAccountsAction::provision();
            $owner->forceFill(['role_id' => SalesAccess::provisionRole('sales_owner')->id])->save();
            foreach (Branch::where('is_active', true)->get() as $branch) {
                FinancialAccount::firstOrCreate(['code' => 'CASH-'.$branch->id], ['name' => 'درج '.$branch->name, 'type' => 'cash', 'branch_id' => $branch->id]);
            }
            if ($this->option('cashier')) {
                $cashier = User::where('username', $this->option('cashier'))->where('is_active', true)->firstOrFail();
                $branch = Branch::whereKey($this->option('branch'))->where('is_active', true)->firstOrFail();
                $cashier->forceFill(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $branch->id])->save();
            }
            DB::table('sales_controls')->where('id', 1)->update(['owner_id' => $owner->id, 'activated_at' => DB::raw('COALESCE(activated_at, CURRENT_TIMESTAMP)')]);
        });
        $this->info('تمت تهيئة الصلاحيات وحسابات الدرج برصيد صفر. لم تُغلق مرحلة الإعداد ولم تُرحّل بيانات قديمة.');

        return self::SUCCESS;
    }
}
