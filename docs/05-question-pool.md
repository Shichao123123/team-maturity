# 题库题池与开测抽题（预留设计）

> 更新日期：2026-08-08  
> **实现状态：** `QuestionPickService` 已落地；**模拟做题**已调用抽题；正式批次 `start` 写快照尚未做。界面不展示「题池」字样。

## 目标

- **现在**：三级指标 ≈ 1 道题（标准套卷，V1.0.0 为 1:1）
- **以后**：同一三级指标可挂多道题（题池），开测时 **每个三级只抽 1 道** 进入答卷

## 数据约定

| 层 | 说明 |
|----|------|
| `tm_question.indicator_id` | 允许多行指向同一三级指标 = 题池 |
| `tm_indicator_version.question_pick_mode` | `first` / `random` / `sequential` |
| `tm_batch.question_pick_mode` | 可选覆盖版本策略；空则跟随版本 |
| `tm_batch_indicator_snap.snap_json` | **只存抽中的那一题**（含选项与权重），不存整池 |

## 开测流程（批次 start）

1. 读取已发布 `version_id`
2. 调用 `QuestionPickService::buildExamSet($versionId, $mode)`
3. 对每个启用三级指标：取启用题目列表 → 按策略抽 1 道 → 带上选项
4. 写入 `tm_batch_indicator_snap`
5. 小程序/答卷 **只读快照**，与题库后续改动隔离

## 算分

- 个人分 = Σ（快照中该题三级 `weight` × 所选 `score_ratio`）
- 与题池大小无关；未抽中的题不参与本次测评

## 代码入口

- `backend/application/index/service/QuestionPickService.php`
- 发布校验：`validateVersionQuestions`（每三级 ≥ 1 题即可，允许多题）
- 已调用方：`TmMockExam::start`（Session 试卷）
- 待调用方：`TmBatch::start` → 写 `tm_batch_indicator_snap`

## 后期改造清单（有需要时再做）

1. 正式批次 start 写快照（当前最优先链路的一环）
2. 题库页：同一三级下「新增题目 / 题池列表」
3. 版本编辑：抽题策略下拉
4. 批次创建：可选覆盖策略
5. 若要「每人抽题不同」：改为答卷创建时抽题（需另议公平性与快照粒度）

当前默认：**批次级统一抽题**（全员同一套抽中题目），实现简单、结果可比。
