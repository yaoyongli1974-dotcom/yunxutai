const api = require('../../utils/api');
Page({
  data: { familyId: 0, family: {}, persons: [], events: [] },
  onLoad(q) { this.setData({ familyId: Number(q.id || 0) }); },
  onShow() { if (this.data.familyId) this.load(); },
  load() {
    const that = this; const id = this.data.familyId;
    Promise.all([api.getFamily(id), api.listPersons(id), api.listEvents(id)]).then(function (res) {
      that.setData({ family: (res[0] && res[0].family) || {}, persons: (res[1] && res[1].items) || [], events: (res[2] && res[2].items) || [] });
    }).catch(function (err) { wx.showToast({ title: (err && err.message) || '加载失败', icon: 'none' }); });
  },
  onNav(e) { wx.navigateTo({ url: e.currentTarget.dataset.url }); },
  onGenealogy() {
    const that = this;
    api.getGenealogy(this.data.familyId).then(function (res) {
      const names = ((res && res.nodes) || []).map(function (n) { return n.name || ''; }).filter(Boolean).join('、');
      wx.showModal({ title: '家族谱系', content: names || '暂无已确认的关系节点', showCancel: false });
    }).catch(function (err) { wx.showToast({ title: (err && err.message) || '加载失败', icon: 'none' }); });
  },
  onWorkflow() {
    const that = this;
    wx.showModal({
      title: 'AI 家族记忆工坊',
      content: '从档案与采访文字中提取候选事实，所有结果都需要人工确认。是否创建提取任务？',
      success(r) {
        if (!r.confirm) return;
        api.createWorkflow(that.data.familyId, { type: 'extract_facts', text: '家族口述资料整理', idempotency_key: 'mp-' + that.data.familyId + '-' + Date.now() })
          .then(function () { wx.showToast({ title: '任务已提交', icon: 'success' }); })
          .catch(function (err) { wx.showToast({ title: (err && err.message) || '提交失败', icon: 'none' }); });
      }
    });
  }
});
