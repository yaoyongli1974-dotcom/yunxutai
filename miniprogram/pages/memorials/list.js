/**
 * 纪念馆列表（可未登录浏览）
 *
 * - 公开馆：GET /memorials（服务端只返回 public + active + verified）
 * - 我管理的：GET /manage/memorials，仅登录后请求；馆主直达成员管理，其余角色进详情
 * - 封面地址是签名相对路径，展示时用 utils/media.absolute 补 origin，不落 storage
 */
const api = require('../../utils/api');
const auth = require('../../utils/auth');
const media = require('../../utils/media');

const PAGE_SIZE = 20;

const ROLE_LABEL = {
  owner: '馆主',
  editor: '协作者',
  viewer: '亲友',
};

function toast(title) {
  wx.showToast({ title: title, icon: 'none', duration: 2500 });
}

function snippet(text, max) {
  const value = String(text || '').replace(/\s+/g, ' ').trim();
  if (value.length <= max) {
    return value;
  }
  return value.slice(0, max) + '…';
}

function lifespan(item) {
  const birth = String(item.birth_date || '').slice(0, 4);
  const death = String(item.death_date || '').slice(0, 4);
  if (!birth && !death) {
    return '';
  }
  return (birth || '?') + ' — ' + (death || '?');
}

function decorate(item, managed) {
  const row = item || {};
  const displayName = row.full_name || row.display_name || row.name || row.title || '未署名';
  const memorialTitle = row.title || row.name || '';
  return {
    id: row.id,
    slug: row.slug,
    role: row.role || '',
    roleLabel: ROLE_LABEL[row.role] || '',
    displayName: displayName,
    memorialTitle: memorialTitle === displayName ? '' : memorialTitle,
    lifespan: lifespan(row),
    excerpt: snippet(row.summary, managed ? 40 : 64),
    coverSrc: media.absolute(row.cover_url || row.media_url || ''),
    coverFailed: false,
    initial: displayName.charAt(0),
    managed: !!managed,
  };
}

Page({
  data: {
    loggedIn: false,
    nickname: '',
    items: [],
    managed: [],
    loading: false,
    loadingManaged: false,
    error: '',
    page: 0,
    total: 0,
    hasMore: false,
  },

  onLoad: function () {
    this._loggedIn = auth.isLoggedIn();
    this.syncLoginState();
    this.loadPublic(true);
    if (this.data.loggedIn) {
      this.loadManaged();
    }
  },

  onShow: function () {
    // 从登录页/详情页返回时同步登录态，只在状态变化时重拉「我管理的」。
    const loggedIn = auth.isLoggedIn();
    if (loggedIn !== this._loggedIn) {
      this._loggedIn = loggedIn;
      this.syncLoginState();
      if (loggedIn) {
        this.loadManaged();
      } else {
        this.setData({ managed: [] });
      }
    }
  },

  onPullDownRefresh: function () {
    const tasks = [this.loadPublic(true)];
    if (this.data.loggedIn) {
      tasks.push(this.loadManaged());
    }
    Promise.all(tasks).then(function () {
      wx.stopPullDownRefresh();
    }, function () {
      wx.stopPullDownRefresh();
    });
  },

  onReachBottom: function () {
    if (this.data.hasMore && !this.data.loading) {
      this.loadPublic(false);
    }
  },

  syncLoginState: function () {
    const user = auth.getCachedUser();
    this.setData({
      loggedIn: auth.isLoggedIn(),
      nickname: (user && (user.nickname || user.display_name)) || '我的',
    });
  },

  loadPublic: function (reset) {
    const that = this;
    if (this.data.loading) {
      return Promise.resolve();
    }
    const page = reset ? 1 : this.data.page + 1;

    this.setData({ loading: true, error: reset ? '' : this.data.error });

    return api.listMemorials({ page: page, per_page: PAGE_SIZE })
      .then(function (res) {
        const payload = res || {};
        const rows = payload.items || [];
        const pagination = payload.pagination || {};
        const decorated = rows.map(function (row) {
          return decorate(row, false);
        });
        const items = reset ? decorated : that.data.items.concat(decorated);
        const perPage = pagination.per_page || PAGE_SIZE;
        const total = pagination.total === undefined ? items.length : pagination.total;
        const totalPages = pagination.total_pages || Math.ceil(total / perPage) || 1;

        that.setData({
          items: items,
          page: page,
          total: total,
          hasMore: page < totalPages,
          loading: false,
        });
      })
      .catch(function (err) {
        const message = (err && err.message) || '加载失败，请稍后重试';
        that.setData({ loading: false, error: message });
        if (reset && that.data.items.length === 0) {
          toast(message);
        }
      });
  },

  loadManaged: function () {
    const that = this;
    this.setData({ loadingManaged: true });

    return api.listManagedMemorials({ per_page: PAGE_SIZE })
      .then(function (res) {
        const rows = (res && res.items) || [];
        that.setData({
          managed: rows.map(function (row) {
            return decorate(row, true);
          }),
          loadingManaged: false,
        });
      })
      .catch(function (err) {
        that.setData({ loadingManaged: false });
        // 401 由 request.js 统一跳登录；其余错误不打断公开列表。
        if (err && err.code !== 'unauthorized') {
          toast((err && err.message) || '无法加载我管理的纪念馆');
        }
      });
  },

  onManagedTap: function (e) {
    const index = Number(e.currentTarget.dataset.index);
    const row = this.data.managed[index];
    if (!row) {
      return;
    }
    if (row.role === 'owner' && row.id) {
      wx.navigateTo({
        url: '/pages/manage/members?id=' + encodeURIComponent(String(row.id)) +
          '&title=' + encodeURIComponent(row.memorialTitle || row.displayName || ''),
      });
      return;
    }
    this.openDetail(row.slug);
  },

  onOpenDetail: function (e) {
    const index = Number(e.currentTarget.dataset.index);
    const row = this.data.items[index];
    this.openDetail(row && row.slug);
  },

  openDetail: function (slug) {
    if (!slug) {
      return;
    }
    wx.navigateTo({ url: '/pages/memorial/detail?slug=' + encodeURIComponent(String(slug)) });
  },

  onCoverError: function (e) {
    const index = Number(e.currentTarget.dataset.index);
    if (isNaN(index) || !this.data.items[index]) {
      return;
    }
    const patch = {};
    patch['items[' + index + '].coverFailed'] = true;
    this.setData(patch);
  },

  onLoginTap: function () {
    wx.navigateTo({ url: '/pages/login/login' });
  },

  onAccountTap: function () {
    const that = this;
    wx.showActionSheet({
      itemList: ['退出登录'],
      success: function () {
        auth.logout().then(function () {
          that._loggedIn = false;
          that.syncLoginState();
          that.setData({ managed: [] });
          toast('已退出登录');
        });
      },
    });
  },
});
