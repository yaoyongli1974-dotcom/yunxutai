/**
 * AI 家族记忆工坊：创建 extract_facts 工作流并轮询任务状态。
 * - POST /families/{id}/ai/workflows  { type, text, idempotency_key }
 * - GET  /ai/workflows/{id}           { workflow, steps }
 * - GET  /families/{id}/ai/sessions   口述会话列表
 * 服务端按设计不返回候选事实正文，这里只展示状态与步骤结果可用性；
 * 所有结果都必须经人工复核后才能作为家族史料。
 */
const api = require('../../utils/api');

const STATUS_LABELS = {
  queued: '排队中', processing: '处理中', completed: '已完成', failed: '失败', cancelled: '已取消',
};
const STEP_STATUS_LABELS = {
  queued: '排队中', processing: '处理中', completed: '已完成', failed: '失败', skipped: '已跳过',
};
const TYPE_LABELS = { extract_facts: '事实提取', transcribe_audio: '口述转写' };
const CONSENT_LABELS = { none: '待授权', granted: '已授权', revoked: '已撤回' };
const PROCESSING_LABELS = {
  draft: '草稿', queued: '排队中', processing: '处理中', completed: '已完成', failed: '失败',
};
const TERMINAL = ['completed', 'failed', 'cancelled'];

function text(value) {
  return value === undefined || value === null ? '' : String(value);
}

function isTerminal(workflow) {
  return !!workflow && TERMINAL.indexOf(workflow.status) >= 0;
}

/** 同一段文字重复提交复用同一个幂等键，避免重复建任务。 */
function idempotencyKey(familyId, value) {
  const input = 'mp-' + familyId + '-' + value;
  let hash = 0;
  for (let i = 0; i < input.length; i += 1) {
    hash = (hash * 31 + input.charCodeAt(i)) % 1000000007;
  }
  return 'mp-' + familyId + '-' + hash;
}

Page({
  data: {
    familyId: 0,
    familyName: '',
    text: '',
    submitting: false,
    workflowId: 0,
    workflow: null,
    steps: [],
    sessions: [],
    sessionsLoaded: false,
    showSessions: false,
  },

  onLoad: function (q) {
    const id = Number((q && q.id) || 0);
    this.setData({ familyId: id });
    if (!id) {
      wx.showToast({ title: '缺少家族参数', icon: 'none' });
    }
  },

  onShow: function () {
    if (!this.data.familyId) {
      return;
    }
    const that = this;
    api.getFamily(this.data.familyId).then(function (res) {
      that.setData({ familyName: (res && res.family && res.family.name) || '' });
    }).catch(function () {});
    if (this.data.workflowId && !isTerminal(this.data.workflow)) {
      this.startPolling();
    }
  },

  onHide: function () { this.stopPolling(); },
  onUnload: function () { this.stopPolling(); },

  onPullDownRefresh: function () {
    if (this.data.workflowId) {
      const that = this;
      this.poll(function () { wx.stopPullDownRefresh(); });
    } else {
      wx.stopPullDownRefresh();
    }
  },

  onText: function (e) { this.setData({ text: e.detail.value }); },

  onSubmit: function () {
    const that = this;
    const value = String(this.data.text || '').trim();
    if (this.data.submitting) {
      return;
    }
    if (!value) {
      wx.showToast({ title: '请填写要提取的文字', icon: 'none' });
      return;
    }
    this.setData({ submitting: true, workflow: null, steps: [], workflowId: 0 });
    this.stopPolling();
    api.createWorkflow(this.data.familyId, {
      type: 'extract_facts',
      text: value,
      idempotency_key: idempotencyKey(this.data.familyId, value),
    }).then(function (res) {
      const id = (res && res.id) || 0;
      if (!id) {
        wx.showToast({ title: '任务提交失败', icon: 'none' });
        return;
      }
      that.setData({ workflowId: id });
      wx.showToast({ title: '任务已提交', icon: 'success' });
      that.startPolling();
    }).catch(function (err) {
      wx.showToast({ title: (err && err.message) || '提交失败', icon: 'none' });
    }).then(function () {
      that.setData({ submitting: false });
    });
  },

  /* --------------------------- 任务状态轮询 --------------------------- */

  startPolling: function () {
    this.stopPolling();
    if (!this.data.workflowId) {
      return;
    }
    this._pollCount = 0;
    const that = this;
    this.poll();
    this._timer = setInterval(function () { that.poll(); }, 2000);
  },

  stopPolling: function () {
    if (this._timer) {
      clearInterval(this._timer);
      this._timer = null;
    }
  },

  poll: function (done) {
    if (this._pollBusy || isTerminal(this.data.workflow)) {
      this.stopPolling();
      if (done) done();
      return;
    }
    if (this._pollCount >= 30) {
      this.stopPolling();
      wx.showToast({ title: '任务仍在处理中，请稍后下拉刷新', icon: 'none' });
      if (done) done();
      return;
    }
    const that = this;
    this._pollBusy = true;
    this._pollCount += 1;
    api.getWorkflow(this.data.workflowId).then(function (res) {
      const wf = (res && res.workflow) || {};
      const steps = ((res && res.steps) || []).map(function (s) {
        const row = s || {};
        return {
          id: row.id,
          label: '第 ' + text(row.step_no) + ' 步 · ' + (text(row.name) || '未命名步骤'),
          statusLabel: STEP_STATUS_LABELS[row.status] || text(row.status) || '未知',
          outputLabel: row.output_ref ? '有候选结果（待人工确认）' : '暂无输出',
          error: text(row.error_message),
        };
      });
      that.setData({
        workflow: {
          id: wf.id || that.data.workflowId,
          status: text(wf.status),
          statusLabel: STATUS_LABELS[wf.status] || text(wf.status) || '未知',
          typeLabel: TYPE_LABELS[wf.workflow_type] || text(wf.workflow_type) || '家族记忆提取',
          currentStep: text(wf.current_step),
          createdAt: text(wf.created_at),
          finishedAt: text(wf.finished_at),
          failureClass: text(wf.failure_class),
        },
        steps: steps,
      });
      if (isTerminal(wf)) {
        that.stopPolling();
      }
    }).catch(function (err) {
      that.stopPolling();
      wx.showToast({ title: (err && err.message) || '状态查询失败', icon: 'none' });
    }).then(function () {
      that._pollBusy = false;
      if (done) done();
    });
  },

  /* ------------------------------ 口述会话 ------------------------------ */

  onToggleSessions: function () {
    const show = !this.data.showSessions;
    this.setData({ showSessions: show });
    if (show && !this.data.sessionsLoaded) {
      this.loadSessions();
    }
  },

  loadSessions: function () {
    const that = this;
    api.listOralSessions(this.data.familyId).then(function (res) {
      const items = ((res && res.items) || []).map(function (s) {
        const row = s || {};
        return {
          id: row.id,
          title: text(row.title) || '未命名会话',
          topic: text(row.topic),
          interviewer: text(row.interviewer),
          consentLabel: CONSENT_LABELS[row.consent_status] || text(row.consent_status) || '待授权',
          processingLabel: PROCESSING_LABELS[row.processing_status] || text(row.processing_status) || '草稿',
        };
      });
      that.setData({ sessions: items, sessionsLoaded: true });
    }).catch(function (err) {
      wx.showToast({ title: (err && err.message) || '会话加载失败', icon: 'none' });
    });
  },
});
