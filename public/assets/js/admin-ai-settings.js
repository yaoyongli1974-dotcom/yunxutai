/**
 * 管理后台 · AI 大模型设置页的分域模型探测与自绘下拉。
 * 由 ai-settings.php 以外链方式加载（CSP 禁内联脚本；下拉样式由 JS 注入 <style>）。
 * 主组 domain=''（文本+图像），视频独立组 domain='video'。
 */
(function () {
  var ENDPOINT = '/admin/ai-settings/models';
  var config = [
    { domain: '', btn: 'ai-fetch-models', list: 'ai-model-options', status: 'ai-models-status', base: 'ai-base-url', keyInput: 'input[name="api_key"]', inputs: ['input[name="text_model"]', 'input[name="image_model"]'] },
    { domain: 'video', btn: 'ai-fetch-video-models', list: 'ai-video-model-options', status: 'ai-video-models-status', base: 'ai-video-base-url', keyInput: 'input[name="video_api_key"]', inputs: ['input[name="video_model"]'] }
  ];

  injectStyles();

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
          var models = (r.j.models || []).filter(function (m) { return m && m.id; });
          list.innerHTML = '';
          models.forEach(function (m) {
            var o = document.createElement('option');
            o.value = m.id;
            if (m.owned_by) { o.label = m.owned_by; }
            list.appendChild(o);
          });
          bindPickers(item, models);
          status.textContent = '获取到 ' + models.length + ' 个模型，点输入框即可选择。';
        })
        .catch(function (e) { status.textContent = '获取失败：' + e; });
    });
  });

  /** 自绘下拉：点开显示全部模型（不受输入框现有值过滤），选中即写入。 */
  function bindPickers(item, models) {
    item.inputs.forEach(function (selector) {
      var input = document.querySelector(selector);
      if (!input) return;
      var dd = null;
      function open() {
        close();
        dd = document.createElement('div');
        dd.className = 'ai-model-dd';
        var q = '';
        models.forEach(function (m) {
          if (q && m.id.toLowerCase().indexOf(q) === -1) return;
          var row = document.createElement('div');
          row.className = 'ai-model-dd__item' + (m.id === input.value ? ' is-active' : '');
          row.textContent = m.id;
          if (m.owned_by) {
            var tag = document.createElement('span');
            tag.className = 'ai-model-dd__tag';
            tag.textContent = m.owned_by;
            row.appendChild(tag);
          }
          row.addEventListener('mousedown', function (e) {
            e.preventDefault();
            input.value = m.id;
            close();
            input.focus();
          });
          dd.appendChild(row);
        });
        if (!dd.children.length) {
          var empty = document.createElement('div');
          empty.className = 'ai-model-dd__item is-muted';
          empty.textContent = '（无候选项）';
          dd.appendChild(empty);
        }
        dd.style.left = input.getBoundingClientRect().left + window.scrollX + 'px';
        dd.style.top = input.getBoundingClientRect().bottom + window.scrollY + 'px';
        dd.style.width = input.getBoundingClientRect().width + 'px';
        document.body.appendChild(dd);
      }
      function close() {
        if (dd && dd.parentNode) { dd.parentNode.removeChild(dd); }
        dd = null;
      }
      input.addEventListener('focus', open);
      input.addEventListener('click', function () { if (!dd) { open(); } });
      input.addEventListener('input', function () { open(); });
      input.addEventListener('blur', function () { window.setTimeout(close, 180); });
      input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    });
  }

  function injectStyles() {
    if (document.getElementById('ai-model-dd-style')) return;
    var style = document.createElement('style');
    style.id = 'ai-model-dd-style';
    style.textContent = '.ai-model-dd{position:absolute;z-index:60;background:#fffdf8;border:1px solid #e2d9c9;border-radius:12px;box-shadow:0 8px 24px rgba(63,90,76,.16);max-height:260px;overflow:auto;padding:4px}'
      + '.ai-model-dd__item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:14px;color:#3f5a4c}'
      + '.ai-model-dd__item:hover{background:#f1ede2}'
      + '.ai-model-dd__item.is-active{background:#e9f1ec}'
      + '.ai-model-dd__item.is-muted{color:#8a7f70;cursor:default}'
      + '.ai-model-dd__tag{font-size:12px;color:#8a7f70}';
    document.head.appendChild(style);
  }
})();
