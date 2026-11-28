/**
 * 家族展馆：GET /families/{id}/exhibitions，草稿可 POST /exhibitions/{id}/publish。
 * 发布前请用户确认：发布后内容对所有可见范围开放，且服务端会逐项复核可见性。
 */
const api = require('../../utils/api');

const STATUS_LABELS = { draft: '草稿', published: '已发布', offline: '已下线' };
const PRIVACY_LABELS = { private: '私密', family: '家族可见', public: '公开' };

function text(value) {
  return value === undefined || value === null ? '' : String(value);
}

Page({
  data: {
    familyId: 0,
    familyName: '',
    exhibitions: [],
    loading: true,
    busyId: 0,
  },

  onLoad: function (q) {
    const id = Number((q && q.id) || 0);
    this.setData({ familyId: id });
    if (!id) {
      this.setData({ loading: false });
      wx.showToast({ title: '缺少家族参数', icon: 'none' });
    }
  },

  onShow: function () {
    if (this.data.familyId) {
      this.load();
    }
  },

  onPullDownRefresh: function () {
    const that = this;
    if (!this.data.familyId) {
      wx.stopPullDownRefresh();
      return;
    }
    this.load(function () { wx.stopPullDownRefresh(); });
  },

  load: function (done) {
    const that = this;
    Promise.all([api.listExhibitions(this.data.familyId), api.getFamily(this.data.familyId)]).then(function (res) {
      const items = ((res[0] && res[0].items) || []).map(function (x) {
        const row = x || {};
        const status = text(row.status) || 'draft';
        return {
          id: row.id,
          title: text(row.title) || '未命名展馆',
          description: text(row.description),
          theme: text(row.theme),
          statusLabel: STATUS_LABELS[status] || status,
          privacyLabel: PRIVACY_LABELS[row.privacy] || text(row.privacy) || '家族可见',
          canPublish: status === 'draft',
        };
      });
      that.setData({
        exhibitions: items,
        familyName: (res[1] && res[1].family && res[1].family.name) || '',
        loading: false,
      });
      if (done) done();
    }).catch(function (err) {
      that.setData({ loading: false });
      wx.showToast({ title: (err && err.message) || '加载失败', icon: 'none' });
      if (done) done();
    });
  },

  onPublish: function (e) {
    const that = this;
    const id = e.currentTarget.dataset.id;
    if (!id || this.data.busyId) {
      return;
    }
    wx.showModal({
      title: '发布展馆',
      content: '发布后将按展馆的可见范围对外开放，确认发布吗？',
      confirmText: '发布',
      success: function (res) {
        if (!res.confirm) {
          return;
        }
        that.setData({ busyId: id });
        api.publishExhibition(id).then(function () {
          wx.showToast({ title: '展馆已发布', icon: 'success' });
          that.load();
        }).catch(function (err) {
          wx.showToast({ title: (err && err.message) || '发布失败', icon: 'none' });
        }).then(function () {
          that.setData({ busyId: 0 });
        });
      },
    });
  },
});
