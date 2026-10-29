/**
 * /api/v1 方法映射：与 docs/api.md 一一对应。
 * 所有方法返回 Promise，成功时是响应包络中的 data，失败时是 { code, message }。
 */
const request = require('./request');

const BASE = '/api/v1';

/** 把 { page: 1, per_page: 20 } 拼成 query string，自动跳过空值。 */
function query(params) {
  const pairs = [];
  Object.keys(params || {}).forEach(function (key) {
    const value = params[key];
    if (value === undefined || value === null || value === '') {
      return;
    }
    pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
  });
  return pairs.length ? '?' + pairs.join('&') : '';
}

function slugPath(slug) {
  return encodeURIComponent(String(slug || ''));
}

/* ------------------------------------------------------------------ */
/* 鉴权                                                                */
/* ------------------------------------------------------------------ */

/** POST /auth/token —— 邮箱密码登录（demo/测试与邮箱密码模式）。 */
function login(email, password) {
  return request.post(BASE + '/auth/token', { email: email, password: password }, { auth: false });
}

/** POST /auth/wechat —— 微信 code 换 token（服务端未配置 WX_APPID/WX_SECRET 时 503）。 */
function wechatLogin(code) {
  return request.post(BASE + '/auth/wechat', { code: code }, { auth: false });
}

/** POST /auth/logout —— 注销当前 token。 */
function logout() {
  return request.post(BASE + '/auth/logout');
}

/** GET /me —— 当前用户 + 成员身份列表。 */
function me() {
  return request.get(BASE + '/me');
}

/* ------------------------------------------------------------------ */
/* 纪念馆（访客/成员）                                                  */
/* ------------------------------------------------------------------ */

/** GET /memorials?page=&per_page= —— 公开纪念馆列表。 */
function listMemorials(params) {
  return request.get(BASE + '/memorials' + query(params));
}

/** GET /memorials/{slug} —— 纪念馆详情：summary + profile + life + articles + media（含签名 media_url）。 */
function getMemorial(slug) {
  return request.get(BASE + '/memorials/' + slugPath(slug));
}

/** GET /memorials/{slug}/articles/{id} —— 单篇文章。 */
function getArticle(slug, id) {
  return request.get(BASE + '/memorials/' + slugPath(slug) + '/articles/' + encodeURIComponent(String(id)));
}

/** POST /memorials/{slug}/tribute —— 献花/点烛/上香/鞠躬，可带附言与匿名。 */
function createTribute(slug, payload) {
  const body = payload || {};
  return request.post(BASE + '/memorials/' + slugPath(slug) + '/tribute', {
    type: body.type,
    appendix: body.appendix === undefined ? '' : body.appendix,
    anonymous: body.anonymous === true,
  });
}

/** GET /memorials/{slug}/messages —— 留言簿列表（分页）。 */
function listMessages(slug, params) {
  return request.get(BASE + '/memorials/' + slugPath(slug) + '/messages' + query(params));
}

/** POST /memorials/{slug}/message —— 发表留言，content 2–1000 字（待审时返回 202）。 */
function createMessage(slug, content) {
  return request.post(BASE + '/memorials/' + slugPath(slug) + '/message', { content: content });
}

/** POST /memorials/{slug}/claim —— 申领纪念馆，提交关系与理由。 */
function claimMemorial(slug, payload) {
  const body = payload || {};
  return request.post(BASE + '/memorials/' + slugPath(slug) + '/claim', {
    relationship: body.relationship,
    reason: body.reason,
  });
}

/* ------------------------------------------------------------------ */
/* 馆内管理                                                             */
/* ------------------------------------------------------------------ */

/** GET /manage/memorials —— 我创建或参与管理的纪念馆。 */
function listManagedMemorials(params) {
  return request.get(BASE + '/manage/memorials' + query(params));
}

/**
 * POST /manage/memorials —— 创建纪念馆（新馆固定为私密草稿，需身份核验后才可公开）。
 * payload 字段与 web 建馆表单一致：full_name、gender、title、summary、slug、
 * birth_date、death_date、birth_place、death_place、bio、declaration。
 */
function createMemorial(payload) {
  return request.post(BASE + '/manage/memorials', payload || {});
}

module.exports = {
  // auth
  login: login,
  wechatLogin: wechatLogin,
  logout: logout,
  me: me,
  // memorials
  listMemorials: listMemorials,
  getMemorial: getMemorial,
  getArticle: getArticle,
  createTribute: createTribute,
  listMessages: listMessages,
  createMessage: createMessage,
  claimMemorial: claimMemorial,
  // manage
  listManagedMemorials: listManagedMemorials,
  createMemorial: createMemorial,
};
