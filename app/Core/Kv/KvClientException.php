<?php
declare(strict_types=1);

namespace App\Core\Kv;

use RuntimeException;

/**
 * KV 客户端基础设施异常：连接失败、IO 失败、超时等。
 *
 * 单独成类便于调用方区分「Redis 服务端返回错误」与「网络/协议层故障」。
 * 不携带连接串与密码，避免异常日志泄露凭据。
 */
final class KvClientException extends RuntimeException
{
}
