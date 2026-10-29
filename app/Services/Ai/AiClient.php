<?php
declare(strict_types=1);

namespace App\Services\Ai;

interface AiClient
{
    public function provider(): string;

    public function generateText(string $system, string $prompt, int $maxTokens = 1800): string;

    /**
     * 返回修复后的图片二进制内容。
     */
    public function restoreImage(string $sourcePath, string $prompt): string;
}
