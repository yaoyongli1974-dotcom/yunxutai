<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 通知投递层内部异常。
 *
 * 仅由 Notify 的驱动实现抛出，最终会被 Notify::send() 捕获并转换为 false；
 * 调用方业务请求不应直接依赖本异常。
 */
final class NotifyException extends \RuntimeException
{
}
