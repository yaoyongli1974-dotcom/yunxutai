<?php
declare(strict_types=1);

namespace App\Core;

final class Storage
{
    public static function uploadsPath(string $relative = ''): string
    {
        $base = (string) config('storage.uploads_path');
        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }

    /**
     * 校验并保存上传文件。返回相对 storage/uploads 的路径与元数据。
     *
     * @return array{disk_path:string, original_name:string, mime:string, size_bytes:int, kind:string}
     */
    public static function storeUpload(array $file, string $expectedKind = 'photo'): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, match ($file['error'] ?? null) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过服务器允许的大小',
                UPLOAD_ERR_PARTIAL => '文件上传不完整，请重试',
                default => '文件上传失败',
            });
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new HttpException(422, '无效的上传文件');
        }

        $size = (int) ($file['size'] ?? 0);
        $max = $expectedKind === 'video'
            ? (int) config('uploads.video_max_bytes')
            : (int) config('uploads.max_bytes');
        if ($size <= 0 || $size > $max) {
            throw new HttpException(422, '文件大小超出限制');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $allowed = (array) config('uploads.allowed', []);
        if (!isset($allowed[$mime])) {
            throw new HttpException(422, '不支持的文件类型');
        }

        $kind = self::kindFromMime($mime);
        if ($kind !== $expectedKind && !($expectedKind === 'photo' && $kind === 'photo')) {
            throw new HttpException(422, '文件类型与所属分类不符');
        }

        $extension = $allowed[$mime];
        $subdir = date('Y/m');
        $directory = self::uploadsPath($subdir);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建上传目录');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($tmp, $target)) {
            throw new \RuntimeException('保存上传文件失败');
        }
        @chmod($target, 0640);

        return [
            'disk_path' => $subdir . '/' . $filename,
            'original_name' => mb_substr(basename((string) ($file['name'] ?? 'file')), 0, 200),
            'mime' => $mime,
            'size_bytes' => $size,
            'kind' => $kind,
        ];
    }

    public static function kindFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'photo';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        return 'document';
    }

    /**
     * 身份核验材料：单独存放于 Web 根之外，仅平台管理员可下载。
     *
     * @return array{disk_path:string, original_name:string, mime:string, size_bytes:int}
     */
    public static function storeVerificationDocument(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, match ($file['error'] ?? null) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '材料文件超过服务器允许的大小',
                UPLOAD_ERR_PARTIAL => '材料上传不完整，请重试',
                default => '材料上传失败，请重新选择文件',
            });
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new HttpException(422, '无效的上传文件');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            throw new HttpException(422, '核验材料不能超过 10MB');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        if (!isset($allowed[$mime])) {
            throw new HttpException(422, '核验材料仅支持 JPG、PNG、WebP 或 PDF');
        }

        $subdir = date('Y/m');
        $directory = self::verificationPath($subdir);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建核验材料目录');
        }

        $filename = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($tmp, $target)) {
            throw new \RuntimeException('保存核验材料失败');
        }
        @chmod($target, 0640);

        return [
            'disk_path' => $subdir . '/' . $filename,
            'original_name' => mb_substr(basename((string) ($file['name'] ?? 'material')), 0, 190),
            'mime' => $mime,
            'size_bytes' => $size,
        ];
    }

    public static function verificationPath(string $relative = ''): string
    {
        $base = (string) config('storage.verification_path');
        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }

    public static function verificationAbsolute(?string $relative): ?string
    {
        if (!$relative || str_contains($relative, '..')) {
            return null;
        }
        $path = self::verificationPath($relative);
        return is_file($path) ? $path : null;
    }

    public static function deleteVerification(?string $relative): void
    {
        if (!$relative || str_contains($relative, '..')) {
            return;
        }
        $path = self::verificationPath($relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 保存服务端生成的文件（AI 修复图、视频、档案），不经过用户上传校验。
     *
     * @return array{disk_path:string, original_name:string, mime:string, size_bytes:int}
     */
    public static function storeGeneratedBytes(string $bytes, string $extension, string $mime, string $originalName): array
    {
        $subdir = 'ai/' . date('Y/m');
        $directory = self::uploadsPath($subdir);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建 AI 输出目录');
        }
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';
        $filename = 'ai-' . bin2hex(random_bytes(18)) . '.' . $extension;
        $target = $directory . '/' . $filename;
        if (file_put_contents($target, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('保存 AI 输出失败');
        }
        @chmod($target, 0640);

        return [
            'disk_path' => $subdir . '/' . $filename,
            'original_name' => mb_substr($originalName, 0, 200),
            'mime' => $mime,
            'size_bytes' => strlen($bytes),
        ];
    }

    /**
     * @return array{disk_path:string, original_name:string, mime:string, size_bytes:int}
     */
    public static function storeGeneratedFile(string $sourcePath, string $extension, string $mime, string $originalName): array
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('生成文件不存在');
        }
        $bytes = file_get_contents($sourcePath);
        if ($bytes === false) {
            throw new \RuntimeException('读取生成文件失败');
        }
        @unlink($sourcePath);
        return self::storeGeneratedBytes($bytes, $extension, $mime, $originalName);
    }

    public static function delete(?string $relative): void
    {
        if (!$relative) {
            return;
        }
        $relative = ltrim($relative, '/');
        if (str_contains($relative, '..')) {
            return;
        }
        $path = self::uploadsPath($relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function absolute(?string $relative): ?string
    {
        if (!$relative || str_contains($relative, '..')) {
            return null;
        }
        $path = self::uploadsPath($relative);
        return is_file($path) ? $path : null;
    }
}
