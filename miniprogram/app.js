const request = require('./utils/request');
const auth = require('./utils/auth');

App({
  globalData: {
    user: null,
  },

  onLaunch: function () {
    this.globalData.user = auth.getCachedUser();

    // 任意接口返回 401：清理 token 已由 request 层完成，这里负责跳回登录页。
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
      wx.showToast({ title: '登录已过期，请重新登录', icon: 'none' });
      wx.reLaunch({ url: '/pages/login/login' });
    });
  },
});
