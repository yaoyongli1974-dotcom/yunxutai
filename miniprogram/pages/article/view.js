/**
 * 纪念文章阅读页
 *
 * - GET /memorials/{slug}/articles/{id}，content_html 由服务端转义后生成，
 *   直接用 rich-text 渲染；不拼接、不注入额外 HTML。
 * - 404（不可见/不存在/未发布且无编辑权）统一展示占位，不区分原因。
 */
const api = require('../../utils/api');

Page({
  data: {
    slug: '',
    id: '',
    loading: true,
    error: '',
    article: {},
  },

  onLoad: function (options) {
    const slug = (options && options.slug) || '';
    const id = (options && options.id) || '';
    if (!slug || !id) {
      this.setData({ loading: false, error: '缺少文章参数' });
      return;
    }
    this.setData({ slug: slug, id: id });
    this.load();
  },

  load: function () {
    const that = this;
    this.setData({ loading: true, error: '' });
    api.getArticle(this.data.slug, this.data.id)
      .then(function (res) {
        const article = res || {};
        that.setData({
          loading: false,
          article: {
            id: article.id,
            title: article.title || '未命名文章',
            author: article.author_name || '',
            publishedAt: String(article.published_at || '').slice(0, 10),
            updatedAt: String(article.updated_at || '').slice(0, 10),
            status: article.status || 'published',
            contentHtml: article.content_html || '',
          },
          pageTitle: article.title || '纪念文章',
        });
        wx.setNavigationBarTitle({ title: article.title || '纪念文章' });
      })
      .catch(function (err) {
        const message = err && err.code === 'not_found'
          ? '这篇文章暂不可见，可能尚未发布或已被隐藏。'
          : ((err && err.message) || '加载失败，请稍后重试');
        that.setData({ loading: false, error: message });
      });
  },

  onBack: function () {
    wx.navigateBack();
  },
});
