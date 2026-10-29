<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Verification
{
    public const METHODS = [
        'death_certificate' => '死亡证明或官方死亡登记材料',
        'relationship_proof' => '亲属关系证明与身份材料',
        'claim' => '已通过的纪念馆申领记录',
        'other' => '其他可核验材料',
    ];

    public static function find(int $id): ?array
    {
        return Db::fetch(
            'SELECT v.*, m.slug, m.title AS memorial_title, m.verification_status, m.created_by,
                    d.full_name AS deceased_name, d.birth_date, d.death_date,
                    u.display_name AS submitter_name, u.email AS submitter_email
             FROM memorial_verifications v
             JOIN memorials m ON m.id = v.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             JOIN users u ON u.id = v.submitted_by
             WHERE v.id = ? LIMIT 1',
            [$id]
        );
    }

    public static function latestFor(int $memorialId): ?array
    {
        return Db::fetch(
            'SELECT v.*, u.display_name AS submitter_name
             FROM memorial_verifications v
             JOIN users u ON u.id = v.submitted_by
             WHERE v.memorial_id = ?
             ORDER BY v.created_at DESC, v.id DESC
             LIMIT 1',
            [$memorialId]
        );
    }

    public static function create(array $data): int
    {
        return Db::insert('memorial_verifications', [
            'memorial_id' => $data['memorial_id'],
            'submitted_by' => $data['submitted_by'],
            'method' => $data['method'],
            'statement' => $data['statement'],
            'document_path' => $data['document_path'] ?? null,
            'document_name' => $data['document_name'] ?? null,
            'document_mime' => $data['document_mime'] ?? null,
            'document_size' => $data['document_size'] ?? null,
        ]);
    }

    public static function paginate(?string $status, int $limit, int $offset): array
    {
        $where = '1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND v.status = ?';
            $params[] = $status;
        }

        return Db::fetchAll(
            "SELECT v.*, m.slug, d.full_name AS deceased_name, u.display_name AS submitter_name
             FROM memorial_verifications v
             JOIN memorials m ON m.id = v.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             JOIN users u ON u.id = v.submitted_by
             WHERE {$where}
             ORDER BY FIELD(v.status, 'pending', 'rejected', 'approved'), v.created_at ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function count(?string $status = null): int
    {
        if ($status === null) {
            return (int) Db::value('SELECT COUNT(*) FROM memorial_verifications');
        }
        return (int) Db::value('SELECT COUNT(*) FROM memorial_verifications WHERE status = ?', [$status]);
    }

    public static function setStatus(int $id, string $status, int $reviewerId, ?string $note): void
    {
        Db::update('memorial_verifications', [
            'status' => $status,
            'reviewer_id' => $reviewerId,
            'review_note' => $note ?: null,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
    }

    public static function pendingForMemorial(int $memorialId): ?array
    {
        return Db::fetch(
            "SELECT * FROM memorial_verifications WHERE memorial_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$memorialId]
        );
    }
}
