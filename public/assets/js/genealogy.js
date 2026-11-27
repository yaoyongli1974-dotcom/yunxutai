/**
 * 家族谱系轻量交互图：无第三方依赖，支持拖动节点、平移画布、滚轮/按钮缩放、移动端触摸。
 * 节点数据来自服务端按 center_id + depth 裁剪后的可见人物，不一次性加载全族数据。
 */
(function () {
  var host = document.getElementById('genealogy-graph');
  if (!host) return;

  var tree;
  try {
    tree = JSON.parse(host.getAttribute('data-tree') || '{"persons":[],"relations":[]}');
  } catch (e) {
    return;
  }

  var NS = 'http://www.w3.org/2000/svg';
  var width = Math.max(720, host.clientWidth || 720);
  var height = Math.max(460, Math.min(720, (tree.persons || []).length * 18 + 300));
  var svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
  svg.setAttribute('width', '100%');
  svg.setAttribute('height', height + 'px');
  svg.style.touchAction = 'none';
  svg.style.background = '#fbf8f1';
  svg.style.border = '1px solid #decfba';
  svg.style.borderRadius = '3px';
  host.appendChild(svg);

  var viewport = document.createElementNS(NS, 'g');
  svg.appendChild(viewport);

  var persons = (tree.persons || []).slice(0, 200);
  var byId = {};
  persons.forEach(function (p, i) {
    var cols = Math.max(1, Math.ceil(Math.sqrt(persons.length)));
    var angle = (i / Math.max(1, persons.length)) * Math.PI * 2;
    var radius = Math.min(width, height) * 0.34;
    byId[p.id] = {
      id: p.id,
      name: p.name,
      x: width / 2 + Math.cos(angle) * radius + (i % cols) * 8,
      y: height / 2 + Math.sin(angle) * radius + (i % cols) * 8,
      el: null,
      label: null
    };
  });

  var edges = document.createElementNS(NS, 'g');
  viewport.appendChild(edges);
  var nodes = document.createElementNS(NS, 'g');
  viewport.appendChild(nodes);

  function lineFor(rel) {
    var a = byId[rel.person_a_id];
    var b = byId[rel.person_b_id];
    if (!a || !b) return;
    var line = document.createElementNS(NS, 'line');
    line.setAttribute('x1', a.x);
    line.setAttribute('y1', a.y);
    line.setAttribute('x2', b.x);
    line.setAttribute('y2', b.y);
    line.setAttribute('stroke', '#c8b89e');
    line.setAttribute('stroke-width', '1.5');
    edges.appendChild(line);
    rel._line = line;
  }
  (tree.relations || []).forEach(lineFor);

  persons.forEach(function (p) {
    var n = byId[p.id];
    var g = document.createElementNS(NS, 'g');
    g.style.cursor = 'grab';
    var circle = document.createElementNS(NS, 'circle');
    circle.setAttribute('r', '17');
    circle.setAttribute('fill', '#5d6f55');
    circle.setAttribute('stroke', '#40503b');
    circle.setAttribute('stroke-width', '1');
    g.appendChild(circle);
    var label = document.createElementNS(NS, 'text');
    label.setAttribute('text-anchor', 'middle');
    label.setAttribute('y', '-25');
    label.setAttribute('font-size', '13');
    label.setAttribute('fill', '#272119');
    label.textContent = n.name;
    g.appendChild(label);
    n.el = g;
    n.label = label;
    nodes.appendChild(g);
    makeDraggable(n);
  });

  function redraw() {
    persons.forEach(function (p) {
      var n = byId[p.id];
      n.el.setAttribute('transform', 'translate(' + n.x + ',' + n.y + ')');
    });
    (tree.relations || []).forEach(function (rel) {
      if (!rel._line) return;
      var a = byId[rel.person_a_id], b = byId[rel.person_b_id];
      if (!a || !b) return;
      rel._line.setAttribute('x1', a.x);
      rel._line.setAttribute('y1', a.y);
      rel._line.setAttribute('x2', b.x);
      rel._line.setAttribute('y2', b.y);
    });
  }
  redraw();

  var scale = 1;
  var tx = 0, ty = 0;
  function applyView() {
    viewport.setAttribute('transform', 'translate(' + tx + ',' + ty + ') scale(' + scale + ')');
  }
  function zoomBy(factor) {
    scale = Math.max(0.4, Math.min(2.5, scale * factor));
    applyView();
  }
  var buttons = host.parentNode.querySelectorAll('[data-graph-action]');
  Array.prototype.forEach.call(buttons, function (btn) {
    btn.addEventListener('click', function () {
      var action = btn.getAttribute('data-graph-action');
      if (action === 'zoom-in') zoomBy(1.2);
      if (action === 'zoom-out') zoomBy(0.83);
      if (action === 'reset') { scale = 1; tx = 0; ty = 0; applyView(); }
    });
  });

  function pointer(event) {
    if (event.touches && event.touches.length) return { x: event.touches[0].clientX, y: event.touches[0].clientY };
    return { x: event.clientX, y: event.clientY };
  }

  function makeDraggable(n) {
    var start = null;
    function down(e) {
      e.preventDefault();
      e.stopPropagation();
      start = { px: pointer(e).x, py: pointer(e).y, x: n.x, y: n.y };
      n.el.style.cursor = 'grabbing';
      document.addEventListener('mousemove', move);
      document.addEventListener('mouseup', up);
      document.addEventListener('touchmove', move, { passive: false });
      document.addEventListener('touchend', up);
    }
    function move(e) {
      if (!start) return;
      e.preventDefault();
      n.x = start.x + (pointer(e).x - start.px) / scale;
      n.y = start.y + (pointer(e).y - start.py) / scale;
      redraw();
    }
    function up() {
      start = null;
      n.el.style.cursor = 'grab';
      document.removeEventListener('mousemove', move);
      document.removeEventListener('mouseup', up);
      document.removeEventListener('touchmove', move);
      document.removeEventListener('touchend', up);
    }
    n.el.addEventListener('mousedown', down);
    n.el.addEventListener('touchstart', down, { passive: false });
  }

  var panStart = null;
  function panDown(e) {
    if (e.target !== svg && e.target.tagName !== 'g') return;
    panStart = { px: pointer(e).x, py: pointer(e).y, x: tx, y: ty };
    document.addEventListener('mousemove', panMove);
    document.addEventListener('mouseup', panUp);
    document.addEventListener('touchmove', panMove, { passive: false });
    document.addEventListener('touchend', panUp);
  }
  function panMove(e) {
    if (!panStart) return;
    e.preventDefault();
    tx = panStart.x + pointer(e).x - panStart.px;
    ty = panStart.y + pointer(e).y - panStart.py;
    applyView();
  }
  function panUp() {
    panStart = null;
    document.removeEventListener('mousemove', panMove);
    document.removeEventListener('mouseup', panUp);
    document.removeEventListener('touchmove', panMove);
    document.removeEventListener('touchend', panUp);
  }
  svg.addEventListener('mousedown', panDown);
  svg.addEventListener('touchstart', panDown, { passive: false });
  svg.addEventListener('wheel', function (e) {
    e.preventDefault();
    zoomBy(e.deltaY < 0 ? 1.1 : 0.9);
  }, { passive: false });
})();
