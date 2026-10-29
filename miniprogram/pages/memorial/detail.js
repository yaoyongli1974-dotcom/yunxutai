/**
 * 纪念馆详情
 *
 * - GET /memorials/{slug} 一次性取 summary/profile/life/articles/media
 * - media_url 是 900 秒签名相对路径：每次进入/刷新都重新拉详情，不写 storage、不沿用旧 URL
 * - 图片 404（签名过期）时静默重拉详情换新 URL，失败两次后显示占位
 * - 祭奠 POST /memorials/{slug}/tribute；留言 GET/POST /memorials/{slug}/messages
 * - summary.role === 'owner'（或 /me 的 membership）时显示「成员管理」入口
 */
const api = require('../../utils/api');
const auth = require('../../utils/auth');
const media = require('../../utils/media');

const TRIBUTES = [
  { type: 'flower', label: '献花', mark: '花' },
  { type: 'candle', label: '点烛', mark: '烛' },
  { type: 'incense', label: '上香', mark: '香' },
  { type: 'bow', label: '鞠躬', mark: '鞠' },
];

const ROLE_LABEL = {
  owner: '馆主',
  editor: '协作者',
  viewer: '亲友',
};

const PAGE_SIZE = 20;
const APPENDIX_MAX = 200;
const MESSAGE_MAX = 1000;

const KIND_LABEL = {
  photo: '照片',
  video: '视频',
  audio: '音频',
  document: '文稿',
};

function toast(title) {
  wx.showToast({ title: title, icon: 'none', duration: 2500 });
}

function pendingTributes(type, pending) {
  return TRIBUTES.map(function (item) {
    return {
      type: item.type,
      label: item.label,
      mark: item.mark,
      pending: item.type === type ? pending : false,
    };
  });
}

function year(value) {
  const text = String(value || '');
  return text.length >= 4 ? text.slice(0, 4) : text;
}

function normalizeMedia(list) {
  return (list || []).map(function (item) {
    return {
      id: item.id,
      kind: item.kind,
      kindLabel: KIND_LABEL[item.kind] || '附件',
      caption: item.caption || '',
      mime: item.mime || '',
      src: media.absolute(item.media_url),
      failed: false,
    };
  });
}

