/**
 * 家族人物列表：GET /families/{id}/persons、POST /families/{id}/persons、GET /persons/{id}。
 * createPerson 字段与 FamilyV2Controller::personData 一致：
 * name / gender / birth_date / death_date / birth_place / death_place / biography / privacy。
 * 服务端要求活人资料保持私密，因此隐私默认 private。
 */
const api = require('../../utils/api');

const GENDERS = ['unknown', 'male', 'female', 'other'];
const GENDER_LABELS = { unknown: '未知', male: '男', female: '女', other: '其他' };
const PRIVACIES = ['private', 'family', 'public'];
const PRIVACY_LABELS = { private: '私密', family: '家族可见', public: '公开' };

function text(value) {
  return value === undefined || value === null ? '' : String(value);
}

Page({
  data: {
    familyId: 0,
    familyName: '',
    persons: [],
    loading: true,
    showForm: false,
    name: '',
    genderIndex: 0,
    genderLabels: ['未知', '男', '女', '其他'],
    birthDate: '',
    deathDate: '',
    birthPlace: '',
    deathPlace: '',
    biography: '',
    privacyIndex: 0,
    privacyLabels: ['私密', '家族可见', '公开'],
    detail: null,
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
    Promise.all([api.listPersons(this.data.familyId), api.getFamily(this.data.familyId)]).then(function (res) {
      const items = ((res[0] && res[0].items) || []).map(function (p) {
        const row = p || {};
        return Object.assign({}, row, {
          genderLabel: GENDER_LABELS[row.gender] || '未知',
          privacyLabel: PRIVACY_LABELS[row.privacy] || '私密',
        });
      });
      that.setData({
        persons: items,
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

  toggleForm: function () { this.setData({ showForm: !this.data.showForm }); },
  onName: function (e) { this.setData({ name: e.detail.value }); },
  onGender: function (e) { this.setData({ genderIndex: Number(e.detail.value) }); },
  onBirthDate: function (e) { this.setData({ birthDate: e.detail.value }); },
  onDeathDate: function (e) { this.setData({ deathDate: e.detail.value }); },
  onBirthPlace: function (e) { this.setData({ birthPlace: e.detail.value }); },
  onDeathPlace: function (e) { this.setData({ deathPlace: e.detail.value }); },
  onBiography: function (e) { this.setData({ biography: e.detail.value }); },
  onPrivacy: function (e) { this.setData({ privacyIndex: Number(e.detail.value) }); },
  onCloseDetail: function () { this.setData({ detail: null }); },

  onCreate: function () {
    const that = this;
    const name = String(this.data.name || '').trim();
    if (!name) {
      wx.showToast({ title: '请填写姓名', icon: 'none' });
      return;
    }
    api.createPerson(this.data.familyId, {
      name: name,
      gender: GENDERS[this.data.genderIndex],
      birth_date: this.data.birthDate,
      death_date: this.data.deathDate,
      birth_place: this.data.birthPlace,
      death_place: this.data.deathPlace,
      biography: this.data.biography,
      privacy: PRIVACIES[this.data.privacyIndex],
    }).then(function () {
      that.setData({
        showForm: false, name: '', birthDate: '', deathDate: '',
        birthPlace: '', deathPlace: '', biography: '', privacyIndex: 0,
      });
      wx.showToast({ title: '已创建', icon: 'success' });
      that.load();
    }).catch(function (err) {
      wx.showToast({ title: (err && err.message) || '创建失败', icon: 'none' });
    });
  },

  onOpen: function (e) {
    const that = this;
    const id = e.currentTarget.dataset.id;
    api.getPerson(id).then(function (res) {
      const p = (res && res.person) || {};
      that.setData({
        detail: {
          id: p.id || id,
          name: text(p.name),
          genderLabel: GENDER_LABELS[p.gender] || '未知',
          birthDate: text(p.birth_date),
          deathDate: text(p.death_date),
          birthPlace: text(p.birth_place),
          deathPlace: text(p.death_place),
          biography: text(p.biography),
          privacyLabel: PRIVACY_LABELS[p.privacy] || text(p.privacy) || '私密',
          verificationLabel: p.verification_status === 'verified' ? '已核验' : '待核验',
        },
      });
    }).catch(function (err) {
      wx.showToast({ title: (err && err.message) || '加载失败', icon: 'none' });
    });
  },
});
