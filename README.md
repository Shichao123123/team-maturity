# 班组安全成熟度测评系统

独立代码仓，与旧「企业安全发展指数」项目（`qa-dev/bk-code-repository`）隔离。

**PC 管理端是核心**（组织 / 题库 / 批次 / 算分 / 看板 / 权限）；小程序仅扫码答题（未建）。

## 目录

| 路径 | 说明 |
|------|------|
| `sql/` | 建库与增量脚本（含 PC 管理员 / 菜单） |
| `docs/` | 需求、API、架构、交接文档 → 见 [`docs/README.md`](docs/README.md) |
| `backend/` | ThinkPHP 5 PC 后台（业务代码） |
| `miniprogram/` | 微信小程序（空，待建） |
| `scripts/` | 启动、样例导入、题库导入等 |

## 本地快速启动

```powershell
powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1
```

| 项 | 值 |
|----|-----|
| 地址 | http://127.0.0.1:8088/ |
| 库 | `team_maturity` @ `127.0.0.1` / `root` / `123456` |
| 账号 | `admin` / `123456` |
| PHP | `D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe` |

## 功能进度（摘要）

**已完成：** 登录与 RBAC、工作台壳、组织/班组/成员（含 Excel 导入）、指标版本与题库浏览、标准题库入库（40 题）、等级阈值与赋能措施（一指标四等级）、系统管理（管理员/角色/导入任务/操作日志）、**模拟做题**（个人算分+判级）。

**未完成：** 正式测评批次、班组算分落库、结果查询页、统计分析看板深化、小程序。

详情与接手清单见 **[`docs/交接文档.md`](docs/交接文档.md)**。

## 常用脚本

```powershell
# 组织/班组/成员样例
php scripts\seed-import.php

# 标准题库导入（含措施补齐规则）
php scripts\import-question-bank.php

# 按指标补齐四等级措施（缺省「暂无」）
php scripts\fill-indicator-measures.php
```

## 参考旧系统

`D:\phpstudy_pro\WWW\qa-dev\bk-code-repository`（仅参考 ThinkPHP + Pear 壳与登录模式，**禁止**写其远程 `qa` 库）。
