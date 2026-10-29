/**
 * 媒体链接工具。
 *
 * 服务端返回的 media_url 是相对路径（如 `/api/v1/media/12?exp=...&sig=...`），
 * 签名 900 秒有效，只做「当次展示」用：不要写入 storage、不要拼进分享参数。
 * 这里只负责补全 origin，不缓存、不改写签名。
 */
const config = require('../config');

function origin() {
  return String(config.BASE_URL || '').replace(/\/+$/, '');
}

/** 相对路径补全为绝对地址；已是 http(s) 地址时原样返回。 */
function absolute(url) {
  if (!url) {
    return '';
  }
  const value = String(url);
  if (/^https?:\/\//i.test(value)) {
    return value;
  }
  return origin() + (value.charAt(0) === '/' ? value : '/' + value);
}

module.exports = {
  absolute: absolute,
};
