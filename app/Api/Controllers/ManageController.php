<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Api;
use App\Api\ApiException;
use App\Api\Input;
use App\Api\Presenter;
use App\Core\Request;
use App\Core\Response;
use App\Models\Memorial;
use App\Services\MemorialService;

/**
 * 馆主侧端点：列表复用 Memorial::forUser，创建复用 MemorialService。
 */
final class ManageController extends BaseController
{
    /**
     * GET /api/v1/manage/memorials — 当前用户有会员关系的馆。
     */
    public function index(Request $request): Response
    {
        $user = $this->user();
        $rows = Memorial::forUser((int) $user['id']);

        $items = array_map(static function (array $row): array {
            $item = Presenter::memorialSummary($row);
            $item['role'] = (string) ($row['member_role'] ?? '');
            return $item;
        }, $rows);

        return Api::ok(['items' => $items]);
    }

    /**
     * POST /api/v1/manage/memorials
     * 与网页 ManageMemorialController::store 同路径：新建即 private 草稿。
     */
    public function store(Request $request): Response
    {
        $user = $this->user();
        $input = Input::all($request);

        // 网页端必须勾选创建声明；API 以「调用方已同意条款」为前提，
        // 但显式传 declaration=false 时仍然拒绝。创建记录照常写入 declaration_accepted_at。
        if (array_key_exists('declaration', $input) && !Input::bool($request, 'declaration')) {
            throw ApiException::validation('请确认创建声明后再继续', ['declaration' => '需要接受创建声明']);
        }
        $input['declaration'] = '1';
        // 新建馆一律私密草稿，忽略客户端传入的 visibility（由 MemorialService 强制 private）。
        $input['visibility'] = 'private';

        $result = MemorialService::validate($input);
        if ($result['errors'] !== []) {
            throw ApiException::validation('纪念馆信息不完整', $result['errors']);
        }

        $id = MemorialService::create($result['data'], (int) $user['id']);
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw new ApiException('internal_error', '纪念馆创建后读取失败', 500);
        }

        return Api::ok([
            'id' => $id,
            'slug' => (string) $memorial['slug'],
        ], 201);
    }
}
