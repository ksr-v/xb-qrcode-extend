<?php

namespace Plugin\Qrcodeextend\Services;

use RuntimeException;

class CoreOverlayDeployer
{
    private const FILES = [
        'app/Console/Kernel.php' => [
            'baseline' => '1a2aaf9567163050e9edef302c9f2d09fb12e2e0fd4c930f3d9bc20d79c56d53',
            'target' => '970efa0baf60cdd307b5b7173b099e93b98332fe2ebc76d938af2daec1e4f27a',
        ],
        'app/Services/Plugin/AbstractPlugin.php' => [
            'baseline' => 'bc3a4b5269c57c77c0085e9dc2f766e154bbef78a859bb6634a8852b8f4a574c',
            'target' => '0132a68c790cd7aaa45354009e81f29c154a9c7f98c38b2a9b55c1a6a050594b',
        ],
        'app/Services/Plugin/PluginManager.php' => [
            'baseline' => '174c33aa171eedb2236220829f1d68d0e7138a40c4309c7081009cab0d953fc6',
            'target' => 'b92086f3afaf9b816ed5c8ae56bdbac25597057981068fc9b1de862e4a41ba74',
        ],
        'resources/views/admin.blade.php' => [
            'baseline' => '86432c6b29e59e2683d984ac95b48a903eb30fe5da4e25341f5e771629d88361',
            'previous' => 'e51f71fb606eb49d17e379eb750ac88a498e4ce05e118f5f65e8061d1768a2b6',
            'target' => 'fdf48bc177600e90f66d1522f54c6a041aa4a7d0c3acaf814aa2efafd30d676d',
        ],
    ];

    private string $projectRoot;
    private string $pluginRoot;
    private string $backupRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? base_path();
        $this->pluginRoot = $this->projectRoot . '/plugins/Qrcodeextend';
        $this->backupRoot = $this->projectRoot . '/storage/qrcodeextend/backups/core-before-setup';
    }

    public function deploy(): array
    {
        $plans = [];
        foreach (self::FILES as $relativePath => $hashes) {
            $sourcePath = $this->pluginRoot . '/resources/overlay/' . $relativePath;
            $targetPath = $this->projectRoot . '/' . $relativePath;
            if (!is_file($sourcePath) || !is_file($targetPath)) {
                throw new RuntimeException('Core integration file is missing: ' . $relativePath);
            }

            $source = file_get_contents($sourcePath);
            $current = file_get_contents($targetPath);
            $sourceHash = $this->normalizedHash($source);
            $currentHash = $this->normalizedHash($current);
            $allowed = array_filter([$hashes['baseline'], $hashes['previous'] ?? null, $hashes['target']]);
            if (!hash_equals($hashes['target'], $sourceHash)) {
                throw new RuntimeException('Core integration package failed SHA256 verification: ' . $relativePath);
            }
            if (!in_array($currentHash, $allowed, true)) {
                throw new RuntimeException('Core file has local changes; refusing to overwrite: ' . $relativePath);
            }

            $plans[] = [
                'path' => $targetPath,
                'relative' => $relativePath,
                'before' => $current,
                'after' => $this->withLineEndings($source, str_contains($current, "\r\n")),
                'already_target' => hash_equals($hashes['target'], $currentHash),
            ];
        }

        foreach ($plans as $plan) {
            if (!$plan['already_target']) {
                $this->backup($plan['relative'], $plan['before']);
            }
        }

        $written = [];
        try {
            foreach ($plans as $plan) {
                if ($plan['already_target']) {
                    continue;
                }
                $this->atomicWrite($plan['path'], $plan['after']);
                $written[] = $plan;
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($written) as $plan) {
                $this->atomicWrite($plan['path'], $plan['before']);
            }
            throw $exception;
        }

        return $plans;
    }

    public function restore(): void
    {
        $plans = [];
        foreach (self::FILES as $relativePath => $hashes) {
            $backupPath = $this->backupPath($relativePath);
            if (!is_file($backupPath)) {
                continue;
            }
            $targetPath = $this->projectRoot . '/' . $relativePath;
            $current = file_get_contents($targetPath);
            $backup = file_get_contents($backupPath);
            $allowed = array_filter([$hashes['baseline'], $hashes['previous'] ?? null, $hashes['target']]);
            if (!in_array($this->normalizedHash($backup), $allowed, true)) {
                throw new RuntimeException('Core backup failed SHA256 verification: ' . $relativePath);
            }
            if (!hash_equals($hashes['target'], $this->normalizedHash($current))) {
                throw new RuntimeException('Core file changed after QRCode Extend setup; refusing to restore: ' . $relativePath);
            }
            $plans[] = ['path' => $targetPath, 'contents' => $backup];
        }

        foreach (array_reverse($plans) as $plan) {
            $this->atomicWrite($plan['path'], $plan['contents']);
        }
    }

    public function rollback(array $snapshot): void
    {
        foreach (array_reverse($snapshot) as $plan) {
            if (!$plan['already_target'] && hash_equals(
                $this->normalizedHash($plan['after']),
                $this->normalizedHash(file_get_contents($plan['path']))
            )) {
                $this->atomicWrite($plan['path'], $plan['before']);
            }
        }
    }

    private function backup(string $relativePath, string $contents): void
    {
        $path = $this->backupPath($relativePath);
        $hashes = self::FILES[$relativePath];
        $allowed = array_filter([$hashes['baseline'], $hashes['previous'] ?? null, $hashes['target']]);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Could not create private core backup directory.');
        }
        if (is_file($path)) {
            if (!in_array($this->normalizedHash(file_get_contents($path)), $allowed, true)) {
                throw new RuntimeException('Existing core backup failed SHA256 verification: ' . $relativePath);
            }
            return;
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false
            || !hash_equals(hash('sha256', $contents), hash_file('sha256', $path))) {
            @unlink($path);
            throw new RuntimeException('Could not create a verified core file backup: ' . $relativePath);
        }
    }

    private function backupPath(string $relativePath): string
    {
        return $this->backupRoot . '/' . $relativePath . '.before-setup';
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $mode = fileperms($path) & 0777;
        $temporary = $path . '.qrcodeextend-setup-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Could not write core file: ' . $path);
        }
        chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not atomically replace core file: ' . $path);
        }
    }

    private function normalizedHash(string $contents): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
    }

    private function withLineEndings(string $contents, bool $useCrLf): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        return $useCrLf ? str_replace("\n", "\r\n", $contents) : $contents;
    }
}