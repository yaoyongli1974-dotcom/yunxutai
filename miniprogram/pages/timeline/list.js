/**
 * 家族时间轴：GET /families/{id}/events。
 * 列表展示日期、标题与来源（source_type 为服务端枚举，这里翻译成中文）。
 */
const api = require('../../utils/api');

const SOURCE_LABELS = {
  user_input: '用户录入',
  archive: '数字档案',
  oral_history: '口述历史',
  person_profile: '人物资料',
  imported: '资料导入',
};
const EVENT_LABELS = {
  birth: '出生',
  death: '逝世',
  marriage: '婚嫁',
  migration: '迁徙',
  other: '其他',
};

function text(value) {
  return value === undefined || value === null ? '' : String(value);
}

Page({
  data: {
    familyId: 0,
    familyName: '',
    events: [],
    loading: true,
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
    Promise.all([api.listEvents(this.data.familyId), api.getFamily(this.data.familyId)]).then(function (res) {
      const events = ((res[0] && res[0].items) || []).map(function (e) {
        const row = e || {};
        return {
          id: row.id,
          title: text(row.title) || '未命名事件',
          date: text(row.event_date) || '日期未知',
          source: SOURCE_LABELS[row.source_type] || text(row.source_type) || '来源未知',
          typeLabel: EVENT_LABELS[row.event_type] || text(row.event_type) || '其他',
          description: text(row.description),
          location: text(row.location),
        };
      });
      that.setData({
        events: events,
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
});
