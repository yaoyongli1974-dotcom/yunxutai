<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Memorial;
use App\Models\Message;
use App\Models\Tribute;

final class ModerationController
{
    public function index(Request $request, string $id): Response
    {
        $memorial = $this->loadModeratable((int) $id);

        return $this->renderIndex($memorial, []);
    }

    public function messageStatus(Request $request, string $id, string $messageId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadModeratable((int) $id);
        $message = Message::find((int) $messageId);
        if ($message === null || (int) $message['memorial_id'] !== (int) $memorial['id']) {
            throw new HttpException(404, '留言不存在');
        }

        $status = (string) $request->input('status', '');
        if (!in_array($status, ['visible', 'hidden'], true)) {
            return $this->renderIndex($memorial, ['status' => '请选择展示或隐藏。'], 422);
        }

        Message::setStatus((int) $message['id'], $status, (int) Auth::user()['id']);
        Audit::log('moderation.message_status', 'message', (int) $message['id'], [
            'memorial_id' => (int) $memorial['id'],
            'from' => $message['status'],
            'to' => $status,
        ]);

        Flash::success($status === 'visible' ? '留言已设为展示。' : '留言已隐藏。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/moderation');
    }

    public function tributeStatus(Request $request, string $id, string $tributeId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadModeratable((int) $id);
        $tribute = Tribute::find((int) $tributeId);
        if ($tribute === null || (int) $tribute['memorial_id'] !== (int) $memorial['id']) {
            throw new HttpException(404, '祭奠记录不存在');
        }

        $status = (string) $request->input('status', '');
        if (!in_array($status, ['visible', 'hidden'], true)) {
            return $this->renderIndex($memorial, ['status' => '请选择展示或隐藏。'], 422);
        }

        Tribute::setStatus((int) $tribute['id'], $status, (int) Auth::user()['id']);
        Audit::log('moderation.tribute_status', 'tribute', (int) $tribute['id'], [
            'memorial_id' => (int) $memorial['id'],
            'from' => $tribute['status'],
            'to' => $status,
        ]);

        Flash::success($status === 'visible' ? '祭奠记录已设为展示。' : '祭奠记录已隐藏。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/moderation');
    }

    private function renderIndex(array $memorial, array $errors, int $status = 200): Response
    {
        return View::render('manage/moderation', [
            'title' => '内容审核 · ' . $memorial['title'],
            'memorial' => $memorial,
            'messages' => $this->group(Message::forModeration((int) $memorial['id'])),
            'tributes' => $this->group(Tribute::forModeration((int) $memorial['id'])),
            'errors' => $errors,
            'noindex' => true,
        ], 'layout', $status);
    }

    /**
     * @return array{pending: array<int,array>, visible: array<int,array>, hidden: array<int,array>}
     */
    private function group(array $rows): array
    {
        $grouped = ['pending' => [], 'visible' => [], 'hidden' => []];
        foreach ($rows as $row) {
            $key = (string) ($row['status'] ?? '');
            if (isset($grouped[$key])) {
                $grouped[$key][] = $row;
            }
        }
        return $grouped;
    }

    private function loadModeratable(int $id): array
    {
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        if (!Policy::canModerate($memorial, Auth::user())) {
            throw new HttpException(403, '没有审核该纪念馆内容的权限');
        }
        return $memorial;
    }
}
