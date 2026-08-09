# 第三步：小程序接口契约

> 更新日期：2026-08-09  
> **实现状态：未编码。** `miniprogram/` 为空目录。PC 侧可用「模拟做题」联调算分规则。  
> **产品口径（已确认）：不必事先导入人员。** 流程为：扫码进入批次 → 选择单位 → 填写姓名 → 做题 → **提交后同步人员与答卷入库**。  
> ~~原「手机号绑定已建档成员」不再作为主路径~~（若保留仅作可选兼容，本期可不实现）。

约定：

- 基础路径：`/TmMini/*`、`/TmMinilogin/*`
- 鉴权：Header `token`（JWT HS256）；密钥走配置/环境变量，**禁止再用 `qa`**
- 统一响应：

```json
{ "code": 200, "success": true, "msg": "ok", "data": {} }
```

- 业务错误码建议：
  - `200` 成功
  - `201` 参数/业务失败（msg 说明）
  - `202` 已提交/已锁定（可带只读 data）
  - `401` 未登录/token 无效
  - `403` 班组不在批次 / 批次不可答 / 无权限

---

## 一、登录与现场身份

### 1.1 微信登录（可选）

`POST /TmMinilogin/login`

**入参：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| code | string | 是 | wx.login code |

**出参 data：**

```json
{
  "token": "jwt...",
  "user": {
    "id": 1,
    "nickname": "",
    "avatar_url": ""
  }
}
```

说明：登录只建立微信会话，**不要求已有成员档案**。

### 1.2 进入批次并确认现场身份（主路径）

建议在扫码落地页调用（需批次进行中，且所选班组 ∈ 批次班组）：

`POST /TmMini/enterBatch`（需登录）

**入参：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| batch_id | int | 是 | 测评批次 |
| team_id | int | 是 | 所选班组（单位） |
| name | string | 是 | 作答人姓名 |

**规则：**

1. 校验批次可答、班组在批次内且启用  
2. **此时可不落库成员**，也可创建草稿答卷会话（Session/JWT 声明：`batch_id/team_id/name`）  
3. 真正写入 `tm_member` + `tm_paper` 可在**首次保存答卷或提交时**完成（事后同步）  
4. 同一微信 + 同一批次建议只保留一份进行中答卷  

**出参 data（示例）：**

```json
{
  "paper_token": "会话令牌或 paper 草稿 id",
  "batch_id": 1,
  "team_id": 3,
  "team_name": "甲班",
  "name": "张三",
  "org_path": "XX公司 / 炼铁厂 / 高炉工段"
}
```

### 1.3 组织/班组选项（供选择单位）

`GET /TmMini/batchTeams?batch_id=`

返回本批次可参评班组列表（含组织路径），供扫码后下拉选择。

### 1.4 我的信息

`GET /TmMini/profile`

**出参：** 微信用户 + 最近作答身份（若已同步成员则带 member/team；否则仅会话中的 name/team）。

### 1.5 （废弃）手机号绑定成员

`POST /TmMini/bindMobile` — **本期不作为主路径**，可不实现。

---

## 二、测评批次

### 2.1 我的可参与批次 / 扫码直达

`GET /TmMini/myBatches`（可选列表）或扫码直接带 `batch_id` 进入。

**规则：** 批次 status=进行中（或未开始但已到 start_time）；**不要求预绑定成员**。进入后走 1.2 选单位填姓名。

**出参 data：** 数组

```json
[
  {
    "batch_id": 1,
    "name": "2026年一季度班组测评",
    "start_time": "2026-03-01 00:00:00",
    "end_time": "2026-03-31 23:59:59",
    "status": 1,
    "paper_status": 0,
    "paper_status_text": "未开始/答题中/已提交",
    "personal_score": null,
    "team_score": null,
    "level_code": null,
    "level_name": null
  }
]
```

`paper_status`：`0` 无答卷或答题中，`1` 已提交。

### 2.2 批次详情（结果页共用）

`GET /TmMini/batchDetail?batch_id=1`

返回批次信息 + 本人 paper + 班组结果（若已算分）+ 等级特征 + 赋能措施列表。

---

## 三、答题

### 3.1 开始/继续答题（拉题）

`POST /TmMini/startPaper`

**入参：** `{ "batch_id": 1, "team_id": 3, "name": "张三" }`（或带 `paper_token` / 会话声明）

**规则：**

1. 校验批次进行中、班组在批次内；**不要求预导成员档案**
2. 若该微信+批次已有已提交 `tm_paper` → `202` + 只读题目与作答
3. 若无进行中 paper → 创建草稿会话/答卷，题目来自 **批次快照**（无快照则拒绝并提示管理员）
4. **成员落库**可延后到提交：提交时按 `team_id + name`（及可选 openid）写入/匹配 `tm_member` 并关联 `tm_paper`
5. 返回题目列表（含已选 option_id）

