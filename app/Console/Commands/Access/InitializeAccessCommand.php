<?php

namespace App\Console\Commands\Access;

use App\Actions\Access\InitializeAccessAction;
use Illuminate\Console\Command;

class InitializeAccessCommand extends Command
{
    protected $signature = 'app:access-setup';

    protected $description = 'Add access definitions without assigning users, changing passwords, or resetting customized roles';

    public function handle(InitializeAccessAction $initialize): int
    {
        $initialize->execute();
        $this->info('تمت تهيئة تعريفات الوصول مع الحفاظ على الحسابات والأدوار المخصصة.');

        return self::SUCCESS;
    }
}
