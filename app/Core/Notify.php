<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 邮件通知入口。
 *
 * 设计取舍：通知不落库，失败仅由驱动层自证（默认写入 mail.log，或由 SMTP 层记录
 * error_log）；任何投递失败都不会向调用方抛出，业务请求继续成功。
 *
 * 驱动（mail.driver）：
 * - log  默认；按 RFC5322 纯文本样式追加到 mail.log_path（含 base64 正文）
 * - smtp 纯 PHP fsockopen 实现（ssl / starttls / none + AUTH LOGIN）
 * - none 停用，恒返回 false
 */
final class Notify
{
    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    /** @var array<string,mixed> */
    private static array $overrides = [];

    /**
     * 发送一封纯文本邮件。目标为空/非法、驱动停用或任何底层失败一律返回 false。
     */
    public static function send(?string $to, string $subject, string $body): bool
    {
        try {
            $to = $to !== null ? trim($to) : '';
            if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }

            $config = self::config();
            $driver = strtolower(trim((string) ($config['driver'] ?? 'log')));

            return match ($driver) {
                'log' => self::sendLog($config, $to, $subject, $body),
                'smtp' => self::sendSmtp($config, $to, $subject, $body),
                default => false,
            };
        } catch (\Throwable $e) {
            // 通知是旁路能力：记录后吞掉，绝不让调用方请求失败。
            error_log('notify failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 测试/特殊部署注入点；覆盖项与 config('mail') 合并，下次取配置时生效。
     *
     * @param array<string,mixed> $overrides
     */
    public static function configure(array $overrides): void
    {
        self::$overrides = $overrides;
        self::$config = null;
    }

    public static function reset(): void
    {
        self::$overrides = [];
        self::$config = null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function config(): array
    {
        if (self::$config === null) {
            $base = config('mail', []);
            self::$config = array_merge(is_array($base) ? $base : [], self::$overrides);
        }
        return self::$config;
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function sendLog(array $config, string $to, string $subject, string $body): bool
    {
        $path = trim((string) ($config['log_path'] ?? ''));
        if ($path === '' || !is_dir(dirname($path))) {
            return false;
        }

        $message = self::compose($config, $to, $subject, $body) . "\r\n\r\n";
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $length = strlen($message);
            $written = 0;
            while ($written < $length) {
                $bytes = fwrite($handle, substr($message, $written));
                if ($bytes === false || $bytes === 0) {
                    return false;
                }
                $written += $bytes;
            }
            fflush($handle);
            flock($handle, LOCK_UN);
            return true;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function sendSmtp(array $config, string $to, string $subject, string $body): bool
    {
        $host = trim((string) ($config['smtp_host'] ?? ''));
        if ($host === '') {
            throw new NotifyException('SMTP host 未配置');
        }

        $port = (int) ($config['smtp_port'] ?? 587);
        if ($port <= 0) {
            $port = 587;
        }
        $timeout = max(1, (int) ($config['smtp_timeout'] ?? 10));
        $encryption = strtolower(trim((string) ($config['smtp_encryption'] ?? 'starttls')));
        if (!in_array($encryption, ['ssl', 'starttls', 'none', ''], true)) {
            throw new NotifyException('不支持的 SMTP 加密方式：' . $encryption);
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host;
        $stream = @fsockopen($remote, $port, $errno, $errstr, $timeout);
        if (!is_resource($stream)) {
            throw new NotifyException('SMTP 连接失败：' . ($errstr !== '' ? $errstr : 'unknown error') . ' (' . $errno . ')');
        }
        stream_set_timeout($stream, $timeout);

        try {
            self::expect(self::read($stream), [220], 'SMTP 握手');

            $identity = gethostname();
            if (!is_string($identity) || $identity === '') {
                $identity = 'localhost';
            }
            self::command($stream, 'EHLO ' . $identity, [250], 'EHLO');

            if ($encryption === 'starttls') {
                self::command($stream, 'STARTTLS', [220], 'STARTTLS');
                if (!@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new NotifyException('STARTTLS 加密协商失败');
                }
                self::command($stream, 'EHLO ' . $identity, [250], 'EHLO(TLS)');
            }

            $username = (string) ($config['smtp_username'] ?? '');
            if ($username !== '') {
                $password = (string) ($config['smtp_password'] ?? '');
                self::command($stream, 'AUTH LOGIN', [334], 'AUTH LOGIN');
                self::command($stream, base64_encode($username), [334], 'AUTH username');
                self::command($stream, base64_encode($password), [235], 'AUTH password');
            }

            $from = self::fromAddress($config);
            self::command($stream, 'MAIL FROM:<' . $from . '>', [250], 'MAIL FROM');
            self::command($stream, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
            self::command($stream, 'DATA', [354], 'DATA');

            $data = self::compose($config, $to, $subject, $body);
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            self::writeLine($stream, $data . "\r\n.");
            self::expect(self::read($stream), [250], '邮件正文');

            // QUIT 为尽力而为，失败不影响已受理的投递结果。
            try {
                self::writeLine($stream, 'QUIT');
                self::read($stream);
            } catch (\Throwable $e) {
                // 已收到 250，忽略收尾异常。
            }

            return true;
        } finally {
            fclose($stream);
        }
    }

    /**
     * 生成 RFC5322 样式纯文本邮件：头 + 分块 base64 正文。
     *
     * @param array<string,mixed> $config
     */
    private static function compose(array $config, string $to, string $subject, string $body): string
    {
        $fromAddress = self::fromAddress($config);
        $fromName = self::headerText((string) ($config['from_name'] ?? ''));

        $from = $fromName !== ''
            ? mb_encode_mimeheader($fromName, 'UTF-8', 'B') . ' <' . $fromAddress . '>'
            : $fromAddress;
        $domain = str_contains($fromAddress, '@') ? substr(strrchr($fromAddress, '@') ?: '', 1) : 'localhost';
        if ($domain === '') {
            $domain = 'localhost';
        }

        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . sha1(random_bytes(24) . microtime(true)) . '@' . $domain . '>',
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . mb_encode_mimeheader(self::headerText($subject), 'UTF-8', 'B'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: base64',
        ];

        $encoded = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));

        return implode("\r\n", $headers) . "\r\n\r\n" . $encoded;
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function fromAddress(array $config): string
    {
        $address = trim((string) ($config['from_address'] ?? ''));
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return 'no-reply@example.com';
        }
        return $address;
    }

    private static function headerText(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }

    /**
     * @param resource $stream
     */
    private static function command($stream, string $command, array $codes, string $stage): void
    {
        self::writeLine($stream, $command);
        self::expect(self::read($stream), $codes, $stage);
    }

    /**
     * @param resource $stream
     */
    private static function writeLine($stream, string $line): void
    {
        $line = str_replace(["\r\n", "\r"], "\n", $line);
        $line = str_replace("\n", "\r\n", $line);
        if (@fwrite($stream, $line . "\r\n") === false) {
            throw new NotifyException('SMTP 写入失败');
        }
    }

    /**
     * @param resource $stream
     */
    private static function read($stream): string
    {
        $response = '';
        while (($line = fgets($stream, 2048)) !== false) {
            $response .= $line;
            // 多行响应以 "250-" 续行，最后一行 "250 " 结束。
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        $meta = stream_get_meta_data($stream);
        if (!empty($meta['timed_out'])) {
            throw new NotifyException('SMTP 读取超时');
        }
        if ($response === '') {
            throw new NotifyException('SMTP 连接已关闭');
        }

        return $response;
    }

    /**
     * @param list<int> $codes
     */
    private static function expect(string $response, array $codes, string $stage): void
    {
        $code = (int) substr(ltrim($response), 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new NotifyException($stage . ' 失败：' . trim($response));
        }
    }
}
