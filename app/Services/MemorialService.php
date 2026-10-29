<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Policy;
use App\Core\Str;
use App\Core\Validator;
use App\Models\Memorial;
use App\Models\User;

final class MemorialService
{
    public const DECLARATION_VERSION = '2026-01';

    public const DECLARATIONS = [
        '我是逝者的近亲属，或已获得逝者近亲属的明确授权。',
        '我所填写的信息真实、准确，不包含仍在世人员的祭奠信息。',
        '我理解平台会对纪念馆内容和访问权限进行必要的审核与管理。',
        '我承诺尊重逝者及在世亲属的隐私、肖像与名誉权益。',
    ];

    /**
     * @return array{errors:array<string,string>,data:array<string,string>}
     */
    public static function validate(array $input, ?array $memorial = null): array
    {
        $validator = Validator::make($input, [
            'full_name' => 'required|max:120',
            'gender' => 'required|in:male,female,unknown',
            'birth_date' => 'date',
            'death_date' => 'date',
            'birth_place' => 'max:190',
            'death_place' => 'max:190',
            'bio' => 'max:5000',
            'title' => 'max:190',
            'summary' => 'max:500',
            'visibility' => 'required|in:public,unlisted,private',
            'slug' => 'max:60',
        ], [
            'full_name' => '逝者姓名',
            'gender' => '性别',
            'birth_date' => '出生日期',
            'death_date' => '逝世日期',
            'birth_place' => '出生地',
            'death_place' => '安葬地',
            'bio' => '生平简介',
            'title' => '纪念馆名称',
            'summary' => '纪念页引言',
            'visibility' => '访问范围',
            'slug' => '纪念馆链接',
        ]);

        $errors = $validator->errors();

        if ($memorial === null && empty($input['declaration'])) {
            $errors['declaration'] = '请确认创建声明后再继续';
        }

        $birth = trim((string) ($input['birth_date'] ?? ''));
        $death = trim((string) ($input['death_date'] ?? ''));
        if ($birth !== '' && $death !== '' && !isset($errors['birth_date']) && !isset($errors['death_date']) && $death < $birth) {
            $errors['death_date'] = '逝世日期不能早于出生日期';
        }

        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug !== '' && !Str::validSlug($slug)) {
            $errors['slug'] = '链接只能使用小写字母、数字和连字符，长度 3–60 位';
        }

        $data = [
            'full_name' => trim((string) ($input['full_name'] ?? '')),
            'gender' => (string) ($input['gender'] ?? 'unknown'),
            'birth_date' => $birth,
            'death_date' => $death,
            'birth_place' => trim((string) ($input['birth_place'] ?? '')),
            'death_place' => trim((string) ($input['death_place'] ?? '')),
            'bio' => trim((string) ($input['bio'] ?? '')),
            'title' => trim((string) ($input['title'] ?? '')),
            'summary' => trim((string) ($input['summary'] ?? '')),
            'visibility' => (string) ($input['visibility'] ?? 'unlisted'),
            'slug' => $slug,
        ];

