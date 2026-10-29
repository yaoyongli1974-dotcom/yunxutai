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
use App\Models\Claim;

/**
 * 申领管理权：与网页 MemorialController::claimStore 同逻辑同限流（3 次/天）。
 */
final class ClaimController extends BaseController
{
    /**
     * POST /api/v1/memorials/{slug}/claim
     * body {relationship, reason}（兼容网页字段 statement/evidence_note）
     */
    public function store(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        if ($user === null) {
            throw ApiException::unauthorized('请先登录');
        }

        if (Policy::role($memorial, $user) !== null) {
            throw ApiException::validation('你已经是该纪念馆的成员', ['form' => '无需重复申领']);
        }

        $relationship = Input::string($request, 'relationship');
        $statement = Input::string($request, 'reason');
        if ($statement === '') {
            $statement = Input::string($request, 'statement');
        }
        $evidenceNote = Input::string($request, 'evidence_note');

        $fields = [];
        if ($relationship === '' || mb_strlen($relationship) > 120) {
            $fields['relationship'] = '请填写你与逝者的关系（120 字以内）';
        }
        if (mb_strlen($statement) < 20 || mb_strlen($statement) > 2000) {
            $fields['reason'] = '请说明申请理由，20–2000 字';
        }
        if (mb_strlen($evidenceNote) > 1000) {
            $fields['evidence_note'] = '证明材料说明不能超过 1000 字';
        }
        if ($fields !== []) {
            throw ApiException::validation('申领信息不完整', $fields);
        }

        if (Claim::pendingForUser((int) $memorial['id'], (int) $user['id'])) {
            throw ApiException::validation('你已有一条待处理的申请，请耐心等待审核', ['form' => '请勿重复提交']);
        }

        if (!RateLimiter::hit('claim:' . $user['id'], 3, 86400)) {
            throw ApiException::rateLimited('提交过于频繁，请明天再试');
        }

        $id = Claim::create(
            (int) $memorial['id'],
            (int) $user['id'],
            $relationship,
            $statement,
            $evidenceNote
        );
        Audit::log('claim.create', 'claim', $id, [
            'memorial_id' => (int) $memorial['id'],
            'source' => 'api',
        ]);

        return Api::ok(['id' => $id, 'status' => 'open'], 201);
    }
}
