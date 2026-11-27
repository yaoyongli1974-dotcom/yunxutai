/**
 * 照片区域标注：在预览图上拖动即可写入百分比坐标，不自动识别人脸。
 */
(function () {
  var previews = document.querySelectorAll('.photo-tag-preview');
  Array.prototype.forEach.call(previews, function (img) {
    var form = img.parentNode.querySelector('[data-photo-tag]');
    if (!form) return;
    var start = null;
    var box = document.createElement('div');
    box.style.cssText = 'position:absolute;border:2px solid #9c3d33;background:rgba(156,61,51,.12);pointer-events:none;display:none';
    var wrap = img.parentNode;
    if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';
    wrap.appendChild(box);

    function pos(e) {
      var r = img.getBoundingClientRect();
      return {
        x: Math.max(0, Math.min(100, ((e.clientX - r.left) / r.width) * 100)),
        y: Math.max(0, Math.min(100, ((e.clientY - r.top) / r.height) * 100))
      };
    }
    img.addEventListener('mousedown', function (e) {
      e.preventDefault();
      start = pos(e);
      box.style.display = 'block';
    });
    document.addEventListener('mousemove', function (e) {
      if (!start) return;
      var end = pos(e);
      var x = Math.min(start.x, end.x), y = Math.min(start.y, end.y);
      var w = Math.abs(end.x - start.x), h = Math.abs(end.y - start.y);
      box.style.left = (img.offsetLeft + (x / 100) * img.clientWidth) + 'px';
      box.style.top = (img.offsetTop + (y / 100) * img.clientHeight) + 'px';
      box.style.width = (w / 100) * img.clientWidth + 'px';
      box.style.height = (h / 100) * img.clientHeight + 'px';
      form.querySelector('[name=x]').value = x.toFixed(2);
      form.querySelector('[name=y]').value = y.toFixed(2);
      form.querySelector('[name=width]').value = w.toFixed(2);
      form.querySelector('[name=height]').value = h.toFixed(2);
    });
    document.addEventListener('mouseup', function () { start = null; });
  });
})();
