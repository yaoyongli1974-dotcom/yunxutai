/**
 * /api/v1 方法映射：与 docs/api.md 一一对应。
 * 所有方法返回 Promise，成功时是响应包络中的 data，失败时是 { code, message }。
 */
const request = require('./request');

// request.js 的 buildUrl 已拼接 config.API_PREFIX（/api/v1），这里只写资源路径，
// 避免出现 /api/v1/api/v1/... 的重复前缀（既有方法同样受益，端点语义不变）。
const BASE = '';

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

function idPath(id) {
  return encodeURIComponent(String(id === undefined || id === null ? '' : id));
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

/**
 * POST /memorials/{slug}/message —— 发表留言（契约字段 body，可匿名）。
 * 与 createMessage 的差异：使用 docs/api.md 的 `body` 字段并支持 anonymous；
 * 服务端两种字段都接受，待审时仍返回 202 + status=pending。
 */
function sendMessage(slug, payload) {
  const body = payload || {};
  return request.post(BASE + '/memorials/' + slugPath(slug) + '/message', {
    body: body.body === undefined ? '' : body.body,
    anonymous: body.anonymous === true,
  });
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

/* ------------------------------------------------------------------ */
/* 成员与邀请（仅馆主）                                                  */
/* ------------------------------------------------------------------ */

/** GET /manage/memorials/{id}/members —— 成员列表 + 待接受邀请。 */
function listMembers(memorialId) {
  return request.get(BASE + '/manage/memorials/' + idPath(memorialId) + '/members');
}

/** POST /manage/memorials/{id}/members/invite —— 邀请成员，email 可为空（链接邀请）。 */
function inviteMember(memorialId, payload) {
  const body = payload || {};
  return request.post(BASE + '/manage/memorials/' + idPath(memorialId) + '/members/invite', {
    email: body.email === undefined || body.email === null ? '' : String(body.email),
    role: body.role || 'viewer',
  });
}

/** POST /manage/memorials/{id}/members/{memberId}/role —— 修改成员角色（editor/viewer）。 */
function updateMemberRole(memorialId, memberId, role) {
  return request.post(
    BASE + '/manage/memorials/' + idPath(memorialId) + '/members/' + idPath(memberId) + '/role',
    { role: role }
  );
}

/** POST /manage/memorials/{id}/members/{memberId}/remove —— 移除成员。 */
function removeMember(memorialId, memberId) {
  return request.post(
    BASE + '/manage/memorials/' + idPath(memorialId) + '/members/' + idPath(memberId) + '/remove',
    {}
  );
}

/** POST /manage/memorials/{id}/invitations/{invitationId}/revoke —— 作废邀请链接。 */
function revokeInvitation(memorialId, invitationId) {
  return request.post(
    BASE + '/manage/memorials/' + idPath(memorialId) + '/invitations/' + idPath(invitationId) + '/revoke',
    {}
  );
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
  sendMessage: sendMessage,
  claimMemorial: claimMemorial,
  // manage
  listManagedMemorials: listManagedMemorials,
  createMemorial: createMemorial,
  listMembers: listMembers,
  inviteMember: inviteMember,
  updateMemberRole: updateMemberRole,
  removeMember: removeMember,
  revokeInvitation: revokeInvitation,
};
