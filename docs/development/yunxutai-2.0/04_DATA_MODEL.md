# 云叙台 2.0 · 数据模型设计（阶段 A）

> 全部为新表设计（**PROPOSED**）；现有 24 张表见 `00_CURRENT_CODEBASE_AUDIT.md` 第 4 节，禁止修改/删除其语义。
> 字段命名与类型遵循现有风格：`BIGINT UNSIGNED AUTO_INCREMENT`、`VARCHAR(190/255)`、`TEXT`、`ENUM`、`DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`、`ON UPDATE CURRENT_TIMESTAMP`（更新时间），`utf8mb4_unicode_ci`。

## 0. 通用约定

- 每张表：`id` 主键；`created_at`、`updated_at`；涉及家族的表带 `family_id` 并建索引；软删除用 `status` + 可选 `deleted_at`，不物理删除业务主体。
- 所有关联使用外键或明确的应用层完整性方案（现有 schema 混合使用两种方式，2.0 新表优先显式 FK）。
- 状态字段统一：`status` / `privacy` / `verification_status` / `review_status`；枚举值写入代码常量，禁止前端猜测。
- 多租户：所有查询必须带 `family_id`；唯一约束尽量含 `family_id` 以避免跨家族冲突。
- 迁移必须新增版本记录表 `schema_migrations`（version, name, checksum, executed_at, status, error），由迁移脚本维护。

## 1. 家族域

### families
```
id BIGINT UNSIGNED PK AI
name VARCHAR(190) NOT NULL
slug VARCHAR(190) NOT NULL
surname VARCHAR(50) NULL
description TEXT NULL
avatar_file_id BIGINT UNSIGNED NULL          -- 指向 archive_files（PROPOSED）
origin_place VARCHAR(190) NULL
privacy ENUM('private','unlisted','public') NOT NULL DEFAULT 'private'
owner_user_id BIGINT UNSIGNED NOT NULL       -- FK users
status ENUM('active','suspended','archived') NOT NULL DEFAULT 'active'
created_at / updated_at
UNIQUE KEY uq_family_slug (slug)
KEY idx_family_owner (owner_user_id), KEY idx_family_status (status)
```

### family_members
```
id, family_id, user_id,
role ENUM('owner','admin','genealogy_editor','archive_editor','member','guest') NOT NULL DEFAULT 'member',
status ENUM('invited','active','left','banned') NOT NULL DEFAULT 'invited',
joined_at DATETIME NULL, invited_by BIGINT UNSIGNED NULL, created_at, updated_at
UNIQUE KEY uq_family_user (family_id, user_id)
KEY idx_member_user_status (user_id, status)
```
约束：同一用户同一家族唯一；left/banned 不得访问家族资源；角色只能由后端流程写入。

### family_invitations
```
id, family_id, email VARCHAR(190), role ENUM(...), token_hash CHAR(64) NOT NULL,
status ENUM('pending','accepted','revoked','expired'), expires_at DATETIME NOT NULL,
invited_by, accepted_by NULL, accepted_at NULL, revoked_at NULL, created_at
UNIQUE KEY uq_invite_token (token_hash)
KEY idx_invite_family (family_id, status)
```
令牌随机生成、仅存哈希、有效期；接受时校验邮箱与令牌，撤销后不可复用。

### family_branches
```
id, family_id, parent_branch_id NULL, name VARCHAR(190), description TEXT NULL, created_at, updated_at
KEY idx_branch_parent (family_id, parent_branch_id)
```
父子支系构成树；禁止自引用与成环（写入时校验）。

### person_branch_members
```
id, family_id, person_id, branch_id, created_at
UNIQUE KEY uq_person_branch (family_id, person_id, branch_id)
```

## 2. 人物与谱系域

