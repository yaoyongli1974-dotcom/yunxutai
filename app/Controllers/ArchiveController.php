<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ArchiveService;
use App\Services\FamilyPersonService;
use App\Services\FamilyService;

/** 数字档案馆 Web 入口；与 API 共用 ArchiveService。 */
final class ArchiveController
{
    private function family(int $familyId, string $capability): array
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require($capability, ['family_id' => $familyId, 'family' => $family], Auth::user());
        return $family;
    }

    private function archive(int $archiveId): array
    {
        $archive = ArchiveService::getVisible(Auth::user(), $archiveId);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在');
        }
        return $archive;
    }

    public function index(Request $request, string $id): Response
    {
        $familyId = (int) $id;
        $family = $this->family($familyId, 'family.view');
        $search = trim((string) ($request->query['q'] ?? ''));
        return View::render('archive/index', [
            'title' => '数字档案',
            'family' => $family,
            'archives' => ArchiveService::listForFamily(Auth::user(), $familyId, $search),
            'search' => $search,
            'canUpload' => Policy::can('archive.upload', ['family_id' => $familyId], Auth::user()),
            'canApprove' => Policy::can('archive.delete.approve', ['family_id' => $familyId], Auth::user()),
            'noindex' => true,
        ]);
    }

    public function create(Request $request, string $id): Response
    {
        $family = $this->family((int) $id, 'archive.upload');
        return View::render('archive/create', [
            'title' => '新建档案', 'family' => $family, 'errors' => [], 'old' => [], 'noindex' => true,
        ]);
    }

    public function store(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $family = $this->family($familyId, 'archive.upload');
        try {
            $archiveId = ArchiveService::create(Auth::user(), $familyId, $request->post);
        } catch (HttpException $e) {
            return View::render('archive/create', [
                'title' => '新建档案', 'family' => $family,
                'errors' => [$e->getMessage()], 'old' => $request->post, 'noindex' => true,
            ], 'layout', $e->getStatusCode());
        }
        Flash::success('档案已创建。原始文件会独立保存，修改会保留历史版本。');
        return Response::redirect('/archives/' . $archiveId);
    }

    public function show(Request $request, string $id): Response
    {
        $archive = $this->archive((int) $id);
        $familyId = (int) $archive['family_id'];
        $family = $this->family($familyId, 'family.view');
        $canEdit = Policy::can('archive.edit', ['family_id' => $familyId], Auth::user());
        $allPersons = [];
        if ($canEdit) {
            foreach (Db::fetchAll('SELECT id FROM family_persons WHERE family_id = ? AND deleted_at IS NULL ORDER BY id', [$familyId]) as $row) {
                $person = FamilyPersonService::getVisible(Auth::user(), (int) $row['id']);
                if ($person !== null) {
                    $allPersons[] = $person;
                }
            }
        }
        return View::render('archive/show', [
            'title' => (string) $archive['title'],
            'family' => $family,
            'archive' => $archive,
            'files' => ArchiveService::files((int) $archive['id']),
            'tags' => ArchiveService::tags((int) $archive['id']),
            'persons' => ArchiveService::linkedPersons((int) $archive['id']),
            'allPersons' => $allPersons,
            'versions' => $canEdit ? ArchiveService::versions(Auth::user(), (int) $archive['id']) : [],
            'photoTags' => (static function () use ($archive): array {
                $map = [];
                foreach (ArchiveService::files((int) $archive['id']) as $file) {
                    $map[(int) $file['id']] = ArchiveService::photoTags((int) $file['id']);
                }
                return $map;
            })(),
            'canExport' => Policy::can('data.export', ['family_id' => $familyId], Auth::user()),
            'canEdit' => $canEdit,
            'canUpload' => Policy::can('archive.upload', ['family_id' => $familyId], Auth::user()),
            'canApprove' => Policy::can('archive.delete.approve', ['family_id' => $familyId], Auth::user()),
            'canDelete' => Policy::can('archive.delete.request', ['family_id' => $familyId], Auth::user()),
            'noindex' => true,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $archive = $this->archive((int) $id);
        try {
            ArchiveService::update(Auth::user(), (int) $archive['id'], $request->post);
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/archives/' . (int) $archive['id']);
    }

    public function upload(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $archive = $this->archive((int) $id);
        if (!\App\Core\RateLimiter::hit(\App\Core\RateLimitKeys::make('upload', (string) (Auth::user()['id'] ?? ''), (string) $archive['family_id']), 30, 3600)) {
            Flash::error('上传次数过多，请稍后再试。');
            return Response::redirect('/archives/' . (int) $archive['id']);
        }
        $file = $request->file('file');
        try {
            if ($file === null) {
                throw new HttpException(422, '请选择要上传的文件');
            }
            $stored = ArchiveService::addFile(Auth::user(), (int) $archive['id'], $file);
            Flash::success('文件已归档，SHA-256 校验值已记录。');
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        } catch (\RuntimeException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/archives/' . (int) $archive['id']);
    }

    public function download(Request $request, string $id): Response
    {
        $found = ArchiveService::getFileVisible(Auth::user(), (int) $id);
        if ($found === null) {
            throw new HttpException(404, '文件不存在或无权下载');
        }
        $path = ArchiveService::absolutePath($found['file']);
        if ($path === null) {
            throw new HttpException(404, '文件不存在');
        }
        Audit::log('archive.file.download', 'archive_file', (int) $found['file']['id'], [
            'archive_id' => (int) $found['archive']['id'],
        ]);
        $name = str_replace(['"', "\r", "\n"], '', (string) $found['file']['original_filename']);
        return Response::file(
            $path,
            (string) $found['file']['mime_type'],
            200,
            [
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function addTag(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $archive = $this->archive((int) $id);
        try {
            ArchiveService::addTag(Auth::user(), (int) $archive['id'], (string) ($request->post['name'] ?? ''));
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/archives/' . (int) $archive['id']);
    }

    public function linkPerson(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $archive = $this->archive((int) $id);
        try {
            ArchiveService::linkPerson(
                Auth::user(),
                (int) $archive['id'],
                (int) ($request->post['person_id'] ?? 0),
                (string) ($request->post['relation_type'] ?? 'subject')
            );
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/archives/' . (int) $archive['id']);
    }

    public function addPhotoTag(Request $request, string $id, string $fileId): Response
    {
        Csrf::verify($request);
        try {
            ArchiveService::addPhotoTag(
                Auth::user(), (int) $fileId, (int) ($request->post['person_id'] ?? 0),
                (float) ($request->post['x'] ?? 0), (float) ($request->post['y'] ?? 0),
                (float) ($request->post['width'] ?? 0), (float) ($request->post['height'] ?? 0)
            );
            Flash::success('标注已提交，待管理者确认。');
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/archives/' . (int) $id);
    }

    public function reviewPhotoTag(Request $request, string $tagId): Response
    {
        Csrf::verify($request);
        try {
            ArchiveService::reviewPhotoTag(Auth::user(), (int) $tagId, (string) ($request->post['status'] ?? ''));
            Flash::success('标注审核已保存。');
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/archives/' . (int) ($request->post['archive_id'] ?? 0));
    }

    public function export(Request $request, string $id): Response
    {
        try {
            $bundle = ArchiveService::exportBundle(Auth::user(), (int) $id);
        } catch (HttpException $e) {
            throw new HttpException($e->getStatusCode(), $e->getMessage());
        }
        return Response::json(['data' => $bundle]);
    }

    public function requestDelete(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $archive = $this->archive((int) $id);
        try {
            ArchiveService::requestDelete(Auth::user(), (int) $archive['id']);
            Flash::success('删除申请已提交，需家族管理员确认。');
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/archives/' . (int) $archive['id']);
    }

    public function decideDelete(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $archive = Db::fetch('SELECT id FROM archives WHERE id = ? LIMIT 1', [(int) $id]);
        if ($archive === null) {
            throw new HttpException(404, '档案不存在');
        }
        try {
            ArchiveService::approveDelete(Auth::user(), (int) $id, $request->bool('approve'));
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/families/' . (int) ($request->post['family_id'] ?? 0) . '/archives');
    }
}
