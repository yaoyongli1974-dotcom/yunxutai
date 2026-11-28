<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Policy;

/**
 * 家族谱系服务（阶段 C）。所有写操作统一经 Policy，Web 与 API 共用。
 * 关系方向以 person_a_id 的视角定义，反向关系由 RELATION_TYPES 统一映射，不由前端猜测。
 */
final class GenealogyService
{
    public const RELATION_TYPES = [
        'parent' => ['label' => '父母', 'inverse' => 'child', 'lineage' => true, 'bidirectional' => false],
        'child' => ['label' => '子女', 'inverse' => 'parent', 'lineage' => true, 'bidirectional' => false],
        'spouse' => ['label' => '配偶', 'inverse' => 'spouse', 'lineage' => false, 'bidirectional' => true],
        'sibling' => ['label' => '兄弟姐妹', 'inverse' => 'sibling', 'lineage' => false, 'bidirectional' => true],
        'adoptive_parent' => ['label' => '养父母', 'inverse' => 'adoptive_child', 'lineage' => true, 'bidirectional' => false],
        'adoptive_child' => ['label' => '养子女', 'inverse' => 'adoptive_parent', 'lineage' => true, 'bidirectional' => false],
        'step_parent' => ['label' => '继父母', 'inverse' => 'step_child', 'lineage' => true, 'bidirectional' => false],
        'step_child' => ['label' => '继子女', 'inverse' => 'step_parent', 'lineage' => true, 'bidirectional' => false],
        'other' => ['label' => '其他亲属', 'inverse' => 'other', 'lineage' => false, 'bidirectional' => true],
    ];

    public const ACTIVE_STATUSES = ['pending', 'confirmed', 'disputed'];

