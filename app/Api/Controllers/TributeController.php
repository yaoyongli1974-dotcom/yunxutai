<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Api;
use App\Api\ApiException;
use App\Api\Input;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Policy;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Models\Message;
use App\Models\Tribute;

/**
 * 互动端点：频率、长度、审核状态全部照搬网页端 TributeController，
 * 唯一差异是去掉 Csrf（Bearer token 即防伪）并把提示改成 JSON。
 */
final class TributeController extends BaseController
{
    private const TYPES = ['flower', 'candle', 'incense', 'bow'];

    /**
     * POST /api/v1/memorials/{slug}/tribute
     * body {type, appendix?, anonymous?}
     */
    public function tribute(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadPostable($slug);

        if (!RateLimiter::hit('tribute:' . $user['id'] . ':' . $memorial['id'], 12, 600)) {
            throw ApiException::rateLimited('祭奠操作过于频繁，请稍后再来');
        }

        $type = Input::string($request, 'type');
        if (!in_array($type, self::TYPES, true)) {
            throw ApiException::validation('无效的祭奠方式', ['type' => '仅支持 flower/candle/incense/bow']);
        }

        // 契约字段为 appendix，兼容网页端的 note。
        $note = Input::string($request, 'appendix');
        if ($note === '') {
            $note = Input::string($request, 'note');
        }
        if (mb_strlen($note) > 200) {
            throw ApiException::validation('附言不能超过 200 字', ['appendix' => '最多 200 字']);
        }

        $pending = $note !== '' && (int) $memorial['moderate_messages'] === 1;
        $status = $pending ? 'pending' : 'visible';
        $id = Tribute::create(
            (int) $memorial['id'],
            (int) $user['id'],
            $type,
            $note,
            Input::bool($request, 'anonymous'),
            Str::ipHash($request->ip()),
            $status
        );

        Audit::log('tribute.create', 'tribute', $id, [
            'memorial_id' => (int) $memorial['id'],
            'type' => $type,
            'pending' => $pending,
            'source' => 'api',
        ]);

        return Api::ok(['id' => $id, 'content_id' => $id, 'status' => $status]);
    }

    /**
     * GET /api/v1/memorials/{slug}/messages
     * 非成员只看 visible；成员返回全部状态（含 pending/hidden）并带 status 字段。
     */
    public function messages(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $isMember = Policy::role($memorial, $user) !== null;
        $memorialId = (int) $memorial['id'];

        [$page, $perPage] = Api::page($request, 20, 50);
        $offset = ($page - 1) * $perPage;

        if ($isMember) {
            $total = (int) Db::value('SELECT COUNT(*) FROM messages WHERE memorial_id = ?', [$memorialId]);
            $rows = Db::fetchAll(
                "SELECT * FROM messages WHERE memorial_id = ?
                 ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}",
                [$memorialId]
            );
        } else {
            $total = Message::visibleCount($memorialId);
            $rows = Message::visiblePaginate($memorialId, $perPage, $offset);
        }

        $items = array_map(static function (array $row) use ($isMember): array {
            $item = [
                'id' => (int) $row['id'],
                'author_name' => (string) $row['author_name'],
                'content' => (string) $row['content'],
                'created_at' => $row['created_at'] ?? null,
            ];
            if ($isMember) {
                $item['status'] = (string) $row['status'];
            }
            return $item;
        }, $rows);

        return Api::ok([
            'items' => $items,
            'pagination' => Api::pagination($total, $page, $perPage),
        ]);
    }

    /**
     * POST /api/v1/memorials/{slug}/message
     * body {body, anonymous?}
     */
    public function message(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadPostable($slug);

        if ((int) $memorial['allow_guestbook'] !== 1) {
            throw ApiException::forbidden('该纪念馆暂未开放留言');
        }

        if (!RateLimiter::hit('message:' . $user['id'] . ':' . $memorial['id'], 6, 600)) {
            throw ApiException::rateLimited('留言过于频繁，请稍后再试');
        }

        // 契约字段为 body，兼容网页端的 content。
        $content = Input::string($request, 'body');
        if ($content === '') {
            $content = Input::string($request, 'content');
        }
        if (mb_strlen($content) < 2 || mb_strlen($content) > 1000) {
            throw ApiException::validation('留言需在 2–1000 字之间', ['body' => '留言需在 2–1000 字之间']);
        }

        // messages 表无匿名列：匿名时以网站既有的访客称呼署名，不落真实昵称。
        $authorName = Input::bool($request, 'anonymous')
            ? '一位访客'
            : (string) $user['display_name'];

        $pending = (int) $memorial['moderate_messages'] === 1;
        $status = $pending ? 'pending' : 'visible';
        $id = Message::create(
            (int) $memorial['id'],
            (int) $user['id'],
            $authorName,
            $content,
            Str::ipHash($request->ip()),
            $status
        );

        Audit::log('message.create', 'message', $id, [
            'memorial_id' => (int) $memorial['id'],
            'source' => 'api',
        ]);

        return Api::ok(['message_id' => $id, 'status' => $status], $pending ? 202 : 200);
    }
}
