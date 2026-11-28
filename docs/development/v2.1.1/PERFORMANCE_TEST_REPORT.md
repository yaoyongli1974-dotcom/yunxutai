# Phase 9 · Lane D — Local Synthetic Performance Test Report

- Date: 2026-11-28
- Branch: `feature/v2.1.1-production-hardening`
- Write scope: `bin/seed-performance.php` (new), this report (new). No application code, tests, other docs, commits or pushes were touched.
- Status labels: **EXECUTED** (command run; output recorded) / **NOT EXECUTED** (explicitly not attempted, with reason).

## 1. Explicit scope disclaimer

**All data in this report is locally generated synthetic data (`PERF-` markers) in a disposable database. It is NOT real production load, NOT real user data, and NOT a production latency SLA measurement.** Timings are single-process SQL-layer microbenchmarks on a warm local MariaDB instance; they are order-of-magnitude sanity checks for index selection and query shape, not throughput or capacity numbers. No production or `jidian_test` rows were read or written by the seeder.

## 2. Environment

| Item | Value |
|---|---|
| PHP | 8.4.28 (CLI, `pdo_mysql`, `mysqlnd`) |
| MariaDB server | 11.8.9-MariaDB-0+deb13u1 (Debian) |
| App env | `APP_ENV=local` (from existing `.env`; `.env` was not modified) |
| Target database | `jidian_perf`, utf8mb4 / utf8mb4_unicode_ci |
| Migrations | 38 versions applied (`php bin/migrate-v2.php --status`, rc=0, 38 lines matching `^ 2026... applied`) |
| Benchmark style | `App\Core\Db` prepared statements with bound parameters; 2 warm-up runs + 30 timed runs per query; `microtime(true)` around `fetchAll()` only |

## 3. Commands executed

```bash
# Setup (both rc=0)
DB_DATABASE=jidian_perf php bin/install.php --fresh --demo
DB_DATABASE=jidian_perf php bin/migrate-v2.php          # all 38 versions already applied -> skipped, rc=0

# Seeding (rc=0, 0.621 s)
DB_DATABASE=jidian_perf php bin/seed-performance.php \
  --families=100 --persons=1000 --relations=10000 \
  --archives=1000 --notifications=2000 --workflows=200

# Migration status (rc=0)
DB_DATABASE=jidian_perf php bin/migrate-v2.php --status

# Read-only EXPLAIN/timing driver: PHP heredoc via `DB_DATABASE=jidian_perf php <<'PHP' ...`,
# using App\Core\Db; no file written inside the repository.

# Safety guards (both rc=1, no writes):
php bin/seed-performance.php --families=1 --persons=10
DB_DATABASE=jidian_test php bin/seed-performance.php --families=1 --persons=10

# Clamp validation on a separate empty perf DB (created, tested, dropped; rc=1 by design:
# missing tables, so no data written):
DB_DATABASE=jidian_perf_clamp php bin/seed-performance.php \
  --families=999999 --persons=-5 --relations=9999999 --archives=0 \
  --notifications=2000 --workflows=200

# Validation (results in §11) and cleanup (§10)
php -l bin/seed-performance.php
git diff --check
```

`install.php` applies the incremental migrations itself via `passthru`; the explicit `migrate-v2.php` run was still performed as requested and reported every version as `已执行，跳过`.

## 4. Dataset actually achieved

All six requested target scales were reached exactly — **no target scale is marked NOT EXECUTED**.

| Table | Requested | Achieved (exact `COUNT(*)`) | Marker predicate |
|---|---:|---:|---|
| `families` | 100 | **100** | `slug LIKE 'perf-family-%'` |
| `family_members` | (1/family, implicit) | **100** | via marker family ids |
| `family_persons` | 1000 | **1000** | `name LIKE 'PERF-Person-%'` |
| `person_relationships` | 10000 | **10000** | `source_id LIKE 'PERF-rel-%'` |
| `archives` | 1000 | **1000** | `title LIKE 'PERF-Archive-%'` |
| `notifications` | 2000 | **2000** | `type = 'PERF-notice'` |
| `ai_workflows` | 200 | **200** | `idempotency_key LIKE 'perf-idem-%'` |