        return ['errors' => $errors, 'data' => $data];
    }

    public static function create(array $data, int $userId): int
    {
        $slug = $data['slug'] !== ''
            ? $data['slug']
            : self::uniqueSlug(Str::slugify($data['full_name'] !== '' ? $data['full_name'] : $data['title']));

        if ($data['slug'] !== '' && Memorial::slugExists($slug)) {
            $slug = self::uniqueSlug($slug . '-');
        }

        $title = $data['title'] !== '' ? $data['title'] : '纪念' . $data['full_name'];

        // 新馆一律先建为私密馆：完成身份材料核验前不对外公开，防止活人被祭奠与恶意建馆。
        $id = Memorial::create(
            [
                'slug' => $slug,
                'title' => $title,
                'summary' => $data['summary'],
                'visibility' => 'private',
            ],
            $data,
            $userId
        );

        Audit::log('memorial.create', 'memorial', $id, ['slug' => $slug, 'visibility' => 'private']);
        return $id;
    }

    public static function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'memory-' . Str::random(4);
        }
        $candidate = substr($base, 0, 55);
        $suffix = 1;
        while (Memorial::slugExists($candidate, $exceptId)) {
            $suffix++;
            $candidate = substr($base, 0, 52) . '-' . $suffix;
        }
        return $candidate;
    }

    /**
     * @return array<string, string>
     */
    public static function updateProfile(array $memorial, array $data): array
    {
        $slug = $data['slug'] !== '' ? $data['slug'] : (string) $memorial['slug'];
        if ($slug !== $memorial['slug'] && Memorial::slugExists($slug, (int) $memorial['id'])) {
            return ['slug' => '该链接已被使用'];
        }

        $title = $data['title'] !== '' ? $data['title'] : '纪念' . $data['full_name'];

        Db::transaction(static function () use ($memorial, $data, $slug, $title): void {
            Memorial::update((int) $memorial['id'], [
                'slug' => $slug,
                'title' => $title,
                'summary' => $data['summary'] ?: null,
                // 访问范围只在“访问与留言”中修改；资料编辑不能绕过核验门槛。
                'visibility' => (string) $memorial['visibility'],
                'allow_search_index' => $memorial['visibility'] === 'public'
                    ? (int) $memorial['allow_search_index']
                    : 0,
            ]);

            Memorial::updateDeceased((int) $memorial['deceased_id'], [
                'full_name' => $data['full_name'],
                'gender' => $data['gender'],
                'birth_date' => $data['birth_date'] ?: null,
                'death_date' => $data['death_date'] ?: null,
                'birth_place' => $data['birth_place'] ?: null,
                'death_place' => $data['death_place'] ?: null,
                'bio' => $data['bio'] ?: null,
            ]);
        });

        Policy::clearCache();
        Audit::log('memorial.update_profile', 'memorial', (int) $memorial['id']);
        return [];
    }

    /**
     * @return array<string,string>
     */
    public static function updateSettings(array $memorial, array $input): array
    {
        $visibility = in_array($input['visibility'] ?? '', ['public', 'unlisted', 'private'], true)
            ? $input['visibility']
            : 'unlisted';

        if ($visibility !== 'private' && !self::isVerified($memorial)) {
            return ['visibility' => '请先完成逝者身份材料核验，再将纪念馆设为公开或不公开收录。当前可继续使用私密馆。'];
        }

        $data = [
            'visibility' => $visibility,
            'allow_search_index' => $visibility === 'public' && !empty($input['allow_search_index']) ? 1 : 0,
            'allow_guestbook' => !empty($input['allow_guestbook']) ? 1 : 0,
            'moderate_messages' => !empty($input['moderate_messages']) ? 1 : 0,
        ];

        Memorial::update((int) $memorial['id'], $data);
        Audit::log('memorial.update_settings', 'memorial', (int) $memorial['id'], $data);
        return [];
    }

    public static function isVerified(array $memorial): bool
    {
        return ($memorial['verification_status'] ?? 'none') === 'verified';
    }

    /**
     * @return array<string,string>
     */
    public static function publish(array $memorial): array
    {
        if (($memorial['visibility'] ?? 'private') !== 'private' && !self::isVerified($memorial)) {
            return ['verification' => '该纪念馆尚未完成身份核验。请先提交核验材料，或暂时设为私密馆。'];
        }

        Db::transaction(static function () use ($memorial): void {
            $data = [
                'status' => 'active',
                'suspended_reason' => null,
                'suspended_by' => null,
            ];
            if (empty($memorial['published_at'])) {
                $data['published_at'] = date('Y-m-d H:i:s');
            }
            Memorial::update((int) $memorial['id'], $data);
        });
        Audit::log('memorial.publish', 'memorial', (int) $memorial['id']);
        return [];
    }

    public static function pauseByOwner(array $memorial): void
    {
        Memorial::update((int) $memorial['id'], [
            'status' => 'suspended',
            'suspended_reason' => '馆主暂停展示',
        ]);
        Audit::log('memorial.pause_owner', 'memorial', (int) $memorial['id']);
    }

    public static function resumeByOwner(array $memorial): void
    {
        Memorial::update((int) $memorial['id'], [
            'status' => 'active',
            'suspended_reason' => null,
            'suspended_by' => null,
        ]);
        Audit::log('memorial.resume_owner', 'memorial', (int) $memorial['id']);
    }

    public static function suspendByAdmin(array $memorial, string $reason, int $adminId): void
    {
        Memorial::update((int) $memorial['id'], [
            'status' => 'suspended',
            'suspended_reason' => $reason,
            'suspended_by' => $adminId,
        ]);
        Audit::log('memorial.suspend_admin', 'memorial', (int) $memorial['id'], ['reason' => $reason]);
    }
}
