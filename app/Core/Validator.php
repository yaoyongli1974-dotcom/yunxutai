<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels
    ) {
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $validator = new self($data, $rules, $labels);
        $validator->run();
        return $validator;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $rules) {
            $label = $this->labels[$field] ?? $field;
            $raw = $this->data[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            $isEmpty = $value === null || $value === '' || $value === [];

            foreach (explode('|', $rules) as $rule) {
                if ($rule === '') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name === 'required') {
                    if ($isEmpty) {
                        $this->add($field, $label . '不能为空');
                        break;
                    }
                    continue;
                }

                if ($isEmpty) {
                    continue;
                }

                switch ($name) {
                    case 'email':
                        if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                            $this->add($field, $label . '格式不正确');
                        }
                        break;
                    case 'min':
                        if (mb_strlen((string) $value) < (int) $arg) {
                            $this->add($field, sprintf('%s至少需要 %d 个字符', $label, (int) $arg));
                        }
                        break;
                    case 'max':
                        if (mb_strlen((string) $value) > (int) $arg) {
                            $this->add($field, sprintf('%s不能超过 %d 个字符', $label, (int) $arg));
                        }
                        break;
                    case 'in':
                        if (!in_array((string) $value, explode(',', (string) $arg), true)) {
                            $this->add($field, $label . '不在允许范围内');
                        }
                        break;
                    case 'integer':
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $this->add($field, $label . '必须为整数');
                        }
                        break;
                    case 'date':
                        if (!self::isDate((string) $value)) {
                            $this->add($field, $label . '日期格式不正确');
                        }
                        break;
                    case 'confirmed':
                        if ((string) ($this->data[$field . '_confirmation'] ?? '') !== (string) $value) {
                            $this->add($field, $label . '两次输入不一致');
                        }
                        break;
                    case 'regex':
                        if (@preg_match((string) $arg, (string) $value) !== 1) {
                            $this->add($field, $label . '格式不正确');
                        }
                        break;
                    case 'url':
                        if (!filter_var((string) $value, FILTER_VALIDATE_URL)) {
                            $this->add($field, $label . '链接格式不正确');
                        }
                        break;
                }
            }
        }
    }

    public static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public static function passwordProblem(string $password): ?string
    {
        $min = (int) config('security.password_min', 10);
        if (mb_strlen($password) < $min) {
            return sprintf('密码至少需要 %d 个字符', $min);
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return '密码需同时包含字母和数字';
        }
        if (mb_strlen($password) > 72) {
            return '密码不能超过 72 个字符';
        }
        return null;
    }

    private function add(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
