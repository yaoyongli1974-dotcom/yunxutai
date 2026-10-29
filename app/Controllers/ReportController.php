<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Captcha;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Article;
use App\Models\Media;
use App\Models\Memorial;
use App\Models\Message;
use App\Models\Report;
use App\Models\Tribute;

final class ReportController
{
    private const REASONS = ['fake_person', 'living_person', 'privacy', 'insult', 'illegal', 'copyright', 'other'];

    public function create(Request $request): Response
    {
        $type = (string) ($request->query['type'] ?? '');
        $id = (int) ($request->query['id'] ?? 0);
        $target = $this->resolveTarget($type, $id);

        $this->assertReportable($target, Auth::user());

        return View::render('report/form', [
            'title' => '投诉与举报',
            'target' => $target,
            'errors' => [],
            'old' => [],
            'captcha' => Captcha::question(),
            'noindex' => true,
        ]);
    }

    public function store(Request $request): Response
    {
        Csrf::verify($request);

        $type = (string) $request->input('target_type', '');
        $id = (int) $request->input('target_id', 0);
        $target = $this->resolveTarget($type, $id);
        $this->assertReportable($target, Auth::user());

        $user = Auth::user();
        $data = [
            'reason' => (string) $request->input('reason', ''),
            'detail' => trim((string) $request->input('detail', '')),
            'reporter_name' => trim((string) $request->input('reporter_name', $user['display_name'] ?? '')),
            'contact' => trim((string) $request->input('contact', $user['email'] ?? '')),
        ];
        $errors = [];

        if ($data['reason'] === '' || !in_array($data['reason'], self::REASONS, true)) {
            $errors['reason'] = '请选择举报类型';
        }
        if (mb_strlen($data['detail']) < 10 || mb_strlen($data['detail']) > 2000) {
            $errors['detail'] = '请补充具体情况，10–2000 字';
        }
        if ($data['reporter_name'] === '' || mb_strlen($data['reporter_name']) > 80) {
            $errors['reporter_name'] = '请填写你的称呼（80 字以内）';
        }
        if (mb_strlen($data['contact']) > 190) {
            $errors['contact'] = '联系方式过长';
        }
        if (!RateLimiter::hit('report:' . $request->ip(), 5, 3600)) {
            $errors['form'] = '提交过于频繁，请稍后再试';
        }
        if (!Captcha::verify($request->input('captcha'))) {
            $errors['captcha'] = '验证码不正确或已过期';
        }

        if ($errors !== []) {
            return View::render('report/form', [
                'title' => '投诉与举报',
                'target' => $target,
                'errors' => $errors,
                'old' => $data,
                'captcha' => Captcha::question(),
                'noindex' => true,
            ], 'layout', 422);
        }

        $reportId = Report::create([
            'target_type' => $type,
            'target_id' => $id,
            'memorial_id' => (int) $target['memorial']['id'],
            'reporter_user_id' => $user['id'] ?? null,
            'reporter_name' => $data['reporter_name'],
            'contact' => $data['contact'] ?: null,
            'reason' => $data['reason'],
            'detail' => $data['detail'],
        ]);

        Audit::log('report.create', 'report', $reportId, [
            'target_type' => $type,
            'target_id' => $id,
            'reason' => $data['reason'],
        ]);

        Flash::success('举报已提交，平台会尽快核查。');
        return Response::redirect('/report/success');
    }

    public function success(Request $request): Response
    {
        return View::render('report/success', ['title' => '举报已提交', 'noindex' => true]);
    }

    /**
     * 举报对象必须对当前访问者可见，避免通过举报表单泄露私密媒体、待审内容。
     */
    private function assertReportable(array $target, ?array $user): void
    {
        $memorial = $target['memorial'];
        if (!Policy::canView($memorial, $user)) {
            throw new HttpException(404, '内容不存在');
        }

        $item = $target['item'] ?? null;
        if ($item === null) {
            throw new HttpException(404, '内容不存在');
        }

        $allowed = match ($target['type']) {
            'memorial' => true,
            'message', 'tribute' => ($item['status'] ?? '') === 'visible'
                || Policy::canModerate($memorial, $user),
            'media' => Policy::canViewMedia($item, $memorial, $user),
            'article' => ($item['status'] ?? '') === 'published'
                || Policy::canEdit($memorial, $user),
            default => false,
        };

        if (!$allowed) {
            throw new HttpException(404, '内容不存在');
        }
    }

    /**
     * @return array{type:string,id:int,memorial:array,item:array,label:string,snippet:string}
     */
    private function resolveTarget(string $type, int $id): array
    {
        if ($id <= 0) {
            throw new HttpException(404, '内容不存在');
        }

        switch ($type) {
            case 'memorial':
                $memorial = Memorial::find($id);
                if ($memorial === null) {
                    throw new HttpException(404, '内容不存在');
                }
                return [
                    'type' => $type,
                    'id' => $id,
                    'memorial' => $memorial,
                    'item' => $memorial,
                    'label' => '纪念馆：' . $memorial['title'],
                    'snippet' => excerpt_text($memorial['summary'] ?: $memorial['bio'], 100),
                ];

            case 'message':
                $message = Message::find($id);
                $memorial = $message ? Memorial::find((int) $message['memorial_id']) : null;
                if ($message === null || $memorial === null) {
                    throw new HttpException(404, '内容不存在');
                }
                return [
                    'type' => $type,
                    'id' => $id,
                    'memorial' => $memorial,
                    'item' => $message,
                    'label' => '留言（' . $message['author_name'] . '）',
                    'snippet' => excerpt_text($message['content'], 100),
                ];

            case 'tribute':
                $tribute = Tribute::find($id);
                $memorial = $tribute ? Memorial::find((int) $tribute['memorial_id']) : null;
                if ($tribute === null || $memorial === null) {
                    throw new HttpException(404, '内容不存在');
                }
                return [
                    'type' => $type,
                    'id' => $id,
                    'memorial' => $memorial,
                    'item' => $tribute,
                    'label' => '祭奠记录：' . tribute_label($tribute['type']),
                    'snippet' => excerpt_text((string) $tribute['note'], 100),
                ];

            case 'media':
                $media = Media::find($id);
                $memorial = $media ? Memorial::find((int) $media['memorial_id']) : null;
                if ($media === null || $memorial === null) {
                    throw new HttpException(404, '内容不存在');
                }
                return [
                    'type' => $type,
                    'id' => $id,
                    'memorial' => $memorial,
                    'item' => $media,
                    'label' => '影像资料：' . $media['original_name'],
                    'snippet' => excerpt_text((string) $media['caption'], 100),
                ];

            case 'article':
                $article = Article::find($id);
                $memorial = $article ? Memorial::find((int) $article['memorial_id']) : null;
                if ($article === null || $memorial === null) {
                    throw new HttpException(404, '内容不存在');
                }
                return [
                    'type' => $type,
                    'id' => $id,
                    'memorial' => $memorial,
                    'item' => $article,
                    'label' => '纪念文章：' . $article['title'],
                    'snippet' => excerpt_text($article['content'], 100),
                ];
        }

        throw new HttpException(404, '内容不存在');
    }
}
