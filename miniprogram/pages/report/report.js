/**
 * 投诉与举报
 *
 * - POST /reports；参数 target_type/target_id 由来源页带入（memorial 详情页）。
 * - 活人被祭拜、虚构逝者属紧急级别，服务端自动升级处理。
 * - 免验证码（Bearer 身份），按 IP 5 次/小时限流，429 时提示稍后再试。
 */
const api = require('../../utils/api');
const auth = require('../../utils/auth');

const REASONS = [
  { value: 'living_person', label: '活人被祭拜（紧急）' },
  { value: 'fake_person', label: '虚构/不存在的人（紧急）' },
  { value: 'privacy', label: '侵犯隐私' },
  { value: 'insult', label: '侮辱、攻击性内容' },
  { value: 'illegal', label: '违法违规内容' },
  { value: 'copyright', label: '侵犯著作权' },
  { value: 'other', label: '其他问题' },
];

function toast(title) {
  wx.showToast({ title: title, icon: 'none', duration: 2500 });
}

Page({
  data: {
    reasons: REASONS,
    reasonIndex: 0,
    targetType: 'memorial',
    targetId: '',
    targetLabel: '',
    detail: '',
    detailLength: 0,
    reporterName: '',
    contact: '',
    errors: {},
    submitting: false,
    detailMax: 2000,
  },

  onLoad: function (options) {
    const user = auth.getCachedUser() || {};
    const type = (options && options.type) || 'memorial';
    this.setData({
      targetType: type,
      targetId: (options && options.id) || '',
      targetLabel: decodeURIComponent((options && options.label) || ''),
      reporterName: user.display_name || user.nickname || '',
    });
  },

  onReasonChange: function (e) {
    this.setData({ reasonIndex: Number(e.detail.value), 'errors.reason': '' });
  },

  onDetailInput: function (e) {
    const value = e.detail.value || '';
    this.setData({ detail: value, detailLength: value.length, 'errors.detail': '' });
  },

  onNameInput: function (e) {
    this.setData({ reporterName: e.detail.value, 'errors.reporter_name': '' });
  },

  onContactInput: function (e) {
    this.setData({ contact: e.detail.value, 'errors.contact': '' });
  },

  validate: function () {
    const errors = {};
    const detail = this.data.detail.trim();
    if (!this.data.targetId) {
      errors.form = '缺少举报对象，请从纪念馆页面进入';
    }
    if (detail.length < 10 || detail.length > 2000) {
      errors.detail = '请补充具体情况，10–2000 字';
    }
    if (!this.data.reporterName.trim()) {
      errors.reporter_name = '请填写你的称呼';
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
      toast('请检查填写内容');
      return;
    }

    const reason = REASONS[this.data.reasonIndex] || REASONS[REASONS.length - 1];
    this.setData({ submitting: true });
    api.sendReport({
      target_type: this.data.targetType,
      target_id: this.data.targetId,
      reason: reason.value,
      detail: this.data.detail.trim(),
      reporter_name: this.data.reporterName.trim(),
      contact: this.data.contact.trim(),
    })
      .then(function () {
        that.setData({ submitting: false });
        wx.showToast({ title: '举报已提交，平台会尽快核查', icon: 'none', duration: 2500 });
        setTimeout(function () {
          wx.navigateBack();
        }, 1500);
      })
      .catch(function (err) {
        that.setData({ submitting: false });
        if (err && err.code === 'validation_failed' && err.fields) {
          that.setData({ errors: err.fields });
          toast('请检查填写内容');
          return;
        }
        if (err && err.code === 'not_found') {
          toast('内容不可见或已被移除');
          return;
        }
        if (err && err.code === 'rate_limited') {
          toast('提交过于频繁，请一小时后再试');
          return;
        }
        toast((err && err.message) || '提交失败，请稍后重试');
      });
  },
});
