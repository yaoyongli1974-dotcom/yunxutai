# 阶段 G 交付报告：小程序联调、迁移演练、安全与发布准备

状态：**本地实现完成，生产发布未执行**（按执行纪律，不推 main、不碰生产库）。

## 1. Git 与基线

- 基线 `4e73652`；本地链：`ebe8e03`(A) → `23460cd`(B) → `044a28a`(C) → `4f0c729`(D) → `38d9dca`(E) → `565d39e`(F) → 本阶段提交。
- `origin/main` 仍为 `4e73652`，A~G 全部本地未推。
- 生产库未连接、未修改；本阶段所有演练均在测试库 `jidian_test` 与本地文件完成。

## 2. 新增文件与修改文件

### 新增
- `app/Api/Controllers/FamilyV2Controller.php`：2.0 家族业务 API 控制器，全部复用 Web 端 Service/Policy，`HttpException` 统一转 API 错误格式；未授权资源 404，不泄露存在性。
- 小程序页面（每页含 `.js/.wxml/.wxss/.json`）：
  - `miniprogram/pages/families/list.*`：家族列表、创建家族（隐私 private/unlisted/public）。
  - `miniprogram/pages/families/detail.*`：家族主页、人物列表、谱系摘要、事件、AI 任务入口。
  - `miniprogram/pages/archives/list.*`：档案列表、创建档案。
  - `miniprogram/pages/notifications/list.*`：通知列表、单条/批量已读。
- `docs/development/yunxutai-2.0/14_PHASE_G_REPORT.md`：本报告。

### 修改
- `app/routes-api.php`：新增 29 条 `/api/v1` 路由（家族、邀请接受、人物、谱系、档案、AI、口述历史、事件、展馆、通知），全部挂 `api-auth` 中间件。
- `miniprogram/utils/api.js`：新增对应 21 个 API 方法映射，保持既有方法不变。
- `miniprogram/app.json`：注册 4 个新页面。
- `tests/run.php`：新增 4 项阶段 G 测试（全链路、跨家族/未授权、通知已读、令牌失效与伪造身份）。

### 删除
无。

## 3. API v1 端点（与既有 20 条并存，未破坏旧接口）

- 家族：`GET /families`、`POST /families`、`GET /families/{id}`、`GET /families/{id}/members`、`POST /families/{id}/invitations`、`POST /invitations/accept`
- 人物/谱系：`GET /families/{id}/persons`、`POST /families/{id}/persons`、`GET /persons/{id}`、`GET /families/{id}/genealogy`、`POST /families/{id}/relationships`、`POST /families/{id}/relationships/{relationId}/review`
- 档案：`GET /families/{id}/archives`、`POST /families/{id}/archives`、`GET /archives/{id}`
- AI：`POST /families/{id}/ai/workflows`、`GET /ai/workflows/{id}`、`POST /ai/workflows/{id}/cancel`、`POST /ai/facts/{id}/review`、`GET /families/{id}/ai/sessions`
- 时间轴/展馆：`GET|POST /families/{id}/events`、`GET|POST /families/{id}/exhibitions`、`POST /exhibitions/{id}/publish`
- 通知：`GET /notifications`、`POST /notifications/{id}/read`、`POST /notifications/read-all`

所有端点强制 Bearer token；身份来自 token，忽略客户端传入的 `user_id` / `owner_user_id`；写操作与 Web 端共用 Policy，不另写权限判断。

## 4. 数据库变化

本阶段**无新增迁移**（24 个迁移在 A~F 已完成，测试库二次执行全部跳过）。生产迁移仍未执行。

## 5. 测试与演练结果

| 项目 | 结果 |
|---|---|
| `bash bin/test.sh` | **105 通过 / 0 失败 / 0 跳过**（原有 101 项全部保留） |
| `php bin/doctor.php` | 21 通过 / 4 环境警告 / 0 阻断 |
| 全量 PHP lint（`app/ bin/ tests/`） | 0 错误 |
| 迁移演练 | 测试库 `jidian_test` 由 `bin/test.sh` 全新安装并执行两次；24 个迁移全部 applied，重跑全部跳过 |
| 备份演练 | 本地执行 `bin/backup.php` 成功：数据库导出、上传目录归档、核验材料归档；备份含敏感信息，仅存本地 `storage/backups/`，不得外传 |
| 小程序脚本语法 | `node --check` 全部通过 |

doctor 的 4 条环境警告与基线一致（APP_DEBUG、APP_ENV、upload_max_filesize、post_max_size），生产配置需单独整改。

## 6. 安全检查结论

- 越权：跨家族家族详情、人物列表、人物详情返回 404；公开家族按设计可读。
- 认证：无 token 401、伪 token 401、撤销 token 401；创建者身份不可伪造。
- 输入：API 创建档案忽略空字段，避免非法 `source_type`；人物活人默认私密且禁止公开创建；文件访问走 Storage 白名单与授权下载。
- 注入与脚本：既有测试覆盖 SQL 注入参数化、XSS 转义、CSRF、伪造 MIME、路径穿越、上传安全；本阶段未削弱任何一项。
- AI：外部调用前校验资料授权；AI 输出仅候选/草稿，未确认事实不会进入正式档案。

