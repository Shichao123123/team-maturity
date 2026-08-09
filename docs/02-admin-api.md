# PC 后台菜单与 API 清单

> 更新日期：2026-08-08  
> 状态图例：✅ 已实现 · 🔶 部分实现 · ❌ 未实现（占位）

约定：

- 模块前缀：`/Tm*`（ThinkPHP 控制器，如 `TmOrg`、`TmTeam`）
- 鉴权：Session `tm_user`（`Base` 基类）
- 列表响应：Layui 表格 `{ code, msg, count, data }`
- 普通响应：`{ code, success, msg, data }`（`DefaultResponse`）
- 重要写操作记 `tm_op_log`（登录、管理员、角色、导入、模拟做题等）

---

## 一、菜单结构（Pear 侧栏，已入库 `tm_power`）

```
班组成熟度
├── 工作台                  /Index/welcome                 ✅
├── 组织档案
│   ├── 组织架构            /TmOrg/index                   ✅
│   ├── 班组管理            /TmTeam/index                  ✅
│   └── 成员管理            /TmMember/index                ✅
├── 评价指标
│   ├── 指标版本            /TmIndicatorVersion/index      ✅
│   ├── 指标与题库          /TmIndicator/index             ✅（浏览为主）
│   └── 等级与赋能          /TmLevel/index                 ✅
├── 测评管理
│   ├── 测评批次            /TmBatch/index                 ❌
│   ├── 结果查询            /TmResult/index                ❌
│   └── 模拟做题            /TmMockExam/index              ✅
├── 统计分析
│   ├── 成熟度看板          /TmDashboard/index             ❌（工作台已有分布壳）
│   ├── 成长趋势            /TmDashboard/trend             ❌
│   └── 班组排行榜          /TmDashboard/rank              ❌
└── 系统管理
    ├── 管理员账号          /TmAdmin/index                 ✅
    ├── 角色权限            /TmRole/index                  ✅
    ├── 导入任务            /TmImport/index                ✅
    └── 操作日志            /TmLog/index                   ✅
```

权限表：`tm_power` / `tm_role_power`（非旧系统 `t_power`）。

---

## 二、API 清单

### 2.0 工作台 `Index` ✅

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/Index/welcome` | 工作台页面 |
| GET | `/Index/workbenchStats` | KPI + 等级分布 + 明细清单（有结果按最近批次；无则班组总数取档案） |
| GET | `/Index/menu` | Pear 侧栏菜单 JSON |

### 2.1 组织架构 `TmOrg` ✅

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmOrg/index` | 页面 |
| GET | `/TmOrg/getList` | 分页列表 |
| POST | `/TmOrg/add` / `edit` / `delete` | 增改软删 |
| GET | `/TmOrg/importTemplate` | 下载导入模板 |
| POST | `/TmOrg/import` | 批量导入 |

层级不强制 2/3 层，见 `docs/06-org-tree.md`。

### 2.2 班组 `TmTeam` ✅

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmTeam/getList` | 分页 |
| POST | `/TmTeam/add` / `edit` / `delete` | 增改软删 |
| GET/POST | `importTemplate` / `import` | Excel |

### 2.3 成员 `TmMember` ✅

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmMember/getList` | 分页；teamId、keyword、roleCode |
| POST | `/TmMember/add` / `edit` / `delete` | 增改软删；手机号唯一 |
| GET/POST | `importTemplate` / `import` | Excel |

**role_code：** `leader | deputy | safety | part_safety | member`

### 2.4 指标版本 `TmIndicatorVersion` 🔶

| 方法 | 路径 | 说明 | 状态 |
|------|------|------|------|
| GET | `/TmIndicatorVersion/index` | 列表页 + 查看题库 | ✅ |
| GET | `/TmIndicatorVersion/getList` | 版本列表 | ✅ |
| POST | add / edit / publish / copy | 草稿编辑与发布校验页 | ❌ 可选收尾 |

数据可由 `scripts/import-question-bank.php` 导入。

### 2.5 指标与题库 `TmIndicator` 🔶

| 方法 | 路径 | 说明 | 状态 |
|------|------|------|------|
| GET | `/TmIndicator/index` | 树 + 题目浏览 | ✅ |
| GET | `/TmIndicator/tree` | 指标树 | ✅ |
| GET | `/TmIndicator/questions` | 题目+选项（含措施摘要） | ✅ |
| POST | 在线增删改题目 | — | ❌（以导入脚本为主） |

