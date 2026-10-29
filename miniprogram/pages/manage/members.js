/**
 * 成员与邀请管理（仅馆主）
 *
 * - GET  /manage/memorials/{id}/members —— 成员 + 待接受邀请
 * - POST …/members/invite                —— email 可为空（链接邀请），role: editor|viewer
 * - POST …/members/{memberId}/role       —— 角色切换（owner 只读）
 * - POST …/members/{memberId}/remove     —— 移除成员
 * - POST …/invitations/{invitationId}/revoke —— 作废邀请
 *
 * 403 统一提示「仅馆主可用」；401 由 request.js 全局回调跳登录页。
 */
const config = require('../../config');
const api = require('../../utils/api');
const auth = require('../../utils/auth');

const ROLE_LABEL = {
  owner: '馆主',
  editor: '协作者',
  viewer: '亲友',
};

const ROLE_OPTIONS = [
  { value: 'editor', label: '协作者', note: '可编辑内容' },
  { value: 'viewer', label: '亲友', note: '仅可浏览' },
];

function toast(title) {
  wx.showToast({ title: title, icon: 'none', duration: 2500 });
}

function roleLabel(role) {
  return ROLE_LABEL[role] || '成员';
}

function decode(value) {
  const text = String(value || '');
  try {
    return decodeURIComponent(text);
  } catch (err) {
    return text;
  }
}

function normalizeMember(row, index) {
  const member = row || {};
  const role = member.role || 'viewer';
  const displayName = member.display_name || member.nickname || member.name ||
    member.email || ('成员 ' + (member.id || index + 1));
  return {
    id: member.id || member.member_id || member.user_id,
    userId: member.user_id || '',
    displayName: displayName,
    initial: String(displayName).charAt(0),
    email: member.email || '',
    role: role,
    roleLabel: roleLabel(role),
    status: member.status || 'active',
    statusLabel: member.status === 'pending' ? '待接受' : '已加入',
    meta: [member.email || '', member.status === 'pending' ? '待接受' : '已加入'].filter(function (part) {
      return !!part;
    }).join(' · '),
    isOwner: role === 'owner',
  };
}

function normalizeInvitation(row, index) {
  const invite = row || {};
  const role = invite.role || 'viewer';
  const token = invite.token || '';
  let url = invite.url || invite.invite_url || invite.link || '';
  if (!url && token) {
    url = String(config.BASE_URL || '').replace(/\/+$/, '') + '/invitations/' + token;
  }
  return {
    id: invite.id || invite.invitation_id || index,
    email: invite.email || '',
    role: role,
    roleLabel: roleLabel(role),
    status: invite.status || 'pending',
    expiresAt: invite.expires_at || '',
    expiresLabel: invite.expires_at ? '有效至 ' + invite.expires_at : '7 天内有效',
    url: url,
    label: invite.email || '链接邀请',
  };
}

