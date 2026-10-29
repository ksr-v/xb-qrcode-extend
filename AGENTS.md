# Agent Handoff

## Project

This repository publishes the standalone Xboard plugin `qrcodeextend`. Current release target: `v1.3.1`.

## Architecture

- `Qrcodeextend/Plugin.php`: native lifecycle and owned asset publication/removal.
- `Qrcodeextend/Services/AdminBundlePatcher.php`: build guard, exact anchor, shared lock, atomic write, ownership markers, and byte-exact reversal.
- `Qrcodeextend/resources/assets/`: local QR UI and generator, lazily loaded by the owned Admin bridge.
- There is no core overlay and no modification of Xboard's plugin framework or `admin.blade.php`.

## Safety Rules

- Keep `storage/framework/xboard-admin-patch.lock` as the shared lock.
- Never restore a whole Admin backup during normal disable/uninstall.
- Marker count must be zero or one complete exact block. Partial, duplicate, moved, or edited blocks fail closed.
- Preserve all foreign bytes, including SmartExpiry transforms.
- Do not delete the shared lock during cleanup.

## Validation and Release

Run focused PHPUnit, PHP/JS syntax checks, `git diff --check`, ZIP content/permission checks, native lifecycle tests, and both SmartExpiry orderings.

The v1.3.1 candidate makes the save link a visible touch-friendly button, updates asset cache-busting, and migrates exact v3/v4 bridges to v5. Run the complete focused suite and mobile image-output checks before release.

Releases are immutable. Keep config version, README filename, ZIP, tag, and Release title aligned. ZIP must contain an explicit `Qrcodeextend/` root and no credentials or private environment data.
