/** 照片区域标注：Pointer Events 统一鼠标/触屏/触控笔，百分比坐标。 */
(function () {
  var imgs = document.querySelectorAll('.photo-tag-preview');
  Array.prototype.forEach.call(imgs, function (img) {
    var form = img.parentNode.querySelector('[data-photo-tag]');
    if (!form || !img.complete || !img.naturalWidth) return;
    var wrap = img.parentNode;
    var box = document.createElement('div');
    box.style.cssText = 'position:absolute;border:2px solid #9c3d33;background:rgba(156,61,51,.12);pointer-events:none;display:none';
    if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';
    wrap.appendChild(box);
    var start = null;
    function pct(e) {
      var r = img.getBoundingClientRect();
      return {
        x: Math.max(0, Math.min(100, ((e.clientX - r.left) / r.width) * 100)),
        y: Math.max(0, Math.min(100, ((e.clientY - r.top) / r.height) * 100))
      };
    }
    function draw(a, b) {
      var x = Math.min(a.x, b.x), y = Math.min(a.y, b.y);
      var w = Math.abs(b.x - a.x), h = Math.abs(b.y - a.y);
      if (w < 0.5 || h < 0.5) return;
      box.style.display = 'block';
      box.style.left = (img.offsetLeft + x * img.clientWidth / 100) + 'px';
      box.style.top = (img.offsetTop + y * img.clientHeight / 100) + 'px';
      box.style.width = (w * img.clientWidth / 100) + 'px';
      box.style.height = (h * img.clientHeight / 100) + 'px';
      form.querySelector('[name=x]').value = x.toFixed(2);
      form.querySelector('[name=y]').value = y.toFixed(2);
      form.querySelector('[name=width]').value = w.toFixed(2);
      form.querySelector('[name=height]').value = h.toFixed(2);
    }
    img.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) return;
      e.preventDefault();
      start = pct(e);
      try { img.setPointerCapture(e.pointerId); } catch (_) {}
    });
    img.addEventListener('pointermove', function (e) {
      if (start) draw(start, pct(e));
    });
    function end(e) {
      if (!start) return;
      draw(start, pct(e));
      start = null;
      try { img.releasePointerCapture(e.pointerId); } catch (_) {}
    }
    img.addEventListener('pointerup', end);
    img.addEventListener('pointercancel', function () { start = null; });
  });
})();