Page({
  data: {
    memorialId: '',
    title: '',
    loading: true,
    error: '',
    denied: false,
    members: [],
    invitations: [],
    roleOptions: ROLE_OPTIONS,
    inviteEmail: '',
    inviteRole: 'editor',
    inviteError: '',
    submitting: false,
    busy: false,
  },

  onLoad: function (options) {
    const opts = options || {};
    const memorialId = opts.id ? String(opts.id) : '';
    const title = decode(opts.title);
    this.setData({ memorialId: memorialId, title: title });

    if (!memorialId) {
      this.setData({ loading: false, error: '缺少纪念馆参数' });
      return;
    }
    if (!auth.isLoggedIn()) {
      this.setData({ loading: false, error: '请先登录后再管理成员' });
      wx.showModal({
        title: '需要登录',
        content: '登录后即可管理纪念馆成员。',
        confirmText: '去登录',
        success: function (res) {
          if (res.confirm) {
            wx.navigateTo({ url: '/pages/login/login' });
          }
        },
      });
      return;
    }
    this.loadMembers();
  },

  onPullDownRefresh: function () {
    this.loadMembers().then(function () {
      wx.stopPullDownRefresh();
    }, function () {
      wx.stopPullDownRefresh();
    });
  },

  loadMembers: function () {
    const that = this;
    if (!this.data.memorialId) {
      return Promise.resolve();
    }
    this.setData({ loading: true, error: '', denied: false });

    return api.listMembers(this.data.memorialId)
      .then(function (res) {
        const payload = res || {};
        const rows = payload.members || payload.items || [];
        const invites = payload.invitations || payload.pending || payload.invites || [];

        that.setData({
          loading: false,
          members: rows.map(normalizeMember),
          invitations: invites.filter(function (item) {
            const status = (item && item.status) || 'pending';
            return status === 'pending';
          }).map(normalizeInvitation),
        });
      })
      .catch(function (err) {
        const code = err && err.code;
        if (code === 'forbidden') {
          that.setData({ loading: false, denied: true, error: '仅馆主可用' });
          toast('仅馆主可用');
          return;
        }
        if (code === 'unauthorized') {
          that.setData({ loading: false, error: '登录已过期，请重新登录' });
          return;
        }
        if (code === 'not_found') {
          that.setData({ loading: false, error: '仅馆主可用，或纪念馆不存在' });
          return;
        }
        that.setData({
          loading: false,
          error: (err && err.message) || '成员列表加载失败',
        });
      });
  },

  /* ------------------------------ 邀请 ------------------------------ */

  onInviteEmailInput: function (e) {
    this.setData({ inviteEmail: e.detail.value, inviteError: '' });
  },

  onInviteRoleTap: function (e) {
    const role = e.currentTarget.dataset.role;
    if (role) {
      this.setData({ inviteRole: role, inviteError: '' });
    }
  },

  onInviteSubmit: function () {
    const that = this;
    if (this.data.submitting) {
      return;
    }
    const email = String(this.data.inviteEmail || '').trim();

    this.setData({ submitting: true, inviteError: '' });

    api.inviteMember(this.data.memorialId, {
      email: email,
      role: this.data.inviteRole,
    })
      .then(function () {
        toast('邀请已创建，7 天内有效');
        that.setData({ inviteEmail: '' });
        return that.loadMembers();
      })
      .catch(function (err) {
        const code = err && err.code;
        if (code === 'validation_failed') {
          const message = (err && (err.serverMessage || err.message)) || '邀请信息未通过校验';
          that.setData({ inviteError: message });
          toast(message);
          return;
        }
        if (code === 'forbidden') {
          that.setData({ denied: true, error: '仅馆主可用' });
          toast('仅馆主可用');
          return;
        }
        if (code !== 'unauthorized') {
          toast((err && err.message) || '邀请失败，请稍后再试');
        }
      })
      .then(function () {
        that.setData({ submitting: false });
      });
  },

  /* ---------------------------- 成员操作 ---------------------------- */

  onMemberTap: function (e) {
    const that = this;
    const index = Number(e.currentTarget.dataset.index);
    const member = this.data.members[index];
    if (!member) {
      return;
    }
    if (member.isOwner) {
      toast('馆主角色不可修改');
      return;
    }

    wx.showActionSheet({
      itemList: ['设为协作者（可编辑）', '设为亲友（只读）'],
      success: function (res) {
        const role = res.tapIndex === 0 ? 'editor' : 'viewer';
        if (role === member.role) {
          toast('该成员已是' + roleLabel(role));
          return;
        }
        that.changeRole(member, role);
      },
    });
  },

  changeRole: function (member, role) {
    const that = this;
    if (this.data.busy) {
      return;
    }
    this.setData({ busy: true });

    api.updateMemberRole(this.data.memorialId, member.id, role)
      .then(function () {
        toast('已设为' + roleLabel(role));
        return that.loadMembers();
      })
      .catch(function (err) {
        if (err && err.code === 'validation_failed') {
          toast((err.serverMessage || err.message) || '角色修改失败');
        } else if (err && err.code === 'forbidden') {
          toast('仅馆主可用');
        } else if (err && err.code !== 'unauthorized') {
          toast((err && err.message) || '角色修改失败');
        }
      })
      .then(function () {
        that.setData({ busy: false });
      });
  },

  onRemoveTap: function (e) {
    const that = this;
    const index = Number(e.currentTarget.dataset.index);
    const member = this.data.members[index];
    if (!member || member.isOwner) {
      return;
    }

    wx.showModal({
      title: '移除成员',
      content: '移除后「' + member.displayName + '」将无法再访问该纪念馆的管理功能。',
      confirmText: '移除',
      confirmColor: '#9c4a3c',
      success: function (res) {
        if (!res.confirm) {
          return;
        }
        api.removeMember(that.data.memorialId, member.id)
          .then(function () {
            toast('成员已移除');
            return that.loadMembers();
          })
          .catch(function (err) {
            if (err && err.code === 'validation_failed') {
              toast((err.serverMessage || err.message) || '移除失败');
            } else if (err && err.code === 'forbidden') {
              toast('仅馆主可用');
            } else if (err && err.code !== 'unauthorized') {
              toast((err && err.message) || '移除失败');
            }
          });
      },
    });
  },

  /* ---------------------------- 邀请操作 ---------------------------- */

  onCopyInvite: function (e) {
    const index = Number(e.currentTarget.dataset.index);
    const invite = this.data.invitations[index];
    if (!invite || !invite.url) {
      toast('暂无邀请链接');
      return;
    }
    wx.setClipboardData({
      data: invite.url,
      success: function () {
        toast('邀请链接已复制');
      },
    });
  },

  onRevokeInvite: function (e) {
    const that = this;
    const index = Number(e.currentTarget.dataset.index);
    const invite = this.data.invitations[index];
    if (!invite) {
      return;
    }

    wx.showModal({
      title: '撤销邀请',
      content: '撤销后该邀请链接将立即失效。',
      confirmText: '撤销',
      confirmColor: '#9c4a3c',
      success: function (res) {
        if (!res.confirm) {
          return;
        }
        api.revokeInvitation(that.data.memorialId, invite.id)
          .then(function () {
            toast('邀请已撤销');
            return that.loadMembers();
          })
          .catch(function (err) {
            if (err && err.code === 'forbidden') {
              toast('仅馆主可用');
            } else if (err && err.code !== 'unauthorized') {
              toast((err && err.message) || '撤销失败');
            }
          });
      },
    });
  },

  onBackTap: function () {
    wx.navigateBack({
      fail: function () {
        wx.reLaunch({ url: '/pages/memorials/list' });
      },
    });
  },
});