Distribution check: for `perf-family-00001/00002/00003` the counts are `persons=10, relations=100, archives=10, workflows=2` each — round-robin across families as designed. ~5% of persons (every 20th row) carry a non-null `deleted_at` so the new composite index is exercised. `run_after` for all workflows was set to `NOW() - 1 day` so the claim SELECT has eligible rows. Because 10 persons yield only 90 distinct ordered pairs, the 100 relations/family necessarily repeat some `(a,b,type)` combinations; uniqueness was never a requirement and there is no such constraint.

Seeder output (final run):

```
目标规模：families=100 persons=1000 relations=10000 archives=1000 notifications=2000 workflows=200
检测到 100 条 PERF- 标记家族，先删除旧标记数据……
families 100 / family_members 100 / persons 1000 / relations 10000
archives 1000 / notifications 2000 / workflows 200
耗时：0.621 秒
```

Idempotency was exercised in practice: the final run found 100 marker families from an earlier interrupted attempt, deleted them (children first), then re-inserted the full dataset. Re-running the same command is the supported cleanup/reset path.

## 5. Seeder safety design (code-level + observed)

- **CLI only**: `PHP_SAPI !== 'cli'` → exit 1; `BASE_PATH` + `app/bootstrap.php` bootstrap.
- **Target DB from env**: `config('db.database')` → `DB_DATABASE`. Refuses names outside `*_perf` and explicitly refuses `jidian`, `jidian_test`, `production` (exit 1). Observed refusal text for both `jidian` and `jidian_test` and both exited 1 without any DML.
- **No raw value interpolation**: every INSERT/DELETE/SELECT value is a `?` bound through `Db::query()`; table and column names are fixed literals in this file. Batch size 100, IN-list chunks 200.
- **Clamps**: families 0–2000, persons 0–100000, relations 0–200000, archives 0–50000, notifications 0–100000, workflows 0–10000; `--families=0` with child data is raised to 1. Observed clamp warnings on `jidian_perf_clamp`: `999999→2000`, `-5→0`, `9999999→200000`; then a clear "缺少数据表" error (rc=1) because that DB was intentionally empty.
- **Marker-only data**: family names `PERF-Family-*`, slugs `perf-family-*`, user `perf-seed@example.invalid`, relation `source_id` `PERF-rel-*`, notification type `PERF-notice`, workflow idempotency keys `perf-idem-*`. No demo/real names, emails or content are copied.
- **Preflight**: verifies required tables exist before any DELETE; otherwise exits 1 with the suggested install/migrate commands.
- **Deterministic id lookup**: inserted family/person ids are re-read via bound `IN (...)` queries instead of assuming `AUTO_INCREMENT` continuity; a short read aborts before foreign-key-dependent writes.

## 6. Representative queries, timings and EXPLAIN

Raw log: `/tmp/opencode/perf-bench.log` (outside the repo). All numbers are ms, 30 runs after 2 warm-ups, on the exact dataset of §4.

| Query (source) | rows | min | median | avg | max | 30-run total | chosen access |
|---|---:|---:|---:|---:|---:|---:|---|
| Family list — `FamilyService::forUser()` | 100 | 0.263 | 0.627 | **0.534** | 0.883 | 16.011 | `family_members` full scan (100 rows) + `families` PRIMARY `eq_ref`; `filesort` |
| Person list — `FamilyPersonController` / `FamilyV2Controller` | 10 | 0.180 | 0.184 | **0.188** | 0.220 | 5.636 | **`idx_person_family_deleted_id`**, `range`, key_len 14, no filesort |
| Genealogy relations — `GenealogyService::relations()` | 100 | 0.516 | 1.083 | **0.977** | 1.566 | 29.306 | **`idx_rel_family_id`**, `range`, key_len 8; join partners PRIMARY `eq_ref` |
| Archive list — `ArchiveService::visibleList()` | 10 | 0.209 | 0.216 | **0.302** | 0.852 | 9.068 | `idx_archive_family`, `range`, key_len 9, `Using index condition; Using where; Using filesort` |
| Notifications — `NotificationService::forUser()` | 100 | 0.263 | 0.296 | **0.300** | 0.343 | 8.988 | `PRIMARY` index scan; `idx_notification_user` present but not chosen |
| AI workflow claim SELECT — `AiWorkflowService::claimStrict()` | 5 | 0.090 | 0.104 | **0.105** | 0.125 | 3.146 | `PRIMARY` index scan, `rows=5`, `FOR UPDATE SKIP LOCKED` |