Page({
  data: {
    slug: '',
    loading: true,
    error: '',
    summary: {},
    profile: {},
    life: [],
    articles: [],
    media: [],
    photos: [],
    others: [],
    coverSrc: '',
    coverFailed: false,
    initial: '',
    lifespan: '',
    birthLine: '',
    deathLine: '',
    loggedIn: false,
    role: '',
    roleLabel: '',
    isOwner: false,
    canPost: false,
    guestbookOpen: true,
    tributes: pendingTributes('', false),
    tributeAppendix: '',
    tributeAnonymous: false,
    appendixMax: APPENDIX_MAX,
    messages: [],
    messagesPage: 0,
    messagesHasMore: false,
    messagesLoading: false,
    messagesError: '',
    messageInput: '',
    messageLength: 0,
    messageAnonymous: false,
    messageSubmitting: false,
    messageMax: MESSAGE_MAX,
    mediaRefreshing: false,
  },

  onLoad: function (options) {
    const slug = options && options.slug ? String(options.slug) : '';
    if (!slug) {
      this.setData({ loading: false, error: '缺少纪念馆参数' });
      return;
    }
    this._mediaRefreshCount = 0;
    this.setData({
      slug: slug,
      loggedIn: auth.isLoggedIn(),
    });
    this.loadDetail(false);
    this.loadMessages(true);
  },

  onShow: function () {
    const loggedIn = auth.isLoggedIn();
    if (loggedIn !== this.data.loggedIn) {
      this._roleChecked = false;
      this.setData({ loggedIn: loggedIn });
      this.resolveRole(this.data.summary);
    }
  },

  onPullDownRefresh: function () {
    this._mediaRefreshCount = 0;
    Promise.all([this.loadDetail(false), this.loadMessages(true)])
      .then(function () {
        wx.stopPullDownRefresh();
      }, function () {
        wx.stopPullDownRefresh();
      });
  },

  onReachBottom: function () {
    if (this.data.messagesHasMore && !this.data.messagesLoading) {
      this.loadMessages(false);
    }
  },

  loadDetail: function (silent) {
    const that = this;
    if (!this.data.slug) {
      return Promise.resolve();
    }
    if (!silent) {
      this.setData({ loading: true, error: '' });
    }

    return api.getMemorial(this.data.slug)
      .then(function (res) {
        that.applyDetail(res || {});
      })
      .catch(function (err) {
        const message = (err && err.message) || '加载失败，请稍后重试';
        if (!silent) {
          that.setData({ loading: false, error: message });
        }
        if (err && err.code !== 'unauthorized') {
          toast(message);
        }
      });
  },

  applyDetail: function (res) {
    const summary = res.summary || {};
    const profile = res.profile || {};
    const list = normalizeMedia(res.media);
    const photos = list.filter(function (item) {
      return item.kind === 'photo' && item.src;
    });
    const coverId = summary.cover_media_id;
    let coverSrc = summary.cover_url ? media.absolute(summary.cover_url) : '';
    if (!coverSrc) {
      for (let i = 0; i < list.length; i += 1) {
        if (coverId && list[i].id === coverId && list[i].src) {
          coverSrc = list[i].src;
          break;
        }
      }
    }
    if (!coverSrc && photos.length) {
      coverSrc = photos[0].src;
    }

    const name = summary.full_name || summary.name || summary.title || '';
    const birth = year(profile.birth_date);
    const death = year(profile.death_date);
    const loggedIn = auth.isLoggedIn();
    const role = loggedIn ? (summary.role || this.data.role) : '';
    const articles = (res.articles || []).map(function (item) {
      const meta = [item.author_name || '', item.published_at || ''].filter(function (part) {
        return !!part;
      });
      return Object.assign({}, item, { meta: meta.join(' · ') });
    });
    const life = (res.life || []).map(function (item) {
      return Object.assign({}, item, {
        dateLabel: item.year_label || year(item.event_date) || '',
      });
    });

    this.setData({
      loading: false,
      error: '',
      summary: summary,
      profile: profile,
      life: life,
      articles: articles,
      media: list,
      photos: photos,
      others: list.filter(function (item) {
        return item.kind !== 'photo';
      }),
      coverSrc: coverSrc,
      coverFailed: false,
      initial: String(name).charAt(0),
      lifespan: birth || death ? (birth || '?') + ' — ' + (death || '?') : '',
      birthLine: [profile.birth_date || '', profile.birth_place || ''].filter(function (part) {
        return !!part;
      }).join(' · '),
      deathLine: [profile.death_date || '', profile.death_place || ''].filter(function (part) {
        return !!part;
      }).join(' · '),
      loggedIn: loggedIn,
      role: role,
      roleLabel: ROLE_LABEL[role] || '',
      isOwner: role === 'owner',
      canPost: summary.can_post !== false,
      guestbookOpen: summary.allow_guestbook !== false,
    });

    // 服务端未回 role 时，用 /me 的 membership 兜底（私有馆/旧版服务端）。
    if (!role) {
      this.resolveRole(summary);
    }
  },

  resolveRole: function (summary) {
    const that = this;
    if (!auth.isLoggedIn()) {
      this.setData({ role: '', roleLabel: '', isOwner: false });
      return;
    }
    const summaryRole = summary && summary.role ? summary.role : '';
    if (summaryRole) {
      this.setData({
        role: summaryRole,
        roleLabel: ROLE_LABEL[summaryRole] || '',
        isOwner: summaryRole === 'owner',
      });
      return;
    }
    if (this._roleChecked) {
      return;
    }
    this._roleChecked = true;

    api.me()
      .then(function (res) {
        const memberships = (res && res.memberships) || [];
        const slug = that.data.slug;
        const hit = memberships.filter(function (row) {
          return row && (row.slug === slug || String(row.memorial_id) === String(that.data.summary.id));
        })[0];
        const role = hit && hit.role ? hit.role : '';
        that.setData({
          role: role,
          roleLabel: ROLE_LABEL[role] || '',
          isOwner: role === 'owner',
        });
      })
      .catch(function () {
        // 401 由全局回调跳登录，静默失败即可。
      });
  },

  /* ------------------------------ 祭奠 ------------------------------ */

  onAppendixInput: function (e) {
    this.setData({ tributeAppendix: e.detail.value });
  },

  onTributeAnonymousChange: function (e) {
    this.setData({ tributeAnonymous: !!e.detail.value });
  },

  onTributeTap: function (e) {
    const that = this;
    const type = e.currentTarget.dataset.type;
    const current = this.data.tributes.filter(function (item) {
      return item.type === type;
    })[0];
    if (!type || !current || current.pending) {
      return;
    }
    if (!this.data.loggedIn) {
      wx.showModal({
        title: '需要登录',
        content: '登录后即可献花、点烛、上香与留言。',
        confirmText: '去登录',
        success: function (res) {
          if (res.confirm) {
            wx.navigateTo({ url: '/pages/login/login' });
          }
        },
      });
      return;
    }

    const label = current.label;

    this.setData({ tributes: pendingTributes(type, true) });

    api.createTribute(this.data.slug, {
      type: type,
      appendix: String(this.data.tributeAppendix || '').trim(),
      anonymous: this.data.tributeAnonymous,
    })
      .then(function (res) {
        const pending = res && res.status === 'pending';
        toast(pending ? label + '已送达，附言待馆主确认' : label + '已送达');
        that.setData({ tributeAppendix: '' });
      })
      .catch(function (err) {
        // 失败只恢复按钮可点，不做「已献花」的乐观状态。
        if (err && err.code === 'rate_limited') {
          toast('操作过于频繁，请稍后再试');
        } else if (err && err.code === 'forbidden') {
          toast('该纪念馆暂不接受祭奠');
        } else if (err && err.code !== 'unauthorized') {
          toast((err && err.message) || '祭奠未送达，请稍后再试');
        }
      })
      .then(function () {
        that.setData({ tributes: pendingTributes(type, false) });
      });
  },

  /* ------------------------------ 留言 ------------------------------ */

  loadMessages: function (reset) {
    const that = this;
    if (!this.data.slug || this.data.messagesLoading) {
      return Promise.resolve();
    }
    if (!reset && !this.data.messagesHasMore) {
      return Promise.resolve();
    }
    const page = reset ? 1 : this.data.messagesPage + 1;

    this.setData({ messagesLoading: true, messagesError: '' });

    return api.listMessages(this.data.slug, { page: page, per_page: PAGE_SIZE })
      .then(function (res) {
        const payload = res || {};
        const rows = payload.items || [];
        const pagination = payload.pagination || {};
        const total = pagination.total === undefined ? rows.length : pagination.total;
        const perPage = pagination.per_page || PAGE_SIZE;
        const totalPages = pagination.total_pages || Math.ceil(total / perPage) || 1;
        const messages = reset ? rows : that.data.messages.concat(rows);

        that.setData({
          messages: messages,
          messagesPage: page,
          messagesHasMore: page < totalPages,
          messagesLoading: false,
        });
      })
      .catch(function (err) {
        that.setData({
          messagesLoading: false,
          messagesError: (err && err.message) || '留言加载失败',
        });
      });
  },

  onMessageInput: function (e) {
    const value = e.detail.value;
    this.setData({ messageInput: value, messageLength: String(value || '').length });
  },

  onMessageAnonymousChange: function (e) {
    this.setData({ messageAnonymous: !!e.detail.value });
  },

  onMessageSubmit: function () {
    const that = this;
    const content = String(this.data.messageInput || '').trim();

    if (!this.data.loggedIn) {
      wx.showModal({
        title: '需要登录',
        content: '登录后即可留下追思。',
        confirmText: '去登录',
        success: function (res) {
          if (res.confirm) {
            wx.navigateTo({ url: '/pages/login/login' });
          }
        },
      });
      return;
    }
    if (!this.data.guestbookOpen) {
      toast('该纪念馆暂未开放留言');
      return;
    }
    if (content.length < 2) {
      toast('留言至少 2 个字');
      return;
    }
    if (content.length > MESSAGE_MAX) {
      toast('留言请控制在 1000 字以内');
      return;
    }
    if (this.data.messageSubmitting) {
      return;
    }

    this.setData({ messageSubmitting: true });

    api.sendMessage(this.data.slug, {
      body: content,
      anonymous: this.data.messageAnonymous,
    })
      .then(function (res) {
        const pending = res && res.status === 'pending';
        that.setData({ messageInput: '', messageLength: 0 });
        if (pending) {
          toast('留言待审核，不会立即显示');
          return;
        }
        toast('留言已发表');
        that.loadMessages(true);
      })
      .catch(function (err) {
        if (err && err.code === 'rate_limited') {
          toast('留言过于频繁，请稍后再试');
        } else if (err && err.code === 'validation_failed') {
          toast((err && (err.serverMessage || err.message)) || '留言未通过校验');
        } else if (err && err.code === 'forbidden') {
          toast('该纪念馆暂未开放留言');
        } else if (err && err.code !== 'unauthorized') {
          toast((err && err.message) || '留言失败，请稍后再试');
        }
      })
      .then(function () {
        that.setData({ messageSubmitting: false });
      });
  },

  /* --------------------------- 媒体签名刷新 --------------------------- */

  onCoverError: function () {
    this.setData({ coverFailed: true });
    this.refreshMedia();
  },

  onPhotoError: function (e) {
    const index = Number(e.currentTarget.dataset.index);
    const photo = this.data.photos[index];
    if (!photo) {
      return;
    }
    const mediaId = photo.id;
    const list = this.data.media.map(function (item) {
      return item.id === mediaId ? Object.assign({}, item, { failed: true }) : item;
    });
    const photos = this.data.photos.slice();
    photos[index] = Object.assign({}, photo, { failed: true });
    this.setData({ media: list, photos: photos });
    this.refreshMedia();
  },

  /**
   * 签名过期 → 整页重拉详情换新 URL（不复用旧地址、不落缓存）。
   * 同一次停留最多 2 次，避免服务端媒体真损坏时无限重试。
   */
  refreshMedia: function () {
    const that = this;
    if (this.data.mediaRefreshing) {
      return;
    }
    const count = this._mediaRefreshCount || 0;
    if (count >= 2) {
      return;
    }
    this._mediaRefreshCount = count + 1;
    this.setData({ mediaRefreshing: true, coverFailed: false });

    this.loadDetail(true).then(function () {
      that.setData({ mediaRefreshing: false });
    }, function () {
      that.setData({ mediaRefreshing: false });
    });
  },

  /* ------------------------------ 其它 ------------------------------ */

  onOwnerTap: function () {
    const summary = this.data.summary || {};
    wx.navigateTo({
      url: '/pages/manage/members?id=' + encodeURIComponent(String(summary.id || '')) +
        '&title=' + encodeURIComponent(summary.title || summary.name || ''),
    });
  },

  onLoginTap: function () {
    wx.navigateTo({ url: '/pages/login/login' });
  },

  onBackList: function () {
    wx.reLaunch({ url: '/pages/memorials/list' });
  },
});
