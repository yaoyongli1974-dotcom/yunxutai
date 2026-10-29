/* 静思园 · 最小的渐进增强脚本
   - data-confirm：危险操作确认
   - data-toggle：展开收起
   - data-copy：复制链接（降级为全选）
   逻辑尽量放服务端，JS 仅做增强，禁用样式不影响可用性。 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // 危险操作确认
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var message = form.getAttribute('data-confirm');
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });

        // 折叠区域
        document.querySelectorAll('[data-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var target = document.querySelector(button.getAttribute('data-toggle'));
                if (target) {
                    target.hidden = !target.hidden;
                    button.setAttribute('aria-expanded', String(!target.hidden));
                }
            });
        });

        // 复制链接
        document.querySelectorAll('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var text = button.getAttribute('data-copy') || '';
                if (!text) { return; }
                var done = function () {
                    var old = button.textContent;
                    button.textContent = '已复制';
                    button.setAttribute('aria-live', 'polite');
                    window.setTimeout(function () { button.textContent = old; }, 2000);
                };
                if (navigator.clipboard && window.isSecurePage !== false) {
                    navigator.clipboard.writeText(text).then(done, function () { fallback(); });
                } else {
                    fallback();
                }
                function fallback() {
                    var input = document.querySelector('[data-copy-target]');
                    if (!input) { return; }
                    input.focus();
                    input.select();
                    try { document.execCommand('copy'); done(); } catch (e) { /* 忽略 */ }
                }
            });
        });
    });
})();
