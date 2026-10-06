<?php

namespace Plugin\Qrcodeextend;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Plugin\Qrcodeextend\Services\AdminBundlePatcher;
use Plugin\Qrcodeextend\Services\CoreOverlayDeployer;

class Plugin extends AbstractPlugin
{
    public function install(): void
    {
        $this->deployIntegration();
        \App\Models\Plugin::query()
            ->where('code', 'qrcodeextend')
            ->update(['is_enabled' => true, 'updated_at' => now()]);
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        $this->deployIntegration();
    }

    public function uninstall(): void
    {
        app(AdminBundlePatcher::class)->restore();
        app(CoreOverlayDeployer::class)->restore();
    }

    private function deployIntegration(): void
    {
        $deployer = app(CoreOverlayDeployer::class);
        $snapshot = $deployer->deploy();

        try {
            app(AdminBundlePatcher::class)->patch();
        } catch (\Throwable $exception) {
            $deployer->rollback($snapshot);
            throw $exception;
        }

        try {
            Artisan::call('optimize:clear');
            Artisan::call('octane:reload');
        } catch (\Throwable $exception) {
            Log::warning('QRCode Extend could not reload Octane automatically: ' . $exception->getMessage());
        }
    }
}
