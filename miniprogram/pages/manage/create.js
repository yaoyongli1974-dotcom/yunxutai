/**
 * 新建纪念馆（私密草稿）
 *
 * - POST /manage/memorials；服务端强制 private + draft，需身份核验后才能公开。
 * - 姓名、性别、创建声明为必填；其余可展开填写。
 * - 422 逐字段错误带 fields，展示在对应输入下方。
 */
const api = require('../../utils/api');
const auth = require('../../utils/auth');

const GENDERS = [
  { value: 'unknown', label: '不详' },
  { value: 'male', label: '男' },
  { value: 'female', label: '女' },
];

function toast(title) {
  wx.showToast({ title: title, icon: 'none', duration: 2500 });
}

Page({
  data: {
    genders: GENDERS,
    genderIndex: 0,
    form: {
      full_name: '',
      gender: 'unknown',
      title: '',
      birth_date: '',
      death_date: '',
      birth_place: '',
      death_place: '',
      bio: '',
      summary: '',
      slug: '',
    },
    declaration: true,
    showMore: false,
    errors: {},
    submitting: false,
    birthMax: '',
  },

  onLoad: function () {
    this.setData({ birthMax: new Date().toISOString().slice(0, 10) });
  },

  onInput: function (e) {
    const field = e.currentTarget.dataset.field;
    if (!field) {
      return;
    }
    const patch = {};
    patch['form.' + field] = e.detail.value;
    patch['errors.' + field] = '';
    this.setData(patch);
  },

  onBirthChange: function (e) {
    this.setData({ 'form.birth_date': e.detail.value, 'errors.birth_date': '' });
  },

  onDeathChange: function (e) {
    this.setData({ 'form.death_date': e.detail.value, 'errors.death_date': '' });
  },

  onGenderChange: function (e) {
    const index = Number(e.detail.value);
    const gender = GENDERS[index] || GENDERS[0];
    this.setData({ genderIndex: index, 'form.gender': gender.value, 'errors.gender': '' });
  },

  onDeclarationChange: function (e) {
    this.setData({ declaration: !!e.detail.value, 'errors.declaration': '' });
  },

  onToggleMore: function () {
    this.setData({ showMore: !this.data.showMore });
  },

  validate: function () {
    const form = this.data.form;
    const errors = {};

    if (!String(form.full_name || '').trim()) {
      errors.full_name = '请填写逝者姓名';
    } else if (String(form.full_name).length > 120) {
      errors.full_name = '姓名过长（120 字以内）';
    }
    if (form.birth_date && form.death_date && form.death_date < form.birth_date) {
      errors.death_date = '逝世日期不能早于出生日期';
    }
    if (!this.data.declaration) {
      errors.declaration = '请确认创建声明后再继续';
    }
    this.setData({ errors: errors });
    return Object.keys(errors).length === 0;
  },

  onSubmit: function () {
    const that = this;
    if (this.data.submitting) {
      return;
    }
    if (!auth.isLoggedIn()) {
      wx.navigateTo({ url: '/pages/login/login' });
      return;
    }
    if (!this.validate()) {
      toast('请检查必填项');
      return;
    }

    const form = this.data.form;
    const payload = {
      full_name: String(form.full_name).trim(),
      gender: form.gender || 'unknown',
      title: String(form.title || '').trim(),
      birth_date: form.birth_date || '',
      death_date: form.death_date || '',
      birth_place: String(form.birth_place || '').trim(),
      death_place: String(form.death_place || '').trim(),
      bio: String(form.bio || '').trim(),
      summary: String(form.summary || '').trim(),
      slug: String(form.slug || '').trim(),
      declaration: true,
    };

    this.setData({ submitting: true });
    api.createMemorial(payload)
      .then(function (res) {
        that.setData({ submitting: false });
        const slug = (res && res.slug) || '';
        wx.showToast({ title: '已创建（私密草稿）', icon: 'none', duration: 2000 });
        setTimeout(function () {
          if (slug) {
            wx.redirectTo({ url: '/pages/memorial/detail?slug=' + encodeURIComponent(slug) });
          } else {
            wx.navigateBack();
          }
        }, 1200);
      })
      .catch(function (err) {
        that.setData({ submitting: false });
        if (err && err.code === 'validation_failed' && err.fields) {
          that.setData({ errors: err.fields });
          toast('请检查表单内容');
          return;
        }
        toast((err && err.message) || '创建失败，请稍后重试');
      });
  },
});
