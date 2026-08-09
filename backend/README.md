# PC 管理端（ThinkPHP 5 + Pear）

## 本地启动

```powershell
powershell -ExecutionPolicy Bypass -File ..\scripts\start-dev.ps1
```

- 浏览器：http://127.0.0.1:8088/
- 账号：`admin` / `123456`
- 数据库：本机 `127.0.0.1` / `team_maturity` / `root` / `123456`
- 配置：`application/database.php`（禁止指向旧远程库）

## phpstudy 站点（可选）

1. 新建网站，根目录指向 `backend/public`
2. 运行目录选 `public`
3. 本机域名如 `tm.local`，hosts 指向 `127.0.0.1`

## 技术约定

- `thinkphp/`、`vendor/`、`public/component/` 通过目录联接复用旧 bk 壳子；业务代码与库完全独立
- Session：文件存储（`tm_user`），不依赖 Redis
- 路由：`url_convert = false`，控制器名保持 `TmOrg` 等形式
- 公共响应：`DefaultResponse` / `DefaultResponseList`（`application/common.php`）

## 代码位置

```
application/index/
├── controller/     # Login, Index, TmOrg, TmTeam, TmMember, TmIndicator*,
│                   # TmLevel, TmMockExam, TmAdmin, TmRole, TmImport, TmLog,
│                   # TmBatch/TmResult/TmDashboard（占位）
├── view/           # tm_* / index / login
├── model/
└── service/        # ImportService, QuestionPickService, LevelJudgeService,
                    # ScoreService, OpLogService, ExcelHelper
```

## 实现状态

| 能力 | 状态 |
|------|------|
| 登录 / 菜单权限 / 工作台壳 | ✅ |
| 组织 / 班组 / 成员 + Excel 导入 | ✅ |
| 指标版本 / 题库浏览 / 标准题库数据 | ✅ |
| 等级阈值 / 赋能措施（一对四） | ✅ |
| 系统管理（管理员 / 角色 / 导入任务 / 日志） | ✅ |
| 模拟做题（个人算分 + 判级） | ✅ |
| 正式测评批次 / 结果落库 / 看板 / 小程序 | ❌ |

进度细节见仓库根目录 [`docs/交接文档.md`](../docs/交接文档.md)。
