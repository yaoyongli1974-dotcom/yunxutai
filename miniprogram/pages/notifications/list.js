const api = require('../../../utils/api');
Page({
  data: { items: [], unreadCount: 0, loading: true },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load(function () { wx.stopPullDownRefresh(); }); },
  load(done) {
    const that = this;
    api.listNotifications().then(function (res) {
      that.setData({ items: (res && res.items) || [], unreadCount: (res && res.unread_count) || 0, loading: false });
      if (done) done();
    }).catch(function (err) { that.setData({ loading: false }); wx.showToast({ title: (err && err.message) || '加载失败', icon: 'none' }); if (done) done(); });
  },
  onRead(e) {
    const that = this; const id = e.currentTarget.dataset.id;
    api.readNotification(id).then(function () { that.load(); }).catch(function (err) { wx.showToast({ title: (err && err.message) || '操作失败', icon: 'none' }); });
  },
  onReadAll() {
    const that = this;
    api.readAllNotifications().then(function () { wx.showToast({ title: '已全部标为已读', icon: 'success' }); that.load(); })
      .catch(function (err) { wx.showToast({ title: (err && err.message) || '操作失败', icon: 'none' }); });
  }
});
