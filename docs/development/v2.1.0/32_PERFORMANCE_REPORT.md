# Phase D — 性能报告

基线：`cbc4042`；分支：`feature/v2.1.0-production-hardening`。
标注：**CONFIRMED**（代码/`EXPLAIN` 证据）/ **NEEDS_VERIFICATION**（需真实量级数据）。

## 1. 已确认瓶颈与修复

| 编号 | 位置 | 问题（证据） | 修复 | 结果 |
|---|---|---|---|---|
| P-01 | `app/Controllers/FamilyPersonController.php:34-44` | 人物列表无 `LIMIT`，逐行 `FamilyPersonService::getVisible()` 造成约 3N+1 查询 | `LIMIT 200`；新增 `FamilyPersonService::visibleRows()`：一次 `IN(...)` 家族查询 + 按家族缓存 `Policy::familyRole()`，照搬 `canViewResource()` 人物分支规则 | CONFIRMED |
| P-02 | `app/Api/Controllers/FamilyV2Controller.php:139-149` | 200 行仍逐行 `getVisible()`（约 600 查询） | 复用 `visibleRows()`；SELECT 暂加 `is_deceased` 供过滤，输出前 `unset()` 保持 API 响应形状 | CONFIRMED |
| P-03 | `app/Services/GenealogyService.php:166-174` | 关系全量拉取 + 每行 2 次 `canViewResource()` | `ORDER BY r.id LIMIT 500`；过滤语义不变 | CONFIRMED |
| P-04 | `app/Controllers/ArchiveController.php:91-113` | 人物循环无上限；`ArchiveService::files()` 调用两次 | 人物循环 `LIMIT 200`；`files()` 只调一次并复用 | CONFIRMED |
| P-05 | `app/Services/FamilyService.php:345`、`app/Models/Memorial.php:101` | `forUser()` 无 `LIMIT` | 分别 `LIMIT 200`；排序明确 | CONFIRMED |
| P-06 | `app/Services/AiWorkflowService.php:221-222` | `recoverExpired()` 中重复查 `max_retries` | 合并为一次查询 | CONFIRMED |

## 2. 索引

新增迁移 `database/migrations/20261128_005_performance_indexes.php`（CONFIRMED）：

- `family_persons`：`idx_person_family_deleted_id (family_id, deleted_at, id)` — 覆盖人物列表的 `WHERE family_id=? AND deleted_at IS NULL ORDER BY id LIMIT n`。
- `person_relationships`：`idx_rel_family_id (family_id, id)` — 覆盖谱系关系的 `WHERE family_id=? ORDER BY id LIMIT n`。

未添加其他推测性索引（档案列表、通知、公开纪念馆列表已有可用前缀或受 `LIMIT` 约束；需真实数据量下 `EXPLAIN` 后再定）。

**EXPLAIN 证据**（测试库，`/tmp/opencode/phase-d/explain.log`）：
- `family_persons` 查询命中 `idx_person_family_deleted_id`（`Using where; Using index`，无 `filesort`）。
- `person_relationships` 小数据集下优化器选 `PRIMARY`；`FORCE INDEX(idx_rel_family_id)` 时确认该索引可用（`Using where; Using index`）。

## 3. 迁移与门禁

- `DB_DATABASE=jidian_test php bin/migrate-v2.php --status` rc=0：32 个版本（含 `20261128_005`）全部 `applied`；重复执行 rc=0。
- `bash bin/test.sh` rc=0：143 通过 / 0 失败，含 `D：性能索引迁移已应用`。
- PHP lint / PHPStan level 5 / composer validate --strict / secret-scan / node --check / `git diff --check`：均 rc=0。

## 4. 未做与需验证

- **NEEDS_VERIFICATION**：未生成任务书建议的 1000 人物 / 10000 关系 / 5000 档案 / 10000 通知 / 1000 工作流数据集；响应时间、内存与慢查询基线未量化。新索引在真实量级下的选择率需再次 `EXPLAIN`。
- **NEEDS_VERIFICATION**：谱系前端仍为服务端截断 + 200 节点渲染，无真正按需加载/虚拟化；`genealogy.js` 未改。
- **OPEN**：Web/API 家族列表仍为固定上限而非完整分页（改分页会变更响应/视图契约，留作后续版本决策）。

## 5. 结论

CONFIRMED：已消除人物/关系/档案详情的 N+1 与无界列表，新增两条经 `EXPLAIN` 验证的索引，所有质量门禁通过。
NEEDS_VERIFICATION：大规模性能基线与前端增量渲染需在具备生产规模数据集的环境补测。
