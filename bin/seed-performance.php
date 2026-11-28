#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 本地性能数据集生成器（仅用于隔离的 *_perf 数据库，禁止对生产或 jidian_test 运行）。
 *
 * 用法：
 *   DB_DATABASE=jidian_perf php bin/seed-performance.php \
 *     --families=100 --persons=1000 --relations=10000 \
 *     --archives=1000 --notifications=2000 --workflows=200
 *
 * 约定：
 * - 通过 BASE_PATH + app/bootstrap.php 读取 config('db.*')；DB_DATABASE 决定目标库。
 * - 数据库名必须包含 perf 且不得为 jidian / jidian_test，否则拒绝运行。
 * - 所有写入走 PDO 占位符绑定；表名/列名均为本文件内固定字面量，无外部拼接。
 * - 所有标记行使用固定测试前缀：家族名 PERF-*、slug perf-*、用户 perf-seed@example.invalid。
 * - 重跑时先删除全部 PERF- 标记行（含上一次运行的种子用户），再重新生成，保证幂等。
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use App\Core\Db;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

/** @return array<string,array{min:int,max:int}> 每个选项的硬上限，防止误传超大值拖垮本地环境。 */
function limits(): array
{
    return [
        'families'     => ['min' => 0, 'max' => 2000],
        'persons'      => ['min' => 0, 'max' => 100000],
        'relations'    => ['min' => 0, 'max' => 200000],
        'archives'     => ['min' => 0, 'max' => 50000],
        'notifications'=> ['min' => 0, 'max' => 100000],
        'workflows'    => ['min' => 0, 'max' => 10000],
    ];
}

/**
 * @param array<string,string|false> $options
 */
function optionInt(array $options, string $name, int $default, int $min, int $max): int
{
    if (!array_key_exists($name, $options)) {
        return $default;
    }
    $raw = $options[$name];
    if (!is_string($raw)) {
        fwrite(STDERR, "--{$name} 缺少数值，使用默认值 {$default}\n");
        return $default;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false) {
        fwrite(STDERR, "--{$name} 不是整数，使用默认值 {$default}\n");
        return $default;
    }
    $clamped = max($min, min($max, (int) $value));
    if ($clamped !== (int) $value) {
        fwrite(STDERR, "--{$name}={$value} 超出安全范围 [{$min},{$max}]，已收敛为 {$clamped}\n");
    }
    return $clamped;
}

/** 固定字面量的批量插入；$columns/$rows 均由本脚本内部构造，值全部绑定。 */
function insertBatch(string $table, array $columns, array $rows, int $batchSize = 100): void
{
    if ($rows === []) {
        return;
    }
    $oneRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $head = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ';
    foreach (array_chunk($rows, $batchSize) as $chunk) {
        $sql = $head . implode(', ', array_fill(0, count($chunk), $oneRow));
        $params = [];
        foreach ($chunk as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }
        Db::query($sql, $params);
    }
}

/** 按 family_id（或主键 id）删除标记数据；$table/$column 为本脚本固定的字面量。 */
function deleteByFamilies(string $table, array $familyIds, string $column = 'family_id'): void
{
    if (!in_array($column, ['family_id', 'id'], true)) {
        throw new InvalidArgumentException('非法的删除列');
    }
    foreach (array_chunk($familyIds, 200) as $chunk) {
        $sql = 'DELETE FROM ' . $table . ' WHERE ' . $column . ' IN ('
            . implode(',', array_fill(0, count($chunk), '?')) . ')';
        Db::query($sql, $chunk);
    }
}

/** 绑定 IN 列表查询 id => name 映射，避免依赖 AUTO_INCREMENT 连续性。 */
function idMap(string $table, string $idColumn, string $nameColumn, array $names): array
{
    $map = [];
    foreach (array_chunk($names, 200) as $chunk) {
        $sql = 'SELECT ' . $idColumn . ', ' . $nameColumn . ' FROM ' . $table
            . ' WHERE ' . $nameColumn . ' IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
        foreach (Db::fetchAll($sql, $chunk) as $row) {
            $map[(string) $row[$nameColumn]] = (int) $row[$idColumn];
        }
    }
    return $map;
}

$declared = limits();
$options = getopt('', [
    'families:', 'persons:', 'relations:', 'archives:', 'notifications:', 'workflows:',
]);

