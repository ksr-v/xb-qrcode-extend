<?php

namespace Tests\Unit\Qrcodeextend;

use PHPUnit\Framework\TestCase;
use Plugin\Qrcodeextend\Services\AdminBundlePatcher;
use ReflectionClass;
use RuntimeException;

class AdminBundlePatcherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qrcodeextend-admin-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/public/assets/admin/assets', 0775, true);
        mkdir($this->root . '/public/plugins/qrcodeextend', 0775, true);
        mkdir($this->root . '/plugins/Qrcodeextend', 0775, true);
        mkdir($this->root . '/storage/framework', 0775, true);
        file_put_contents($this->root . '/plugins/Qrcodeextend/config.json', '{"version":"test"}');
        file_put_contents($this->root . '/public/assets/admin/manifest.json', json_encode([
            'index.html' => ['file' => AdminBundlePatcher::TARGET_BUNDLE, 'isEntry' => true],
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSmartExpiryThenQrcodeInstallAndUninstallPreservesSmartExpiry(): void
    {
        $smart = '/*smart-expiry-edit-v7*/;' . $this->anchor() . ';/*smart-expiry-create-v7*/';
        $this->writeBundle($smart);
        $patcher = $this->patcher();

        $patcher->patch();
        self::assertStringContainsString('smart-expiry-edit-v7', $this->readBundle());
        self::assertStringContainsString('/*qrcodeextend:start:v3*/', $this->readBundle());

        $patcher->removeOwnedChanges();
        self::assertSame($smart, $this->readBundle());
    }

    public function testQrcodeThenSmartExpiryInstallAndQrcodeUninstallPreservesSmartExpiry(): void
    {
        $original = 'before;' . $this->anchor() . ';after';
        $this->writeBundle($original);
        $patcher = $this->patcher();
        $patcher->patch();
        $this->writeBundle('/*smart-expiry-edit-v7*/;' . $this->readBundle() . ';/*smart-expiry-create-v7*/');

        $patcher->removeOwnedChanges();
        self::assertSame('/*smart-expiry-edit-v7*/;' . $original . ';/*smart-expiry-create-v7*/', $this->readBundle());
    }

    public function testRepeatedPatchAndRestoreAreIdempotent(): void
    {
        $original = $this->anchor();
        $this->writeBundle($original);
        $patcher = $this->patcher();
        $patcher->patch();
        $once = $this->readBundle();
        $patcher->patch();
        self::assertSame($once, $this->readBundle());
        $patcher->removeOwnedChanges();
        $patcher->removeOwnedChanges();
        self::assertSame($original, $this->readBundle());
    }

    public function testLegacyUnownedInjectionFailsClosed(): void
    {
        $reflection = new ReflectionClass(AdminBundlePatcher::class);
        $bridge = $reflection->getMethod('bridgeMenuItem')->invoke($this->patcher());
        $old = 'smart-before;' . $bridge . $this->anchor() . ';smart-after';
        $this->writeBundle($old);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('legacy');
        $this->patcher()->patch();
    }

    public function testMissingOrDuplicateAnchorStopsWithoutWriting(): void
    {
        foreach (['no anchor', $this->anchor() . $this->anchor()] as $contents) {
            $this->writeBundle($contents);
            try {
                $this->patcher()->patch();
                self::fail('Expected incompatible anchor count to fail.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('anchor count', $exception->getMessage());
            }
            self::assertSame($contents, $this->readBundle());
        }
    }

    public function testTamperedOwnedCodeStopsRestoreWithoutWriting(): void
    {
        $this->writeBundle($this->anchor());
        $patcher = $this->patcher();
        $patcher->patch();
        $tampered = str_replace('qrcodeextend:ready', 'third-party:rewrite', $this->readBundle());
        $this->writeBundle($tampered);

        try {
            $patcher->removeOwnedChanges();
            self::fail('Expected modified injection to fail preflight.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('modified', $exception->getMessage());
        }
        self::assertSame($tampered, $this->readBundle());
    }

    public function testPartialAndDuplicateMarkersFailClosed(): void
    {
        foreach ([
            '/*qrcodeextend:start:v3*/' . $this->anchor(),
            '/*qrcodeextend:start:v3*//*qrcodeextend:end:v3*/'
                . '/*qrcodeextend:start:v3*//*qrcodeextend:end:v3*/' . $this->anchor(),
        ] as $contents) {
            $this->writeBundle($contents);
            try {
                $this->patcher()->patch();
                self::fail('Expected invalid marker topology to fail.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('markers', $exception->getMessage());
            }
            self::assertSame($contents, $this->readBundle());
        }
    }

    public function testUnsupportedManifestFailsWithoutWriting(): void
    {
        $contents = $this->anchor();
        $this->writeBundle($contents);
        file_put_contents($this->root . '/public/assets/admin/manifest.json', json_encode([
            'index.html' => ['file' => 'assets/index-new-build.js', 'isEntry' => true],
        ]));

        try {
            $this->patcher()->patch();
            self::fail('Expected unsupported build to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unsupported Admin build', $exception->getMessage());
        }
        self::assertSame($contents, $this->readBundle());
    }

    public function testWriteFailureRollsBackToExactInput(): void
    {
        $contents = 'smart-expiry;' . $this->anchor() . ';smart-tail';
        $this->writeBundle($contents);
        $patcher = new class($this->root) extends AdminBundlePatcher {
            private int $writes = 0;
            protected function atomicWrite(string $contents): void
            {
                $this->writes++;
                if ($this->writes === 1) {
                    parent::atomicWrite('simulated-partial-output');
                    throw new RuntimeException('simulated write failure');
                }
                parent::atomicWrite($contents);
            }
        };

        try {
            $patcher->patch();
            self::fail('Expected simulated write failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated write failure', $exception->getMessage());
        }
        self::assertSame($contents, $this->readBundle());
    }

    public function testConcurrentPatchWaitsForSharedLock(): void
    {
        $this->writeBundle($this->anchor());
        $lockPath = $this->root . '/' . AdminBundlePatcher::LOCK_FILE;
        $lock = fopen($lockPath, 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));

        $class = (new ReflectionClass(AdminBundlePatcher::class))->getFileName();
        $helper = $this->root . '/concurrent-patch.php';
        file_put_contents($helper, '<?php require ' . var_export($class, true)
            . '; (new \\Plugin\\Qrcodeextend\\Services\\AdminBundlePatcher($argv[1]))->patch();');
        $process = proc_open([PHP_BINARY, $helper, $this->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        usleep(150000);
        self::assertTrue(proc_get_status($process)['running'], 'Concurrent patch did not wait for the shared lock.');

        flock($lock, LOCK_UN);
        fclose($lock);
        self::assertSame(0, proc_close($process));
        self::assertStringContainsString('/*qrcodeextend:start:v3*/', $this->readBundle());
    }

    private function anchor(): string
    {
        return (new ReflectionClass(AdminBundlePatcher::class))->getReflectionConstant('ANCHOR')->getValue();
    }

    private function patcher(): AdminBundlePatcher { return new AdminBundlePatcher($this->root); }

    private function bundlePath(): string
    {
        return $this->root . '/public/assets/admin/' . AdminBundlePatcher::TARGET_BUNDLE;
    }

    private function writeBundle(string $contents): void { file_put_contents($this->bundlePath(), $contents); }
    private function readBundle(): string { return file_get_contents($this->bundlePath()); }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
