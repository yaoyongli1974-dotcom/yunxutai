const request = require('./utils/request');
const auth = require('./utils/auth');
const config = require('./config');

App({
  globalData: {
    user: null,
  },

  onLaunch: function () {
    this.globalData.user = auth.getCachedUser();

    // 任意接口返回 401：清理 token 已由 request 层完成，这里负责续期或跳回登录页。
    let recovering = false;
    request.onUnauthorized(function () {
      const app = getApp();
      if (app) {
        app.globalData.user = null;
      }
      const pages = getCurrentPages();
      const current = pages.length ? pages[pages.length - 1].route : '';
      if (current === 'pages/login/login') {
        return;
      }

      const redirectToLogin = function () {
        wx.showToast({ title: '登录已过期，请重新登录', icon: 'none' });
        wx.reLaunch({ url: '/pages/login/login' });
      };

      // 仅开启微信登录时尝试静默重登；并发 401 共用一次尝试，避免重复 wx.login。
      // 重登失败仍走原有提示 + reLaunch；重登成功只更新用户缓存，失败的原请求由页面重新拉取。
      if (!config.WECHAT_LOGIN_ENABLED || recovering) {
        if (!recovering) {
          redirectToLogin();
        }
        return;
      }
      recovering = true;
      auth.ensureSession().then(function (data) {
        recovering = false;
        if (app) {
          app.globalData.user = (data && data.user) || auth.getCachedUser();
        }
      }).catch(function () {
        recovering = false;
        redirectToLogin();
      });
    });
  },
});
