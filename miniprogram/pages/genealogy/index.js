/**
 * 家族谱系：GET /families/{id}/genealogy。
 * 服务端返回 { persons, relations, truncated }，只含当前用户可见的节点与已确认关系。
 * 这里用「成员 + 关系」两张可读列表呈现，不绘制 SVG。
 */
const api = require('../../utils/api');

const RELATION_LABELS = {
  parent: '父母',
  child: '子女',
  spouse: '配偶',
  sibling: '兄弟姐妹',
  adoptive_parent: '养父母',
  adoptive_child: '养子女',
  step_parent: '继父母',
  step_child: '继子女',
  other: '其他亲属',
};

function text(value) {
  return value === undefined || value === null ? '' : String(value);
}

Page({
  data: {
    familyId: 0,
    familyName: '',
    persons: [],
    relations: [],
    truncated: false,
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
    Promise.all([api.getGenealogy(this.data.familyId), api.getFamily(this.data.familyId)]).then(function (res) {
      const tree = res[0] || {};
      const persons = ((tree.persons) || []).map(function (p) {
        const row = p || {};
        return {
          id: row.id,
          name: text(row.name) || '未命名',
          dates: (text(row.birth_date) || '?') + ' — ' + (text(row.death_date) || '?'),
        };
      });
      const byId = {};
      persons.forEach(function (p) { byId[String(p.id)] = p.name; });
      const relations = ((tree.relations) || []).map(function (r) {
        const row = r || {};
        const a = byId[String(row.person_a_id)] || text(row.person_a_name) || '人物 ' + text(row.person_a_id);
        const b = byId[String(row.person_b_id)] || text(row.person_b_name) || '人物 ' + text(row.person_b_id);
        return {
          id: row.id,
          label: a + ' — ' + b,
          typeLabel: RELATION_LABELS[row.relationship_type] || text(row.relationship_type) || '亲属',
          note: text(row.note),
        };
      });
      that.setData({
        persons: persons,
        relations: relations,
        truncated: tree.truncated === true,
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

  onPersons: function () {
    wx.navigateTo({ url: '/pages/persons/list?id=' + this.data.familyId });
  },
});
