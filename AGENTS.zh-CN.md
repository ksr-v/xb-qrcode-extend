# Agent 交接说明

本仓库发布独立 Xboard 插件 `qrcodeextend`，当前发布目标为 `v1.3.1`。

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

发布前执行 PHPUnit、PHP/JS 语法检查、ZIP 内容和 Linux 权限检查、原生生命周期测试，以及 SmartExpiry 两种安装顺序测试。v1.3.1 将保存链接改为醒目的触屏按钮，更新资源缓存版本，并精确迁移 v3/v4 自有桥接到 v5，发布前必须覆盖这些路径。

配置版本、README 文件名、ZIP、Git tag 和 Release 标题必须一致；ZIP 必须包含明确的 `Qrcodeextend/` 根目录，且不得包含凭据或私有环境数据。
