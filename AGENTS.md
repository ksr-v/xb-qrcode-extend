# Agent Handoff

## Project

This repository publishes the standalone Xboard plugin `qrcodeextend`. Current release target: `v1.1.0`.

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

The v1.1.0 candidate passed 10 tests / 28 assertions, native install-enable-disable-reenable-uninstall, exact Admin hash restoration, and real SmartExpiry `S→Q→Q⁻¹` plus `Q→S→Q⁻¹` tests.

Releases are immutable. Keep config version, README filename, ZIP, tag, and Release title aligned. ZIP must contain an explicit `Qrcodeextend/` root and no credentials or private environment data.
