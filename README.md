# QRCode Extend

Standalone plugin package for a clean Xboard installation. It includes the QR-code UI, the four required Xboard core integration files, and the exact-version Admin bundle patcher. No prior qrcodeextend version or manual core-file upload is required.

## Install

1. Upload `qrcodeextend-1.0.4.zip` in **Plugin Management**.
2. Install **QRCode Extend**. The install hook verifies the supported Xboard core files, backs them up under `storage/qrcodeextend/backups/`, atomically deploys the included integration, patches the supported Admin bundle, clears Laravel caches, and enables the plugin.
3. Reload the admin page. The menu action appears under each user's **Actions** menu.

The plugin manager's upload API requires selecting a ZIP file. No SSH or Artisan commands are needed for installation. Octane is asked to reload automatically; if the host disables that command, restart the site's Octane service in its panel.

## Safety and restore

The installer checks all four core files before writing any of them. It only accepts the pinned clean Xboard baseline, the previous qrcodeextend integration, or the exact included integration. Unknown local changes stop the install without overwriting files. Original core files are backed up outside the public directory.

Uninstall the plugin through Plugin Management to restore the verified Admin bundle and core-file backups. Restoration refuses to overwrite files that changed after setup. Admin bundle updates remain version-locked; restore the bridge before replacing the Admin distribution.

The QR payload uses the selected user's existing `subscribe_url`, adds the frontend-compatible `types` parameter, and is generated locally. No subscription data is persisted or sent to a third party.
