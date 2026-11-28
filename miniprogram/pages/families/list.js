const api = require('../../utils/api');
const privacies = ['private', 'unlisted', 'public'];
Page({
  data: { families: [], loading: true, showForm: false, name: '', slug: '', privacies: privacies, privacyIndex: 0 },
  onShow() { this.load(); },
  onPullDownRefresh() { this.load(() => wx.stopPullDownRefresh()); },
  load(done) {
    const that = this;
    api.listFamilies().then(function (res) {
      that.setData({ families: (res && res.items) || [], loading: false });
      if (done) done();
    }).catch(function (err) {
      that.setData({ loading: false });
      wx.showToast({ title: (err && err.message) || '加载失败', icon: 'none' });
      if (done) done();
    });
  },
  toggleForm() { this.setData({ showForm: !this.data.showForm }); },
  onName(e) { this.setData({ name: e.detail.value }); },
  onSlug(e) { this.setData({ slug: e.detail.value }); },
  onPrivacy(e) { this.setData({ privacyIndex: Number(e.detail.value) }); },
  onCreate() {
    const that = this;
    const payload = { name: this.data.name, slug: this.data.slug, privacy: privacies[this.data.privacyIndex] };
    if (!payload.name || !payload.slug) { wx.showToast({ title: '请填写名称与标识', icon: 'none' }); return; }
    api.createFamily(payload).then(function () {
      that.setData({ showForm: false, name: '', slug: '' });
      wx.showToast({ title: '已创建', icon: 'success' });
      that.load();
    }).catch(function (err) { wx.showToast({ title: (err && err.message) || '创建失败', icon: 'none' }); });
  },
  onOpen(e) { wx.navigateTo({ url: '/pages/families/detail?id=' + e.currentTarget.dataset.id }); },
  onAcceptInvite() { wx.navigateTo({ url: '/pages/invitations/accept' }); }
});
