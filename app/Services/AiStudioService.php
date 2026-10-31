<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Storage;
use App\Models\AiJob;
use App\Models\Article;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Memorial;
use App\Services\Ai\AiClientFactory;
use RuntimeException;

final class AiStudioService
{
    /**
     * 同步处理一个 AI 任务。失败时记录错误并抛出，便于控制器展示。
     */
    public static function process(int $jobId): array
    {
        $job = AiJob::find($jobId);
        if ($job === null) {
            throw new RuntimeException('AI 任务不存在');
        }
        if ($job['status'] === 'completed') {
            return $job;
        }

        if (!AiJob::markProcessing($jobId)) {
            $fresh = AiJob::find($jobId);
            if ($fresh !== null && $fresh['status'] === 'completed') {
                return $fresh;
            }
            throw new RuntimeException('该任务正在处理中，请稍后刷新');
        }
        @set_time_limit(180);

        try {
            $memorial = Memorial::find((int) $job['memorial_id']);
            if ($memorial === null) {
                throw new RuntimeException('纪念馆不存在');
            }
            $deceased = \App\Core\Db::fetch('SELECT * FROM deceased WHERE id = ?', [(int) $memorial['deceased_id']]) ?? [];
            $input = $job['input'];

            match ((string) $job['service_slug']) {
                'photo_restore' => self::runPhoto($job, $memorial, $input),
                'life_article' => self::runArticle($job, $memorial, $deceased, $input),
                'memorial_video' => self::runVideo($job, $memorial, $deceased, $input),
                'family_archive' => self::runArchive($job, $memorial, $deceased, $input),
                default => throw new RuntimeException('不支持的 AI 服务类型'),
            };

            Audit::log('ai.job_completed', 'ai_job', $jobId, [
                'service' => (string) $job['service_slug'],
                'memorial_id' => (int) $job['memorial_id'],
            ]);
            return AiJob::find($jobId) ?? $job;
        } catch (\Throwable $e) {
            AiJob::markFailed($jobId, $e->getMessage());
            Audit::log('ai.job_failed', 'ai_job', $jobId, [
                'service' => (string) $job['service_slug'],
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            throw $e;
        }
    }

    private static function runPhoto(array $job, array $memorial, array $input): void
    {
        $media = Media::find((int) ($input['media_id'] ?? 0));
        if ($media === null || (int) $media['memorial_id'] !== (int) $memorial['id'] || $media['kind'] !== 'photo') {
            throw new RuntimeException('原照片不存在或不属于该纪念馆');
        }
        $source = Storage::absolute((string) $media['disk_path']);
        if ($source === null) {
            throw new RuntimeException('原照片文件缺失');
        }

        $client = AiClientFactory::makeFor('image');
        if ($client !== null) {
            $bytes = $client->restoreImage(
                $source,
                '请温和地修复这张家庭老照片：去除明显划痕、噪点与偏色，恢复自然肤色和细节，'
                . '保持人物原貌、面部特征、服饰与背景不变，不要添加原图不存在的人物或物品，不要过度磨皮。'
                . '输出一张适合家庭纪念的清晰照片。'
            );
        } else {
            $bytes = self::localEnhance($source);
        }

        $file = Storage::storeGeneratedBytes(
            $bytes,
            'jpg',
            'image/jpeg',
            'AI修复-' . pathinfo((string) $media['original_name'], PATHINFO_FILENAME) . '.jpg'
        );
        $mediaId = Media::create(
            (int) $memorial['id'],
            (int) $job['user_id'],
            $file + ['kind' => 'photo'],
            'photo',
            'members',
            mb_substr((string) ($input['caption'] ?? '') ?: ('AI 辅助修复 · 来自原照片 #' . $media['id'] . '，请家属确认后使用'), 0, 255)
        );

        AiJob::markCompleted((int) $job['id'], [
            'service' => 'photo_restore',
            'source_media_id' => (int) $media['id'],
            'media_id' => $mediaId,
            'note' => '输出默认仅成员可见；原图未被覆盖。',
        ], ['media_id' => $mediaId]);
    }

    private static function runArticle(array $job, array $memorial, array $deceased, array $input): void
    {
        $events = LifeEvent::forMemorial((int) $memorial['id']);
        $client = AiClientFactory::makeFor('text');
        $name = (string) $deceased['full_name'];

        if ($client !== null) {
            $content = $client->generateText(
                '你是一位严谨的中文家族史编辑。只依据用户提供的资料写作，不虚构姓名、时间、地点、荣誉或对话；'
                . '资料不足处用“据家人回忆”“尚待补充”标注。语气克制、具体、有温度，不使用营销话术，不写封建迷信内容。'
                . '输出一篇 800–1400 字的生平文章，包含小标题与自然分段，不要使用 Markdown 代码块。',
                self::articlePrompt($memorial, $deceased, $events, $input)
            );
        } else {
            $content = self::localArticle($memorial, $deceased, $events);
        }

        $title = trim((string) ($input['title'] ?? '')) ?: ('生平记述：' . $name);
        $articleId = Article::create((int) $memorial['id'], [
            'title' => mb_substr($title, 0, 190),
            'content' => $content,
            'author_name' => 'AI 辅助整理（待家属核对）',
            'status' => 'draft',
        ], (int) $job['user_id']);

        AiJob::markCompleted((int) $job['id'], [
            'service' => 'life_article',
            'article_id' => $articleId,
            'words' => mb_strlen(strip_tags($content)),
            'note' => '文章以草稿保存，发布前必须由家属逐项核对事实。',
        ], ['article_id' => $articleId]);
    }

    private static function runVideo(array $job, array $memorial, array $deceased, array $input): void
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($input['media_ids'] ?? []))));
        if ($ids === [] || count($ids) > 8) {
            throw new RuntimeException('请选择 1–8 张照片');
        }
        $photos = [];
        foreach ($ids as $id) {
            $media = Media::find($id);
            if ($media === null || (int) $media['memorial_id'] !== (int) $memorial['id'] || $media['kind'] !== 'photo' || $media['status'] !== 'active') {
                throw new RuntimeException('所选照片不存在或不可用');
            }
            $path = Storage::absolute((string) $media['disk_path']);
            if ($path === null) {
                throw new RuntimeException('照片文件缺失');
            }
            $photos[] = ['media' => $media, 'path' => $path];
        }

        $events = LifeEvent::forMemorial((int) $memorial['id']);
        $client = AiClientFactory::makeFor('text');
        if ($client !== null) {
            $storyboardText = $client->generateText(
                '你是纪念短片的分镜编辑。根据提供的照片说明与生平时间轴，写 4–6 句简短、克制的中文字幕，'
                . '每句不超过 24 字；不要虚构事实，不要使用感叹号或营销语言。只输出字幕，每行一句。',
                self::videoPrompt($memorial, $deceased, $events, $photos)
            );
            $storyboard = array_values(array_filter(array_map('trim', preg_split('/\R/u', $storyboardText) ?: [])));
        } else {
            $storyboard = self::localStoryboard($memorial, $deceased, $events);
        }
        $storyboard = array_slice($storyboard, 0, 6);

        $path = self::makeSlideshow(array_column($photos, 'path'));
        $file = Storage::storeGeneratedFile(
            $path,
            'mp4',
            'video/mp4',
            '纪念短片-' . $deceased['full_name'] . '.mp4'
        );
        $mediaId = Media::create(
            (int) $memorial['id'],
            (int) $job['user_id'],
            $file + ['kind' => 'video'],
            'video',
            'members',
            'AI 辅助纪念短片（待家属确认）'
        );

        AiJob::markCompleted((int) $job['id'], [
            'service' => 'memorial_video',
            'media_id' => $mediaId,
            'photo_count' => count($photos),
            'storyboard' => $storyboard,
            'note' => '短片为照片拼接，无配乐；默认仅成员可见。',
        ], ['media_id' => $mediaId]);
    }

    private static function runArchive(array $job, array $memorial, array $deceased, array $input): void
    {
        $events = LifeEvent::forMemorial((int) $memorial['id']);
        $articles = Article::publishedFor((int) $memorial['id']);
        $photos = Media::visible((int) $memorial['id'], true);
        $photos = array_values(array_filter($photos, static fn (array $m): bool => $m['kind'] === 'photo'));

        $client = AiClientFactory::makeFor('text');
        if ($client !== null) {
            $preface = $client->generateText(
                '你是一位家族档案编辑。请写一段 300–500 字的中文档案前言，说明这份档案依据哪些资料整理、'
                . '哪些部分仍待家人补充。不得虚构事实，不使用营销语言，语气安静、克制。',
                self::archivePrompt($memorial, $deceased, $events)
            );
        } else {
            $preface = self::localPreface($memorial, $deceased, $events);
        }

        $html = self::buildArchiveHtml($memorial, $deceased, $events, $articles, $photos, $preface);
        $file = Storage::storeGeneratedBytes(
            $html,
            'html',
            'text/html; charset=utf-8',
            '家族数字档案-' . $deceased['full_name'] . '-' . date('Ymd') . '.html'
        );
        $mediaId = Media::create(
            (int) $memorial['id'],
            (int) $job['user_id'],
            $file + ['kind' => 'document'],
            'document',
            'members',
            'AI 辅助家族数字档案（可离线保存）'
        );

        AiJob::markCompleted((int) $job['id'], [
            'service' => 'family_archive',
            'media_id' => $mediaId,
            'events' => count($events),
            'articles' => count($articles),
            'photos' => count($photos),
            'note' => 'HTML 档案默认仅成员可见，可下载后离线保存。',
        ], ['media_id' => $mediaId]);
    }

    private static function localEnhance(string $source): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if ($image === false) {
            throw new RuntimeException('无法读取照片，请换一张 JPG/PNG/WebP 图片');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $max = 1600;
        if (max($width, $height) > $max) {
            $ratio = $max / max($width, $height);
            $resized = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BICUBIC);
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }
        imagefilter($image, IMG_FILTER_CONTRAST, -8);
        imagefilter($image, IMG_FILTER_BRIGHTNESS, 6);
        imagefilter($image, IMG_FILTER_SMOOTH, 1);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        if ($bytes === '') {
            throw new RuntimeException('生成修复图片失败');
        }
        return $bytes;
    }

    private static function localArticle(array $memorial, array $deceased, array $events): string
    {
        $name = (string) $deceased['full_name'];
        $parts = [];
        $parts[] = '一、生平简述';
        $parts[] = trim((string) ($deceased['bio'] ?? '')) ?: ('关于' . $name . '的生平资料尚待家人补充。');
        $parts[] = '';
        $parts[] = '二、时间里的片段';
        if ($events === []) {
            $parts[] = '目前还没有录入时间轴。家人可以在纪念馆中补充出生、求学、工作、迁居与家庭大事。';
        } else {
            foreach ($events as $event) {
                $when = (string) ($event['event_date'] ?? '');
                if ($when === '') {
                    $when = (string) ($event['year_label'] ?? '');
                }
                $line = trim($when . '　' . (string) $event['title']);
                if (!empty($event['description'])) {
                    $line .= "\n" . (string) $event['description'];
                }
                if (!empty($event['location'])) {
                    $line .= '（' . (string) $event['location'] . '）';
                }
                $parts[] = $line;
            }
        }
        $parts[] = '';
        $parts[] = '三、留给家人的话';
        $parts[] = '这篇文字依据纪念馆中已填写的资料整理，没有补写未经验证的细节。哪些记忆需要更正、哪些片段还想补上，'
            . '请在发布前与家人一起核对。';
        return implode("\n\n", $parts);
    }

    private static function localStoryboard(array $memorial, array $deceased, array $events): array
    {
        $lines = ['纪念' . $deceased['full_name']];
        foreach (array_slice($events, 0, 4) as $event) {
            $when = (string) ($event['event_date'] ?? $event['year_label'] ?? '');
            $lines[] = trim($when . ' · ' . (string) $event['title']);
        }
        $lines[] = '把记得的片段，慢慢留给后来的人。';
        return $lines;
    }

    private static function localPreface(array $memorial, array $deceased, array $events): string
    {
        return '这份家族数字档案依据纪念馆「' . (string) $memorial['title'] . '」中已填写的资料整理，'
            . '收录了' . (string) $deceased['full_name'] . '的生平、'
            . (count($events) > 0 ? count($events) . ' 条时间轴记录' : '尚待补充的时间轴')
            . '、已发布的纪念文章与照片目录。它不是完整的一生，只是一份可以被家人继续补充的底稿。'
            . '未经验证的细节没有写入；如发现错误，请以家人的共同记忆为准。';
    }

    private static function articlePrompt(array $memorial, array $deceased, array $events, array $input): string
    {
        $lines = [
            '纪念馆：' . (string) $memorial['title'],
            '逝者：' . (string) $deceased['full_name'],
            '生卒：' . (string) ($deceased['birth_date'] ?? '不详') . ' — ' . (string) ($deceased['death_date'] ?? '不详'),
            '出生地：' . (string) ($deceased['birth_place'] ?? '不详'),
            '安葬/离世地：' . (string) ($deceased['death_place'] ?? '不详'),
            '简介：' . mb_substr((string) ($deceased['bio'] ?? ''), 0, 3000),
            '',
            '时间轴：',
        ];
        foreach ($events as $event) {
            $lines[] = '- ' . (string) ($event['event_date'] ?? $event['year_label'] ?? '') . ' ' . (string) $event['title']
                . '：' . mb_substr((string) ($event['description'] ?? ''), 0, 300);
        }
        $notes = trim((string) ($input['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = '';
            $lines[] = '家属补充说明（仅作事实来源，不要照抄）：' . mb_substr($notes, 0, 1200);
        }
        return implode("\n", $lines);
    }

    private static function videoPrompt(array $memorial, array $deceased, array $events, array $photos): string
    {
        $lines = ['纪念馆：' . (string) $memorial['title'], '逝者：' . (string) $deceased['full_name']];
        foreach ($photos as $photo) {
            $lines[] = '照片说明：' . (string) ($photo['media']['caption'] ?? '未标注');
        }
        foreach (array_slice($events, 0, 6) as $event) {
            $lines[] = '时间轴：' . (string) ($event['event_date'] ?? '') . ' ' . (string) $event['title'];
        }
        return implode("\n", $lines);
    }

    private static function archivePrompt(array $memorial, array $deceased, array $events): string
    {
        return '纪念馆：' . (string) $memorial['title'] . "\n"
            . '逝者：' . (string) $deceased['full_name'] . "\n"
            . '简介：' . mb_substr((string) ($deceased['bio'] ?? ''), 0, 2000) . "\n"
            . '时间轴条数：' . count($events);
    }

    private static function makeSlideshow(array $paths): string
    {
        $ffmpeg = (string) config('ai.ffmpeg', '/usr/bin/ffmpeg');
        if (!is_file($ffmpeg)) {
            throw new RuntimeException('服务器未安装 ffmpeg，暂时无法生成短片');
        }
        $output = sys_get_temp_dir() . '/jingsi-video-' . bin2hex(random_bytes(8)) . '.mp4';
        $args = [$ffmpeg, '-y'];
        foreach ($paths as $path) {
            $args[] = '-loop';
            $args[] = '1';
            $args[] = '-t';
            $args[] = '4';
            $args[] = '-i';
            $args[] = $path;
        }

        $filter = '';
        $concat = '';
        foreach ($paths as $index => $path) {
            $filter .= "[{$index}:v]scale=1280:720:force_original_aspect_ratio=decrease,"
                . "pad=1280:720:(ow-iw)/2:(oh-ih)/2:color=0xf7f3ea,setsar=1,fps=25,format=yuv420p[v{$index}];";
            $concat .= "[v{$index}]";
        }
        $filter .= $concat . 'concat=n=' . count($paths) . ':v=1:a=0[out]';

        array_push(
            $args,
            '-filter_complex', $filter,
            '-map', '[out]',
            '-an',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '23',
            '-movflags', '+faststart',
            $output
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($args, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动视频生成程序');
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || !is_file($output) || filesize($output) < 1024) {
            @unlink($output);
            throw new RuntimeException('视频生成失败：' . mb_substr(trim($stderr), 0, 300));
        }
        return $output;
    }

    private static function buildArchiveHtml(array $memorial, array $deceased, array $events, array $articles, array $photos, string $preface): string
    {
        $name = (string) $deceased['full_name'];
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $timeline = '';
        foreach ($events as $event) {
            $timeline .= '<li><strong>' . $escape((string) ($event['event_date'] ?? $event['year_label'] ?? '')) . '</strong>'
                . '<span>' . $escape((string) $event['title']) . '</span>'
                . ($event['description'] ? '<p>' . nl2br($escape((string) $event['description'])) . '</p>' : '')
                . '</li>';
        }

        $articleHtml = '';
        foreach ($articles as $article) {
            $articleHtml .= '<article><h3>' . $escape((string) $article['title']) . '</h3>'
                . '<p class="meta">' . $escape((string) ($article['author_name'] ?? '')) . '</p>'
                . nl2br($escape((string) $article['content'])) . '</article>';
        }

        $photoHtml = '';
        foreach (array_slice($photos, 0, 12) as $photo) {
            $thumb = self::thumbnailData($photo);
            $photoHtml .= '<figure>'
                . ($thumb !== '' ? '<img src="' . $thumb . '" alt="' . $escape((string) ($photo['caption'] ?? '纪念照片')) . '">' : '')
                . '<figcaption>' . $escape((string) ($photo['caption'] ?? '未命名照片')) . '</figcaption></figure>';
        }

        $generated = date('Y-m-d H:i');
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>家族数字档案 · ' . $escape($name) . '</title><style>'
            . 'body{margin:0;background:#f7f3ea;color:#242824;font-family:"Noto Serif SC",Songti SC,serif;line-height:1.85}'
            . 'main{max-width:860px;margin:0 auto;padding:48px 24px}h1{font-size:2.2rem;margin:0 0 .2rem}'
            . '.meta{color:#6b7168;font-size:.9rem}h2{margin-top:2.6rem;border-bottom:1px solid #d9d2c3;padding-bottom:.4rem}'
            . '.lede{font-size:1.05rem;background:#fffdf8;border:1px solid #e3dccd;padding:1.2rem 1.4rem}'
            . 'ul.timeline{list-style:none;padding:0}ul.timeline li{border-left:2px solid #9aa68f;padding:0 0 1.2rem 1.2rem}'
            . 'ul.timeline span{font-weight:600;margin-left:.7rem}ul.timeline p{margin:.4rem 0 0;color:#4d544b}'
            . 'article{margin:1.6rem 0;background:#fffdf8;padding:1rem 1.4rem;border:1px solid #e3dccd}'
            . '.photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem}'
            . 'figure{margin:0;background:#fffdf8;border:1px solid #e3dccd;padding:.5rem}figure img{width:100%;height:auto;display:block}'
            . 'figcaption{font-size:.82rem;color:#6b7168;margin-top:.4rem}footer{margin-top:3rem;color:#777;font-size:.8rem}'
            . '</style></head><body><main>'
            . '<p class="meta">云叙台 · 家族数字档案</p><h1>' . $escape($name) . '</h1>'
            . '<p class="meta">' . $escape((string) ($deceased['birth_date'] ?? '')) . ' — ' . $escape((string) ($deceased['death_date'] ?? '')) . '</p>'
            . '<div class="lede">' . nl2br($escape($preface)) . '</div>'
            . '<h2>生平</h2><p>' . nl2br($escape((string) ($deceased['bio'] ?? '尚待补充'))) . '</p>'
            . '<h2>时间轴</h2>' . ($timeline !== '' ? '<ul class="timeline">' . $timeline . '</ul>' : '<p>尚待补充。</p>')
            . '<h2>纪念文章</h2>' . ($articleHtml !== '' ? $articleHtml : '<p>尚待补充。</p>')
            . '<h2>照片目录</h2>' . ($photoHtml !== '' ? '<div class="photos">' . $photoHtml . '</div>' : '<p>尚待补充。</p>')
            . '<footer>本档案由云叙台 AI 工作室辅助整理，内容需家属核对。生成时间：' . $escape($generated) . '。'
            . '档案仅用于家庭纪念，不构成对逝者身份或史实的官方证明。</footer>'
            . '</main></body></html>';
    }

    private static function thumbnailData(array $photo): string
    {
        $path = Storage::absolute((string) $photo['disk_path']);
        if ($path === null) {
            return '';
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return '';
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $max = 640;
        if (max($width, $height) > $max) {
            $ratio = $max / max($width, $height);
            $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BICUBIC);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }
        ob_start();
        imagejpeg($image, null, 78);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes === '' ? '' : 'data:image/jpeg;base64,' . base64_encode($bytes);
    }
}
