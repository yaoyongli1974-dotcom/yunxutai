<?php
declare(strict_types=1);

namespace App\Services\Moderation;

use App\Core\Request;

final class IdsParser
{
    /**
     * 解析并去重的勾选 id 列表：仅保留数字 id、按 id 去重、上限 100 条，空或超限返回 null。
     *
     * array_keys 已返回连续整数列表，故无需再套一层无副作用的 array_values。
     *
     * @return non-empty-list<int>|null
     */
    public static function parse(Request $request): ?array
    {
        $raw = $request->raw('ids', []);
        if (!is_array($raw)) {
            return null;
        }
        $ids = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $ids[(int) $v] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === [] || count($ids) > 100) {
            return null;
        }
        return $ids;
    }
}
