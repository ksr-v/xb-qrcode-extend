# Agent 交接说明

本仓库发布独立 Xboard 插件 `qrcodeextend`，当前发布目标为 `v1.3.2`。

## 架构

- `Qrcodeextend/Plugin.php` 使用原生生命周期，只发布和删除自身资源。
- `AdminBundlePatcher.php` 负责构建识别、唯一锚点、共享锁、原子写入、ownership marker 和精确反向清理。
- Admin bridge 延迟加载插件自己的 JS/CSS。
- 不再包含 core overlay，也不修改 Xboard 插件框架或 `admin.blade.php`。

## 安全规则

- 共享锁固定为 `storage/framework/xboard-admin-patch.lock`。
- 正常禁用/卸载不得使用完整备份覆盖 Admin bundle。
- marker 只能为零个或一个完整且字节一致的自有块；残缺、重复、移动或被编辑时必须 fail closed。
- 必须保留 SmartExpiry 等其他插件的全部字节。
- cleanup 不得删除共享锁文件。

## 验证与发布

发布前执行 PHPUnit、PHP/JS 语法检查、ZIP 内容和 Linux 权限检查、原生生命周期测试，以及 SmartExpiry 两种安装顺序测试。v1.3.2 让完整验证通过的已安装桥接直接返回，并跳过相同资源的重复复制；首次安装、升级、异常复核、状态和清理仍必须加锁。v5 标记、资源内容和 `1.3.1` 资源缓存参数保持原样，原有二维码图片功能也须通过检查。

配置版本、README 文件名、ZIP、Git tag 和 Release 标题必须一致；ZIP 必须包含明确的 `Qrcodeextend/` 根目录，且不得包含凭据或私有环境数据。
