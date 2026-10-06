<?php

namespace Plugin\Qrcodeextend\Services;

use App\Models\Plugin;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AdminBundlePatcher
{
    public const SUPPORTED_ADMIN_COMMIT = 'ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7';
    public const SUPPORTED_BRIDGE_COMMIT = '3d26e91a4534e255f0873ecb12a1d1a4acd9199b';
    public const TARGET_BUNDLE = 'assets/index-CEIYH7i8.js';
    public const ORIGINAL_SHA256 = '0cfb12e1ec9afee9e439744671de2c14cb1af1b3118c291f0a87348492948666';
    public const ORIGINAL_GIT_BLOB_SHA256 = 'f04f09a95bfdb04fa5132e336bf87c364d4e360cb95774872ff18eb69e9bcd77';

    private const ANCHOR = 'Q.jsxs($st,{className:"",onSelect:()=>{lT(t.original.id)';
    private const BRIDGE_MARKER = 'qrcodeextend:ready';
    private const LEGACY_BRIDGE_MARKER = 'window.Qrcodeextend?.isReady?.()';

    public function status(): array
    {
        $adminRoot = base_path('public/assets/admin');
        $bundlePath = $adminRoot . '/' . self::TARGET_BUNDLE;
        $currentHash = is_file($bundlePath) ? hash_file('sha256', $bundlePath) : null;
        $adminCommit = $this->getAdminCommit($adminRoot);
        $source = $this->supportedSourceForStatus($bundlePath, $adminRoot, $adminCommit);
        $expectedPatchedHash = $source === null ? null : hash('sha256', $this->patchedContents($source));
        $patched = $currentHash !== null
            && $source !== null
            && $this->isPatchedContents(File::get($bundlePath), $source);
        try {
            $plugin = Plugin::query()->where('code', 'qrcodeextend')->first();
            $pluginEnabled = (bool) ($plugin?->is_enabled ?? false);
        } catch (\Throwable) {
            $pluginEnabled = null;
        }
        $publicAssets = public_path('plugins/qrcodeextend');

        return [
            'plugin_version' => $this->pluginVersion(),
            'admin_commit' => $adminCommit,
            'target_bundle' => self::TARGET_BUNDLE,
            'expected_original_sha256' => $source === null ? null : hash('sha256', $source),
            'original_git_blob_sha256' => self::ORIGINAL_GIT_BLOB_SHA256,
            'current_sha256' => $currentHash,
            'expected_patched_sha256' => $expectedPatchedHash,
            'supported' => $source !== null,
            'patched' => $patched,
            'bridge_detected' => $currentHash !== null && $this->containsBridge($bundlePath),
            'plugin_assets_published' => is_file($publicAssets . '/qrcodeextend.js') && is_file($publicAssets . '/qrcodeextend.css'),
            'plugin_enabled' => $pluginEnabled,
        ];
    }

    public function patch(): string
    {
        $adminRoot = base_path('public/assets/admin');
        $bundlePath = $adminRoot . '/' . self::TARGET_BUNDLE;
        $source = $this->supportedSourceForStatus($bundlePath, $adminRoot, $this->getAdminCommit($adminRoot));

        if ($source === null) {
            throw new \RuntimeException('当前 Xboard Admin 版本不受 qrcodeextend 支持。已停止 patch，未修改任何文件。');
        }

        $patchedContents = $this->patchedContents($source);
        $expectedPatchedHash = hash('sha256', $patchedContents);
        $currentHash = hash_file('sha256', $bundlePath);

        if (hash_equals($expectedPatchedHash, $currentHash)) {
            return 'Admin bridge is already patched; no changes made.';
        }

        if (!hash_equals(hash('sha256', $source), $currentHash)
            && !$this->isLegacyPatchedContents(File::get($bundlePath), $source)) {
            throw new \RuntimeException('Admin bundle SHA256 不符合受支持版本。已停止 patch，未修改任何文件。');
        }

        $this->backupOriginal($source);
        $this->atomicWrite($bundlePath, $patchedContents);

        if (!hash_equals($expectedPatchedHash, hash_file('sha256', $bundlePath))) {
            throw new \RuntimeException('Patched bundle SHA256 验证失败。请使用 qrcodeextend:restore 检查恢复。');
        }

        return 'Admin bridge patched successfully.';
    }

    public function restore(): string
    {
        $adminRoot = base_path('public/assets/admin');
        $bundlePath = $adminRoot . '/' . self::TARGET_BUNDLE;
        $adminCommit = $this->getAdminCommit($adminRoot);
        $source = $this->supportedSourceForStatus($bundlePath, $adminRoot, $adminCommit);

        if ($source === null) {
            throw new \RuntimeException('Admin bundle 在 qrcodeextend patch 后发生变化，无法安全自动恢复。保留 backup 和人工恢复说明。');
        }

        $currentHash = hash_file('sha256', $bundlePath);
        $originalHash = hash('sha256', $source);
        if (hash_equals($originalHash, $currentHash)) {
            return 'Admin bundle is already original; no changes made.';
        }
        if (!$this->isPatchedContents(File::get($bundlePath), $source)) {
            throw new \RuntimeException('Admin bundle 在 qrcodeextend patch 后发生变化，无法安全自动恢复。保留 backup 和人工恢复说明。');
        }

        $this->backupOriginal($source);
        $this->atomicWrite($bundlePath, File::get($this->backupPath($originalHash)));

        if (!hash_equals($originalHash, hash_file('sha256', $bundlePath))) {
            throw new \RuntimeException('Restored bundle SHA256 verification failed.');
        }

        return 'Admin bundle restored successfully.';
    }

    private function originalBundleSource(string $bundlePath, string $adminRoot, ?string $adminCommit): ?string
    {
        if (!is_file($bundlePath)) {
            return null;
        }

        $current = File::get($bundlePath);
        if ($this->isOriginalBundle($current)) {
            return $current;
        }

        if ($adminCommit !== null
            && !in_array($adminCommit, [self::SUPPORTED_ADMIN_COMMIT, self::SUPPORTED_BRIDGE_COMMIT], true)
            && !$this->isKnownPatchedBundle($current)) {
            return null;
        }

        if ($adminCommit === null && $this->isKnownPatchedBundle($current)) {
            return $this->originalFromBackup();
        }

        $useCrLf = str_contains($current, "\r\n");
        $backup = $this->originalFromBackup($useCrLf);
        if ($backup !== null) {
            return $backup;
        }

        if ($adminCommit === null) {
            return null;
        }

        $process = new Process([
            'git', '-C', $adminRoot, 'show', self::SUPPORTED_ADMIN_COMMIT . ':' . self::TARGET_BUNDLE,
        ]);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        $original = $this->normalizeLineEndings($process->getOutput(), $useCrLf);
        return $this->isOriginalBundle($original) ? $original : null;
    }

    private function originalFromBackup(?bool $useCrLf = null): ?string
    {
        foreach ([self::ORIGINAL_SHA256, self::ORIGINAL_GIT_BLOB_SHA256] as $knownHash) {
            $backupPath = $this->backupPath($knownHash);
            if (!is_file($backupPath) || !hash_equals($knownHash, hash_file('sha256', $backupPath))) {
                continue;
            }

            $backup = File::get($backupPath);
            if ($useCrLf !== null) {
                $backup = $this->normalizeLineEndings($backup, $useCrLf);
            }
            if ($this->isOriginalBundle($backup)) {
                return $backup;
            }
        }

        return null;
    }

    private function isKnownPatchedBundle(string $contents): bool
    {
        if (substr_count($contents, self::BRIDGE_MARKER) !== 1
            && substr_count($contents, self::LEGACY_BRIDGE_MARKER) !== 1) {
            return false;
        }

        $normalized = $this->normalizeLineEndings($contents, false);
        $original = $this->normalizeLineEndings($this->originalFromBackup() ?? '', false);
        if (!$this->isOriginalBundle($original)) {
            $adminRoot = base_path('public/assets/admin');
            if (!in_array($this->getAdminCommit($adminRoot), [self::SUPPORTED_ADMIN_COMMIT, self::SUPPORTED_BRIDGE_COMMIT], true)) {
                return false;
            }

            try {
                $process = new Process(['git', '-C', $adminRoot, 'show', self::SUPPORTED_ADMIN_COMMIT . ':' . self::TARGET_BUNDLE]);
                $process->run();
            } catch (\Throwable) {
                return false;
            }
            if (!$process->isSuccessful()) {
                return false;
            }
            $original = $process->getOutput();
            if (!$this->isOriginalGitBlob($original)) {
                return false;
            }
        }

        return $this->isPatchedContents($normalized, $original);
    }

    private function supportedSourceForStatus(string $bundlePath, string $adminRoot, ?string $adminCommit): ?string
    {
        if (!is_file($bundlePath)) {
            return null;
        }

        $original = $this->originalBundleSource($bundlePath, $adminRoot, $adminCommit);
        if ($original === null) {
            return null;
        }

        $currentHash = hash_file('sha256', $bundlePath);
        return hash_equals(hash('sha256', $original), $currentHash)
            || $this->isPatchedContents(File::get($bundlePath), $original)
            ? $original
            : null;
    }

    private function isPatchedContents(string $contents, string $original): bool
    {
        return hash_equals(hash('sha256', $this->patchedContents($original)), hash('sha256', $contents))
            || $this->isLegacyPatchedContents($contents, $original);
    }

    private function isLegacyPatchedContents(string $contents, string $original): bool
    {
        if (substr_count($contents, self::LEGACY_BRIDGE_MARKER) !== 1) {
            return false;
        }

        $legacyBridge = 'window.Qrcodeextend?.isReady?.()?Q.jsx($st,{onSelect:()=>{window.Qrcodeextend?.open?.(t.original)},className:"p-0",children:Q.jsxs(xtt,{variant:"ghost",className:"w-full justify-start px-2 py-1.5",children:[Q.jsx("svg",{className:"mr-2 size-4",viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"2",children:[Q.jsx("rect",{x:"3",y:"3",width:"7",height:"7",rx:"1",key:"a"}),Q.jsx("rect",{x:"14",y:"3",width:"7",height:"7",rx:"1",key:"b"}),Q.jsx("rect",{x:"3",y:"14",width:"7",height:"7",rx:"1",key:"c"}),Q.jsx("path",{d:"M14 14h3v3h-3zM20 14v3m-6 3h3m3-3v3",key:"d"})]}),r("columns.actions_menu.generate_qrcode")]})}):null,';
        return hash_equals(
            hash('sha256', str_replace(self::ANCHOR, $legacyBridge . self::ANCHOR, $original)),
            hash('sha256', $contents)
        );
    }

    private function patchedContents(string $contents): string
    {
        if ($this->anchorCount($contents) !== 1) {
            throw new \RuntimeException('Admin bridge anchor occurrence must equal exactly one.');
        }

        return str_replace(self::ANCHOR, $this->bridgeMenuItem() . self::ANCHOR, $contents);
    }

    private function bridgeMenuItem(): string
    {
        return 'document.querySelector(\'script[src="/plugins/qrcodeextend/qrcodeextend.js"]\')?Q.jsx($st,{onSelect:()=>{if(window.Qrcodeextend?.isReady?.())window.Qrcodeextend.open(t.original);else window.addEventListener("qrcodeextend:ready",()=>window.Qrcodeextend?.open?.(t.original),{once:!0})},className:"p-0",children:Q.jsxs(xtt,{variant:"ghost",className:"w-full justify-start px-2 py-1.5",children:[Q.jsx("svg",{className:"mr-2 size-4",viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:"2",children:[Q.jsx("rect",{x:"3",y:"3",width:"7",height:"7",rx:"1",key:"a"}),Q.jsx("rect",{x:"14",y:"3",width:"7",height:"7",rx:"1",key:"b"}),Q.jsx("rect",{x:"3",y:"14",width:"7",height:"7",rx:"1",key:"c"}),Q.jsx("path",{d:"M14 14h3v3h-3zM20 14v3m-6 3h3m3-3v3",key:"d"})]}),r("columns.actions_menu.generate_qrcode")==="columns.actions_menu.generate_qrcode"?"生成订阅二维码":r("columns.actions_menu.generate_qrcode")]})}):null,';
    }

    private function anchorCount(string $contents): int
    {
        return substr_count($contents, self::ANCHOR);
    }

    private function containsBridge(string $bundlePath): bool
    {
        $contents = File::get($bundlePath);
        return str_contains($contents, self::BRIDGE_MARKER)
            || str_contains($contents, self::LEGACY_BRIDGE_MARKER);
    }

    private function getAdminCommit(string $adminRoot): ?string
    {
        $process = new Process(['git', '-C', $adminRoot, 'rev-parse', 'HEAD']);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    private function backupOriginal(string $contents): void
    {
        if (!$this->isOriginalBundle($contents)) {
            throw new \RuntimeException('Refusing to back up an unrecognized original Admin bundle.');
        }

        $originalHash = hash('sha256', $contents);
        $backupPath = $this->backupPath($originalHash);
        File::ensureDirectoryExists(dirname($backupPath));

        if (is_file($backupPath)) {
            if (!hash_equals($originalHash, hash_file('sha256', $backupPath))) {
                throw new \RuntimeException('Existing original bundle backup failed SHA256 verification.');
            }
            return;
        }

        if (file_put_contents($backupPath, $contents, LOCK_EX) === false
            || !hash_equals($originalHash, hash_file('sha256', $backupPath))) {
            @unlink($backupPath);
            throw new \RuntimeException('Could not create a verified original bundle backup.');
        }
    }

    private function backupPath(string $originalHash): string
    {
        return storage_path('qrcodeextend/backups/admin-bundle.' . $originalHash . '.original.js');
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporaryPath = $path . '.qrcodeextend.tmp';
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false || !rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Could not atomically replace the Admin bundle.');
        }
    }

    private function pluginVersion(): ?string
    {
        $configPath = base_path('plugins/Qrcodeextend/config.json');
        if (!is_file($configPath)) {
            return null;
        }

        $config = json_decode(File::get($configPath), true);
        return is_array($config) ? ($config['version'] ?? null) : null;
    }

    private function isOriginalBundle(string $contents): bool
    {
        return (in_array(hash('sha256', $contents), [self::ORIGINAL_SHA256, self::ORIGINAL_GIT_BLOB_SHA256], true)
                || $this->isOriginalGitBlob($contents))
            && $this->anchorCount($contents) === 1;
    }

    private function isOriginalGitBlob(string $contents): bool
    {
        return hash_equals(self::ORIGINAL_GIT_BLOB_SHA256, hash('sha256', $contents));
    }

    private function normalizeLineEndings(string $contents, bool $useCrLf): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
        return $useCrLf ? str_replace("\n", "\r\n", $normalized) : $normalized;
    }
}
