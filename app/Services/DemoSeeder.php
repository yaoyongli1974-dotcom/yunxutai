<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Storage;
use App\Core\Str;
use App\Models\Article;
use App\Models\Claim;
use App\Models\DataRequest;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Member;
use App\Models\Memorial;
use App\Models\Message;
use App\Models\Report;
use App\Models\ServiceRequest;
use App\Models\Tribute;
use App\Models\User;

final class DemoSeeder
{
    private const DEMO_PASSWORD = 'Demo@123456';

    public static function run(): array
    {
        if (Memorial::findBySlug('zhang-mingyuan') !== null) {
            return ['skipped' => true, 'message' => '演示数据已存在'];
        }

        $demoId = self::user('demo@example.com', self::DEMO_PASSWORD, '林晚');
        $familyId = self::user('family@example.com', self::DEMO_PASSWORD, '张宁');
        $claimantId = self::user('claimant@example.com', self::DEMO_PASSWORD, '王远');

        $memorialId = Memorial::create(
            [
                'slug' => 'zhang-mingyuan',
                'title' => '纪念张明远',
                'summary' => '他把一生留在讲台与书桌之间，也把温和与好奇留给了我们。',
                'visibility' => 'public',
            ],
            [
                'full_name' => '张明远',
                'gender' => 'male',
                'birth_date' => '1942-03-12',
                'death_date' => '2023-11-08',
                'birth_place' => '江苏苏州',
                'death_place' => '江苏苏州',
                'bio' => "张明远，1942 年生于苏州平江路。青年时代求学于南京，毕业后赴西北工作十余年，1978 年回到苏州，在中学教授物理直至退休。\n\n他喜欢修旧收音机，喜欢在夏夜辨认星座，也喜欢把家里每一件旧物背后的来历讲给孩子们听。晚年他整理家谱，留下了大量手写笔记。\n\n2023 年 11 月，他在家中安详离世。",
            ],
            $demoId
        );

        $admin = User::findByEmail((string) config('app.admin_email'));

        Memorial::update($memorialId, [
            'status' => 'active',
            'allow_search_index' => 1,
            'allow_guestbook' => 1,
            'moderate_messages' => 1,
            'published_at' => date('Y-m-d H:i:s', strtotime('-40 days')),
            'verification_status' => 'verified',
            'verification_method' => 'death_certificate',
            'verified_at' => date('Y-m-d H:i:s', strtotime('-39 days')),
            'verified_by' => $admin !== null ? (int) $admin['id'] : null,
        ]);

        Member::upsert($memorialId, $familyId, 'editor', $demoId);

        $events = [
            ['1942-03-12', '出生于苏州', '在平江路附近的老宅出生，家中排行第三。', '江苏苏州'],
            ['1965-08-01', '赴西北工作', '大学毕业后响应号召，赴西北从事技术工作。', '甘肃兰州'],
            ['1978-09-01', '回到苏州任教', '回到家乡，在中学教授物理，直到退休。', '江苏苏州'],
            ['2003-06-30', '退休', '离开讲台，开始整理家谱与旧照片。', '江苏苏州'],
            ['2023-11-08', '安详离世', '在家中离世，家人陪伴在侧。', '江苏苏州'],
        ];
        foreach ($events as $index => $event) {
            LifeEvent::create($memorialId, [
                'event_date' => $event[0],
                'year_label' => '',
                'title' => $event[1],
                'description' => $event[2],
                'location' => $event[3],
                'sort_order' => $index,
            ], $demoId);
        }

        Article::create($memorialId, [
            'title' => '父亲的书桌',
            'content' => "父亲的书桌靠窗，桌面右角有一道很深的划痕，是年轻时搬家具留下的。\n\n他总把正在读的书摊开扣在桌上，像随时会回来继续读。抽屉里有几只旧钢笔、一叠写满公式的纸，还有我们小时候的成绩单——他一直留着。\n\n整理遗物时，我在最底层找到一本笔记本，扉页上写着：“把知道的事记下来，是为了以后有人问起。”\n\n这句话，大概也是这个纪念馆的由来。",
            'author_name' => '张宁',
            'status' => 'published',
        ], $familyId);

        Article::create($memorialId, [
            'title' => '他教我们认星星',
            'content' => "夏天的晚上，父亲会搬两把竹椅到院子里，指着天空教我们认北斗七星。\n\n他说，星星的光要走很多年才到我们眼睛里，所以我们现在看到的，其实是很久以前的它们。\n\n那时不懂这句话。现在再抬头，才明白记忆也是这样——它从很远的地方赶来，落在我们身上。",
            'author_name' => '林晚',
            'status' => 'published',
        ], $demoId);

        Article::create($memorialId, [
            'title' => '旧信札记（待整理）',
            'content' => '整理父亲留下的信件时，发现他与旧友往来近四十年。部分信件涉及他人隐私，需要逐封核对后再决定是否公开。',
            'author_name' => '张宁',
            'status' => 'pending',
        ], $familyId);

        $photoIds = [];
        $photos = [
            ['study.jpg', '书房的一角', [214, 199, 176], [116, 101, 84]],
            ['garden.jpg', '院子里的夏天', [204, 210, 193], [92, 104, 88]],
            ['dusk.jpg', '傍晚的窗', [213, 193, 182], [104, 94, 97]],
        ];
        foreach ($photos as $index => $photo) {
            $file = self::makeImage($photo[0], $photo[2], $photo[3]);
            $photoIds[] = Media::create($memorialId, $demoId, $file, 'photo', 'visitors', $photo[1]);
        }
        Media::setCover($memorialId, $photoIds[0]);

        Message::create($memorialId, $demoId, '林晚', '爸，家里一切都好。院子里的桂花又开了，香味和你在的时候一样。', Str::ipHash('127.0.0.1'), 'visible');
        Message::create($memorialId, $familyId, '张宁', '整理书房时找到你的备课本，字还是那么工整。等有空，我会把里面的批注慢慢录入。', Str::ipHash('127.0.0.1'), 'visible');
        Message::create($memorialId, $familyId, '张宁', '今年清明我们去看你，带了你喜欢的青团。', Str::ipHash('127.0.0.1'), 'visible');
        Message::create($memorialId, $demoId, '林晚', '想你了。', Str::ipHash('127.0.0.1'), 'visible');
        Message::create($memorialId, null, '一位老同事', '张老师，谢谢您当年在课堂上对我的鼓励。', Str::ipHash('127.0.0.1'), 'pending');

        foreach (['flower' => 3, 'candle' => 2, 'incense' => 2, 'bow' => 1] as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                Tribute::create(
                    $memorialId,
                    $i % 2 === 0 ? $demoId : $familyId,
                    $type,
                    $type === 'flower' ? '愿花香常在。' : null,
                    false,
                    Str::ipHash('127.0.0.1'),
                    'visible'
                );
            }
        }

