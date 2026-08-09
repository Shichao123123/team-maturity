# 班组成熟度测评 · 微信小程序

## 已配好的本机联调（当前）

| 项 | 值 |
|----|-----|
| AppID | `wxc23577d86aaf957c` |
| 后端 | `http://127.0.0.1:8088`（已监听 `0.0.0.0`，真机可用 `http://192.168.1.3:8088`） |
| 登录 | `config.local.php` 中 `dev_login=true`（不调微信，开发者工具可直接用） |
| 进行中批次示例 | `batch_id=42`（以库中实际为准） |

上线前务必将 `backend/application/config.local.php` 里的 **`dev_login` 改为 `false`**。

## 打开工程

1. 打开微信开发者工具 → 导入目录：`team-maturity/miniprogram`
2. AppID 选「使用 `wxc23577d86aaf957c`」
3. 详情 → 本地设置 → **勾选「不校验合法域名…」**
4. 若后端未开，在项目根执行：
   ```powershell
   powershell -File scripts/start-mini-api.ps1
   ```

## 调试进场页

开发者工具 → 编译模式 → 添加：

- 启动页面：`pages/exam/index`
- 启动参数：`batch_id=42`

流程：选班组 → 填姓名 → 答题 → 提交 → 看结果。

## 真机预览

1. 手机与电脑同一 WiFi  
2. 改 `config.js`：
   ```js
   baseUrl: 'http://192.168.1.3:8088'
   ```
3. 后端须用 `scripts/start-mini-api.ps1`（监听 `0.0.0.0`）

## 页面

| 路径 | 说明 |
|------|------|
| `pages/index/index` | 我的测评 |
| `pages/exam/index?batch_id=` | 扫码进场（PC 二维码默认此路径） |
| `pages/paper/index` | 答题 |
| `pages/result/index?batch_id=` | 结果 |
