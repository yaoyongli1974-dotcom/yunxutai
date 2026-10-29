/**
 * 登录页
 *
 * - 邮箱密码登录：调用 POST /api/v1/auth/token，成功后 token 与 user 写入 storage
 * - 微信登录：wx.login 取 code → POST /api/v1/auth/wechat；服务端未配置时按钮只提示「暂未配置」
 *
 * 登录成功后的跳转：进入纪念馆列表（可继续浏览公开馆）。
 */
const config = require('../../config');
const auth = require('../../utils/auth');

function toast(title) {
  wx.showToast({ title: title, icon: 'none', duration: 2500 });
}

/** 登录成功后进入纪念馆列表。 */
function goMemorials() {
  wx.showToast({ title: '登录成功', icon: 'none', duration: 800 });
  setTimeout(function () {
    wx.reLaunch({ url: '/pages/memorials/list' });
  }, 500);
}

Page({
  data: {
    email: 'demo@example.com',
    password: '',
    loading: false,
    wechatEnabled: config.WECHAT_LOGIN_ENABLED,
    wechatHint: config.WECHAT_LOGIN_ENABLED
      ? ''
      : '微信登录需服务端配置 WX_APPID / WX_SECRET；当前仅支持邮箱密码登录。',
  },

  onEmailInput: function (e) {
    this.setData({ email: e.detail.value });
  },

  onPasswordInput: function (e) {
    this.setData({ password: e.detail.value });
  },

  onLoginTap: function () {
    const that = this;
    const email = String(this.data.email || '').trim();
    const password = String(this.data.password || '');

    if (!email || !password) {
      toast('请填写邮箱和密码');
      return;
    }
    if (this.data.loading) {
      return;
    }

    this.setData({ loading: true });
    auth.loginDemo(email, password)
      .then(function (payload) {
        const app = getApp();
        if (app) {
          app.globalData.user = payload.user || null;
        }
        that.setData({ loading: false });
        goMemorials();
      })
      .catch(function (err) {
        toast((err && err.message) || '登录失败');
        that.setData({ loading: false });
      });
  },

  onWechatTap: function () {
    const that = this;

    if (!this.data.wechatEnabled) {
      toast('暂未配置');
      return;
    }
    if (this.data.loading) {
      return;
    }

    this.setData({ loading: true });
    auth.loginWechat()
      .then(function (payload) {
        const app = getApp();
        if (app) {
          app.globalData.user = payload.user || null;
        }
        that.setData({ loading: false });
        goMemorials();
      })
      .catch(function (err) {
        let message = (err && err.message) || '微信登录失败';
        if (err && err.code === 'wechat_not_configured') {
          message = '服务端暂未配置微信登录（503）';
        } else if (err && err.code === 'not_found') {
          message = '微信登录接口不可用（404）';
        }
        toast(message);
        that.setData({ loading: false });
      });
  },

  onGuestTap: function () {
    wx.reLaunch({ url: '/pages/memorials/list' });
  },
});