    /** @param array<string,mixed> $user */
    private static function family(array $user, int $familyId, string $capability): array
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require($capability, ['family_id' => $familyId, 'family' => $family], $user);
        return $family;
    }

    private static function person(int $familyId, int $personId): array
    {
        $person = Db::fetch(
            'SELECT * FROM family_persons WHERE id = ? AND family_id = ? AND deleted_at IS NULL LIMIT 1',
            [$personId, $familyId]
        );
        if ($person === null) {
            throw new HttpException(404, '人物不存在');
        }
        return $person;
    }

    /**
     * 提交关系候选：永不直接成为正式关系，必须经审核。
     * @param array<string,mixed> $user
     */
    public static function submit(array $user, int $familyId, int $a, int $b, string $type, ?string $note = null): int
    {
        self::family($user, $familyId, 'relationship.submit');
        if (!isset(self::RELATION_TYPES[$type])) {
            throw new HttpException(422, '关系类型不合法');
        }
        if ($a <= 0 || $b <= 0 || $a === $b) {
            throw new HttpException(422, '关系不能指向同一个人');
        }
        self::person($familyId, $a);
        self::person($familyId, $b);

        $exists = (int) Db::value(
            'SELECT COUNT(*) FROM person_relationships WHERE family_id = ? AND person_a_id = ? AND person_b_id = ?
             AND relationship_type = ? AND status IN (\'pending\',\'confirmed\',\'disputed\')',
            [$familyId, $a, $b, $type]
        );
        if ($exists > 0) {
            throw new HttpException(409, '该关系已存在或正在审核');
        }
        if (self::RELATION_TYPES[$type]['lineage'] && self::wouldCycle($familyId, $a, $b, $type, 0)) {
            throw new HttpException(422, '该关系会形成血缘循环');
        }

        $id = Db::insert('person_relationships', [
            'family_id' => $familyId,
            'person_a_id' => $a,
            'person_b_id' => $b,
            'relationship_type' => $type,
            'direction' => self::RELATION_TYPES[$type]['bidirectional'] ? 'bidirectional' : 'a_to_b',
            'status' => 'pending',
            'source_type' => 'user_input',
            'note' => $note !== null ? mb_substr(trim($note), 0, 255) : null,
            'submitted_by' => (int) $user['id'],
        ]);
        Audit::log('relationship.submit', 'family', $familyId, [
            'relationship_id' => $id, 'person_a_id' => $a, 'person_b_id' => $b, 'type' => $type,
        ], (int) $user['id']);
        return $id;
    }

    /** 审核关系：只有 owner/admin 可确认；确认前再次检查血缘循环。 */
    public static function review(array $user, int $familyId, int $relationId, string $status): void
    {
        self::family($user, $familyId, 'relationship.review');
        if (!in_array($status, ['confirmed', 'disputed', 'rejected'], true)) {
            throw new HttpException(422, '审核结果不合法');
        }
        $relation = self::relation($familyId, $relationId);
        if (!in_array((string) $relation['status'], ['pending', 'disputed'], true)) {
            throw new HttpException(409, '该关系已处理');
        }
        if ($status === 'confirmed' && self::RELATION_TYPES[(string) $relation['relationship_type']]['lineage']
            && self::wouldCycle(
                $familyId,
                (int) $relation['person_a_id'],
                (int) $relation['person_b_id'],
                (string) $relation['relationship_type'],
                $relationId
            )) {
            throw new HttpException(422, '该关系会形成血缘循环');
        }
        Db::update('person_relationships', [
            'status' => $status,
            'reviewed_by' => (int) $user['id'],
            'reviewed_at' => date('Y-m-d H:i:s'),
        ], 'id = ? AND family_id = ?', [$relationId, $familyId]);
        Audit::log('relationship.review', 'family', $familyId, [
            'relationship_id' => $relationId, 'status' => $status,
        ], (int) $user['id']);
    }

    /** 撤销关系：审核者或提交者可撤销，撤销后不再出现在谱系中。 */
    public static function revoke(array $user, int $familyId, int $relationId): void
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        $role = Policy::familyRole($familyId, (int) ($user['id'] ?? 0));
        $relation = self::relation($familyId, $relationId);
        $isSubmitter = (int) $relation['submitted_by'] === (int) ($user['id'] ?? 0);
        if (!in_array($role, ['owner', 'admin'], true) && !$isSubmitter) {
            throw new HttpException(404, '关系不存在或无权操作');
        }
        Db::update('person_relationships', ['status' => 'revoked'], 'id = ? AND family_id = ?', [$relationId, $familyId]);
        Audit::log('relationship.revoke', 'family', $familyId, ['relationship_id' => $relationId], (int) $user['id']);
    }

    /** 争议说明：只允许家族管理员标记争议，记录进入审计。 */
    public static function dispute(array $user, int $familyId, int $relationId, string $reason): void
    {
        self::review($user, $familyId, $relationId, 'disputed');
        Audit::log('relationship.dispute', 'family', $familyId, [
            'relationship_id' => $relationId, 'reason' => mb_substr(trim($reason), 0, 255),
        ], (int) $user['id']);
    }

    /**
     * @param array<string,mixed> $user
     * @return list<array<string,mixed>>
     */
    public static function relations(array $user, int $familyId): array
    {
        $family = FamilyService::find($familyId);
        if ($family === null || !Policy::can('family.view', ['family_id' => $familyId, 'family' => $family], $user)) {
            throw new HttpException(404, '家族不存在');
        }
        $rows = Db::fetchAll(
            'SELECT r.*, pa.name AS person_a_name, pb.name AS person_b_name
             FROM person_relationships r
             JOIN family_persons pa ON pa.id = r.person_a_id
             JOIN family_persons pb ON pb.id = r.person_b_id
             WHERE r.family_id = ? AND pa.deleted_at IS NULL AND pb.deleted_at IS NULL
             ORDER BY r.id LIMIT 500',
            [$familyId]
        );
        $role = Policy::familyRole($familyId, isset($user['id']) ? (int) $user['id'] : null);
        $privileged = in_array($role, ['owner', 'admin'], true);
        return array_values(array_filter($rows, static function (array $row) use ($privileged, $user): bool {
            if (in_array((string) $row['status'], ['rejected', 'revoked'], true) && !$privileged) {
                return false;
            }
            $userId = isset($user['id']) ? (int) $user['id'] : null;
            return Policy::canViewResource('person', (int) $row['person_a_id'], $userId)
                && Policy::canViewResource('person', (int) $row['person_b_id'], $userId);
        }));
    }

    /**
     * 谱系按需加载：只返回当前用户可见的人物与已确认关系，限制单次节点数。
     * @param array<string,mixed> $user
     * @return array{persons:list<array<string,mixed>>,relations:list<array<string,mixed>>,truncated:bool}
     */
    public static function tree(array $user, int $familyId, int $centerId = 0, int $depth = 2): array
    {
        $relations = self::relations($user, $familyId);
        $confirmed = array_values(array_filter($relations, static fn (array $r): bool => $r['status'] === 'confirmed'));
        $persons = Db::fetchAll(
            'SELECT id, name, gender, birth_date, death_date, privacy, verification_status
             FROM family_persons WHERE family_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 500',
            [$familyId]
        );
        $visible = [];
        foreach ($persons as $person) {
            if (Policy::canViewResource('person', (int) $person['id'], isset($user['id']) ? (int) $user['id'] : null)) {
                $visible[$person['id']] = $person;
            }
        }
        $confirmed = array_values(array_filter($confirmed, static fn (array $r): bool =>
            isset($visible[(int) $r['person_a_id']], $visible[(int) $r['person_b_id']])));

        $depth = max(1, min(4, $depth));
        if ($centerId > 0 && isset($visible[$centerId])) {
            $adjacent = [];
            foreach ($confirmed as $r) {
                $adjacent[$r['person_a_id']][] = $r['person_b_id'];
                $adjacent[$r['person_b_id']][] = $r['person_a_id'];
            }
            $seen = [$centerId => 0];
            $queue = [$centerId];
            while ($queue !== []) {
                $current = array_shift($queue);
                if ($seen[$current] >= $depth) {
                    continue;
                }
                foreach ($adjacent[$current] ?? [] as $next) {
                    if (!isset($seen[$next])) {
                        $seen[$next] = $seen[$current] + 1;
                        $queue[] = $next;
                    }
                }
            }
            $visible = array_intersect_key($visible, $seen);
            $confirmed = array_values(array_filter($confirmed, static fn (array $r): bool =>
                isset($visible[(int) $r['person_a_id']], $visible[(int) $r['person_b_id']])));
        }

        $truncated = count($visible) >= 500;
        if ($truncated) {
            $visible = array_slice($visible, 0, 200, true);
            $ids = array_keys($visible);
            $confirmed = array_values(array_filter($confirmed, static fn (array $r): bool =>
                in_array((int) $r['person_a_id'], $ids, true) && in_array((int) $r['person_b_id'], $ids, true)));
        }
        return ['persons' => array_values($visible), 'relations' => $confirmed, 'truncated' => $truncated];
    }

    /** @param array<string,mixed> $user */
    public static function createBranch(array $user, int $familyId, string $name, ?int $parentId): int
    {
        self::family($user, $familyId, 'person.edit');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new HttpException(422, '支系名称不合法');
        }
        if ($parentId !== null && (int) Db::value(
            'SELECT COUNT(*) FROM family_branches WHERE id = ? AND family_id = ?', [$parentId, $familyId]
        ) === 0) {
            throw new HttpException(404, '上级支系不存在');
        }
        $id = Db::insert('family_branches', [
            'family_id' => $familyId, 'parent_branch_id' => $parentId, 'name' => $name,
        ]);
        Audit::log('branch.create', 'family', $familyId, ['branch_id' => $id], (int) $user['id']);
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public static function branches(array $user, int $familyId): array
    {
        $family = FamilyService::find($familyId);
        if ($family === null || !Policy::can('family.view', ['family_id' => $familyId, 'family' => $family], $user)) {
            throw new HttpException(404, '家族不存在');
        }
        return Db::fetchAll('SELECT * FROM family_branches WHERE family_id = ? ORDER BY id', [$familyId]);
    }

    /** @param array<string,mixed> $user */
    public static function assignBranch(array $user, int $familyId, int $personId, int $branchId): void
    {
        self::family($user, $familyId, 'person.edit');
        self::person($familyId, $personId);
        if ((int) Db::value('SELECT COUNT(*) FROM family_branches WHERE id = ? AND family_id = ?', [$branchId, $familyId]) === 0) {
            throw new HttpException(404, '支系不存在');
        }
        Db::query(
            'INSERT IGNORE INTO person_branch_members (family_id, person_id, branch_id, created_by) VALUES (?,?,?,?)',
            [$familyId, $personId, $branchId, (int) $user['id']]
        );
        Audit::log('branch.assign', 'person', $personId, ['family_id' => $familyId, 'branch_id' => $branchId], (int) $user['id']);
    }

    /**
     * 人物合并：保留主人物与原记录，迁移关联并写入合并日志；被合并人物软删除，不丢档案/馆关联。
     * @param array<string,mixed> $user
     */
    public static function mergePersons(array $user, int $familyId, int $primaryId, int $duplicateId): void
    {
        self::family($user, $familyId, 'person.merge');
        if ($primaryId === $duplicateId) {
            throw new HttpException(422, '请选择两个不同的人物');
        }
        $primary = self::person($familyId, $primaryId);
        $duplicate = self::person($familyId, $duplicateId);
        if ((int) Db::value('SELECT COUNT(*) FROM person_merge_log WHERE duplicate_id = ?', [$duplicateId]) > 0) {
            throw new HttpException(409, '该人物已合并');
        }
        Db::transaction(static function () use ($familyId, $primaryId, $duplicateId, $user): void {
            Db::query(
                'UPDATE IGNORE person_memorial_links SET person_id = ? WHERE person_id = ? AND family_id = ?',
                [$primaryId, $duplicateId, $familyId]
            );
            Db::query('DELETE FROM person_memorial_links WHERE person_id = ? AND family_id = ?', [$duplicateId, $familyId]);
            Db::query(
                'UPDATE IGNORE person_branch_members SET person_id = ? WHERE person_id = ? AND family_id = ?',
                [$primaryId, $duplicateId, $familyId]
            );
            Db::query('DELETE FROM person_branch_members WHERE person_id = ? AND family_id = ?', [$duplicateId, $familyId]);
            Db::query(
                'UPDATE person_relationships SET person_a_id = ? WHERE person_a_id = ? AND family_id = ?',
                [$primaryId, $duplicateId, $familyId]
            );
            Db::query(
                'UPDATE person_relationships SET person_b_id = ? WHERE person_b_id = ? AND family_id = ?',
                [$primaryId, $duplicateId, $familyId]
            );
            Db::query('DELETE FROM person_relationships WHERE person_a_id = person_b_id');
            Db::query(
                'DELETE r1 FROM person_relationships r1 JOIN person_relationships r2
                 ON r2.id < r1.id AND r2.family_id = r1.family_id AND r2.person_a_id = r1.person_a_id
                 AND r2.person_b_id = r1.person_b_id AND r2.relationship_type = r1.relationship_type
                 WHERE r1.family_id = ?',
                [$familyId]
            );
            Db::insert('person_merge_log', [
                'family_id' => $familyId, 'primary_id' => $primaryId, 'duplicate_id' => $duplicateId,
                'created_by' => (int) $user['id'],
            ]);
            Db::update(
                'family_persons',
                ['deleted_at' => date('Y-m-d H:i:s'), 'privacy' => 'private', 'verification_status' => 'none'],
                'id = ? AND family_id = ?',
                [$duplicateId, $familyId]
            );
        });
        // 姓名属于个人信息：只保留摘要哈希，审计仍可关联到具体人物 ID。
        Audit::log('person.merge', 'person', $primaryId, [
            'family_id' => $familyId, 'duplicate_id' => $duplicateId,
            'duplicate_name_hash' => hash('sha256', (string) $duplicate['name']),
            'primary_name_hash' => hash('sha256', (string) $primary['name']),
        ], (int) $user['id']);
        Policy::clearCache();
    }

    /** 同名候选（供人工确认，不是自动合并）。 */
    public static function duplicates(int $familyId): array
    {
        return Db::fetchAll(
            'SELECT name, COUNT(*) AS total, GROUP_CONCAT(id ORDER BY id) AS ids
             FROM family_persons WHERE family_id = ? AND deleted_at IS NULL
             GROUP BY name HAVING total > 1 ORDER BY total DESC, name',
            [$familyId]
        );
    }

    private static function relation(int $familyId, int $relationId): array
    {
        $relation = Db::fetch('SELECT * FROM person_relationships WHERE id = ? AND family_id = ? LIMIT 1', [$relationId, $familyId]);
        if ($relation === null) {
            throw new HttpException(404, '关系不存在');
        }
        return $relation;
    }

    /** 沿已确认的血缘边（父/母 → 子/女）检查新增关系是否闭环。 */
    private static function wouldCycle(int $familyId, int $a, int $b, string $type, int $ignoreId): bool
    {
        $typeDef = self::RELATION_TYPES[$type];
        [$parent, $child] = in_array($type, ['parent', 'adoptive_parent', 'step_parent'], true)
            ? [$a, $b] : [$b, $a];
        if (!$typeDef['lineage']) {
            return false;
        }
        $edges = Db::fetchAll(
            'SELECT person_a_id, person_b_id, relationship_type FROM person_relationships
             WHERE family_id = ? AND status = ? AND id <> ?',
            [$familyId, 'confirmed', $ignoreId]
        );
        $adjacent = [];
        foreach ($edges as $edge) {
            $edgeType = (string) $edge['relationship_type'];
            if (!self::RELATION_TYPES[$edgeType]['lineage']) {
                continue;
            }
            $edgeParent = in_array($edgeType, ['parent', 'adoptive_parent', 'step_parent'], true)
                ? (int) $edge['person_a_id'] : (int) $edge['person_b_id'];
            $edgeChild = $edgeParent === (int) $edge['person_a_id']
                ? (int) $edge['person_b_id'] : (int) $edge['person_a_id'];
            $adjacent[$edgeParent][] = $edgeChild;
        }
        $seen = [];
        $queue = [$child];
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            if ($current === $parent) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($adjacent[$current] ?? [] as $next) {
                $queue[] = (int) $next;
            }
        }
        return false;
    }
}
