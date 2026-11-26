# 班组安全成熟度测评系统

面向企业班组安全管理的测评与改进平台。系统以 **PC 管理端 + 微信小程序** 为双端入口，覆盖组织建模、题库配置、测评批次、自动算分、成熟度看板和现场答题闭环。

## 怎么启动？

完整步骤（PC + 小程序 + 真机 + 排错）见：

**→ [`docs/启动说明.md`](docs/启动说明.md)**

快速摘要：

```powershell
# 1. 启动后端（推荐：PC 和小程序都能用）
cd d:\phpstudy_pro\WWW\team-maturity
powershell -ExecutionPolicy Bypass -File scripts\start-mini-api.ps1

# 浏览器打开 PC：http://127.0.0.1:8088/   账号 admin / 123456
# 微信开发者工具导入：miniprogram/   AppID wxc23577d86aaf957c
# 编译模式：pages/exam/index   参数 batch_id=进行中批次ID
```

| 项 | 值 |
|----|-----|
| PC 地址 | http://127.0.0.1:8088/ |
| 库 | `team_maturity` @ `127.0.0.1` / `root` / `123456` |
| 管理员 | `admin` / `123456` |
| 小程序目录 | `miniprogram/` |
| 小程序说明 | [`miniprogram/README.md`](miniprogram/README.md) |

## 目录

| 路径 | 说明 |
|------|------|
| `sql/` | 建库与增量脚本 |
| `docs/` | 需求、API、启动与交接文档 → [`docs/README.md`](docs/README.md) |
| `backend/` | ThinkPHP 5 PC 后台 + 小程序 API |
| `miniprogram/` | 微信小程序（进场 / 答题 / 结果） |
| `scripts/` | 启动、样例导入、联调脚本等 |

## 常用脚本

```powershell
# 仅本机 127.0.0.1（旧方式，真机连不上）
powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1

# 全网卡 0.0.0.0（小程序真机 / 开发者工具推荐）
powershell -ExecutionPolicy Bypass -File scripts\start-mini-api.ps1

# 小程序答卷 API 联调（不依赖微信）
php scripts\test-mini-paper.php
```

## 更多文档

- 启动：[`docs/启动说明.md`](docs/启动说明.md)
- 交接进度：[`docs/交接说明-开发进度与待办.md`](docs/交接说明-开发进度与待办.md)
- 小程序 API：[`docs/03-mini-api.md`](docs/03-mini-api.md)