**出参 data：**

```json
{
  "paper_id": 100,
  "batch_id": 1,
  "status": 0,
  "total": 40,
  "answered": 12,
  "questions": [
    {
      "question_id": 1001,
      "indicator_id": 301,
      "indicator_name": "安全意识",
      "title": "题干...",
      "remark": "",
      "sort_no": 1,
      "selected_option_id": 5001,
      "options": [
        { "option_id": 5001, "content": "完全符合", "sort_no": 1 },
        { "option_id": 5002, "content": "基本符合", "sort_no": 2 },
        { "option_id": 5003, "content": "部分符合", "sort_no": 3 },
        { "option_id": 5004, "content": "不符合", "sort_no": 4 }
      ]
    }
  ]
}
```

**安全：** 不下发 `weight` / `score_ratio` 给前端（防刷分）；得分仅服务端计算。

### 3.2 保存单题

`POST /TmMini/answer`

**入参：**

```json
{ "paper_id": 100, "question_id": 1001, "option_id": 5002 }
```

**规则：**

1. paper 属于当前用户且 status=0
2. question/option 必须属于该批次快照
3. upsert `tm_paper_answer`（固化 weight、score_ratio、item_score）
4. 返回 `{ answered, total }`

**错误：** 已提交 → `202`；选项非法 → `201`

### 3.3 提交答卷

`POST /TmMini/submit`

**入参：** `{ "paper_id": 100 }`

**规则：**

1. 校验全部题目已答（快照题目数 = 明细数）
2. 事务内：
   - 计算 `personal_score = SUM(item_score)`
   - `paper.status=1, submitted_at=now`
   - `tm_batch_team.submitted_cnt + 1`（注意幂等）
3. 返回个人得分；若批次已整体算分则附带班组结果，否则班组分为 null，文案「班组结果将在测评结束后公布」

**幂等：** 重复提交 → `202`，返回已有结果。

---

## 四、结果与赋能

### 4.1 我的测评结果

`GET /TmMini/myResult?batch_id=1`

```json
{
  "personal_score": 86.5,
  "submitted_at": "2026-03-10 12:00:00",
  "team": {
    "team_id": 3,
    "team_name": "甲班",
    "team_score": 84.2,
    "level_code": "independent",
    "level_name": "自立型",
    "feature_desc": "...",
    "submit_cnt": 12,
    "scored": true
  },
  "measures": [
    { "title": "深化风险预控", "content": "..." }
  ],
  "weak_indicators": [
    { "indicator_id": 301, "name": "安全意识", "avg_score": 62.0 }
  ]
}
```

`scored=false` 时只展示个人分与「等待班组算分」。

---

## 五、JWT Payload 建议

```json
{
  "uid": 1,
  "batch_id": 1,
  "team_id": 3,
  "name": "张三",
  "member_id": null,
  "exp": 1710000000
}
```

- 进入批次确认身份后，可把 `batch_id/team_id/name` 写入 token 或 paper_token  
- `member_id` 可在提交同步后回写  
- 过期时间建议按测评场次设置（如数小时～7 天）

---

## 六、前端页面流（现行）

```
启动 → login（可选建会话）
  → 扫码进入批次（batch_id）
  → 选择单位（batchTeams）
  → 填写姓名（enterBatch）
  → 开始答题（startPaper）
  → 逐题 answer
  → submit（同步成员 + 答卷锁定）
  → 结果页 myResult
```

---

## 七、与旧 Mini API 对照（勿混用）

| 旧接口 | 新接口 | 差异 |
|--------|--------|------|
| `/Minilogin/login` | `/TmMinilogin/login` | 仅建微信会话，不强制预绑成员 |
| 无 | `/TmMini/enterBatch` + `/TmMini/batchTeams` | 选单位 + 填姓名（主路径） |
| `/TmMini/bindMobile` | （废弃/可选） | 不再作为主路径 |
| `/Mini/getMyQuestion` JSON 整包 | `/TmMini/startPaper` + 明细表 | 快照拉题 |
| `/Mini/doAnswer` | `/TmMini/answer` | 行级 upsert |
| `/Mini/endAnswer` | `/TmMini/submit` | 校验全答 + 锁定 + **人员同步入库** |

旧接口保持不动，新旧小程序配置不同 base API 即可。

---

## 八、联调检查清单

- [ ] 未预导人员也能扫码选单位填姓名后答题
- [ ] 所选班组不在批次内时拒绝
- [ ] 姓名为空时拒绝
- [ ] 提交后人员与答卷已写入库
- [ ] 未答完不能提交
- [ ] 提交后不能改答
- [ ] 前端拿不到权重/比例
- [ ] 批次无快照时 startPaper 失败且可理解
- [ ] token 伪造/过期返回 401
