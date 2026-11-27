# 阶段 D 交付报告：数字档案馆

- 本地提交：待提交（本文件生成后提交，不推 main）
- 基线：阶段 C `044a28a`
- 生产：未部署、未修改生产数据库

## 变更清单

### 数据库（新增 6 个迁移，累计 16 个）
- `20261127_011_create_archives`：档案主表（family_id、类型、日期、地点、来源、privacy、status、软删除）。
- `20261127_012_create_archive_files`：文件表（storage_key 唯一、SHA-256、file_role、processing_status）。
- `20261127_013_create_archive_tags` + `014_create_archive_tag_links`：家族内唯一标签与多对多关联。
- `20261127_015_create_archive_person_links`：档案与人物多对多关联（含关联类型与创建者）。
- `20261127_016_create_archive_versions`：编辑前快照版本，不覆盖历史。
- `database/schema.sql` 同步相同建表语句；`bin/migrate-v2.php` 无需修改即可执行。

### 代码
- `app/Core/Storage.php` 新增 `storeArchiveUpload()`：沿用统一 MIME 白名单、随机文件名与 Web 根外存储；计算 SHA-256；JPEG 在 GD 可用时重编码清理 EXIF，失败不影响文件。
- `app/Core/Policy.php`：资源白名单加入 `archive`；公开家族下的公开档案允许访客查看；私密档案仅 owner/admin/archive_editor；family 档案仅成员；文件下载必须带资源级授权。
- `app/Services/ArchiveService.php`：档案 CRUD、可见性过滤、版本快照、文件上传、标签、人物关联、删除申请/审批（软删除）、全链路审计。
- `app/Controllers/ArchiveController.php`：档案列表/详情/创建/编辑/上传/下载/标签/人物关联/删除审批。
- `app/Views/archive/{index,create,show}.php`：页面沿用现有视觉与语义类，全部 noindex。
- `app/routes.php`：新增 11 条 `/families/{id}/archives`、`/archives/...` 路由（auth 中间件）。

## 测试结果
- `bash bin/test.sh`：92 通过 / 0 失败 / 0 跳过（阶段 C 的 86 项全部保留）。
- 新增 6 项：迁移版本、创建+版本历史、上传 SHA-256+授权下载+审计、跨家族 404、公开档案访客可见但下载需成员、标签/人物关联/删除审批、伪造 MIME 拒绝。
- `bin/migrate-v2.php` 在测试库连续执行两次：首次 applied、二次全部跳过（由 bin/test.sh 自动验证）。
- 全量 PHP lint：0 错误。

## 冲突复查
- 未修改认证流程、未删除旧功能或旧测试、未绕过 Policy。
- 旧纪念馆媒体系统保持独立；档案文件走新表与授权下载路由，不生成公开文件 URL。
- `archive_editor` 在现有 6 角色内，无需新增角色。

## 未决事项
- 生产库迁移仍需用户授权 + 备份 + 演练后才可执行。
- 处理副本（缩略图/转写稿）与 AI 处理链在阶段 E 接入；本阶段已预留 file_role。
- 档案导出、EXIF 清理失败的显式告警、CI 迁移一致性检查留待后续阶段。
- 照片人物标注（区域坐标）尚未实现，按要求首期不引入自动人脸识别。
