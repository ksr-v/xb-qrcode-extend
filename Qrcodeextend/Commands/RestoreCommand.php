<?php

namespace Plugin\Qrcodeextend\Commands;

use Illuminate\Console\Command;
use Plugin\Qrcodeextend\Services\AdminBundlePatcher;
use Throwable;

class RestoreCommand extends Command
{
    protected $signature = 'qrcodeextend:restore';
    protected $description = 'Safely restore the verified original Admin bundle';

    public function handle(AdminBundlePatcher $patcher): int
    {
        try {
            $this->info($patcher->restore());
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