## 7. 性能约束

- 谱系 `tree()` 限制 500 节点、深度 1~5；档案列表上限 200、事件上限 500、通知上限 100。
- 迁移表已包含 `family_id` / `status` / `created_at` 等索引；跨家族查询均带 `family_id`。

## 8. 生产发布清单（待用户授权，未执行）

1. 核对生产库版本与当前结构，确认无手工改表；
2. 执行加密备份并核验备份完整性；
3. 在备份副本上演练 `php bin/migrate-v2.php --status` 与完整迁移，核对迁移前后记录数；
4. 仅在生产窗口执行迁移，逐条检查 `schema_migrations` 状态；
5. 部署代码（不覆盖生产 `.env`）、清 opcache、重启 AI workflow worker；
6. 健康检查：`/`、家族页、`/api/v1/me`、任务队列状态、通知发送；
7. 异常时按备份回滚，并保留迁移失败日志。

## 9. 未完成/未决事项（不得据此宣称整体发布完成）

- 生产迁移、部署与发布报告签署仍需用户明确授权；
- 外部 OCR / 语音转写 Provider 未接入（当前为模板化本地草稿）；
- 谱系为列表式按需加载，尚无拖动缩放图谱；照片区域人物标注、档案导出、机构租户未实现；
- GitHub Actions CI 未配置；生产 `php.ini` 上传限制与 APP_DEBUG/APP_ENV 需整改；
- 小程序页面仅覆盖核心浏览与创建流程，复杂档案整理和展馆编辑仍建议在 Web 端完成。

## 10. 未决项补齐（用户追加指令后完成）

用户指令：「1、先解决未解决的问题，外部接入可通过管理后台进行操作，其他的按需求完成」。以下在同一本地提交中完成，仍未推 main、未碰生产库。

### 外部 OCR / 语音转写 Provider
- 新增 `app/Services/Ai/ProcessingClient.php`：统一 Provider 适配，POST `{base_url}/ocr` 与 `/transcriptions`，Bearer 鉴权，兼容 `text` / `result` / `data.text` / `choices[0].message.content` 响应；未配置时不发起外部请求。
- `AiWorkflowService` 的 `ocr_document` / `transcribe_audio` 在配置了 Provider 且任务输入含合法 `file_id` 时调用识别，输出保留 `processing_provider`；输入授权仍先经 `assertInputAuthorized` 校验。
- 管理后台 `app/Views/admin/ai-settings.php` 增加 OCR/ASR 两组配置；`AiServiceController` 保存与回填 `ocr_*` / `asr_*` 白名单字段；API Key 支持清空。
- `Settings` 白名单增加 `ai.ocr.*` / `ai.asr.*`（含 `enabled`）。

### 可交互谱系
- 新增 `public/assets/js/genealogy.js`：SVG 节点/连线、拖动节点、平移、滚轮与按钮缩放、触摸拖动，无第三方依赖；服务端仍按 `center_id` + `depth` 裁剪，保持按需加载。

### 照片区域标注与导出
- 迁移 `20261127_025_create_archive_photo_tags`：百分比坐标、标注人、待确认状态、审核人与时间；同步 `schema.sql`。
- `ArchiveService` 新增 `addPhotoTag` / `photoTags` / `reviewPhotoTag` / `exportBundle`；Policy 新增 `archive.tag` 能力；Web 增加标注提交/审核与图片拖动选区脚本 `public/assets/js/photo-tags.js`，导出为授权范围内的 JSON 数据包并写审计。全程人工标注，不做自动人脸识别。

### 机构租户预留
- 迁移 `20261127_026_create_institutions`：`institutions` / `institution_members` / `family_institutions`，家族与机构显式关联，不修改现有 `families` 表；同步 `schema.sql`。
- `InstitutionService`：仅平台管理员可创建机构、任免角色、关联家族，全部写审计；普通用户越权返回 403。

### CI
- 新增 `.github/workflows/ci.yml`：PHP 8.4 + MySQL 8 service；PHP 全量 lint、小程序 JS 语法检查、`bin/test.sh` 全量集成测试、`bin/migrate-v2.php --status`、生产式上传限制的 doctor。

### 验证更新
- `bash bin/test.sh`：**109 通过 / 0 失败**（新增 4 项：Provider 配置、标注权限与审核、导出审计、机构隔离）。
- 全量 PHP lint 0 错误；小程序与新增 JS 的 `node --check` 全部通过；doctor 0 阻断；26 个迁移在测试库全部 applied。

### 仍未完成（需生产授权）
- 上述迁移未在生产库执行；外部 Provider 凭据需管理员在后台填入；生产部署、CI 首次运行验证与回滚演练仍需用户授权。
