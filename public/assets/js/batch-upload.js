/* 云叙台 · 批量上传渐进增强
   - 只使用页面既有上传端点与表单里的 _token，逐个小批量请求（每次一个文件）。
   - 不新增接口、不改动端点契约，也不模拟断点续传。
   - 服务端仍是 PRG：依据重定向后页面里的 flash / 表单错误判断单个文件结果。
   - 脚本未加载时批量面板保持隐藏，原有单个上传表单照常可用。 */

(function () {
    'use strict';

    function start() {
        var forms = document.querySelectorAll('[data-batch-upload]');
        if (!forms.length) { return; }
        Array.prototype.forEach.call(forms, function (form) {
            initForm(form);
        });
    }

    function initForm(form) {
        var panel = form.closest ? form.closest('[data-batch-panel]') : null;
        if (panel) { panel.hidden = false; }

        var input = form.querySelector('input[type="file"][multiple]');
        var submit = form.querySelector('button[type="submit"]');
        if (!input || !submit) { return; }

        var selector = form.getAttribute('data-batch-results') || '';
        var list = selector ? document.querySelector(selector) : null;
        if (!list) { return; }

        var summary = panel ? panel.querySelector('[data-batch-summary]') : form.parentNode.querySelector('[data-batch-summary]');
        var items = [];
        var running = false;

        function setSummary(text) {
            if (summary) { summary.textContent = text; }
        }

        function render(item) {
            var row = item.row;
            row.className = 'batch-item'
                + (item.state === 'ok' ? ' batch-item--ok' : '')
                + (item.state === 'error' ? ' batch-item--error' : '');
            if (item.state === 'pending') {
                item.status.textContent = '等待中';
            } else if (item.state === 'uploading') {
                item.status.textContent = '上传中 ' + item.percent + '%';
            } else if (item.state === 'ok') {
                item.status.textContent = '成功 · ' + (item.message || '已上传');
            } else {
                item.status.textContent = '失败 · ' + (item.message || '未知错误');
            }
            item.fill.style.width = item.percent + '%';
            item.bar.setAttribute('aria-valuenow', String(item.percent));
            item.retry.hidden = item.state !== 'error';
        }

        function setState(item, state, message, percent) {
            item.state = state;
            item.message = message || '';
            if (typeof percent === 'number') { item.percent = percent; }
            render(item);
        }

        function makeItem(file) {
            var row = document.createElement('li');
            row.className = 'batch-item';

            var name = document.createElement('span');
            name.className = 'batch-item__name';
            name.textContent = file.name || '未命名文件';
            name.title = file.name || '';

            var status = document.createElement('span');
            status.className = 'batch-item__status';

            var bar = document.createElement('span');
            bar.className = 'batch-item__bar';
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-valuemin', '0');
            bar.setAttribute('aria-valuemax', '100');
            var fill = document.createElement('i');
            bar.appendChild(fill);

            var retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'button button--small button--ghost batch-item__retry';
            retry.textContent = '重试';
            retry.hidden = true;

            row.appendChild(name);
            row.appendChild(status);
            row.appendChild(bar);
            row.appendChild(retry);
            list.appendChild(row);

            var item = {
                file: file,
                row: row,
                status: status,
                bar: bar,
                fill: fill,
                retry: retry,
                state: 'pending',
                percent: 0,
                message: '',
                active: false
            };
            retry.addEventListener('click', function () {
                if (running) { setSummary('请等待当前批次结束。'); return; }
                setState(item, 'pending', '', 0);
                run();
            });
            return item;
        }

        function firstText(doc, selector) {
            var node = doc.querySelector(selector);
            return node ? (node.textContent || '').trim() : '';
        }

        // 端点仍返回重定向后的完整页面；只读取本次响应里的提示信息。
        function readResult(xhr) {
            var doc = null;
            try {
                doc = new DOMParser().parseFromString(xhr.responseText || '', 'text/html');
            } catch (e) { /* 无法解析时回退到状态码判断 */ }
            if ((xhr.responseURL || '').indexOf('/login') !== -1 || (doc && doc.querySelector('.auth-card'))) {
                return { ok: false, message: '登录已失效，请重新登录后再试。' };
            }
            var error = doc ? firstText(doc, '.flash--error, .form-error, .field__error') : '';
            if (error) { return { ok: false, message: error }; }
            var success = doc ? firstText(doc, '.flash--success') : '';
            if (success) { return { ok: true, message: success }; }
            if (xhr.status >= 200 && xhr.status < 300) {
                return { ok: true, message: '已上传' };
            }
            return { ok: false, message: '上传失败（HTTP ' + xhr.status + '）' };
        }

        function upload(item, done) {
            setState(item, 'uploading', '', 0);

            var data;
            try {
                data = new FormData(form);
                data.delete('file[]');
                data.append('file', item.file, item.file.name || 'file');
            } catch (e) {
                setState(item, 'error', '无法读取文件，请重试。', item.percent);
                done();
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.action, true);
            xhr.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable && event.total > 0) {
                    var percent = Math.max(0, Math.min(100, Math.round(event.loaded * 100 / event.total)));
                    setState(item, 'uploading', '', percent);
                }
            });
            xhr.addEventListener('load', function () {
                var result = readResult(xhr);
                setState(item, result.ok ? 'ok' : 'error', result.message, result.ok ? 100 : item.percent);
                done();
            });
            xhr.addEventListener('error', function () {
                setState(item, 'error', '网络连接失败，请重试。', item.percent);
                done();
            });
            xhr.addEventListener('abort', function () {
                setState(item, 'error', '上传已中断，可重试。', item.percent);
                done();
            });
            try {
                xhr.send(data);
            } catch (e) {
                setState(item, 'error', '无法发送文件，请重试。', item.percent);
                done();
            }
        }

        function finish() {
            running = false;
            submit.disabled = false;
            input.disabled = false;
            var ok = 0;
            var failed = 0;
            items.forEach(function (item) {
                if (item.state === 'ok') { ok++; }
                if (item.state === 'error') { failed++; }
            });
            setSummary('结束：成功 ' + ok + ' 个，失败 ' + failed + ' 个。刷新页面可查看最新文件。');
        }

        function next() {
            var target = null;
            for (var i = 0; i < items.length; i++) {
                if (items[i].state === 'pending' && !items[i].active) {
                    target = items[i];
                    break;
                }
            }
            if (!target) { finish(); return; }
            target.active = true;
            upload(target, function () {
                target.active = false;
                next();
            });
        }

        function run() {
            if (running) { return; }
            running = true;
            submit.disabled = true;
            input.disabled = true;
            next();
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (running) { return; }
            var files = Array.prototype.slice.call(input.files || []);
            if (!files.length) {
                setSummary('请先选择要上传的文件。');
                return;
            }
            files.forEach(function (file) { items.push(makeItem(file)); });
            input.value = '';
            setSummary('共 ' + files.length + ' 个文件，按顺序上传中…');
            run();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
