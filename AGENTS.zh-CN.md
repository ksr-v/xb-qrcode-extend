# Agent 交接说明

## 项目概况

本仓库发布 Xboard 独立插件 `qrcodeextend`。截至 2026-10-06，最新安装包为 `qrcodeextend-1.0.6.zip`，tag 为 `v1.0.6`，对应提交 `f9d3386`。源码仓库为 `ksr-v/xb-qrcode-extend`。

## 架构与职责

- `Qrcodeextend/Plugin.php`：插件生命周期入口。安装和升级时部署核心 overlay、修补 Admin bundle、清理 Laravel 缓存并尝试重载 Octane；首次安装成功后启用插件。卸载时恢复 Admin bundle 和核心 overlay 备份。
- `Qrcodeextend/Services/AdminBundlePatcher.php`：校验 Admin bundle 文件哈希及唯一锚点，负责备份、原子 patch/restore 和旧桥接升级兼容。不要放宽为接受任意 bundle。
- `Qrcodeextend/Services/CoreOverlayDeployer.php`：部署和受保护恢复 4 个 Xboard 核心文件。
- `Qrcodeextend/resources/assets/qrcodeextend.js` 与 `resources/assets/vendor/qrcodegen.js`：二维码 UI 和本地二维码生成。Admin 菜单根据插件脚本标签判断是否启用；插件初始化时通过 `qrcodeextend:ready` 事件协调加载时序。
- `Qrcodeextend/resources/overlay/`：安装 ZIP 中随附的精确核心文件副本。

## 易冲突文件

插件会覆盖并管理以下 4 个 Xboard 核心文件：

- `app/Console/Kernel.php`
- `app/Services/Plugin/AbstractPlugin.php`
- `app/Services/Plugin/PluginManager.php`
- `resources/views/admin.blade.php`

安装器只接受固定的原始版本、上一版集成版本或本插件目标版本 SHA256。若其他插件或站点定制改动了这些文件，安装器会故意拒绝覆盖。需要先协调并合并双方改动，再更新 overlay 文件、SHA256 白名单及测试；禁止绕过校验或强行覆盖未知文件。

`admin.blade.php` 负责加载已启用插件的 JS/CSS，并为 Admin JS URL 添加内容哈希参数。其他插件若修改插件资源加载或缓存参数，应在这里协调合并，避免相互覆盖。

Admin 补丁只针对 `public/assets/admin/assets/index-CEIYH7i8.js`，并要求原始文件哈希和用户操作菜单锚点精确匹配。升级 Admin dist 时，需检查新的 bundle、确认锚点恰好出现一次，更新批准的原始 hash/commit，并覆盖 patch、status、upgrade、restore 测试。若原版 bundle SHA 精确匹配，即使 Admin 子模块 commit 不同也可识别；其他未知 bundle 字节仍不支持。

## 恢复语义

`php artisan qrcodeextend:restore` 只恢复 Admin JS 桥接，不会恢复 4 个核心 overlay 文件，也不会卸载插件。完整回滚请在插件管理中先禁用再卸载；卸载钩子会尝试恢复 bundle 和已验证的核心文件备份。若文件在安装后发生变化，恢复会拒绝覆盖。排查恢复失败时请保留 `storage/qrcodeextend/backups/`。

## 安装约束

- ZIP 必须包含明确的 `Qrcodeextend/` 根目录项和 `Qrcodeextend/config.json`，否则 Xboard 会报“缺少配置文件”。
- PHP/Octane 运行用户必须能写入 Admin assets 目录，补丁器才能原子替换 bundle。不要建议使用 `chmod 777`；仅在遇到权限错误时，按面板和运行用户说明安全调整目录所有者/组。
- README 中站点路径使用 `example.com` 占位符。不要将用户真实站点域名重新写入公开文档。

## 验证方式

在 Xboard 源码仓库中运行目标测试：

```powershell
php -d "extension=<PHP 扩展目录>\php_mbstring.dll" vendor\bin\phpunit tests\Unit\Qrcodeextend\CoreOverlayDeployerTest.php tests\Unit\Qrcodeextend\AdminBundlePatcherTest.php
```

v1.0.6 时测试结果为 7 项、42 个断言通过。还应对变更 PHP 文件运行 `php -l`，对 `qrcodeextend.js` 运行 `node --check`，执行 `git diff --check`，验证 ZIP 根目录及文件清单，并比较 GitHub Release 下载包与本地包 SHA256。

## 发布流程

- 已发布 Release 和 tag 不可覆盖修改；文档或包有变化时递增版本，生成新 ZIP、tag 和 Release。
- `Qrcodeextend/config.json` 中的版本、README 下载文件名、ZIP 文件名、Git tag 和 Release 标题必须一致。
- 上传前扫描源码和 ZIP，确认没有凭据、私钥、环境文件、数据库或真实站点域名。
- 当前公开版本包括 v1.0.4、v1.0.5、v1.0.6；v1.0.6 是最新版本，包含中文安装、权限与恢复说明。
