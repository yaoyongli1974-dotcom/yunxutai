/**
 * 接受家族邀请：POST /invitations/accept { token }。
 * 支持从 ?token= 进入，也支持手动粘贴邀请令牌；成功后回到家族列表。
 */
const api = require('../../utils/api');

Page({
  data: {
    token: '',
    submitting: false,
    error: '',
  },

  onLoad: function (q) {
    const token = (q && q.token) ? String(q.token) : '';
    this.setData({ token: token });
  },

  onToken: function (e) {
    this.setData({ token: e.detail.value, error: '' });
  },

  onBack: function () {
    wx.reLaunch({ url: '/pages/families/list' });
  },

  onSubmit: function () {
    const that = this;
    const token = String(this.data.token || '').trim();
    if (this.data.submitting) {
      return;
    }
    if (!token) {
      wx.showToast({ title: '请填写邀请令牌', icon: 'none' });
      return;
    }
    this.setData({ submitting: true, error: '' });
    api.acceptInvitation(token).then(function () {
      wx.showModal({
        title: '接受成功',
        content: '已加入该家族，返回家族列表即可查看。',
        showCancel: false,
        success: function () {
          wx.reLaunch({ url: '/pages/families/list' });
        },
      });
    }).catch(function (err) {
      const message = (err && err.message) || '接受失败，请检查邀请令牌';
      that.setData({ error: message });
      wx.showToast({ title: message, icon: 'none' });
    }).then(function () {
      that.setData({ submitting: false });
    });
  },
});
