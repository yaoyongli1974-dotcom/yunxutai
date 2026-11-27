# Round2 Phase 4：Web / 小程序完整性与机构边界报告

## 修改/新增文件
- 修改：`public/assets/js/photo-tags.js`（H-01 Pointer Events）、`app/Services/ArchiveService.php`（H-03 同家族校验）、`app/Services/InstitutionService.php`（I 事务/最后 owner/重复归属）、`app/Api/Api.php`（G-03 统一分页）、`tests/run.php`。
- 新增：本报告。无新增数据库迁移。

## 关键函数
- `Api::paged()`：统一 `items` + `pagination{page,per_page,total,total_pages}`。
- `ArchiveService::addPhotoTag()`：先取 `family_persons`，强制 `person.family_id === archive.family_id`，越权 404。
- `InstitutionService::create()`：机构 + owner 成员 + 审计同事务；`setRole()` 禁止降级最后一个 active owner（422）；`attachFamily()` 事务 + 查重（409）。
- `photo-tags.js`：pointerdown/move/up/cancel + capture，百分比边界 [0,100]、极小选区不写入、图片加载完成前不绑定。

## 测试
- `bash bin/test.sh`：**125 通过 / 0 失败**（Phase 0~3 的 122 项全部保留，新增 3 项：G03 分页、H03 跨家族标注、I 机构边界）。
- `node --check` 与全量 `php -l` 通过。

## 未决
- 小程序页面尚未逐步覆盖所有新增管理接口（任务书 G-02 的完整页面矩阵仍有缺口）。
- 真实触屏设备与浏览器兼容性未做人工验收。
- 机构仍仅为预留：未开放机构管理员登录、跨家族搜索、机构文件下载/导出、配额。

## 门禁
本地测试全绿，可进入 Phase 5（数据一致性与发布质量）。仍未推送 main、未动生产。