### family_persons
```
id, family_id,
name VARCHAR(190) NOT NULL, gender ENUM('unknown','male','female','other') DEFAULT 'unknown',
birth_date DATE NULL, death_date DATE NULL,
birth_place VARCHAR(190) NULL, death_place VARCHAR(190) NULL,
biography TEXT NULL, avatar_file_id BIGINT UNSIGNED NULL,
privacy ENUM('private','family','public') NOT NULL DEFAULT 'private',
verification_status ENUM('none','pending','verified','disputed') NOT NULL DEFAULT 'none',
is_deceased TINYINT(1) NOT NULL DEFAULT 0,
created_by BIGINT UNSIGNED, created_at, updated_at, deleted_at NULL
KEY idx_person_family_name (family_id, name), KEY idx_person_dates (birth_date, death_date)
```
重要：人物与账户分离；活人（`is_deceased=0`）默认 `privacy=private`，公开须显式授权 + 审核。

### person_memorial_links
```
id, family_id, person_id, memorial_id, relation_note VARCHAR(255) NULL,
created_by, created_at, status ENUM('active','revoked')
UNIQUE KEY uq_person_memorial (person_id, memorial_id)
KEY idx_memorial_person (memorial_id)
```
保留纪念馆原始 ID；一个人物可关联一个馆（业务层校验唯一 active），一个馆也可暂不关联人物。

### person_relationships
统一方向定义：`relationship_type` 始终描述 **person_a 相对 person_b** 的关系；反向关系由代码常量表推导，不让前端猜测。

| type（A→B） | 反向（B→A） | 类别 |
|---|---|---|
| parent | child | 血缘/养育 |
| child | parent | 血缘/养育 |
| spouse | spouse | 婚姻 |
| sibling | sibling | 血缘 |
| adopted_parent | adopted_child | 收养 |
| adopted_child | adopted_parent | 收养 |
| step_parent | step_child | 继亲 |
| step_child | step_parent | 继亲 |
| other | other | 其他（需 description） |

```
id, family_id, person_a_id, person_b_id,
relationship_type VARCHAR(40) NOT NULL, reverse_type VARCHAR(40) NOT NULL,
direction ENUM('a_to_b','bidirectional') NOT NULL DEFAULT 'a_to_b',
status ENUM('pending','active','disputed','revoked','rejected') NOT NULL DEFAULT 'pending',
source_type ENUM('user_input','archive_ocr','ai_extraction','oral_history','imported'),
source_id BIGINT UNSIGNED NULL, description TEXT NULL,
submitted_by, reviewed_by NULL, reviewed_at NULL, created_at, updated_at
UNIQUE KEY uq_rel_pair (family_id, person_a_id, person_b_id, relationship_type)
KEY idx_rel_family_status (family_id, status), KEY idx_rel_person_a (person_a_id), KEY idx_rel_person_b (person_b_id)
```
约束写入前校验：自关联禁止；规范化 A<B 存储 + direction 标记，避免重复与反向重复；parent 类关系确认前做祖先链环检测；撤销只改 status；争议允许双方说明（`relationship_disputes`）。

### relationship_disputes
```
id, family_id, relationship_id, raised_by, statement TEXT, status ENUM('open','resolved','rejected'),
handled_by NULL, handled_at NULL, created_at
```

### person_merge_logs
```
id, family_id, primary_person_id, merged_person_id,
snapshot_json TEXT NOT NULL,       -- 合并前关联档案/关系/馆清单
performed_by, created_at, status ENUM('done','rolled_back')
```
禁止物理删除被合并人物：保留记录并设置 `deleted_at` / `merged_into_person_id`，全部关联改挂主人物并留审计。

## 3. 数字档案域

### archives
```
id, family_id, title VARCHAR(190), description TEXT NULL,
archive_type ENUM('document','photo','video','audio','letter','artifact','other'),
event_date DATE NULL, location VARCHAR(190) NULL,
source_type ENUM('user_input','imported','archive_ocr','oral_history','ai_extraction'),
source_description TEXT NULL,
privacy ENUM('private','family','public') DEFAULT 'private',
status ENUM('draft','active','archived','deletion_requested','deleted'),
created_by, created_at, updated_at, deleted_at NULL
KEY idx_archive_family_type (family_id, archive_type, status), KEY idx_archive_date (event_date)
```

