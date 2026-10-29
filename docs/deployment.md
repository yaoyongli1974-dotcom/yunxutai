# 部署与运维

## 1. 运行环境

- PHP 8.4+（推荐 8.5），扩展：`pdo_mysql`、`mbstring`、`fileinfo`、`gd`、`openssl`、`json`、`session`。
- MySQL 8.0+ / 8.4 LTS 或 MariaDB 11+。
- Nginx + PHP-FPM（推荐）或 Apache + `mod_rewrite`。
- 可选：Redis（多实例会话/限流）、对象存储（媒体）、ffmpeg/ffprobe（AI 纪念短片）、OpenAI 或兼容 API（AI 文本/图像）。

## 2. 发布步骤

```bash
# 代码放到 /srv/jidian，Web 根目录指向 /srv/jidian/public
cd /srv/jidian
cp .env.example .env
# 编辑 .env：
#   APP_ENV=production
#   APP_DEBUG=false
#   APP_URL=https://memorial.example.com
#   APP_KEY=<64 位随机十六进制>
#   DB_*=<独立数据库账号>
php bin/install.php            # 首次建表与管理员
php bin/install.php --demo     # 仅演示/验收环境
```

已有数据库升级到「身份核验」版本（幂等，可重复执行）：

```bash
php bin/migrate.php
```

迁移默认把历史公开/不公开纪念馆回退私密，馆主补充材料并核验后才能重新公开；如已逐馆人工确认历史数据可信，可显式加 `--grandfather` 标记为已核验（仍建议抽查）。全新安装已包含新表与字段，无需执行迁移。

目录权限（以 `www-data` 为例）：

```bash
chown -R www-data:www-data storage
find storage -type d -exec chmod 750 {} \;
find storage -type f -exec chmod 640 {} \;
chmod 750 bin
```

`storage/` 必须位于 Web 根目录之外；只有 `public/` 对外暴露。`storage/verifications` 存放死亡证明/亲属关系材料，属于敏感个人信息：仅 Web 进程与授权运维可读，禁止进入对象存储公开桶或 CDN。

## 3. Nginx 样例

见 [deploy/nginx.conf](../deploy/nginx.conf)。关键点：

- `root .../public`，`try_files $uri /index.php?$query_string`。
- 拒绝 `.env`、`app/`、`config/`、`storage/`、`database/` 等路径。
- `client_max_body_size 52m`（视频上限 50MB + 表单开销）。
- 静态资源长缓存；HTML 不缓存或短缓存。
- HTTPS + HSTS；仅 TLS 1.2/1.3。

## 4. PHP 配置

见 [deploy/php.ini](../deploy/php.ini)。至少确认：

```ini
upload_max_filesize = 52M
post_max_size = 56M
max_file_uploads = 10
expose_php = Off
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
```

应用自身也会设置安全响应头与 session 参数；PHP-FPM 层再做一次兜底。

## 4.1 AI 工作室

```dotenv
AI_ENABLED=true
AI_PROVIDER=demo              # demo | openai
AI_BASE_URL=https://api.openai.com/v1
AI_API_KEY=
AI_TEXT_MODEL=gpt-4.1-mini
AI_IMAGE_MODEL=gpt-image-1
AI_TIMEOUT=120
AI_DAILY_LIMIT=10
AI_SYNC=false                 # 生产建议 false，交给 worker
FFMPEG_BIN=/usr/bin/ffmpeg
```

- `demo` 不需要外部服务：照片用 GD 增强，文章/分镜用模板，短片用 ffmpeg 合成。
- `openai` 会将用户上传的照片/生平文本发送到配置的 API；上线前需在隐私政策披露第三方处理、数据出境与撤回方式，并禁止发送核验材料/身份证件。
- 异步队列：

```bash
# 每 1–5 分钟消费队列；失败任务保留错误原因，可在后台重试
php bin/ai-worker.php --limit=3
```

- 依赖 `ffmpeg`/`ffprobe`；`FFMPEG_BIN` 必须指向可执行文件。无 ffmpeg 时短片任务会失败并提示。
- AI 输出默认仅成员可见；文章为草稿。运营需在 `/admin/ai-services` 确认占位价与说明后再对外报价。
- 成本控制：`AI_DAILY_LIMIT` 限制每人每馆每日任务数；生产应增加预算告警、按服务限额与供应商用量对账。

## 5. 数据库

- 独立账号，仅授予目标库 `SELECT, INSERT, UPDATE, DELETE`；安装时临时需要 `CREATE`。
- 开启慢查询与 binlog（按云厂商能力）；生产禁用 `root` 空密码。
- 备份使用 `--single-transaction`，与上传目录一起归档。
- 字符集固定 `utf8mb4` / `utf8mb4_unicode_ci`。

## 6. 备份与恢复

```bash
# 每天 03:20 执行；默认保留 14 份
20 3 * * * cd /srv/jidian && php bin/backup.php >> storage/logs/backup.log 2>&1
```

产物：`storage/backups/YYYYmmdd-HHMMSS/database.sql.gz`、`uploads.tar.gz`、`verifications.tar.gz` 与 `manifest.json`。

`verifications.tar.gz` 含身份证件/死亡证明等敏感材料，必须使用独立密钥加密后异地保存，访问需审批与审计。恢复时先恢复数据库，再解包上传与核验材料；核验材料的目录权限应保持 750/640。

恢复演练（至少每季度）：

```bash
# 1. 新建空库并导入
gunzip -c database.sql.gz | mysql -u user -p dbname
# 2. 解包上传目录与核验材料目录
tar xzf uploads.tar.gz -C storage
tar xzf verifications.tar.gz -C storage
# 3. 校验：登录、纪念馆页、媒体鉴权、审核队列、核验材料下载
```

备份应加密并异地保存；包含个人信息与逝者资料，访问需授权与审计。

## 7. 日志与监控

- 应用错误：PHP-FPM `error_log`；应用审计：`audit_logs`。
- 监控：5xx 比例、登录失败突增、上传失败、队列积压（举报/数据请求）、磁盘使用率。
- 告警接收人需具备处置个人信息事件的能力。

## 8. 多实例与扩展

当前 MVP 默认单机：

- Session 存本机；多实例需改为 Redis 或数据库 session。
- `RateLimiter` 存 MySQL，多实例可共享；高并发建议 Redis。
- 媒体存本地磁盘；多实例需共享存储或对象存储。替换 `App\Core\Storage` 并保持 `MediaController` 鉴权逻辑。
- 定时任务目前只有备份；数据留存清理、通知重试需接入队列/计划任务。

## 9. 上线检查表

- [ ] `APP_DEBUG=false`，错误不显示堆栈。
- [ ] `APP_KEY` 随机且未提交到版本库。
- [ ] HTTPS 全站，HTTP 跳转，HSTS。
- [ ] Web 根目录仅 `public/`；`storage/` 不可 URL 直达。
- [ ] 数据库最小权限、强密码、仅内网访问。
- [ ] 上传限制与 MIME 校验生效（尝试上传 `.php` 应被拒绝）。
- [ ] 身份核验队列有人值守；新馆默认私密；未核验馆不出现在列表/sitemap。
- [ ] `storage/verifications` 不可 URL 直达，备份加密且访问受控。
- [ ] 管理员使用强密码；上线后立即修改安装密码。
- [ ] 隐私政策、服务条款、举报入口、数据权利入口可访问。
- [ ] 备份任务与恢复演练完成。
- [ ] 审核/举报/申领/数据请求有明确负责人和 SLA。
- [ ] 停止运营预案：公告期、数据导出、删除与第三方（对象存储/短信）清理。
