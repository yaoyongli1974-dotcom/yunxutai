/**
 * 统一请求封装：Promise 化 wx.request。
 *
 * - 自动附带 Authorization: Bearer <token>（可用 { auth: false } 关闭，如登录接口）
 * - 成功（HTTP 2xx）resolve 响应包络中的 `data` 字段
 * - 失败 reject { code, message }，code 取服务端 error.code，缺省按 HTTP 状态码映射
 * - 404 统一按「无权限访问或内容不存在」提示（私密/暂停纪念馆对外一律 404）
 * - 401 清除本地 token 并触发全局重新登录回调（见 utils/auth.js 与 app.js）
 * - 超时 10s；非 GET 自动带 Content-Type: application/json
 */
const config = require('../config');

const TOKEN_KEY = config.TOKEN_KEY;

const STATUS_CODE_MAP = {
  401: 'unauthorized',
  403: 'forbidden',
  404: 'not_found',
  422: 'validation_failed',
  429: 'rate_limited',
  500: 'internal_error',
  503: 'wechat_not_configured',
};

const DEFAULT_MESSAGE = {
  401: '登录已过期，请重新登录',
  403: '没有权限执行该操作',
  404: '无权限访问或内容不存在',
  422: '提交的内容未通过校验',
  429: '操作过于频繁，请稍后再试',
  500: '服务暂时不可用，请稍后再试',
  503: '服务尚未开通该能力',
};

let unauthorizedHandler = null;

/** 注册 401 全局回调（只保留一个，app.js 启动时注册）。 */
function onUnauthorized(handler) {
  unauthorizedHandler = typeof handler === 'function' ? handler : null;
}

function triggerUnauthorized(error) {
  if (!unauthorizedHandler) {
    return;
  }
  try {
    unauthorizedHandler(error);
  } catch (err) {
    console.error('[request] unauthorized handler failed', err);
  }
}

function getToken() {
  return wx.getStorageSync(TOKEN_KEY) || '';
}

function setToken(token) {
  if (token) {
    wx.setStorageSync(TOKEN_KEY, token);
  } else {
    wx.removeStorageSync(TOKEN_KEY);
  }
}

function clearToken() {
  wx.removeStorageSync(TOKEN_KEY);
}

/** 拼接 BASE_URL + /api/v1 + path，避免出现重复或缺失的斜杠。 */
function buildUrl(path) {
  const origin = String(config.BASE_URL || '').replace(/\/+$/, '');
  const prefix = String(config.API_PREFIX || '').replace(/\/+$/, '');
  let suffix = String(path || '');
  if (suffix.charAt(0) !== '/') {
    suffix = '/' + suffix;
  }
  return origin + prefix + suffix;
}

/** 把 wx.request 的失败/非 2xx 响应归一成 { code, message }。 */
function toError(statusCode, body) {
  const payload = body && typeof body === 'object' && body.error ? body.error : {};
  let code = payload.code || STATUS_CODE_MAP[statusCode] || 'http_' + statusCode;
  let message = payload.message || DEFAULT_MESSAGE[statusCode] || '请求失败（HTTP ' + statusCode + '）';

  if (statusCode === 404) {
    // 私密/暂停纪念馆、未发布内容、无权限资源在服务端一律 404：客户端不再区分原因。
    code = 'not_found';
    message = DEFAULT_MESSAGE[404];
  }

  return {
    code: code,
    message: message,
    statusCode: statusCode,
    serverMessage: payload.message || '',
  };
}

/**
 * 发起请求。
 * @param {string|object} options 路径字符串，或 { path, method, data, auth, header, timeout }
 * @returns {Promise<any>} 响应包络中的 data
 */
function request(options) {
  const opts = typeof options === 'string' ? { path: options } : (options || {});
  const method = String(opts.method || 'GET').toUpperCase();
  const header = Object.assign({}, opts.header || {});
  const token = getToken();

  if (method !== 'GET') {
    header['Content-Type'] = 'application/json';
  }
  if (opts.auth !== false && token) {
    header.Authorization = 'Bearer ' + token;
  }

  return new Promise(function (resolve, reject) {
    wx.request({
      url: buildUrl(opts.path),
      method: method,
      data: opts.data === undefined ? undefined : opts.data,
      header: header,
      timeout: opts.timeout || config.REQUEST_TIMEOUT,
      success: function (res) {
        const statusCode = res.statusCode;
        const body = res.data;

        if (statusCode >= 200 && statusCode < 300) {
          resolve(body && typeof body === 'object' && Object.prototype.hasOwnProperty.call(body, 'data')
            ? body.data
            : body);
          return;
        }

        const error = toError(statusCode, body);
        if (statusCode === 401) {
          clearToken();
          triggerUnauthorized(error);
        }
        reject(error);
      },
      fail: function (err) {
        reject({
          code: 'network_error',
          message: '网络连接失败，请检查网络后重试',
          statusCode: 0,
          serverMessage: err && err.errMsg ? err.errMsg : '',
        });
      },
    });
  });
}

function get(path, data) {
  return request({ path: path, method: 'GET', data: data });
}

function post(path, data, options) {
  return request(Object.assign({ path: path, method: 'POST', data: data }, options || {}));
}

module.exports = {
  request: request,
  get: get,
  post: post,
  getToken: getToken,
  setToken: setToken,
  clearToken: clearToken,
  onUnauthorized: onUnauthorized,
  toError: toError,
  buildUrl: buildUrl,
};