### archive_files
```
id, family_id, archive_id, storage_key VARCHAR(255) NOT NULL,
original_filename VARCHAR(190), mime_type VARCHAR(100), file_size INT UNSIGNED,
sha256 CHAR(64), file_role ENUM('original','thumbnail','restored','preview','transcript','converted'),
processing_status ENUM('pending','processing','done','failed'),
created_at
UNIQUE KEY uq_file_role (archive_id, file_role, sha256)
KEY idx_file_hash (sha256), KEY idx_file_family (family_id)
```
原文件不可覆盖；处理副本独立行；校验失败不得产生孤立记录（事务 + 清理）。

### archive_person_links / archive_event_links
```
archive_person_links: id, archive_id, person_id, relation_type VARCHAR(40), created_by, created_at
  UNIQUE (archive_id, person_id, relation_type)
archive_event_links: id, archive_id, event_id, created_by, created_at
  UNIQUE (archive_id, event_id)
```

### archive_tags / archive_tag_links
```
archive_tags: id, family_id, name VARCHAR(100), created_by, created_at, UNIQUE(family_id, name)
archive_tag_links: id, archive_id, tag_id, created_at, UNIQUE(archive_id, tag_id)
```

### archive_versions
```
id, archive_id, version INT, title_snapshot VARCHAR(190), metadata_json TEXT,
change_note VARCHAR(255), created_by, created_at, UNIQUE(archive_id, version)
```

### photo_annotations
```
id, family_id, archive_id, file_id, person_id,
x1 DECIMAL(8,4), y1 DECIMAL(8,4), x2 DECIMAL(8,4), y2 DECIMAL(8,4),
annotated_by, review_status ENUM('pending','confirmed','rejected'), created_at
```
首期仅人工标注；不做自动人脸识别；未来启用需单独授权与隐私评估。

### file_audits
```
id, family_id, file_id, actor_user_id, action ENUM('upload','view','download','delete','restore'),
reason VARCHAR(500) NULL, ip VARCHAR(64), created_at
```
敏感材料下载必须填写原因。

## 4. 事件、口述历史、AI 事实

### family_events / family_event_persons
```
family_events: id, family_id, title, description TEXT, event_date DATE, end_date DATE NULL,
 location VARCHAR(190), event_type VARCHAR(40), privacy ENUM('private','family','public'),
 source_type VARCHAR(40), source_id BIGINT NULL, status ENUM('draft','active','archived'),
 created_by, created_at, updated_at
family_event_persons: id, event_id, person_id, role_note VARCHAR(190) NULL, UNIQUE(event_id, person_id)
```
一个事件可关联多人物；事件可来源于家属提交/档案提取/转写/审核/人物资料。

### oral_history_sessions / oral_history_segments / oral_history_consents
```
oral_history_sessions: id, family_id, title, topic VARCHAR(190), interviewer_id, interviewee_person_id,
 audio_file_id BIGINT NULL, consent_status ENUM('pending','granted','withdrawn'),
 processing_status ENUM('pending','processing','done','failed'),
 privacy ENUM('private','family','public') DEFAULT 'private', created_by, created_at, updated_at
oral_history_segments: id, session_id, speaker VARCHAR(190), start_time INT, end_time INT,
 transcript TEXT, corrected_transcript TEXT NULL,
 review_status ENUM('pending','approved','rejected'), created_at, updated_at
oral_history_consents: id, session_id, granted_by, scope VARCHAR(190), granted_at, withdrawn_at NULL, note TEXT NULL
```
撤回授权后停止公开与再次分发；未校正转写稿不得发布；默认私密。

### memory_facts / fact_sources
```
memory_facts: id, family_id, person_id NULL, event_id NULL,
 fact_type VARCHAR(60), fact_content TEXT,
 confidence DECIMAL(4,3) NULL,
 verification_status ENUM('pending','confirmed','disputed','rejected'),
 submitted_by, reviewed_by NULL, reviewed_at NULL, created_at, updated_at
fact_sources: id, fact_id, source_type ENUM('user_input','oral_history','archive_ocr','ai_extraction','family_confirmation','imported'),
 source_id BIGINT NULL, source_location VARCHAR(255) NULL, quote TEXT NULL, created_at
```
AI 生成一律 pending；每个重要事实至少一条 source；禁止自动覆盖已确认资料。

