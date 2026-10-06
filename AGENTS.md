# Agent Handoff

## Project

This repository publishes the standalone Xboard plugin `qrcodeextend`. The latest published package is `qrcodeextend-1.0.6.zip`, tag `v1.0.6`, commit `f9d3386` (2026-10-06). Repository: `ksr-v/xb-qrcode-extend`.

## Architecture

- `Qrcodeextend/Plugin.php`: lifecycle. Install/update deploys the core overlay, patches the Admin bundle, clears Laravel caches, and attempts Octane reload. Fresh install enables the plugin. Uninstall restores the Admin bundle and core overlay backups.
- `Qrcodeextend/Services/AdminBundlePatcher.php`: exact Admin bundle/anchor validation, backup, atomic patch/restore, and legacy bridge migration. Do not broaden this to accept arbitrary bundles.
- `Qrcodeextend/Services/CoreOverlayDeployer.php`: four-file Xboard overlay deployment and guarded restore.
- `Qrcodeextend/resources/assets/qrcodeextend.js` and `resources/assets/vendor/qrcodegen.js`: local UI and QR generation. The bridge menu is controlled by the plugin script tag, and startup ordering is coordinated through the `qrcodeextend:ready` event.
- `Qrcodeextend/resources/overlay/`: exact core files included in the install ZIP.

## Conflict-Sensitive Files

The overlay owns these Xboard core files:

- `app/Console/Kernel.php`
- `app/Services/Plugin/AbstractPlugin.php`
- `app/Services/Plugin/PluginManager.php`
- `resources/views/admin.blade.php`

The installer only accepts pinned baseline/previous/target SHA256 values. If another plugin or local change edits any of these files, installation intentionally fails closed. Coordinate and merge both plugins' changes, regenerate overlay payloads and hash allowlists, and add tests before publishing; never weaken the checks or overwrite an unknown file. `admin.blade.php` loads enabled plugin JS/CSS and adds a content-hash query parameter to the Admin JS URL, so coordinate any other Admin asset loader or cache-busting changes there.

The Admin patcher targets only `public/assets/admin/assets/index-CEIYH7i8.js`, with a unique user-actions anchor and pinned original bundle hashes. An Admin dist update requires inspecting its source bundle, confirming the anchor exactly once, adding the new approved source hash/commit, and testing patch, status, upgrade, and restore. A matching original bundle SHA is accepted even if the Admin submodule commit differs; unknown bundle bytes remain unsupported.

## Restore Semantics

`php artisan qrcodeextend:restore` restores only the Admin JS bridge. It does not restore the four core overlay files or uninstall the plugin. Full rollback is Plugin Management -> disable -> uninstall; the uninstall hook restores both bundle and verified core backups. Restore refuses to overwrite any file changed after installation. Preserve `storage/qrcodeextend/backups/` when diagnosing a failed restore.

## Install Constraints

The ZIP must contain an explicit `Qrcodeextend/` root entry and `Qrcodeextend/config.json`, or Xboard reports a missing config. The Admin assets directory must be writable by the PHP/Octane runtime user for atomic bundle replacement. Do not recommend `chmod 777`; document a panel-specific owner/group fix only when needed. Permission examples in README use `example.com` as a placeholder; never reintroduce a user's real host name into public docs.

## Validation

In the Xboard checkout, focused tests are:

```powershell
php -d "extension=<PHP ext path>\php_mbstring.dll" vendor\bin\phpunit tests\Unit\Qrcodeextend\CoreOverlayDeployerTest.php tests\Unit\Qrcodeextend\AdminBundlePatcherTest.php
```

At v1.0.6 these passed: 7 tests, 42 assertions. Also run `php -l` on changed PHP files, `node --check` on `qrcodeextend.js`, `git diff --check`, verify ZIP root/contents, and compare the downloaded Release ZIP SHA256 with the locally built package.

## Release Process

- Keep releases immutable; documentation or package changes need a new version, ZIP, and tag.
- The package version in `Qrcodeextend/config.json`, README download filename, ZIP name, Git tag, and Release title must agree.
- Scan source and ZIP for credentials, private keys, environment files, and real hostnames before publishing.
- Current public versions: v1.0.4, v1.0.5, v1.0.6. v1.0.6 is latest and includes Chinese installation/permission/restore documentation.
