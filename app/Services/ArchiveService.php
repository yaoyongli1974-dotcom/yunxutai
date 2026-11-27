<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Storage;

/**
 * 数字档案馆统一业务逻辑（PROPOSED，阶段 D）。
 * Web 与 API 都必须通过本服务访问档案与文件；所有文件访问走授权校验。
 */
final class ArchiveService
{
    private const PRIVACY = ['private', 'family', 'public'];
    private const TYPES = ['photo', 'document', 'audio', 'video', 'letter', 'certificate', 'other'];

    private static function context(int $familyId, ?array $resource = null, ?array $family = null): array
    {
        $context = ['family_id' => $familyId];
        if ($family !== null) {
            $context['family'] = $family;
        }
        if ($resource !== null) {
            $context['resource'] = $resource;
        }
        return $context;
    }

    private static function requireFamily(int $familyId, string $capability, ?array $user, ?array $resource = null): array
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require($capability, self::context($familyId, $resource, $family), $user);
        return $family;
    }

    /** @return array<string, string> */
    private static function normalize(array $data, bool $creating): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 180) {
            throw new HttpException(422, '档案标题不能为空且不能超过 180 字');
        }
        $privacy = (string) ($data['privacy'] ?? 'private');
        if (!in_array($privacy, self::PRIVACY, true)) {
            throw new HttpException(422, '无效的隐私设置');
        }
        $type = (string) ($data['archive_type'] ?? 'document');
        if (!in_array($type, self::TYPES, true)) {
            throw new HttpException(422, '无效的档案类型');
        }
        $date = trim((string) ($data['event_date'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new HttpException(422, '日期格式不正确');
        }
        $source = (string) ($data['source_type'] ?? 'user_input');
        if (!in_array($source, ['user_input', 'imported', 'archive_ocr', 'oral_history', 'ai_extraction'], true)) {
            throw new HttpException(422, '无效的来源类型');
        }
        $out = [
            'title' => $title,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'archive_type' => $type,
            'event_date' => $date !== '' ? $date : null,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'source_type' => $source,
            'source_description' => trim((string) ($data['source_description'] ?? '')) ?: null,
            'privacy' => $privacy,
        ];
        if (!$creating) {
            $status = (string) ($data['status'] ?? 'active');
            if (!in_array($status, ['active', 'archived'], true)) {
                throw new HttpException(422, '无效的档案状态');
            }
            $out['status'] = $status;
        }
        return $out;
    }

    public static function create(?array $user, int $familyId, array $data): int
    {
        self::requireFamily($familyId, 'archive.upload', $user);
        $fields = self::normalize($data, true);
        $fields['family_id'] = $familyId;
        $fields['created_by'] = (int) ($user['id'] ?? 0);
        $id = Db::insert('archives', $fields);
        Audit::log('archive.create', 'archive', $id, ['family_id' => $familyId]);
        return $id;
    }

    /** 列表只允许返回当前用户有权查看的档案。 */
    public static function listForFamily(?array $user, int $familyId, string $search = '', int $limit = 100): array
    {
        self::requireFamily($familyId, 'family.view', $user);
        $params = [$familyId];
        $sql = 'SELECT * FROM archives WHERE family_id = ? AND status IN (\'active\',\'archived\')';
        if ($search !== '') {
            $sql .= ' AND (title LIKE ? OR description LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit));
        $rows = [];
        foreach (Db::fetchAll($sql, $params) as $row) {
            $visible = self::getVisible($user, (int) $row['id']);
            if ($visible !== null) {
                $rows[] = $visible;
            }
        }
        return $rows;
    }

    public static function getVisible(?array $user, int $archiveId): ?array
    {
        $archive = Db::fetch(
            "SELECT * FROM archives WHERE id = ? AND status IN ('active','archived') AND deleted_at IS NULL LIMIT 1",
            [$archiveId]
        );
        if ($archive === null) {
            return null;
        }
        $familyId = (int) $archive['family_id'];
        $family = FamilyService::find($familyId);
        if ($family === null) {
            return null;
        }
        if (!Policy::can(
            'archive.view',
            self::context($familyId, ['type' => 'archive', 'id' => $archiveId], $family),
            $user
        )) {
            return null;
        }
        return $archive;
    }

    public static function update(?array $user, int $archiveId, array $data): void
    {
        $archive = self::getVisible($user, $archiveId);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在');
        }
        Policy::require('archive.edit', self::context((int) $archive['family_id']), $user);
        $fields = self::normalize($data, false);
        $nextVersion = (int) Db::value(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM archive_versions WHERE archive_id = ?',
            [$archiveId]
        );
        // 修改前先保存快照：档案原始信息与文件记录均不会被覆盖。
        Db::insert('archive_versions', [
            'archive_id' => $archiveId,
            'version' => $nextVersion,
            'title' => (string) $archive['title'],
            'description' => $archive['description'],
            'privacy' => (string) $archive['privacy'],
            'status' => (string) $archive['status'],
            'change_note' => mb_substr(trim((string) ($data['change_note'] ?? '')), 0, 255) ?: null,
            'created_by' => (int) ($user['id'] ?? 0),
        ]);
        Db::update('archives', $fields, 'id = ?', [$archiveId]);
        Audit::log('archive.update', 'archive', $archiveId, ['family_id' => (int) $archive['family_id'], 'version' => $nextVersion]);
    }

    /** @return array{disk_path:string, original_name:string, mime:string, size_bytes:int, sha256:string, kind:string} */
    public static function addFile(?array $user, int $archiveId, array $upload): array
    {
        $archive = self::getVisible($user, $archiveId);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在');
        }
        Policy::require('archive.upload', self::context((int) $archive['family_id']), $user);
        $stored = Storage::storeArchiveUpload($upload);
        $fileId = Db::insert('archive_files', [
            'archive_id' => $archiveId,
            'storage_key' => $stored['disk_path'],
            'original_filename' => $stored['original_name'],
            'mime_type' => $stored['mime'],
            'file_size' => $stored['size_bytes'],
            'sha256' => $stored['sha256'],
            'file_role' => 'original',
            'processing_status' => 'ready',
        ]);
        Audit::log('archive.file.upload', 'archive_file', $fileId, [
            'archive_id' => $archiveId,
            'sha256' => $stored['sha256'],
        ]);
        return $stored + ['id' => $fileId];
    }

    public static function files(int $archiveId, ?string $role = null): array
    {
        $sql = 'SELECT id, archive_id, original_filename, mime_type, file_size, sha256, file_role, processing_status, created_at
                FROM archive_files WHERE archive_id = ?';
        $params = [$archiveId];
        if ($role !== null) {
            $sql .= ' AND file_role = ?';
            $params[] = $role;
        }
        $sql .= ' ORDER BY id';
        return Db::fetchAll($sql, $params);
    }

    /** 下载必须带资源级授权；调用方禁止自行拼接文件路径。 */
    public static function getFileVisible(?array $user, int $fileId): ?array
    {
        $file = Db::fetch('SELECT * FROM archive_files WHERE id = ? LIMIT 1', [$fileId]);
        if ($file === null) {
            return null;
        }
        $archive = self::getVisible($user, (int) $file['archive_id']);
        if ($archive === null) {
            return null;
        }
        $familyId = (int) $archive['family_id'];
        $family = FamilyService::find($familyId);
        if ($family === null) {
            return null;
        }
        if (!Policy::can(
            'file.download',
            self::context($familyId, ['type' => 'archive', 'id' => (int) $archive['id']], $family),
            $user
        )) {
            return null;
        }
        return ['file' => $file, 'archive' => $archive];
    }

    public static function absolutePath(array $file): ?string
    {
        $key = (string) ($file['storage_key'] ?? '');
        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            return null;
        }
        $path = Storage::uploadsPath($key);
        return is_file($path) ? $path : null;
    }

    public static function tags(int $archiveId): array
    {
        return Db::fetchAll(
            'SELECT t.id, t.name FROM archive_tags t
             JOIN archive_tag_links l ON l.tag_id = t.id
             WHERE l.archive_id = ? ORDER BY t.name',
            [$archiveId]
        );
    }

    public static function addTag(?array $user, int $archiveId, string $name): void
    {
        $archive = self::getVisible($user, $archiveId);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在');
        }
        Policy::require('archive.edit', self::context((int) $archive['family_id']), $user);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new HttpException(422, '标签不能为空或超过 60 字');
        }
        $familyId = (int) $archive['family_id'];
        $tagId = (int) Db::value('SELECT id FROM archive_tags WHERE family_id = ? AND name = ?', [$familyId, $name]);
        if ($tagId === 0) {
            $tagId = Db::insert('archive_tags', ['family_id' => $familyId, 'name' => $name]);
        }
        Db::query('INSERT IGNORE INTO archive_tag_links (archive_id, tag_id) VALUES (?, ?)', [$archiveId, $tagId]);
        Audit::log('archive.tag.add', 'archive', $archiveId, ['family_id' => $familyId, 'tag' => $name]);
    }

    public static function linkPerson(?array $user, int $archiveId, int $personId, string $relationType = 'subject'): void
    {
        $archive = self::getVisible($user, $archiveId);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在');
        }
        Policy::require('archive.edit', self::context((int) $archive['family_id']), $user);
        $person = FamilyPersonService::getVisible($user, $personId);
        if ($person === null || (int) $person['family_id'] !== (int) $archive['family_id']) {
            throw new HttpException(404, '人物不存在');
        }
        if (!in_array($relationType, ['subject', 'mentioned', 'author', 'photographer'], true)) {
            throw new HttpException(422, '无效的关联类型');
        }
        Db::query(
            'INSERT IGNORE INTO archive_person_links (archive_id, person_id, relation_type, created_by) VALUES (?, ?, ?, ?)',
            [$archiveId, $personId, $relationType, (int) ($user['id'] ?? 0)]
        );
        Audit::log('archive.person.link', 'archive', $archiveId, ['person_id' => $personId]);
    }

    public static function linkedPersons(int $archiveId): array
    {
        return Db::fetchAll(
            'SELECT p.id, p.name FROM archive_person_links l
             JOIN family_persons p ON p.id = l.person_id AND p.deleted_at IS NULL
             WHERE l.archive_id = ? ORDER BY p.id',
            [$archiveId]
        );
    }

    /** 人工照片区域标注：仅图片文件，坐标以百分比存储，标注默认待确认。 */
    public static function addPhotoTag(?array $user, int $fileId, int $personId, float $x, float $y, float $w, float $h): int
    {
        $file = Db::fetch('SELECT * FROM archive_files WHERE id = ? LIMIT 1', [$fileId]);
        if ($file === null || !str_starts_with((string) $file['mime_type'], 'image/')) {
            throw new HttpException(422, '只能标注图片文件');
        }
        $archive = self::getVisible($user, (int) $file['archive_id']);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在或无权访问');
        }
        $familyId = (int) $archive['family_id'];
        Policy::require('archive.tag', ['family_id' => $familyId], $user);
        if (FamilyPersonService::getVisible($user, $personId) === null) {
            throw new HttpException(404, '人物不存在或无权访问');
        }
        foreach (['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h] as $key => $value) {
            if ($value < 0 || $value > 100 || ($key !== 'x' && $key !== 'y' && $value <= 0)) {
                throw new HttpException(422, '标注坐标无效');
            }
        }
        if ($x + $w > 100 || $y + $h > 100) {
            throw new HttpException(422, '标注区域超出图片范围');
        }
        if ($user === null) {
            throw new HttpException(401, '请先登录');
        }
        $id = Db::insert('archive_photo_tags', [
            'family_id' => $familyId,
            'archive_id' => (int) $archive['id'],
            'archive_file_id' => $fileId,
            'person_id' => $personId,
            'x' => round($x, 2), 'y' => round($y, 2), 'width' => round($w, 2), 'height' => round($h, 2),
            'tagged_by' => (int) $user['id'],
            'review_status' => 'pending',
        ]);
        Audit::log('archive.photo_tag.added', 'archive_photo_tag', $id, [
            'family_id' => $familyId, 'archive_id' => (int) $archive['id'], 'file_id' => $fileId,
        ], (int) $user['id']);
        return $id;
    }

    public static function photoTags(int $fileId): array
    {
        return Db::fetchAll(
            'SELECT t.*, p.name AS person_name FROM archive_photo_tags t
             INNER JOIN family_persons p ON p.id = t.person_id AND p.deleted_at IS NULL
             WHERE t.archive_file_id = ? ORDER BY t.id',
            [$fileId]
        );
    }

    public static function reviewPhotoTag(?array $user, int $tagId, string $status): void
    {
        if (!in_array($status, ['confirmed', 'rejected'], true)) {
            throw new HttpException(422, '无效的审核状态');
        }
        $tag = Db::fetch('SELECT * FROM archive_photo_tags WHERE id = ? LIMIT 1', [$tagId]);
        if ($tag === null) {
            throw new HttpException(404, '标注不存在');
        }
        Policy::require('archive.tag', ['family_id' => (int) $tag['family_id']], $user);
        if ($user === null) {
            throw new HttpException(401, '请先登录');
        }
        Db::update('archive_photo_tags', [
            'review_status' => $status,
            'reviewed_by' => (int) $user['id'],
            'reviewed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$tagId]);
        Audit::log('archive.photo_tag.reviewed', 'archive_photo_tag', $tagId, [
            'family_id' => (int) $tag['family_id'], 'status' => $status,
        ], (int) $user['id']);
    }

    /** 导出授权范围内的档案元数据与文件清单（不绕过文件下载鉴权）。 */
    public static function exportBundle(?array $user, int $archiveId): array
    {
        $archive = self::getVisible($user, $archiveId);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在或无权访问');
        }
        Policy::require('data.export', ['family_id' => (int) $archive['family_id']], $user);
        if ($user === null) {
            throw new HttpException(401, '请先登录');
        }
        $files = self::files($archiveId);
        Audit::log('archive.exported', 'archive', $archiveId, [
            'family_id' => (int) $archive['family_id'], 'files' => count($files),
        ], (int) $user['id']);
        return [
            'archive' => $archive,
            'files' => array_map(static fn (array $f): array => [
                'id' => (int) $f['id'], 'original_filename' => $f['original_filename'],
                'mime_type' => $f['mime_type'], 'sha256' => $f['sha256'], 'file_size' => (int) $f['file_size'],
            ], $files),
            'tags' => self::tags($archiveId),
            'persons' => self::linkedPersons($archiveId),
            'versions' => self::versions($user, $archiveId),
            'exported_at' => date('c'),
        ];
    }

    public static function versions(?array $user, int $archiveId): array
    {
        $archive = self::getVisible($user, $archiveId);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在');
        }
        return Db::fetchAll(
            'SELECT version, title, privacy, status, change_note, created_at FROM archive_versions WHERE archive_id = ? ORDER BY version DESC',
            [$archiveId]
        );
    }

    public static function requestDelete(?array $user, int $archiveId): void
    {
        $archive = self::getVisible($user, $archiveId);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在');
        }
        Policy::require('archive.delete.request', self::context((int) $archive['family_id']), $user);
        Db::update('archives', ['status' => 'delete_requested'], 'id = ?', [$archiveId]);
        Audit::log('archive.delete.request', 'archive', $archiveId, ['family_id' => (int) $archive['family_id']]);
    }

    public static function approveDelete(?array $user, int $archiveId, bool $approve): void
    {
        $archive = Db::fetch('SELECT * FROM archives WHERE id = ? LIMIT 1', [$archiveId]);
        if ($archive === null || (string) $archive['status'] !== 'delete_requested') {
            throw new HttpException(404, '没有待处理的删除申请');
        }
        Policy::require('archive.delete.approve', self::context((int) $archive['family_id']), $user);
        if ($approve) {
            // 软删除：记录与原始文件均保留，便于回滚与审计。
            Db::update('archives', ['status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$archiveId]);
        } else {
            Db::update('archives', ['status' => 'active'], 'id = ?', [$archiveId]);
        }
        Audit::log($approve ? 'archive.delete.approve' : 'archive.delete.reject', 'archive', $archiveId);
    }
}