## 5. AI 工作流

### ai_workflows / ai_workflow_steps
```
ai_workflows: id, family_id, workflow_type ENUM('ocr','transcription','extraction','biography','family_history','interview_assistant'),
 subject_type VARCHAR(40), subject_id BIGINT NULL,
 status ENUM('queued','processing','completed','failed','cancelled'),
 idempotency_key CHAR(64) NOT NULL,
 current_step INT DEFAULT 0, input_ref_json TEXT, output_ref_json TEXT NULL,
 provider VARCHAR(60) NULL, model VARCHAR(100) NULL, prompt_version VARCHAR(60) NULL,
 error_code VARCHAR(60) NULL, error_message TEXT NULL,
 retry_count INT DEFAULT 0, max_retries INT DEFAULT 3,
 leased_until DATETIME NULL, started_at NULL, finished_at NULL,
 created_by, created_at, updated_at
UNIQUE KEY uq_workflow_idem (idempotency_key)
KEY idx_workflow_queue (status, leased_until, created_at)

ai_workflow_steps: id, workflow_id, step_no INT, name VARCHAR(100),
 status ENUM('queued','processing','completed','failed','cancelled'),
 input_ref_json TEXT, output_ref_json TEXT NULL, error_message TEXT NULL,
 retry_count INT DEFAULT 0, started_at NULL, finished_at NULL, created_at,
UNIQUE (workflow_id, step_no)
```
执行时间记录到 steps；租约用于崩溃恢复；重试上限 + 指数退避；取消为状态变更而非删除。旧 `ai_jobs` 保留，新增 `legacy_job_id` 可选引用。

## 6. 展馆

### exhibitions / exhibition_items
```
exhibitions: id, family_id, name VARCHAR(190), slug VARCHAR(190), cover_file_id BIGINT NULL,
 description TEXT, theme VARCHAR(100) NULL, privacy ENUM('private','family','public'),
 status ENUM('draft','review','published','offline'),
 published_at NULL, created_by, created_at, updated_at
UNIQUE (family_id, slug)

exhibition_items: id, exhibition_id, section VARCHAR(100), item_type ENUM('person','memorial','archive','event','article','photo','video'),
 item_id BIGINT, title VARCHAR(190) NULL, sort_order INT DEFAULT 0,
 visibility_override ENUM('inherit','private','family','public') DEFAULT 'inherit',
 status ENUM('draft','review','published','offline'), created_at
KEY idx_exhibit_items (exhibition_id, section, sort_order)
```
发布前必须校验所有引用内容对目标受众可见；私密档案不能被误公开；机构租户仅通过 `institution_tenant_id` 预留隔离字段，首期不启用。

## 7. 通知

```
notifications: id, user_id, type VARCHAR(60), title VARCHAR(190), body TEXT,
 family_id BIGINT NULL, subject_type VARCHAR(40) NULL, subject_id BIGINT NULL,
 read_at DATETIME NULL, created_at, KEY idx_user_unread (user_id, read_at, created_at)

notification_preferences: id, user_id, type VARCHAR(60), channel ENUM('in_app','email'),
 enabled TINYINT(1) DEFAULT 1, UNIQUE(user_id, type, channel)

notification_logs: id, notification_id NULL, user_id, channel, status ENUM('pending','sent','failed'),
 attempts INT, error_message TEXT NULL, created_at, sent_at NULL
```
通知失败不影响核心事务；重要通知默认开启，纪念日等非必要提醒可关闭。

## 8. 兼容与约束核对

- 不新建与现有 `memorials`、`media`、`ai_jobs`、`reports` 同义的重复表；关联通过 link 表。
- `families.owner_user_id` 引用现有 `users`；纪念馆关联保留其原 ID；不确定家族归属的历史馆保持独立。
- 所有新表加入迁移脚本并登记 `schema_migrations`；`schema.sql` 同步作为新装基线，避免双源冲突（详见 `07_MIGRATION_PLAN.md`）。
- 数据迁移前后必须核对：纪念馆数量、媒体数量、AI job 数量、用户数量不变；新关联数量有报告。
