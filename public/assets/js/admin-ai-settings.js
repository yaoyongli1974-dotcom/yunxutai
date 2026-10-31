/**
 * 管理后台 · AI 大模型设置页的分域模型探测。
 * 由 ai-settings.php 以外链方式加载（CSP 禁内联脚本）。
 * 主组 domain=''（文本+图像），视频独立组 domain='video'。
 */
(function () {
  var ENDPOINT = '/admin/ai-settings/models';
  var config = [
    { domain: '', btn: 'ai-fetch-models', list: 'ai-model-options', status: 'ai-models-status', base: 'ai-base-url', keyInput: 'input[name="api_key"]' },
    { domain: 'video', btn: 'ai-fetch-video-models', list: 'ai-video-model-options', status: 'ai-video-models-status', base: 'ai-video-base-url', keyInput: 'input[name="video_api_key"]' }
  ];
  config.forEach(function (item) {
    var btn = document.getElementById(item.btn);
    if (!btn) return;
    btn.addEventListener('click', function () {
      var status = document.getElementById(item.status);
      var list = document.getElementById(item.list);
      if (!status || !list) return;
      status.textContent = '获取中…';
      var fd = new FormData();
      fd.append('domain', item.domain);
      var base = document.getElementById(item.base);
      if (base && base.value) { fd.append('base_url', base.value); }
      var keyInput = document.querySelector(item.keyInput);
      if (keyInput && keyInput.value) { fd.append('api_key', keyInput.value); }
      var token = document.querySelector('input[name="_token"]');
      if (token) { fd.append('_token', token.value); }
      fetch(ENDPOINT, { method: 'POST', body: fd })
        .then(function (r) { return r.json().then(function (j) { return { code: r.status, j: j }; }); })
        .then(function (r) {
          if (r.code !== 200 || !r.j.models) {
            status.textContent = '获取失败：' + ((r.j && r.j.message) || ('HTTP ' + r.code));
            return;
          }
          list.innerHTML = '';
          (r.j.models || []).forEach(function (m) {
            if (!m || !m.id) return;
            var o = document.createElement('option');
            o.value = m.id;
            if (m.owned_by) { o.label = m.owned_by; }
            list.appendChild(o);
          });
          status.textContent = '获取到 ' + list.children.length + ' 个模型，可直接点输入框选择。';
        })
        .catch(function (e) { status.textContent = '获取失败：' + e; });
    });
  });
})();
