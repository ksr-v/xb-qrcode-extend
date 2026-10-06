# Xboard 二维码扩展

为 Xboard 后台用户管理增加“生成订阅二维码”操作。二维码在浏览器本地生成，使用当前用户已有的订阅地址，不保存或上传订阅内容。

## 安装

1. 从 [GitHub Releases](https://github.com/ksr-v/xb-qrcode-extend/releases) 下载 `qrcodeextend-1.0.6.zip`。
2. 登录 Xboard 后台，进入“插件管理”，上传 ZIP 并安装“QRCode Extend”。安装钩子会校验 Xboard 核心文件版本、备份原文件、部署随包提供的核心集成、修补受支持的 Admin bundle、清理 Laravel 缓存并启用插件。
3. 刷新后台，在“用户管理”的用户行“操作”菜单中使用“生成订阅二维码”。

插件安装接口需要在后台选择并上传 ZIP。完成后不需要手动覆盖核心文件，也不需要运行 Artisan 命令。安装钩子会尝试重载 Octane；如果主机不支持自动重载，请在控制面板重启该站点的 Octane 服务。

### 权限准备

只有遇到 `Permission denied`，且错误指向 `public/assets/admin/assets/` 时，才需要检查该目录权限。补丁器需要在 Admin 静态资源目录创建临时文件并替换 bundle。请通过 SSH 连接服务器，以 root 身份或具备相应 sudo 权限的账号执行以下示例：

```bash
cd /www/wwwroot/example.com
chown www:www public/assets/admin/assets
chmod 755 public/assets/admin/assets
su -s /bin/sh www -c 'touch /www/wwwroot/example.com/public/assets/admin/assets/.qrcodeextend-test && rm -f /www/wwwroot/example.com/public/assets/admin/assets/.qrcodeextend-test' && echo "权限验证通过：目录可写，可以安装 qrcodeextend 插件"
```

示例使用站点根目录 `/www/wwwroot/example.com` 和 PHP/Octane 运行用户 `www`。请将 `example.com` 替换为实际站点目录，并按面板确认 PHP 运行用户；如果 PHP 用户不是 `www`，`chown` 和 `su` 中的用户/组也必须相应替换。不要将目录权限设置为 `777`。验证成功后回到插件管理重试安装。

## 安全与恢复

安装前会检查全部核心文件，只接受受支持的原版文件或插件已部署的精确版本。遇到未知或已自定义修改的文件时会停止，不会强制覆盖。原始核心文件备份保存在站点 `storage/qrcodeextend/backups/` 目录，不位于公开目录。

如需通过命令恢复 Admin JS 桥接，请先 SSH 登录服务器并进入 Xboard 网站根目录，执行：

```bash
php artisan qrcodeextend:restore
```

命令会校验当前 Admin bundle，并在安全条件满足时恢复原始 Admin JS；遇到无法识别的文件或版本变化时会拒绝覆盖并保留备份。该命令只恢复 Admin 桥接，不会卸载插件或恢复核心 overlay 文件。

如需完整移除插件并恢复 Admin bundle 与核心文件，请在 Xboard 后台进入“插件管理”，先禁用再卸载“QRCode Extend”。卸载钩子会校验备份后尝试恢复；如果文件在安装后又被其他更新修改，恢复操作会拒绝覆盖。保留 `storage/qrcodeextend/backups/` 中的备份以便人工检查。

Admin bundle 补丁按明确版本和文件哈希锁定。升级 Xboard Admin 前，请先通过插件命令恢复桥接并确认恢复成功，再更新 Admin。

## 数据处理

二维码内容来自所选用户已有的 `subscribe_url`，并附加与前台扫码订阅一致的 `types` 参数。二维码在本地生成，不持久化订阅数据，也不调用第三方二维码服务。