Row counts are smaller than the `LIMIT`s because each marker family holds 10 persons/archives/100 relations and all 2000 notifications belong to one synthetic user; `LIMIT 200/500/100/5` never binds. This is a property of the dataset, not a query failure.

### 6.1 Index usage details

Confirmed index definitions (`SHOW INDEX`, raw output in `/tmp/opencode/perf-bench-extra.log`):

- `family_persons.idx_person_family_deleted_id = (family_id, deleted_at, id)` — migration `20261128_005`.
- `person_relationships.idx_rel_family_id = (family_id, id)` — migration `20261128_005`.
- `archives.idx_archive_family = (family_id, status, id)`.
- `notifications.idx_notification_user = (user_id, read_at, created_at)`.
- `ai_workflows.idx_ai_workflow_status = (status, run_after)`.
- `family_members.idx_family_member_user = (user_id, status)`, `uq_family_user = (family_id, user_id)`.

Exact EXPLAIN lines (abridged to the decision columns, `possible_keys` retained):

```
family_list       fm: type=ALL    possible_keys=uq_family_user,idx_family_member_user key=NULL                       rows=100 Extra="Using where; Using filesort"
                  f : type=eq_ref key=PRIMARY                                         ref=jidian_perf.fm.family_id    rows=1
person_list       family_persons: type=range key=idx_person_family_deleted_id          key_len=14 rows=10              Extra="Using where"
genealogy_relations r: type=range key=idx_rel_family_id                              key_len=8  rows=100             Extra="Using where"
                  pa/pb: type=eq_ref key=PRIMARY                                      rows=1 each
archive_list      archives: type=range key=idx_archive_family                         key_len=9  rows=10              Extra="Using index condition; Using where; Using filesort"
notification_list notifications: type=index key=PRIMARY                              rows=100                        Extra="Using where"
workflow_claim    ai_workflows: type=index key=PRIMARY                                rows=5                          Extra="Using where"
```

Honest interpretation: the optimizer **does select both new Phase-D indexes** for the two queries they were added for (person list, genealogy relations). For family list, notifications and workflow claim it chose a full/PK scan at this small scale despite usable alternative indexes; that is a cost-based choice over 100–2000 rows, not an index defect. Forced-plan checks confirm the alternatives are usable:

```
family_list       FORCE INDEX(idx_family_member_user)          -> fm: ref, key=idx_family_member_user, rows=100, filesort
person_list       FORCE INDEX(idx_person_family_deleted_id)    -> ref, key=idx_person_family_deleted_id, rows=10
genealogy         FORCE INDEX(idx_rel_family_id)               -> r:  ref, key=idx_rel_family_id, rows=100
notification      FORCE INDEX(idx_notification_user)           -> ref, key=idx_notification_user, rows=2000, filesort
workflow claim    FORCE INDEX(idx_ai_workflow_status)          -> range, key=idx_ai_workflow_status, rows=181, filesort
```

The notification case is expected: every synthetic notification belongs to the single seed user, so `idx_notification_user` has selectivity 1/1 and `ORDER BY id DESC` makes the PK path cheaper. A production-shaped distribution (many users) was not simulated.

## 7. N+1 checks

**EXECUTED (static + measured simulation):**

