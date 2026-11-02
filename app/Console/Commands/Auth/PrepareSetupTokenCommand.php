<?php

namespace App\Console\Commands\Auth;

use App\Actions\Access\FirstRun\PrepareSetupTokenAction;
use Illuminate\Console\Command;
use Throwable;

class PrepareSetupTokenCommand extends Command
{
    protected $signature = 'app:setup-token {--expires=60 : Lifetime in minutes (15-1440)} {--rotate : Explicitly replace a pending unused token}';

    protected $description = 'Prepare a private one-time installation token for a pristine installation; never reopen an existing installation';

    public function handle(PrepareSetupTokenAction $tokens): int
    {
        $minutes = filter_var($this->option('expires'), FILTER_VALIDATE_INT);
        if ($minutes === false) {
            $this->error('مدة الصلاحية غير صحيحة.');

            return self::INVALID;
        }
        try {
            $result = $tokens->execute($minutes, (bool) $this->option('rotate'));
        } catch (Throwable) {
            $this->error('لم يُجهّز الرمز. تحقق من ترحيل جدول حالة التثبيت وأن القاعدة جديدة، أو استخدم مسار استرداد معتمد. لا تعدّل الحالة يدويًا.');

            return self::FAILURE;
        }
        $this->info('جُهّز الرمز في ملف خاص، ولم يظهر في المخرجات أو سجلات التطبيق.');
        $this->line('ملف التسليم: '.$result['path']);
        $this->line('صالح حتى: '.$result['expires_at']);
        $this->line('سلّم محتوى الملف عبر قناة آمنة للعميل. لا تضعه في رابط أو Git أو سجل نشر. الواجهة: /setup');

        return self::SUCCESS;
    }
}
