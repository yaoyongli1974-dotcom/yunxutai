/**
 * 云叙台小程序 · 全局配置（开发参考）
 *
 * BASE_URL 只填 origin（协议 + 域名 + 端口），不要带 /api/v1，请求层会自动拼接。
 * 本地开发默认指向 `php -S 0.0.0.0:8020 -t public`：
 *   - 微信开发者工具与 PHP 在同一台机器时，可改回 http://127.0.0.1:8020；
 *   - 开发者工具运行在局域网另一台机器（如 Windows）时，保持本机 LAN IP 192.168.0.199:8020。
 * 微信开发者工具需勾选「详情 → 本地设置 → 不校验合法域名、web-view（业务域名）、TLS 版本以及 HTTPS 证书」。
 * 真机/上线必须改为已在微信公众平台「开发管理 → 服务器域名」备案的 HTTPS 域名。
 */
const BASE_URL = 'https://yunxutai.cn';

/** API 版本前缀，与 app/routes.php 的 /api/v1 保持一致。 */
const API_PREFIX = '/api/v1';

/** storage key：登录 token 与缓存的用户信息。 */
const TOKEN_KEY = 'jidian_token';
const USER_KEY = 'jidian_user';

/** 请求超时（毫秒）。 */
const REQUEST_TIMEOUT = 10000;

/**
 * 微信登录开关：
 * 服务端已配置 WX_APPID / WX_SECRET 并通过 code2session 连通验证（appid/secret 有效）后置为 true。
 * 关闭时登录页的「微信登录」只提示「暂未配置」，ensureSession 在 401 后直接失败（demo 环境行为）。
 */
const WECHAT_LOGIN_ENABLED = true;

module.exports = {
  BASE_URL: BASE_URL,
  API_PREFIX: API_PREFIX,
  TOKEN_KEY: TOKEN_KEY,
  USER_KEY: USER_KEY,
  REQUEST_TIMEOUT: REQUEST_TIMEOUT,
  WECHAT_LOGIN_ENABLED: WECHAT_LOGIN_ENABLED,
};
