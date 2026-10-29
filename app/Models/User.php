<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Core\Str;

final class User
{
    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Db::fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email))]);
    }

    public static function create(string $email, string $password, string $displayName): int
    {
        return Db::insert('users', [
            'email' => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => trim($displayName),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        Db::update('users', $data, 'id = ?', [$id]);
    }

    public static function searchPaginate(string $query, int $limit, int $offset): array
    {
        $where = '1 = 1';
        $params = [];
        if ($query !== '') {
            $where = '(email LIKE ? OR display_name LIKE ?)';
            $like = '%' . $query . '%';
            $params = [$like, $like];
        }
        return Db::fetchAll(
            "SELECT * FROM users WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function searchCount(string $query): int
    {
        if ($query === '') {
            return (int) Db::value('SELECT COUNT(*) FROM users');
        }
        $like = '%' . $query . '%';
        return (int) Db::value('SELECT COUNT(*) FROM users WHERE email LIKE ? OR display_name LIKE ?', [$like, $like]);
    }

    public static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '·';
        }
        return mb_substr($name, 0, 1);
    }
}
