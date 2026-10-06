# Xboard 二维码扩展

为 Xboard 后台用户管理增加“生成订阅二维码”操作。二维码完全在浏览器本地生成，不保存或上传订阅内容。

## 安装

1. 从 [GitHub Releases](https://github.com/ksr-v/xb-qrcode-extend/releases) 下载 `qrcodeextend-1.2.0.zip`。
2. 在 Xboard 后台“插件管理”中上传 ZIP、安装并启用 **QRCode Extend**。
3. 刷新后台，在用户管理的操作菜单中使用“生成订阅二维码”。

插件使用 Xboard 原生生命周期，不再覆盖 `AbstractPlugin.php`、`PluginManager.php`、Console Kernel 或 `admin.blade.php`，也不包含 core overlay。

## 安全与兼容

- 只向受支持的 Admin bundle 唯一锚点插入带 `qrcodeextend:start:v3` / `end:v3` 标记的最小桥接。
- patch 和清理的完整事务使用共享锁 `storage/framework/xboard-admin-patch.lock`。
- 禁用或卸载只删除 QRCodeExtend 自己的片段，SmartExpiry 等其他插件的修改保持不变。
- 重复启用不会重复注入；禁用会移除桥接与自身资源；重新启用会重新发布资源并恢复桥接。
- 卸载后删除 `public/plugins/qrcodeextend/`、`storage/qrcodeextend/` 和插件数据库记录，并精确恢复安装前 Admin bundle。
- 共享锁文件属于所有 Admin patch 插件，不包含插件数据，因此不会在卸载时删除。

当前支持 manifest 入口 `assets/index-CEIYH7i8.js`。未知 Admin 构建、缺失或重复锚点、残缺/重复/被改写的 ownership marker 均会安全拒绝。

## 命令

插件启用期间可以使用：

```bash
php artisan qrcodeextend:status
php artisan qrcodeextend:patch
php artisan qrcodeextend:restore
```

`restore` 只移除 QRCodeExtend 自己的桥接片段，不会用完整备份覆盖当前 bundle。

## 数据处理

二维码内容来自所选用户已有的 `subscribe_url`，并附加与前台一致的 `types` 参数。二维码由随包提供的本地库生成，不调用第三方二维码服务。

二维码最终输出为 PNG 图片；手机端可以长按二维码使用浏览器或系统提供的“保存图片”操作。

更详细的生命周期、ownership 规则和已知限制参见 [插件 README](Qrcodeextend/README.md)。
