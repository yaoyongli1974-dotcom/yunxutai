# Phase 4 — 原始文件哈希与媒体处理可信度报告

基线：Phase 3 提交 `729e9ae`。本阶段只改归档文件哈希与处理溯源，不改业务语义、不删测试、不降 PHPStan。

## 阶段名称
原始文件与处理后文件双哈希、处理溯源与上传安全（任务书 Phase 4）。

## 修改文件
- `database/migrations/20261128_009_archive_file_hashes.php`（新增）
- `app/Core/Storage.php`
- `app/Services/ArchiveService.php`
- `bin/archive-cleanup.php`
- `tests/run.php`

## 修改函数 / 新增方法
- `Storage::storeArchiveUpload()`：接收文件后尽早计算 `original_sha256/original_size`；JPEG 经 GD 真实解码成功后先把原始字节保存到 `archives/original/Y/m` 独立副本（保留原始文件），再以 quality=88 重编码并计算 `processed_sha256`。兼容字段 `sha256`/`file_size` 仍表示处理后结果。新增 GIF 真实解码校验（`imagecreatefromgif`）以拒绝 `GIF89a` + HTML 多态文件；JPEG 解码失败直接拒绝。失败时清除主文件、原始副本与数据库记录。
- `ArchiveService::addFile()`：写入原始/处理哈希、大小、处理方式/库/参数、时间戳、`original_kept/verified`，并在同一事务写入 `archive_file_processing` 溯源行；审计同时记录两种哈希。补偿删除同时移除原始副本。
- `ArchiveService::files()`：SELECT 增加双哈希与处理溯源字段。
- `bin/archive-cleanup.php`：物理清理时同时删除 `original_storage_key` 指向的原始副本，并写 `original_removed` 审计；共享 `storage_key` 保护仍生效。
- 迁移 009：`archive_files` 增加 10 个字段；新建 `archive_file_processing` 表（FK → archive_files，记录原始/处理哈希、大小、方法、库版本、参数、worker、verified）。

## 数据库迁移
`20261128_009_archive_file_hashes`：追加字段 + 新表，不改历史迁移。`migrate-v2.php --status` 显示 36 个版本全 applied；重复执行 rc=0。

## API 变化
无 API 契约变化；`ArchiveService::files()` 返回的数组新增内部字段，Web 页面仅展示处理后 SHA-256（文案已改为“入库文件 SHA-256”，避免将派生哈希误称为原始哈希）。

## 前端变化
无。

## 安全影响
- 原始哈希与处理后哈希分离，处理后哈希不再覆盖原始哈希；保留原始文件副本以供溯源。
- 图片签名真实解码（GIF/JPEG）阻止多态文件与损坏图片伪装上传；MIME 白名单与文件大小限制保持。
- 删除队列同时清理原始副本，避免隐私数据残留；审计覆盖处理与清理。

## 测试
`bash bin/test.sh` rc=0，**160 通过 / 0 失败**（Phase 3 的 157 + 本阶段 3），日志 `/tmp/opencode/v2.1.1/phase4/integration.log`。新增测试：JPEG 双哈希/原始副本/溯源记录；伪造图片签名与多态内容被拒；归档清理同时删除两份物理文件。
其他门禁：PHP lint rc=0；PHPStan Level 5 `[OK] No errors`；secret-scan rc=0；node --check rc=0；迁移 36/36 applied + 重复执行 rc=0；doctor rc=0（0 阻断，4 条环境警告）；`git diff --check` rc=0。

## 未解决问题
- 多处理版本（同一原图的多次处理）尚未建立版本链，当前只记录单次处理；列为后续增强。
- SVG/HTML 风险仍由扩展名白名单与 `nosniff`/attachment 缓解，未引入病毒扫描（需部署环境支持）。
- `media` 表仍无哈希字段（归档体系已具备）。

## 下一阶段
Phase 5：资源级权限与隐私治理专项审查，补齐口述会话/片段 API 越权、导出范围与角色缓存失效测试。