$scale = [];
foreach ($declared as $name => $rule) {
    $scale[$name] = optionInt(
        $options,
        $name,
        (int) (['families' => 100, 'persons' => 1000, 'relations' => 10000,
            'archives' => 1000, 'notifications' => 2000, 'workflows' => 200][$name]),
        $rule['min'],
        $rule['max']
    );
}
if ($scale['families'] === 0
    && ($scale['persons'] + $scale['relations'] + $scale['archives'] + $scale['workflows']) > 0) {
    $scale['families'] = 1;
    fwrite(STDERR, "--families=0 与子表数据冲突，已收敛为 1\n");
}

$database = (string) config('db.database');
if ($database === '' || preg_match('/^[a-zA-Z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "数据库名不合法：{$database}\n");
    exit(1);
}
if (in_array($database, ['jidian', 'jidian_test', 'production'], true)
    || stripos($database, 'perf') === false) {
    fwrite(STDERR, "拒绝运行：目标库 {$database} 不是隔离的 *_perf 数据库。"
        . "本脚本只写 PERF- 标记数据，禁止用于生产或 jidian_test。\n");
    exit(1);
}

$requiredTables = [
    'users', 'families', 'family_members', 'family_persons', 'person_relationships',
    'archives', 'notifications', 'ai_workflows', 'ai_workflow_steps', 'ai_generation_logs',
];
$existing = [];
foreach (Db::fetchAll(
    'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'
) as $row) {
    $existing[(string) $row['t']] = true;
}
$missing = array_values(array_diff($requiredTables, array_keys($existing)));
if ($missing !== []) {
    fwrite(STDERR, '缺少数据表：' . implode(', ', $missing)
        . "。请先运行 DB_DATABASE={$database} php bin/install.php --fresh --demo 与 php bin/migrate-v2.php。\n");
    exit(1);
}

echo "目标数据库：{$database}（隔离性能库）\n";
printf(
    "目标规模：families=%d persons=%d relations=%d archives=%d notifications=%d workflows=%d\n",
    $scale['families'], $scale['persons'], $scale['relations'],
    $scale['archives'], $scale['notifications'], $scale['workflows']
);

$started = microtime(true);
$seedEmail = 'perf-seed@example.invalid';
$seedName = 'PERF-Seed-User';
$noticeType = 'PERF-notice';

try {
    // ---- 幂等：先清理所有 PERF- 标记行（子表 -> 父表），保留非标记数据不受影响 ----
    $markerFamilies = Db::fetchAll(
        "SELECT id FROM families WHERE slug LIKE 'perf-family-%' OR name LIKE 'PERF-%'"
    );
    $familyIds = array_map(static fn (array $r): int => (int) $r['id'], $markerFamilies);

    if ($familyIds !== []) {
        printf("检测到 %d 条 PERF- 标记家族，先删除旧标记数据……\n", count($familyIds));
        foreach (array_chunk($familyIds, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            // 工作流步骤/生成日志引用工作流，必须先于工作流删除。
            Db::query(
                'DELETE FROM ai_workflow_steps WHERE workflow_id IN '
                . '(SELECT id FROM ai_workflows WHERE family_id IN (' . $ph . '))',
                $chunk
            );
            Db::query(
                'DELETE FROM ai_generation_logs WHERE family_id IN (' . $ph . ')',
                $chunk
            );
        }
        foreach (['ai_workflows', 'notifications', 'archives', 'person_relationships',
            'family_persons', 'family_members'] as $table) {
            deleteByFamilies($table, $familyIds);
        }
        deleteByFamilies('families', $familyIds, 'id');
    }

    // 标记用户的通知独立于家族，单独清理；随后删除用户本身（若有残留引用则报错，不静默吞掉）。
    Db::query('DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE email = ?) AND type = ?',
        [$seedEmail, $noticeType]);
    Db::query('DELETE FROM users WHERE email = ?', [$seedEmail]);

    // ---- 种子用户 ----
    $seedUserId = Db::insert('users', [
        'email' => $seedEmail,
        'password_hash' => password_hash('PERF-ONLY-SEED', PASSWORD_DEFAULT),
        'display_name' => $seedName,
        'role' => 'user',
        'status' => 'active',
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);

    $familyIds = [];
    $familySlugs = [];
    if ($scale['families'] > 0) {
        $rows = [];
        for ($i = 1; $i <= $scale['families']; $i++) {
            $slug = sprintf('perf-family-%05d', $i);
            $familySlugs[] = $slug;
            $rows[] = [
                'PERF-Family-' . sprintf('%05d', $i),
                $slug,
                'PERF',
                'PERF synthetic family (local test only)',
                'PERF-local',
                ['private', 'unlisted', 'public'][$i % 3],
                $seedUserId,
                'active',
            ];
        }
        insertBatch('families', [
            'name', 'slug', 'surname', 'description', 'origin_place', 'privacy', 'owner_user_id', 'status',
        ], $rows);
        $slugToId = idMap('families', 'id', 'slug', $familySlugs);
        foreach ($familySlugs as $slug) {
            $familyIds[] = $slugToId[$slug] ?? 0;
        }
        $familyIds = array_values(array_filter($familyIds, static fn (int $id): bool => $id > 0));
        if (count($familyIds) !== $scale['families']) {
            throw new RuntimeException('家族 id 回读数量不足，已中止以避免外键误写');
        }

        $memberRows = [];
        foreach ($familyIds as $fid) {
            $memberRows[] = [$fid, $seedUserId, 'owner', 'active', date('Y-m-d H:i:s'), null];
        }
        insertBatch('family_members', [
            'family_id', 'user_id', 'role', 'status', 'joined_at', 'invited_by',
        ], $memberRows);
    }

    // ---- 人物（每个家族至少 2 人便于生成关系；约 5% 软删除用于覆盖 deleted_at 索引） ----
    $personsByFamily = [];
    if ($scale['persons'] > 0 && $familyIds !== []) {
        $rows = [];
        $names = [];
        for ($i = 1; $i <= $scale['persons']; $i++) {
            $name = 'PERF-Person-' . sprintf('%06d', $i);
            $names[] = $name;
            $fid = $familyIds[($i - 1) % count($familyIds)];
            $personsByFamily[$fid][] = $name;
            $rows[] = [
                $fid,
                $name,
                ['unknown', 'male', 'female', 'other'][$i % 4],
                sprintf('19%02d-01-01', 40 + ($i % 50)),
                sprintf('20%02d-01-01', 10 + ($i % 15)),
                'PERF-birth',
                'PERF-death',
                'PERF synthetic person (local test only)',
                ['private', 'family', 'public'][$i % 3],
                'none',
                1,
                $seedUserId,
                $i % 20 === 0 ? date('Y-m-d H:i:s') : null,
            ];
        }
        insertBatch('family_persons', [
            'family_id', 'name', 'gender', 'birth_date', 'death_date', 'birth_place', 'death_place',
            'biography', 'privacy', 'verification_status', 'is_deceased', 'created_by', 'deleted_at',
        ], $rows);
        $nameToId = idMap('family_persons', 'id', 'name', $names);
        foreach ($personsByFamily as $fid => $personNames) {
            $personsByFamily[$fid] = array_values(array_filter(array_map(
                static fn (string $n): int => $nameToId[$n] ?? 0, $personNames
            ), static fn (int $id): bool => $id > 0));
        }
        if (array_sum(array_map('count', $personsByFamily)) !== $scale['persons']) {
            throw new RuntimeException('人物 id 回读数量不足，已中止以避免外键误写');
        }
    }

    // ---- 谱系关系（同一家族内成对；规模大于唯一对数时允许重复组合，仅作压力样本） ----
    if ($scale['relations'] > 0 && $personsByFamily !== []) {
        $usable = array_values(array_filter($personsByFamily, static fn (array $p): bool => count($p) >= 2));
        if ($usable !== []) {
            $rows = [];
            $types = ['parent', 'child', 'spouse', 'sibling', 'other'];
            $statuses = ['confirmed', 'pending', 'confirmed', 'disputed', 'rejected'];
            $sources = ['user_input', 'archive_ocr', 'ai_extraction'];
            for ($i = 1; $i <= $scale['relations']; $i++) {
                $persons = $usable[($i - 1) % count($usable)];
                $a = $persons[($i - 1) % count($persons)];
                $b = $persons[$i % count($persons)];
                if ($a === $b) {
                    continue;
                }
                $familyId = $familyIds[($i - 1) % count($familyIds)];
                $rows[] = [
                    $familyId, $a, $b, $types[$i % count($types)],
                    $i % 3 === 0 ? 'bidirectional' : 'a_to_b',
                    $statuses[$i % count($statuses)],
                    $sources[$i % count($sources)],
                    'PERF synthetic relation',
                    'PERF-rel-' . $i,
                    $seedUserId,
                ];
            }
            insertBatch('person_relationships', [
                'family_id', 'person_a_id', 'person_b_id', 'relationship_type', 'direction',
                'status', 'source_type', 'note', 'source_id', 'submitted_by',
            ], $rows);
        } else {
            fwrite(STDERR, "每个家族不足 2 人，已跳过关系生成。\n");
        }
    }

    // ---- 档案 ----
    if ($scale['archives'] > 0 && $familyIds !== []) {
        $rows = [];
        for ($i = 1; $i <= $scale['archives']; $i++) {
            $rows[] = [
                $familyIds[($i - 1) % count($familyIds)],
                'PERF-Archive-' . sprintf('%06d', $i),
                'PERF synthetic archive (local test only)',
                ['document', 'photo', 'video'][$i % 3],
                sprintf('19%02d-05-06', 50 + ($i % 40)),
                'PERF-local',
                'user_input',
                'PERF synthetic source',
                ['private', 'family', 'public'][$i % 3],
                $i % 7 === 0 ? 'archived' : 'active',
                $seedUserId,
                null,
            ];
        }
        insertBatch('archives', [
            'family_id', 'title', 'description', 'archive_type', 'event_date', 'location',
            'source_type', 'source_description', 'privacy', 'status', 'created_by', 'deleted_at',
        ], $rows);
    }

    // ---- 通知 ----
    if ($scale['notifications'] > 0) {
        $rows = [];
        for ($i = 1; $i <= $scale['notifications']; $i++) {
            $rows[] = [
                $seedUserId,
                $familyIds === [] ? null : $familyIds[($i - 1) % count($familyIds)],
                $noticeType,
                'PERF-Notification-' . sprintf('%06d', $i),
                'PERF synthetic notification (local test only)',
                null,
                'in_app',
                'sent',
                $i % 2 === 0 ? date('Y-m-d H:i:s') : null,
                null,
            ];
        }
        insertBatch('notifications', [
            'user_id', 'family_id', 'type', 'title', 'body', 'link', 'channel', 'status', 'read_at', 'dedupe_key',
        ], $rows);
    }

    // ---- AI 工作流（run_after 设为过去时间，便于 EXPLAIN 领取队列） ----
    if ($scale['workflows'] > 0 && $familyIds !== []) {
        $rows = [];
        $runAfter = date('Y-m-d H:i:s', time() - 86400);
        $types = ['family_timeline', 'archive_digest', 'oral_history_summary'];
        for ($i = 1; $i <= $scale['workflows']; $i++) {
            $rows[] = [
                $familyIds[($i - 1) % count($familyIds)],
                $types[$i % count($types)],
                $i % 10 === 0 ? 'processing' : 'queued',
                'perf-idem-' . sprintf('%06d', $i),
                '{"marker":"PERF-","n":' . $i . '}',
                null,
                null,
                'demo',
                'v1',
                0,
                3,
                $runAfter,
                null,
                null,
                null,
                $seedUserId,
                null,
                null,
            ];
        }
        insertBatch('ai_workflows', [
            'family_id', 'workflow_type', 'status', 'idempotency_key', 'input_json', 'output_json',
            'current_step', 'provider', 'prompt_version', 'retry_count', 'max_retries', 'run_after',
            'deadline_at', 'locked_by', 'lease_until', 'created_by', 'started_at', 'finished_at',
        ], $rows);
    }

    $counts = [];
    $countSpecs = [
        'families' => 'families', 'family_members' => 'family_members',
        'family_persons' => 'persons', 'person_relationships' => 'relations',
        'archives' => 'archives', 'notifications' => 'notifications', 'ai_workflows' => 'workflows',
    ];
    foreach ($countSpecs as $table => $label) {
        $counts[$label] = (int) Db::value('SELECT COUNT(*) FROM ' . $table);
    }
    $elapsed = microtime(true) - $started;
    echo "\n标记数据写入完成（全库计数，含安装/演示数据）：\n";
    foreach ($counts as $label => $count) {
        printf("  %-20s %d\n", $label, $count);
    }
    printf("耗时：%.3f 秒\n", $elapsed);
    echo "提示：本脚本只写 PERF- 标记行；清理请用同一命令重跑（先删后建）或 DROP DATABASE {$database}。\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n写入失败：" . $e->getMessage() . "\n");
    exit(1);
}