- `app/Services/FamilyPersonService.php:74-109` `visibleRows()`: one batch `families WHERE id IN (...)` plus one cached `Policy::familyRole()` per distinct family; no per-person query. `app/Core/Policy.php:146-164` caches roles in `self::$roles`.
- `app/Services/GenealogyService.php:166-184`: one relations query with `ORDER BY r.id LIMIT 500`; visibility filtering uses the already-loaded family/role data, not one query per relation.
- `app/Services/FamilyService.php:341-347`, `app/Services/ArchiveService.php:117-124`, `app/Services/NotificationService.php:93-101`: all lists carry fixed `LIMIT`s (200/200/100).
- Measured simulation of the current person-list path: 1 list query + 1 `families IN (...)` + 1 role lookup = **3 statements for 10 returned rows in 1 family**. The pre-fix path described in `docs/development/v2.1.0/32_PERFORMANCE_REPORT.md` would have been roughly `1 + 3N` (≈31) for the same rows. The statement count is bounded by distinct families, not by rows.

**NOT EXECUTED (with reason):**

- Full `AiWorkflowService::claimStrict()` loop: only its representative claim SELECT was timed. The loop issues one steps query, one UPDATE, one `lock_version` read and one audit insert **per claimed row**, but is hard-bounded by `max(1, min(50, $limit))` (default 5). Multi-worker `SKIP LOCKED` contention was not simulated.
- Full HTTP request/rendering path (`php -S`/FPM, browser) — not measured; this report is SQL-layer only.

## 8. Deviations / incident note

The first benchmark heredoc omitted `DB_DATABASE` and therefore connected to the default `.env` database `jidian`. It executed **only SELECT and EXPLAIN statements** (no INSERT/UPDATE/DELETE/DDL), returned empty result sets for marker predicates, and was immediately re-run with `DB_DATABASE=jidian_perf`. All numbers in §6 come from the corrected run, whose first line is `database=jidian_perf`.

## 9. NOT EXECUTED — summary of unattempted work

| Item | Status | Reason |
|---|---|---|
| Requested dataset scale (100/1000/10000/1000/2000/200) | **EXECUTED, 100% reached** | — |
| Real production traffic / real user data | **NOT EXECUTED** | Explicitly out of scope; no production access permitted. |
| Larger-than-requested stress (e.g. 2000 families / 100000 persons) | **NOT EXECUTED** | Clamp limits exist and clamp warnings were verified, but generating them was not requested. |
| HTTP end-to-end latency, memory profiling, concurrency | **NOT EXECUTED** | SQL-layer benchmark only. |
| Full claim/worker loop and multi-worker locking | **NOT EXECUTED** | Only the claim SELECT was timed (see §7). |

## 10. Cleanup

```bash
php -r '... PDO server connection ... DROP DATABASE `jidian_perf` ...'
# output: DROP DATABASE jidian_perf executed; exists_after=no
# exit code: 0
```

The temporary `jidian_perf_clamp` database used for clamp validation was created empty and dropped in the same command (no tables, no rows written). `jidian` and `jidian_test` were never modified and still exist. No git commit or push was made.

## 11. Validation

Run from the repository root on the final files:

```
$ php -l bin/seed-performance.php
No syntax errors detected in bin/seed-performance.php
exit=0

$ git diff --check
(no output)
exit=0
```

Note: `git diff --check` inspects tracked changes only; both files from this lane are new/untracked, so an additional explicit whitespace check was run on them: trailing-whitespace lines = 0 for each file, and both end with a newline (`0a`). No staged changes, commits or pushes were made by this lane.

## 12. Conclusion

A repeatable, marker-only seeder exists and reached the full requested synthetic scale in an isolated `jidian_perf` database (100 families, 1000 persons, 10000 relations, 1000 archives, 2000 notifications, 200 workflows) in 0.621 s. The two Phase-D performance indexes are present and are actually chosen by the optimizer for the person-list and genealogy-relation queries; no N+1 regression was observed in the measured list path. All timings are sub-millisecond on this small local dataset and **must not be extrapolated to production**. The isolated database was dropped after capture.
