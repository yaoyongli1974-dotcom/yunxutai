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
use App\Core\Storage;
use App\Core\View;
use App\Models\Memorial;
use App\Models\Verification;

final class VerificationController
{
    public function show(Request $request, string $id): Response
    {
        $memorial = $this->load((int) $id);
        $this->requireManage($memorial);

        return View::render('manage/verification', [
            'title' => '身份核验 · ' . $memorial['title'],
            'memorial' => $memorial,
            'latest' => Verification::latestFor((int) $memorial['id']),
            'methods' => Verification::METHODS,
            'errors' => [],
            'old' => [],
            'noindex' => true,
        ]);
    }

    public function store(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->load((int) $id);
        $this->requireManage($memorial);
        $memorialId = (int) $memorial['id'];

        $latest = Verification::latestFor($memorialId);
        $render = function (array $errors, array $old) use ($memorial, $latest): Response {
            return View::render('manage/verification', [
                'title' => '身份核验 · ' . $memorial['title'],
                'memorial' => $memorial,
                'latest' => $latest,
                'methods' => Verification::METHODS,
                'errors' => $errors,
                'old' => $old,
                'noindex' => true,
            ], 'layout', 422);
        };

        if (($memorial['verification_status'] ?? 'none') === 'verified') {
            Flash::info('该纪念馆已完成身份核验，无需重复提交。');
            return Response::redirect('/manage/memorials/' . $memorialId . '/verification');
        }

        if ($latest !== null && $latest['status'] === 'pending') {
            return $render(['form' => '已有一份核验材料正在审核中，请耐心等待，无需重复提交。'], $request->post);
        }

        $method = (string) $request->input('method', '');
        $statement = trim((string) $request->input('statement', ''));
        $errors = [];

        if (!isset(Verification::METHODS[$method])) {
            $errors['method'] = '请选择材料类型';
        }
        $length = mb_strlen($statement);
        if ($length < 20) {
            $errors['statement'] = '请用至少 20 个字说明你与逝者的关系及材料来源';
        } elseif ($length > 2000) {
            $errors['statement'] = '说明不能超过 2000 字';
        }

        $file = $request->file('document');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['document'] = '请上传至少一份核验材料（死亡证明、亲属关系证明等）';
        }

        if ($errors !== []) {
            return $render($errors, $request->post);
        }

        try {
            $stored = Storage::storeVerificationDocument($file);
        } catch (HttpException $e) {
            return $render(['document' => $e->getMessage()], $request->post);
        }

        try {
            Verification::create([
                'memorial_id' => $memorialId,
                'submitted_by' => (int) Auth::user()['id'],
                'method' => $method,
                'statement' => $statement,
                'document_path' => $stored['disk_path'],
                'document_name' => $stored['original_name'],
                'document_mime' => $stored['mime'],
                'document_size' => $stored['size_bytes'],
            ]);
            Memorial::update($memorialId, [
                'verification_status' => 'pending',
                'verification_method' => $method,
                'verified_at' => null,
                'verified_by' => null,
                // 待核验期间不允许对外公开；避免利用“审核中”窗口传播未核验信息。
                'visibility' => 'private',
                'allow_search_index' => 0,
            ]);
        } catch (\Throwable $e) {
            Storage::deleteVerification($stored['disk_path']);
            throw $e;
        }

        Audit::log('memorial.verification_submitted', 'memorial', $memorialId, ['method' => $method]);

        Flash::success('核验材料已提交。平台会人工核对，通常需要 1–3 个工作日；审核期间纪念馆仍保持私密。');
        return Response::redirect('/manage/memorials/' . $memorialId . '/verification');
    }

    private function load(int $id): array
    {
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        return $memorial;
    }

    private function requireManage(array $memorial): void
    {
        if (!Policy::canManage($memorial, Auth::user())) {
            throw new HttpException(403, '只有馆主可以提交身份核验材料');
        }
    }
}