        Claim::create($memorialId, $claimantId, '远房侄子', '看到纪念馆由表姐维护，希望能补充一些祖父留下的家族资料，并协助校对早年经历。', '可提供家族合影与通信记录。');

        $reportMessageId = Message::create($memorialId, null, '访客', '这条留言用于演示举报处理流程。', Str::ipHash('127.0.0.1'), 'visible');
        Report::create([
            'target_type' => 'message',
            'target_id' => $reportMessageId,
            'memorial_id' => $memorialId,
            'reporter_user_id' => $claimantId,
            'reporter_name' => '王远',
            'contact' => 'claimant@example.com',
            'reason' => 'privacy',
            'detail' => '这条留言涉及在世亲属的姓名与住址，希望平台核实并处理。',
        ]);

        ServiceRequest::create($claimantId, $memorialId, '王远', 'claimant@example.com', 'photo_restore', '家中有几张 1970 年代的老照片，希望修复后用于家族档案整理。');
        DataRequest::create($demoId, $memorialId, 'export', '希望导出纪念馆当前的文字与照片清单，用于家庭备份。');

        Audit::log('seed.demo', 'memorial', $memorialId);

        return [
            'skipped' => false,
            'message' => '演示数据已创建',
            'memorial_id' => $memorialId,
            'users' => [
                'demo@example.com' => self::DEMO_PASSWORD,
                'family@example.com' => self::DEMO_PASSWORD,
                'claimant@example.com' => self::DEMO_PASSWORD,
            ],
        ];
    }

    private static function user(string $email, string $password, string $name): int
    {
        $existing = User::findByEmail($email);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $id = User::create($email, $password, $name);
        User::update($id, ['email_verified_at' => date('Y-m-d H:i:s')]);
        return $id;
    }

    /**
     * 生成一张不带文字的低饱和记忆底色图，用于演示。
     *
     * @return array{disk_path:string,original_name:string,mime:string,size_bytes:int,kind:string}
     */
    private static function makeImage(string $name, array $from, array $to): array
    {
        $directory = Storage::uploadsPath('demo');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建演示图片目录');
        }

        $path = $directory . '/' . $name;

        if (function_exists('imagecreatetruecolor')) {
            $width = 1200;
            $height = 800;
            $image = imagecreatetruecolor($width, $height);
            for ($y = 0; $y < $height; $y++) {
                $ratio = $y / $height;
                $color = imagecolorallocate(
                    $image,
                    (int) ($from[0] + ($to[0] - $from[0]) * $ratio),
                    (int) ($from[1] + ($to[1] - $from[1]) * $ratio),
                    (int) ($from[2] + ($to[2] - $from[2]) * $ratio)
                );
                imageline($image, 0, $y, $width, $y, $color);
            }

            for ($i = 0; $i < 14; $i++) {
                $alpha = imagecolorallocatealpha($image, 245, 241, 232, random_int(100, 118));
                imagefilledellipse(
                    $image,
                    random_int(100, $width - 100),
                    random_int(80, $height - 80),
                    random_int(120, 420),
                    random_int(120, 320),
                    $alpha
                );
            }

            imagejpeg($image, $path, 84);
            imagedestroy($image);
        } else {
            // 缺少 GD 时退化为一张纯色 JPEG 占位图。
            $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==');
            file_put_contents($path, $jpeg);
        }

        @chmod($path, 0640);

        return [
            'disk_path' => 'demo/' . $name,
            'original_name' => $name,
            'mime' => 'image/jpeg',
            'size_bytes' => (int) filesize($path),
            'kind' => 'photo',
        ];
    }
}
