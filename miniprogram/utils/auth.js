/**
 * 登录态管理。
 *
 * - loginDemo(email, password)：邮箱密码换 token（demo/测试环境可用）
 * - loginWechat()：wx.login → code → POST /auth/wechat，需服务端配置 WX_APPID/WX_SECRET
 * - ensureSession()：有 token 就 GET /me 校验；401 清除本地会话，开启微信登录后尝试静默重登
 * - logout()：POST /auth/logout 并清空本地 storage（接口失败也会清本地）
 *
 * 约束：客户端只持有 token，绝不保存 AppSecret；code2session 必须由服务端完成。
 */
const config = require('../config');
const request = require('./request');
const api = require('./api');

function saveUser(user) {
  if (user) {
    wx.setStorageSync(config.USER_KEY, user);
  } else {
    wx.removeStorageSync(config.USER_KEY);
  }
}

function saveSession(payload) {
  const data = payload || {};
  if (!data.token) {
    throw { code: 'invalid_response', message: '登录响应缺少 token' };
  }
  request.setToken(data.token);
  saveUser(data.user || null);
  if (data.expires_at) {
    wx.setStorageSync('jidian_token_expires_at', data.expires_at);
  }
  return data;
}

function clearSession() {
  request.clearToken();
  wx.removeStorageSync(config.USER_KEY);
  wx.removeStorageSync('jidian_token_expires_at');
}

function isLoggedIn() {
  return !!request.getToken();
}

function getCachedUser() {
  return wx.getStorageSync(config.USER_KEY) || null;
}

/** wx.login 的 Promise 包装。 */
function wxLogin() {
  return new Promise(function (resolve, reject) {
    wx.login({
      success: function (res) {
        if (res && res.code) {
          resolve(res.code);
        } else {
          reject({ code: 'wechat_login_failed', message: '微信登录失败，请重试' });
        }
      },
      fail: function () {
        reject({ code: 'wechat_login_failed', message: '微信登录失败，请重试' });
      },
    });
  });
}

/** 邮箱密码登录，成功后写入 token 与 user。 */
function loginDemo(email, password) {
  return api.login(email, password).then(function (payload) {
    return saveSession(payload);
  });
}

/** 微信一键登录。404（接口未部署）/503（未配置）原样透出错误码。 */
function loginWechat() {
  return wxLogin()
    .then(function (code) {
      return api.wechatLogin(code);
    })
    .then(function (payload) {
      return saveSession(payload);
    })
    .catch(function (err) {
      if (err && err.code === 'not_found') {
        throw { code: 'not_found', message: '微信登录接口不可用（404），请确认 API 已部署' };
      }
      throw err;
    });
}

/**
 * 校验/续期登录态，返回 GET /me 的 data（{ user, memberships }）。
 * demo 环境（WECHAT_LOGIN_ENABLED=false）在 401 后直接 reject，由页面引导手动登录。
 */
function ensureSession() {
  const token = request.getToken();

  if (!token) {
    if (config.WECHAT_LOGIN_ENABLED) {
      return loginWechat();
    }
    return Promise.reject({ code: 'unauthorized', message: '请先登录' });
  }

  return api.me()
    .then(function (data) {
      if (data && data.user) {
        saveUser(data.user);
      }
      return data;
    })
    .catch(function (err) {
      if (!err || err.code !== 'unauthorized') {
        throw err;
      }
      // token 已失效：request 层已清 token，这里补齐 user 缓存并尝试静默续登。
      clearSession();
      if (!config.WECHAT_LOGIN_ENABLED) {
        throw err;
      }
      return loginWechat().catch(function () {
        throw err;
      });
    });
}

/** 注销：无论服务端是否成功，都清空本地会话。 */
function logout() {
  const finish = function () {
    clearSession();
    return { ok: true };
  };

  if (!request.getToken()) {
    return Promise.resolve(finish());
  }

  return api.logout().then(finish, finish);
}

module.exports = {
  loginDemo: loginDemo,
  loginWechat: loginWechat,
  ensureSession: ensureSession,
  logout: logout,
  isLoggedIn: isLoggedIn,
  getCachedUser: getCachedUser,
  clearSession: clearSession,
};
