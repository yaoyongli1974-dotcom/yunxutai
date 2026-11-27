# Phase 3 交付报告：口述历史闭环与 AI 工作流资源授权

状态：本地实现完成，未推送、未部署。全量测试 122 通过 / 0 失败（Round2 Phase 1/2/3 全部保留）。

## 修改文件
- `app/Services/AiWorkflowService.php`：新增 `getVisible()`、`visibleSteps()`、`familyVisible()`、`sessionAudio()`、`transcribeSession()`、`revokeSessionConsent()`、`addSegmentChecked()`、`correctSegment()`、`exportSessionTranscript()`；强化 `addFactCandidate()` / `reviewFact()` 家族校验与状态机。
- `app/Api/Controllers/FamilyV2Controller.php`：`workflow()` 改用资源级授权并最小化输出；新增口述历史会话/分段/授权/转写/导出接口。
- `app/routes-api.php`：新增 9 条口述历史相关 API。
- `tests/run.php`：新增 4 项 Phase 3 测试。

## 关键函数
- F-01：工作流详情与步骤必须经过 `getVisible()` 的家族可见性 + `familyRole()` 校验，未授权 404。
- F-02：事实候选校验 person/source 同家族；`reviewFact()` 拒绝同状态、rejected→confirmed、confirmed→rejected，条件 UPDATE 防并发，状态变更全程审计。
- F-03：API 输出仅含 id/类型/状态/步骤/时间/失败分类，不暴露 input_json、Provider、存储路径、内部错误。
- E-02/E-03：`sessionAudio()` 校验会话归属、consent granted、音频文件属于同家族档案；`transcribeSession()` 创建转写工作流；撤销授权取消未执行任务并留审计。
- E-04：分段人工修正保留原文、重置待审核；导出仅含已确认片段（优先 corrected_transcript）。

## 数据库迁移
无新增迁移；使用既有 020/017/019 表结构。

## 测试
`bash bin/test.sh`：122 通过 / 0 失败 / 0 跳过；全量 PHP lint 通过。

## 尚未解决
- E-03 的真实 Provider 分段转写依赖后台配置；未配置时仅创建工作流，不生成事实。
- 已执行转写内容的「停止使用/删除」流程尚未实现。
- Phase 4（API 完整性、Pointer Events 标注、机构边界）待实施。

## 门禁
可进入 Phase 4。
