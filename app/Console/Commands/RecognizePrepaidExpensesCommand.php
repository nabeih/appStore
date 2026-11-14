<?php

namespace App\Console\Commands;

use App\Actions\Finance\Expenses\RecognizePrepaidExpense;
use App\Models\Expense;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:expenses-recognize')]
#[Description('Recognize due portions of approved prepaid expenses; keep closed periods and failures pending')]
class RecognizePrepaidExpensesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecognizePrepaidExpense $recognize): int
    {
        if (! RecognizePrepaidExpense::installed()) {
            $this->warn('توزيع المصروفات غير مثبت؛ لم تُعالج بيانات.');

            return self::SUCCESS;
        }
        $failed = false;
        Expense::where('recognition_method', 'prepaid')->where('status', 'approved')
            ->whereHas('allocations', fn ($query) => $query->whereNull('posted_at')->where('due_date', '<=', OperationContext::today()))
            ->orderBy('id')->chunkById(50, function ($expenses) use ($recognize, &$failed): void {
                foreach ($expenses as $expense) {
                    try {
                        $actor = User::findOrFail($expense->approved_by);
                        $count = $recognize->execute($expense, $actor);
                        $this->info($expense->number.': '.$count.' حصة');
                    } catch (\Throwable) {
                        $failed = true;
                        $this->error($expense->number.': بقيت حصص معلقة؛ راجع الفترة والصلاحيات والحسابات من شاشة المصروف.');
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
