<?php
declare(strict_types=1);

// Uses the real panel PluginManager, Composer loader and SQLite against a new
// copied fixture. Set QRCODE_TEST_PANEL and XBOARD_TEST_VENDOR to local sources.
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Plugin\Qrcodeextend\Services\AdminBundlePatcher;

$candidate = dirname(__DIR__);
$panel = getenv('QRCODE_TEST_PANEL');
if (!is_string($panel) || !is_file($panel . '/tests/BundledPlugins/bootstrap.php')) {
    throw new RuntimeException('Set QRCODE_TEST_PANEL to a local Xboard-Custom-Source checkout.');
}
$order = $argv[1] ?? '';
if (!in_array($order, ['smart-first', 'qr-first'], true)) {
    throw new RuntimeException('Run in a fresh PHP process with smart-first or qr-first.');
}
$GLOBALS['__composer_autoload_files']['a03e85e827f2f53ca6b173cf8d947ac6'] = true;
require $panel . '/tests/BundledPlugins/bootstrap.php';

$root = fixtureRoot('qr132-' . $order);
copyFixture($sourceRoot . '/public/assets/admin', $root . '/public/assets/admin');
copy($sourceRoot . '/resources/views/admin.blade.php', $root . '/resources/views/admin.blade.php');
copyFixture($candidate . '/Qrcodeextend', $root . '/plugins/Qrcodeextend');
copyFixture($sourceRoot . '/bundled-plugins/SmartExpiry', $root . '/plugins/SmartExpiry');
$app = bootFixture($root);
$manager = app(PluginManager::class);
$sequence = $order === 'smart-first' ? ['smart_expiry', 'qrcodeextend'] : ['qrcodeextend', 'smart_expiry'];
foreach ($sequence as $code) {
    check($manager->install($code), 'Native installation failed: ' . $code);
    check($manager->enable($code), 'Native enable failed: ' . $code);
}
$paths = ['public/assets/admin/index.html', 'public/assets/admin/' . AdminBundlePatcher::TARGET_BUNDLE,
    'public/assets/admin/locales/en-US.js', 'public/assets/admin/locales/ru-RU.js',
    'public/assets/admin/locales/zh-CN.js', 'resources/views/admin.blade.php'];
$read = static function () use ($root, $paths): array {
    $bytes = [];
    foreach ($paths as $path) { $bytes[$path] = file_get_contents($root . '/' . $path); }
    return $bytes;
};
$patcher = new AdminBundlePatcher($root);
$bundlePath = 'public/assets/admin/' . AdminBundlePatcher::TARGET_BUNDLE;
$both = $read();
$reflection = new ReflectionClass($patcher);
$block = $reflection->getMethod('ownedBlock')->invoke($patcher);
$smartOnly = $both;
$smartOnly[$bundlePath] = str_replace($block, '', $both[$bundlePath], $count);
check($count === 1, 'Expected exactly one complete QR bridge.');
check(str_contains($both[$bundlePath], 'smart-expiry-edit-v7'), 'SmartExpiry edit bridge missing.');
check(Plugin::where('code', 'qrcodeextend')->value('version') === '1.3.2', 'Wrong installed QR version.');
check(Plugin::where('code', 'qrcodeextend')->value('is_enabled') === true, 'QR is not enabled.');
test('native installation and enable preserve both integrations: ' . $order, static function (): void {});

$identity = Plugin::where('code', 'qrcodeextend')->value('id');
$settings = '{"fixture_setting":"keep"}';
Plugin::where('code', 'qrcodeextend')->update(['config' => $settings]);
$manager->enable('qrcodeextend');
$manager->initializeEnabledPlugins();
check($read() === $both, 'Repeated enable/request boot changed either integration.');
test('repeated native enable and request initialization are idempotent: ' . $order, static function (): void {});

$published = $root . '/public/plugins/qrcodeextend';
$asset = $published . '/qrcodeextend.js';
$expectedAsset = file_get_contents($candidate . '/Qrcodeextend/resources/assets/qrcodeextend.js');
check(file_get_contents($asset) === $expectedAsset, 'Published asset differs.');
file_put_contents($asset, ($expectedAsset[0] === 'x' ? 'y' : 'x') . substr($expectedAsset, 1));
$manager->enable('qrcodeextend');
check(file_get_contents($asset) === $expectedAsset, 'Native boot did not repair same-size corrupt asset.');
unlink($published . '/vendor/qrcodegen.js');
$manager->enable('qrcodeextend');
check(file_get_contents($published . '/vendor/qrcodegen.js') === file_get_contents($candidate . '/Qrcodeextend/resources/assets/vendor/qrcodegen.js'),
    'Native boot did not repair missing nested asset.');
check($read() === $both, 'Asset repair changed the integrations.');
test('native boot repairs corrupt and missing owned assets: ' . $order, static function (): void {});

$private = $root . '/storage/qrcodeextend';
mkdir($private, 0700, true);
file_put_contents($private . '/proof.txt', 'private-update-canary');
Plugin::where('code', 'qrcodeextend')->update(['version' => '1.3.1']);
check($manager->update('qrcodeextend'), 'Native update failed.');
check(Plugin::where('code', 'qrcodeextend')->value('version') === '1.3.2', 'Native update did not advance the record.');
check(Plugin::where('code', 'qrcodeextend')->value('id') === $identity
    && Plugin::where('code', 'qrcodeextend')->value('config') === $settings
    && Plugin::where('code', 'qrcodeextend')->value('is_enabled') === true, 'Native update changed identity, settings or enabled choice.');
check(file_get_contents($private . '/proof.txt') === 'private-update-canary', 'Native update cleanup did not preserve private storage.');
check($read() === $both, 'Native update changed the final integrations.');
test('native version update preserves settings, identity and private storage: ' . $order, static function (): void {});

$manager->disable('qrcodeextend');
check($read() === $smartOnly, 'Disable changed SmartExpiry or foreign Admin bytes.');
check(!is_dir($published) && !is_dir($private), 'Normal disable left owned published/private files.');
check(is_file($root . '/storage/framework/xboard-admin-patch.lock'), 'Cleanup deleted the shared lock.');
check(Plugin::where('code', 'qrcodeextend')->value('config') === $settings, 'Disable erased settings.');
$manager->enable('qrcodeextend');
check($read() === $both && is_file($asset), 'Re-enable did not restore the bridge and assets.');
$manager->uninstall('qrcodeextend');
check(!Plugin::where('code', 'qrcodeextend')->exists(), 'Uninstall left the QR database record.');
check($read() === $smartOnly, 'Uninstall changed SmartExpiry or foreign Admin bytes.');
check(Plugin::where('code', 'smart_expiry')->value('is_enabled') === true, 'QR lifecycle changed SmartExpiry enabled state.');
check(is_file($root . '/storage/framework/xboard-admin-patch.lock'), 'Uninstall removed the shared lock.');
test('native disable, re-enable and uninstall preserve SmartExpiry and the shared lock: ' . $order, static function (): void {});

echo "PASS {$passed} native QR 1.3.2 lifecycle checks ({$order}); isolated SQLite fixture only\n";
