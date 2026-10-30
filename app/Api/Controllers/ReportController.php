<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Api;
use App\Api\ApiException;
use App\Api\Input;
use App\Core\Audit;
use App\Core\Policy;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Models\Article;
use App\Models\Media;
use App\Models\Memorial;
use App\Models\Message;
use App\Models\Report;
use App\Models\Tribute;

/**
 * 投诉与举报（小程序端）。
 *
 * 语义与网页端 App\Controllers\ReportController 完全一致：
 *   - 目标必须对当前访问者可见，否则统一 404（不泄露私密馆/待审内容的存在性）；
 *   - reason 七类，Report::create 自动分级（活人被祭拜/虚构逝者 → urgent）；
 *   - 限流按 IP 5 次/小时；审计动作同为 report.create。
 * 差异：API 为 Bearer 会话，不要求算术验证码（身份已建立），以限流替代。
 */
final class ReportController extends BaseController
{
    private const REASONS = ['fake_person', 'living_person', 'privacy', 'insult', 'illegal', 'copyright', 'other'];

    /**
     * POST /api/v1/reports
     */
    public function store(Request $request): Response
    {
        $user = $this->user();
        $type = Input::string($request, 'target_type');
        $id = Input::int($request, 'target_id');
        $target = $this->resolveTarget($type, $id);
        $this->assertReportable($target, $user);

        $reason = Input::string($request, 'reason');
        $detail = Input::string($request, 'detail');
        $reporterName = Input::string($request, 'reporter_name');
        $contact = Input::string($request, 'contact');

        $fields = [];
        if (!in_array($reason, self::REASONS, true)) {
            $fields['reason'] = '请选择举报类型';
        }
        $detailLength = mb_strlen($detail);
        if ($detailLength < 10 || $detailLength > 2000) {
            $fields['detail'] = '请补充具体情况，10–2000 字';
        }
        if ($reporterName === '') {
            $reporterName = (string) ($user['display_name'] ?? '');
        }
        if ($reporterName === '' || mb_strlen($reporterName) > 80) {
            $fields['reporter_name'] = '请填写你的称呼（80 字以内）';
        }
        if (mb_strlen($contact) > 190) {
            $fields['contact'] = '联系方式过长';
        }
        if ($fields !== []) {
            throw ApiException::validation('举报信息不完整', $fields);
        }

        if (!RateLimiter::hit('report:' . $request->ip(), 5, 3600)) {
            throw ApiException::rateLimited('提交过于频繁，请稍后再试');
        }

        // 微信合成邮箱不是可用联系方式，不默认回填，避免运营拿到无法触达的地址。
        if ($contact === '') {
            $email = (string) ($user['email'] ?? '');
            $contact = str_ends_with($email, '@wx.local') ? '' : $email;
        }

        $reportId = Report::create([
            'target_type' => $type,
            'target_id' => $id,
            'memorial_id' => (int) $target['memorial']['id'],
            'reporter_user_id' => (int) $user['id'],
            'reporter_name' => $reporterName,
            'contact' => $contact !== '' ? $contact : null,
            'reason' => $reason,
            'detail' => $detail,
        ]);

        Audit::log('report.create', 'report', $reportId, [
            'target_type' => $type,
            'target_id' => $id,
            'reason' => $reason,
            'channel' => 'api',
        ]);

        return Api::ok([
            'id' => $reportId,
            'status' => 'open',
            'priority' => Report::priorityForReason($reason),
        ], 201);
    }

    /**
     * 目标可见性校验：与网页端 assertReportable 同口径。
     */
    private function assertReportable(array $target, array $user): void
    {
        $memorial = $target['memorial'];
        if (!Policy::canView($memorial, $user)) {
            throw ApiException::notFound('内容不存在');
        }

        $item = $target['item'] ?? null;
        if ($item === null) {
            throw ApiException::notFound('内容不存在');
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
            throw ApiException::notFound('内容不存在');
        }
    }

    /**
     * @return array{type:string,id:int,memorial:array,item:array}
     */
    private function resolveTarget(string $type, int $id): array
    {
        if ($id <= 0) {
            throw ApiException::notFound('内容不存在');
        }

        switch ($type) {
            case 'memorial':
                $memorial = Memorial::find($id);
                if ($memorial === null) {
                    throw ApiException::notFound('内容不存在');
                }
                return ['type' => $type, 'id' => $id, 'memorial' => $memorial, 'item' => $memorial];

            case 'message':
                $message = Message::find($id);
                $memorial = $message ? Memorial::find((int) $message['memorial_id']) : null;
                if ($message === null || $memorial === null) {
                    throw ApiException::notFound('内容不存在');
                }
                return ['type' => $type, 'id' => $id, 'memorial' => $memorial, 'item' => $message];

            case 'tribute':
                $tribute = Tribute::find($id);
                $memorial = $tribute ? Memorial::find((int) $tribute['memorial_id']) : null;
                if ($tribute === null || $memorial === null) {
                    throw ApiException::notFound('内容不存在');
                }
                return ['type' => $type, 'id' => $id, 'memorial' => $memorial, 'item' => $tribute];

            case 'media':
                $media = Media::find($id);
                $memorial = $media ? Memorial::find((int) $media['memorial_id']) : null;
                if ($media === null || $memorial === null) {
                    throw ApiException::notFound('内容不存在');
                }
                return ['type' => $type, 'id' => $id, 'memorial' => $memorial, 'item' => $media];

            case 'article':
                $article = Article::find($id);
                $memorial = $article ? Memorial::find((int) $article['memorial_id']) : null;
                if ($article === null || $memorial === null) {
                    throw ApiException::notFound('内容不存在');
                }
                return ['type' => $type, 'id' => $id, 'memorial' => $memorial, 'item' => $article];
        }

        throw ApiException::notFound('内容不存在');
    }
}
