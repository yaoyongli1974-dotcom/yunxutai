<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Memorial;
use App\Services\MemorialService;

final class MemorialController
{
    public function create(Request $request): Response
    {
        return View::render('manage/create', [
            'title' => '创建纪念馆',
            'declarations' => MemorialService::DECLARATIONS,
            'errors' => [],
            'old' => [],
            'noindex' => true,
        ]);
    }

    public function store(Request $request): Response
    {
        Csrf::verify($request);

        $result = MemorialService::validate($request->post);
        $errors = $result['errors'];
        $data = $result['data'];

        if ($errors !== []) {
            return View::render('manage/create', [
                'title' => '创建纪念馆',
                'declarations' => MemorialService::DECLARATIONS,
                'errors' => $errors,
                'old' => $request->post,
                'noindex' => true,
            ], 'layout', 422);
        }

        $id = MemorialService::create($data, (int) Auth::user()['id']);
        Flash::success('纪念馆已创建，目前为草稿且仅成员可见。请补充资料，并在“身份核验”中提交材料；核验通过后才可选择公开范围。');
        return Response::redirect('/manage/memorials/' . $id);
    }

    public function overview(Request $request, string $id): Response
    {
        $memorial = $this->load((int) $id);
        $user = Auth::user();
        if (!Policy::canView($memorial, $user)) {
            throw new HttpException(404, '纪念馆不存在');
        }

        return View::render('manage/overview', [
            'title' => '管理 · ' . $memorial['title'],
            'memorial' => $memorial,
            'role' => Policy::role($memorial, $user),
            'counts' => Memorial::counts((int) $memorial['id']),
            'canManage' => Policy::canManage($memorial, $user),
            'isAdmin' => Auth::isAdmin(),
            'noindex' => true,
        ]);
    }

    public function profile(Request $request, string $id): Response
    {
        $memorial = $this->load((int) $id);
        $this->requireEdit($memorial);

        return View::render('manage/profile', [
            'title' => '纪念馆资料 · ' . $memorial['title'],
            'memorial' => $memorial,
            'errors' => [],
            'old' => $memorial,
            'noindex' => true,
        ]);
    }

    public function updateProfile(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->load((int) $id);
        $this->requireEdit($memorial);

        $result = MemorialService::validate($request->post, $memorial);
        $errors = $result['errors'];
        $data = $result['data'];

        if ($errors !== []) {
            return View::render('manage/profile', [
                'title' => '纪念馆资料 · ' . $memorial['title'],
                'memorial' => $memorial,
                'errors' => $errors,
                'old' => array_merge($memorial, $request->post),
                'noindex' => true,
            ], 'layout', 422);
        }

        $errors = MemorialService::updateProfile($memorial, $data);
        if ($errors !== []) {
            return View::render('manage/profile', [
                'title' => '纪念馆资料 · ' . $memorial['title'],
                'memorial' => $memorial,
                'errors' => $errors,
                'old' => array_merge($memorial, $request->post),
                'noindex' => true,
            ], 'layout', 422);
        }

        $newSlug = $data['slug'] !== '' ? $data['slug'] : (string) $memorial['slug'];
        Flash::success('纪念馆资料已保存。');

        if ($newSlug !== (string) $memorial['slug']) {
            Flash::info('纪念馆链接已更新为 /m/' . $newSlug . '，旧链接不再可用。');
            return Response::redirect('/m/' . $newSlug);
        }

        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/profile');
    }

    public function settings(Request $request, string $id): Response
    {
        $memorial = $this->load((int) $id);
        $this->requireManage($memorial);

        return View::render('manage/settings', [
            'title' => '访问与留言设置 · ' . $memorial['title'],
            'memorial' => $memorial,
            'errors' => [],
            'old' => $memorial,
            'noindex' => true,
        ]);
    }

    public function updateSettings(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->load((int) $id);
        $this->requireManage($memorial);

        $visibility = (string) $request->input('visibility', '');
        if (!in_array($visibility, ['public', 'unlisted', 'private'], true)) {
            return View::render('manage/settings', [
                'title' => '访问与留言设置 · ' . $memorial['title'],
                'memorial' => $memorial,
                'errors' => ['visibility' => '请选择有效的访问范围'],
                'old' => [
                    'visibility' => $visibility,
                    'allow_search_index' => $request->bool('allow_search_index') ? 1 : 0,
                    'allow_guestbook' => $request->bool('allow_guestbook') ? 1 : 0,
                    'moderate_messages' => $request->bool('moderate_messages') ? 1 : 0,
                ],
                'noindex' => true,
            ], 'layout', 422);
        }

        $errors = MemorialService::updateSettings($memorial, [
            'visibility' => $visibility,
            'allow_search_index' => $request->bool('allow_search_index'),
            'allow_guestbook' => $request->bool('allow_guestbook'),
            'moderate_messages' => $request->bool('moderate_messages'),
        ]);

        if ($errors !== []) {
            return View::render('manage/settings', [
                'title' => '访问与留言设置 · ' . $memorial['title'],
                'memorial' => $memorial,
                'errors' => $errors,
                'old' => [
                    'visibility' => $visibility,
                    'allow_search_index' => $request->bool('allow_search_index') ? 1 : 0,
                    'allow_guestbook' => $request->bool('allow_guestbook') ? 1 : 0,
                    'moderate_messages' => $request->bool('moderate_messages') ? 1 : 0,
                ],
                'noindex' => true,
            ], 'layout', 422);
        }

        Flash::success('访问与留言设置已保存。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/settings');
    }

    public function publish(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->load((int) $id);
        $this->requireManage($memorial);

        if (($memorial['status'] ?? '') === 'active') {
            Flash::info('纪念馆已经处于展示状态。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
        }

        if (($memorial['status'] ?? '') === 'suspended'
            && !empty($memorial['suspended_by'])
            && !Auth::isAdmin()
        ) {
            Flash::error('该纪念馆由平台暂停，暂不能自行恢复。请通过页脚的联系渠道与我们沟通。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
        }

        $errors = MemorialService::publish($memorial);
        if ($errors !== []) {
            Flash::error(implode(' ', $errors));
            return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
        }

        Flash::success('纪念馆已发布，访客可以按设定的访问范围查看。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
    }

    public function pause(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->load((int) $id);
        $this->requireManage($memorial);

        if (($memorial['status'] ?? '') !== 'active') {
            Flash::info('只有展示中的纪念馆可以暂停。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
        }

        MemorialService::pauseByOwner($memorial);
        Flash::success('纪念馆已暂停对外展示。恢复后可继续访问。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
    }

    public function resume(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->load((int) $id);
        $this->requireManage($memorial);

        if (($memorial['status'] ?? '') !== 'suspended') {
            Flash::info('纪念馆当前不需要恢复。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
        }

        if (!empty($memorial['suspended_by']) && !Auth::isAdmin()) {
            Flash::error('该纪念馆由平台暂停，暂不能自行恢复。请通过页脚的联系渠道与我们沟通。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
        }

        MemorialService::resumeByOwner($memorial);
        Flash::success('纪念馆已恢复展示。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id']);
    }

    private function load(int $id): array
    {
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        return $memorial;
    }

    private function requireEdit(array $memorial): void
    {
        if (!Policy::canEdit($memorial, Auth::user())) {
            throw new HttpException(403, '没有编辑该纪念馆的权限');
        }
    }

    private function requireManage(array $memorial): void
    {
        if (!Policy::canManage($memorial, Auth::user())) {
            throw new HttpException(403, '只有馆主可以管理该设置');
        }
    }
}