### 2.6 等级与赋能 `TmLevel` ✅

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmLevel/thresholds` | 等级阈值列表 |
| POST | `/TmLevel/saveThresholds` | 批量保存（校验区间不重叠） |
| GET | `/TmLevel/measures` | **按三级指标聚合**：一指标一行含四等级内容 |
| POST | `/TmLevel/saveMeasure` | 新增/编辑单条措施 |
| POST | `/TmLevel/deleteMeasure` | 删除措施 |

约定：每指标 4 条（`passive/dependent/independent/essential`）；缺省内容为「暂无」。表：`tm_empower_measure`。

### 2.7 模拟做题 `TmMockExam` ✅

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmMockExam/index` | 页面 |
| POST | `/TmMockExam/start` | 按版本抽题写入 Session |
| GET | `/TmMockExam/paper` | 取卷（选项不含 score_ratio） |
| POST | `/TmMockExam/submit` | 交卷：个人算分 + 判级 + 薄弱指标措施 |
| POST | `/TmMockExam/clear` | 放弃清空 |

复用：`QuestionPickService`、`ScoreService`、`LevelJudgeService`。不写 `tm_batch` / `tm_paper`。

### 2.8 测评批次 `TmBatch` ❌

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmBatch/getList` | 批次列表 |
| GET | `/TmBatch/detail` | 详情+绑定班组+进度 |
| POST | `/TmBatch/add` / `edit` / `bindTeams` | 创建与绑班组 |
| POST | `/TmBatch/start` | 抽题写快照、进行中 |
| POST | `/TmBatch/finish` / `score` | 结束 / 触发算分 |
| GET | `/TmBatch/progress` | 进度 |

### 2.9 结果查询 `TmResult` ❌

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmResult/teamList` / `teamDetail` | 班组汇总与薄弱指标 |
| GET | `/TmResult/paperList` / `paperDetail` | 个人答卷 |
| GET | `/TmResult/exportTeam` / `exportRank` | 导出 |

### 2.10 看板 `TmDashboard` ❌

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmDashboard/levelDist` / `trend` / `rank` | 分布 / 趋势 / 排行 |
| POST | `/TmDashboard/refresh` | 清缓存 |

工作台 `/Index/workbenchStats` 已覆盖分布类 KPI 壳。

### 2.11 系统管理 ✅

#### 管理员 `TmAdmin`

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmAdmin/getList` | 分页 |
| POST | `/TmAdmin/save` | 新增/编辑 |
| POST | `/TmAdmin/delete` | 软删 |
| POST | `/TmAdmin/resetPassword` | 重置密码 |

#### 角色 `TmRole`

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmRole/getList` | 分页 |
| POST | `/TmRole/save` / `delete` | 增改删 |
| GET | `/TmRole/powerTree` | 权限树（含勾选） |
| POST | `/TmRole/savePowers` | 保存角色菜单（自动带父级） |

#### 导入任务 / 日志

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/TmImport/getList` / `detail` | 导入任务与失败明细 |
| GET | `/TmLog/getList` | 操作日志 |

---

## 三、关键业务规则

1. **停用班组**不可加入新批次；进行中批次已绑定的可继续答完。（批次未实现时暂无运行时校验）
2. **手机号**全局唯一；导入冲突返回行号错误。 ✅
3. **仅草稿版本**可改指标/题目；已发布只读，需 copy 后改。 🔶（发布流程 UI 未做）
4. **发布校验：** 一级 `weight` 合计 = 100；每个三级至少 1 题；每题至少 2 选项。
5. **批次开始**调用 `QuestionPickService::buildExamSet` → `tm_batch_indicator_snap`。详见 `docs/05-question-pool.md`。
6. **算分：** 个人 = Σ(weight×ratio)（`ScoreService` ✅）；班组 = AVG(已提交) ❌ 落库。
7. **判级：** 按 `tm_level_threshold`；当前种子：被动 `[0,70)`、依赖 `[70,80)`、自立 `[80,90]`、本质 `(90,100]`。
8. **模拟做题**不落批次；正式答卷提交后 `tm_paper.status=1` 锁定（待批次实现）。

---

## 四、与旧系统复用点

| 复用 | 说明 |
|------|------|
| 登录 / Session 模式 | `Login` + `Base`（Session 键为 `tm_user`） |
| Layui/Pear 页面骨架 | view 目录 `tm_*` |
| Excel | PHPExcel（`ExcelHelper`） |
| thinkphp / vendor / component | 目录联接旧 bk，业务库独立 |

不复用：旧 `Project/Assess/Question/Result/Mini` 业务与 Doscore；不写远程 `qa` 库。
