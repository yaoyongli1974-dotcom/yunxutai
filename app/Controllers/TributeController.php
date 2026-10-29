<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Models\Memorial;
use App\Models\Message;
use App\Models\Tribute;

final class TributeController
{
    private const TYPES = ['flower', 'candle', 'incense', 'bow'];

    public function store(Request $request, string $slug): Response
    {
        Csrf::verify($request);
        $memorial = $this->loadActionable($slug);
        $user = Auth::user();

        if (!RateLimiter::hit('tribute:' . $user['id'] . ':' . $memorial['id'], 12, 600)) {
            Flash::error('祭奠操作过于频繁，请稍后再来。');
            return Response::redirect('/m/' . $memorial['slug'] . '#tributes');
        }

        $type = (string) $request->input('type', '');
        if (!in_array($type, self::TYPES, true)) {
            throw new HttpException(422, '无效的祭奠方式');
        }

        $note = trim((string) $request->input('note', ''));
        if (mb_strlen($note) > 200) {
            Flash::error('附言不能超过 200 字。');
            return Response::redirect('/m/' . $memorial['slug'] . '#tributes');
        }

        $pending = $note !== '' && (int) $memorial['moderate_messages'] === 1;
        $id = Tribute::create(
            (int) $memorial['id'],
            (int) $user['id'],
            $type,
            $note,
            $request->bool('anonymous'),
            \App\Core\Str::ipHash($request->ip()),
            $pending ? 'pending' : 'visible'
        );

        Audit::log('tribute.create', 'tribute', $id, [
            'memorial_id' => (int) $memorial['id'],
            'type' => $type,
            'pending' => $pending,
        ]);

        Flash::success($pending ? '祭奠已记录，附言将在馆主确认后展示。' : '已完成' . tribute_label($type) . '。');
        return Response::redirect('/m/' . $memorial['slug'] . '#tributes');
    }

    public function message(Request $request, string $slug): Response
    {
        Csrf::verify($request);
        $memorial = $this->loadActionable($slug);
        $user = Auth::user();

        if ((int) $memorial['allow_guestbook'] !== 1) {
            Flash::error('该纪念馆暂未开放留言。');
            return Response::redirect('/m/' . $memorial['slug']);
        }

        if (!RateLimiter::hit('message:' . $user['id'] . ':' . $memorial['id'], 6, 600)) {
            Flash::error('留言过于频繁，请稍后再试。');
            return Response::redirect('/m/' . $memorial['slug'] . '#messages');
        }

        $content = trim((string) $request->input('content', ''));
        if (mb_strlen($content) < 2 || mb_strlen($content) > 1000) {
            Flash::error('留言需在 2–1000 字之间。');
            return Response::redirect('/m/' . $memorial['slug'] . '#messages');
        }

        $pending = (int) $memorial['moderate_messages'] === 1;
        $id = Message::create(
            (int) $memorial['id'],
            (int) $user['id'],
            (string) $user['display_name'],
            $content,
            \App\Core\Str::ipHash($request->ip()),
            $pending ? 'pending' : 'visible'
        );

        Audit::log('message.create', 'message', $id, ['memorial_id' => (int) $memorial['id']]);
        Flash::success($pending ? '留言已提交，馆主确认后展示。' : '留言已发布。');
        return Response::redirect('/m/' . $memorial['slug'] . '#messages');
    }

    private function loadActionable(string $slug): array
    {
        $memorial = Memorial::findBySlug($slug);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        if (!Policy::canPost($memorial, Auth::user())) {
            throw new HttpException(403, '无法在该纪念馆留言或祭奠');
        }
        return $memorial;
    }
}
