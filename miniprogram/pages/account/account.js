/**
 * 个人中心
 *
 * - 展示 /me 的用户信息与成员馆；退出登录走 auth.logout（服务端撤销 token + 清本地）。
 */
const api = require('../../utils/api');
const auth = require('../../utils/auth');

const ROLE_LABEL = {
  owner: '馆主',
  editor: '协作者',
  viewer: '亲友',
};

Page({
  data: {
    user: null,
    membershipText: '',
    loading: true,
    error: '',
  },

  onShow: function () {
    if (!auth.isLoggedIn()) {
      wx.redirectTo({ url: '/pages/login/login' });
      return;
    }
    this.load();
  },

  load: function () {
    const that = this;
    this.setData({ loading: true, error: '' });
    api.me()
      .then(function (res) {
        const user = (res && res.user) || null;
        const memberships = (res && res.memberships) || [];
        const labels = memberships.map(function (item) {
          const label = ROLE_LABEL[item.role] || '成员';
          const name = item.title || item.name || item.slug || '';
          return name ? name + '（' + label + '）' : label;
        });
        that.setData({
          loading: false,
          user: user,
          membershipText: labels.join('、'),
        });
      })
      .catch(function (err) {
        that.setData({ loading: false, error: (err && err.message) || '加载失败' });
      });
  },

  onLogoutTap: function () {
    const that = this;
    wx.showModal({
      title: '退出登录',
      content: '退出后将无法使用馆主管理功能，确定退出？',
      confirmText: '退出',
      confirmColor: '#3f5a4c',
      success: function (res) {
        if (!res.confirm) {
          return;
        }
        auth.logout().then(function () {
          wx.showToast({ title: '已退出登录', icon: 'none', duration: 1500 });
          setTimeout(function () {
            wx.reLaunch({ url: '/pages/memorials/list' });
          }, 900);
        });
      },
    });
  },
});
