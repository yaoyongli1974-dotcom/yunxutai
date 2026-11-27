const api = require('../../../utils/api');
Page({
  data: { familyId: 0, familyName: '', archives: [], loading: true, showForm: false, title: '', archiveType: 'document' },
  onLoad(q) {
    const id = Number(q.familyId || 0); this.setData({ familyId: id });
    if (id) api.getFamily(id).then(function (res) { this.setData({ familyName: (res && res.family && res.family.name) || '' }); }.bind(this)).catch(function () {});
  },
  onShow() { if (this.data.familyId) this.load(); },
  onPullDownRefresh() { this.load(function () { wx.stopPullDownRefresh(); }); },
  load(done) {
    const that = this;
    api.listArchives(this.data.familyId).then(function (res) {
      that.setData({ archives: (res && res.items) || [], loading: false }); if (done) done();
    }).catch(function (err) { that.setData({ loading: false }); wx.showToast({ title: (err && err.message) || '加载失败', icon: 'none' }); if (done) done(); });
  },
  toggleForm() { this.setData({ showForm: !this.data.showForm }); },
  onTitle(e) { this.setData({ title: e.detail.value }); },
  onType(e) { this.setData({ archiveType: e.detail.value }); },
  onCreate() {
    const that = this;
    if (!this.data.title) { wx.showToast({ title: '请填写标题', icon: 'none' }); return; }
    api.createArchive(this.data.familyId, { title: this.data.title, archive_type: this.data.archiveType })
      .then(function () { that.setData({ showForm: false, title: '' }); wx.showToast({ title: '已创建', icon: 'success' }); that.load(); })
      .catch(function (err) { wx.showToast({ title: (err && err.message) || '创建失败', icon: 'none' }); });
  }
});
