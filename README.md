# 云叙台 YunXuTai v2.1.0

[![CI](https://github.com/yaoyongli1974-dotcom/yunxutai/actions/workflows/ci.yml/badge.svg)](https://github.com/yaoyongli1974-dotcom/yunxutai/actions/workflows/ci.yml)

以个人和家族为核心，以数字档案为载体，以 AI 为整理工具，以家族协作为基础的家族记忆与数字文化传承平台。
保留原有纪念馆业务，并扩展家族空间、谱系、档案馆、AI 家族记忆工坊、时间轴、展馆与统一通知中心。

## 能力总览
- **纪念空间**：个人纪念馆、追思互动、生平资料、身份核验后公开与收录。
- **家族空间**：家族创建/资料/头像/隐私、成员邀请与角色、多家族协作。
- **家族谱系**：人物档案（可无账户）、关系提交与审核、支系、人物合并、按需加载的谱系图。
- **数字档案馆**：照片/视频/音频/文献、标签、版本、人物关联、照片区域标注、授权下载与导出。
- **AI 家族记忆工坊**：OCR、语音转写、口述历史、事实候选与人工审核、人物传记/家族史草稿；统一 Provider 适配层与独立 Worker。
- **家族时间轴**：家族/人物/迁徙事件、纪念日、来源追踪。
- **数字展馆**：专题展览、展品权限复核、草稿→发布→下线。
- **统一通知中心**：站内/邮件、偏好、失败旁路。
- **机构能力预留**：仅平台管理员管理，显式家族归属，不构成完整多租户。

## 技术架构
PHP 8.4+ 原生模块化、MySQL 8.4 / MariaDB 11、PDO、服务端渲染、原生 JavaScript；
小程序通过 `/api/v1` 接入；Web 与 API 共用 Service + Policy；运行时零 Composer 依赖。

关键目录：
```
app/Core/           # Router / Auth / ApiAuth / Policy / Audit / Storage / RateLimiter / Kv
app/Api/            # API 入口、Input、Presenter、Controllers（/api/v1）
app/Services/       # 业务服务：Family / Genealogy / Archive / AiWorkflow / Timeline / Exhibition / Notification
app/Services/Ai/    # AiClientFactory / OpenAiClient / ProcessingClient（OCR/ASR）
app/Controllers/    # Web 控制器（含 admin/）
app/Views/          # 服务端模板（含 family/、archive/、ai/、timeline/、exhibition/）
database/migrations/# 独立增量迁移（schema_migrations + checksum）
bin/                # install / migrate-v2 / ai-worker（旧）/ ai-workflow-worker（2.0）/ backup / restore-check / doctor
deploy/             # nginx.conf、php.ini、ai-workflow-worker.service
miniprogram/        # 微信小程序
tests/              # 集成测试（bin/test.sh 统一入口）
docs/development/   # 架构、数据模型与阶段报告
```

## 快速开始
```bash
git clone https://github.com/yaoyongli1974-dotcom/yunxutai.git
cd yunxutai
cp .env.example .env
# 编辑 .env：DB_DATABASE=yunxutai，配置 APP_KEY / DB 账号 / SMTP
php bin/install.php --fresh --demo
php -S 127.0.0.1:8110 -t public
```
生产请使用 `deploy/nginx.conf` 指向 `public/`，并配置 PHP-FPM。

## 测试与迁移
```bash
bash bin/test.sh                  # 全量集成测试（自动建测试库、执行迁移）
php bin/migrate-v2.php --status   # 迁移状态（只读）
php bin/migrate-v2.php            # 执行增量迁移
php bin/doctor.php                # 环境与健康检查
```

## 权限模型
纪念馆维度：访客 / 只读 / 协作者 / 馆主 / 平台管理员（由现有 Policy 管理）。
家族维度：`owner` / `admin` / `genealogy_editor` / `archive_editor` / `member` / `guest`；
能力包括 family.view/manage、person.view/edit/merge、relationship.submit/review、
archive.view/upload/edit/delete、file.download、ai.workflow.create、ai.result.review、
timeline.edit、exhibition.edit/publish、data.export。
默认拒绝、资源级校验、平台管理员无隐式家族权限；未授权资源统一 404。

## AI Worker
```bash
php bin/ai-workflow-worker.php --limit=5 --max-runtime=300 --once
```
常驻部署参考 `deploy/ai-workflow-worker.service`（systemd）。旧版 `bin/ai-worker.php` 仅处理现有 AiJob，不执行 2.0 工作流。

## 版本与许可
当前版本：v2.1.0（Release 基于 v2.1.0 tag）。历史版本见 Releases：v2.0.0（基于 `784bc2b`）、v2.0.0-rc1、v1.0.0。
本仓库为专有/内部代码，仅公开审阅，详见 `LICENSE`；安全披露见 `SECURITY.md`。
根目录 `需求.md` 为早期产品设计草稿，商业化设想不代表已实现功能。
