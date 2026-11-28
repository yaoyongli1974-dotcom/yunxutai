# Phase 3 — AI 事实来源、人工审核与内容可信度报告

基线：Phase 2 提交 `ff997ae`。本阶段只改 AI 事实可信度链路，不改业务事实语义、不删测试、不降 PHPStan。

## 阶段名称
AI 事实来源、人工审核与内容可信度（任务书 Phase 3）。

## 修改文件
- `database/migrations/20261128_008_ai_fact_credibility.php`（新增）
- `app/Services/AiWorkflowService.php`
- `app/Views/ai/index.php`、`app/Views/ai/show.php`
- `miniprogram/pages/ai/workflow.js`
- `tests/run.php`

## 修改函数 / 数据库
- 新增迁移 008：`memory_facts` 加 `fact_kind`（user_provided/family_verified/archive_verified/public_source/ai_inferred/unknown）、`workflow_id`、`review_status`（pending/approved/rejected/needs_revision）、`review_note`、`version` 与索引；新建 `ai_fact_revisions` 保存审核前内容/状态/版本/审核人。
- `addFactCandidate()`：追加第 8 个可选参数 `?int $workflowId`；校验 source archive/version/workflow 与家族归属；按 source_type 映射 fact_kind（AI 提取默认 `ai_inferred`）；写 `source_location`、`review_status=pending`，置信度仍 <1。
- `reviewFact()`：支持 confirmed/approved/disputed/needs_revision/rejected/pending 到 (verification_status, review_status) 的映射；consent 已撤回的工作流拒绝审核；同事务 CAS 更新并写 revision（version+1）；保留 confirmed→rejected 与 rejected→confirmed 的限制。
- 新增 `syncWorkflowReview()`：同工作流无待审核事实时，把 `awaiting_review` 步骤推进到 approved/rejected，并按枚举兼容把 generation log 写为 confirmed/rejected。
- `facts()`：过滤 consent 已撤回工作流的事实，LIMIT 250。

## API / 前端变化
本次未新增 API 字段；Web/小程序展示 `fact_kind`/`review_status` 中文标签，并明确标注「AI 候选（非已核实事实）」。

## 安全影响
- AI 推测不能自动成为确认事实；人工审核后才可确认。
- 审核版本与意见可追溯；普通用户无法改审核记录；consent 撤回后禁止审核与查询相关事实。
- 来源档案/版本归属校验防止跨家族引用。

## 测试
`bash bin/test.sh` rc=0，**157 通过 / 0 失败**（Phase 2 的 154 + 本阶段 3）；新增 `P3：AI 事实默认标记为 AI 推测并写入来源位置`、`P3：人工审核事实写入版本、意见与修订历史`、`P3：事实审核完成后联动工作流审核步骤与生成日志`。
`php -l tests/run.php` rc=0；`vendor/bin/phpstan analyse --no-progress` `[OK] No errors` rc=0；secret-scan rc=0；迁移 35 版本（含 20261128_008）全 applied；doctor rc=0（0 阻断，4 条环境警告）；`git diff --check` rc=0。
日志：`/tmp/opencode/v2.1.1/phase3/integration.log`。

## 未解决问题
- Phase 4 将处理原始/处理后文件双哈希与处理溯源。
- 用户提供的 `user_provided` 事实尚未接入前端显式录入流程（当前仅通过 service 写入）。
- `ai_generation_logs.review_status` 枚举与步骤 `approved` 语义不同，已做映射但后续可评估统一。

## 下一阶段
Phase 4：媒体原始哈希、处理后哈希、文件处理记录与上传安全复查。
