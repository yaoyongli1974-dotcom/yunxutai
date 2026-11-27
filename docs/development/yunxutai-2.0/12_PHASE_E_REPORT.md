# 阶段 E 交付报告：AI 家族记忆工坊

## 结论
已完成。新链路（`ai_workflows` + `AiWorkflowService`）与旧 `ai_jobs` / `AiStudioService` / `/manage/memorials/{id}/ai` 完全隔离，旧功能与旧测试未改动。所有 AI 输出均为草稿或候选事实，未经人工审核不会成为正式事实。

## 新增文件
- `database/migrations/20261127_017_create_ai_workflows.php` ~ `021_create_ai_generation_logs.php`（5 个迁移，6 条建表语句；`schema.sql` 同步）
- `app/Services/AiWorkflowService.php`、`app/Controllers/AiWorkshopController.php`
- `app/Views/ai/index.php`、`app/Views/ai/show.php`
- `bin/ai-workflow-worker.php`
- 本报告

## 修改文件
- `database/schema.sql`：追加阶段 E 表结构（与迁移同源）
- `app/routes.php`：新增 10 条 `/families/{id}/ai/...` 与 `/ai/facts|segments/...` 路由，均带 auth
- `tests/run.php`：新增 5 项阶段 E 测试

## 数据库变化
- `ai_workflows`、`ai_workflow_steps`、`memory_facts`、`oral_history_sessions`、`oral_history_segments`、`ai_generation_logs`，共 6 张新表，无旧表删除/改名。
- 测试库执行两次迁移：全部 applied / 重复执行跳过；生产库未动。

## 关键设计
- 任务幂等：`(family_id, workflow_type, idempotency_key)` 唯一。
- 原子领取：条件 UPDATE 成功才算领取；`lease_until` 15 分钟，超时回收为 `lease_expired` 并按 2^n 秒退避重排；`max_retries=3`，输入/授权错误不重试。
- 取消：创建者或 owner/admin，仅未结束任务可取消。
- 审核：AI 生成事实一律 `pending`；仅 `ai.result.review`（owner/admin）可确认/标记争议。
- 口述历史：未授权不可录入片段；授权撤回后不可复核或发起转写任务。
- 来源追踪：`ai_generation_logs` 记录 provider/model/prompt_version/输入键，不记录资料正文与密钥；`memory_facts` 保留 source_type/source_id/confidence。
- AI Provider：统一走 `AiClientFactory`；demo provider 用本地排版模板，只重组原文，不生成新事实。

## 测试结果
- `bash bin/test.sh`：97 通过 / 0 失败 / 0 跳过（阶段 D 的 92 项全部保留）。
- `php bin/doctor.php`：21 通过 / 4 环境警告 / 0 阻断。
- 全量 PHP lint：0 错误。

## 未决事项
- 生产库迁移仍需用户授权与备份/演练；本阶段未连接生产。
- 图片 OCR 与音频转写的外部 Provider 尚未接入（接口、任务链与人工校正流程已就绪）；当前只处理文本资料。
- 展馆、时间轴、通知中心（F）与 API/小程序（G）未实施。
- 谱系图谱拖动/缩放仍为列表式按需加载（C 阶段未决）。
