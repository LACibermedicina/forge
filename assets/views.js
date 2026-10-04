/* =====================================================================
   TestForge AI — views: dashboard, kanban, anotações, administração
   Gráficos desenhados em SVG (sem CDN) — funcionam offline.
   ===================================================================== */
'use strict';

/* ---------------------------------------------------------------- */
/*  Gráficos SVG                                                    */
/* ---------------------------------------------------------------- */
function lineChart(series, thr, opts) {
  const o = opts || {}, W = 960, H = o.h || 300, pad = { l: 46, r: 18, t: 18, b: 34 };
  const n = series.length;
  if (!n) return '<p class="mut sm">' + esc(t('Nenhum item')) + '</p>';
  const iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
  const x = (i) => n === 1 ? pad.l + iw / 2 : pad.l + (i * iw) / (n - 1);
  const y = (v) => pad.t + ih - (Math.max(0, Math.min(100, v)) / 100) * ih;
  let grid = '', gy = '';
  for (let g = 0; g <= 4; g++) {
    const val = g * 25, yy = y(val);
    grid += '<line x1="' + pad.l + '" y1="' + yy + '" x2="' + (W - pad.r) + '" y2="' + yy + '" stroke="#1d4b58" stroke-width="1" stroke-dasharray="3 5"/>';
    gy += '<text x="' + (pad.l - 8) + '" y="' + (yy + 4) + '" fill="#8fb3bb" font-size="11" text-anchor="end">' + val + '%</text>';
  }
  const pts = series.map((s, i) => x(i) + ',' + y(s.score)).join(' ');
  const area = pad.l + ',' + y(0) + ' ' + pts + ' ' + x(n - 1) + ',' + y(0);
  const tend = series.map((s, i) => s.trend).filter(v => v != null);
  const tpts = tend.length === n ? series.map((s, i) => x(i) + ',' + y(s.trend)).join(' ') : null;
  const ai = series.map(s => s.ai_score);
  const hasAi = ai.some(v => v != null && v > 0);
  const aipts = hasAi ? series.map((s, i) => x(i) + ',' + y(s.ai_score == null ? 0 : s.ai_score)).join(' ') : null;
  const thrLine = '<line x1="' + pad.l + '" y1="' + y(thr) + '" x2="' + (W - pad.r) + '" y2="' + y(thr) + '" stroke="#ffc857" stroke-width="1.6" stroke-dasharray="7 5"/>' +
    '<text x="' + (W - pad.r) + '" y="' + (y(thr) - 6) + '" fill="#ffc857" font-size="11" text-anchor="end">' + esc(t('Limiar')) + ' ' + thr + '%</text>';
  let dots = '';
  series.forEach((s, i) => {
    const c = s.verdict === 'aprovado' ? '#3ddc84' : '#ff6b6b';
    dots += '<circle cx="' + x(i) + '" cy="' + y(s.score) + '" r="4.4" fill="' + c + '" stroke="#071318" stroke-width="1.4"><title>' +
      esc(s.label + '  ' + s.score + '%  ' + s.verdict) + '</title></circle>';
  });
  let ticks = '';
  const step = Math.max(1, Math.ceil(n / 8));
  series.forEach((s, i) => { if (i % step === 0 || i === n - 1) ticks += '<text x="' + x(i) + '" y="' + (H - 12) + '" fill="#8fb3bb" font-size="10" text-anchor="middle">' + esc(s.short || '') + '</text>'; });
  let bars = '';
  series.forEach((s, i) => {
    const bw = Math.max(5, Math.min(14, iw / Math.max(1, n) * .45));
    const tot = s.pass + s.fail + s.partial + s.blocked;
    if (!tot) return;
    let acc = 0;
    [['fail', '#ff6b6b'], ['partial', '#ffc857'], ['blocked', '#a78bfa'], ['pass', '#3ddc84']].forEach(([k, col]) => {
      const hh = (s[k] / tot) * (ih * .30), xx = x(i) - bw / 2, yy = pad.t + ih - acc - hh;
      if (hh > 0) bars += '<rect x="' + xx + '" y="' + yy + '" width="' + bw + '" height="' + hh + '" fill="' + col + '" opacity=".82"/>';
      acc += hh;
    });
  });
  return '<svg class="chart" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet">' +
    '<defs><linearGradient id="ga" x1="0" y1="0" x2="0" y2="1">' +
    '<stop offset="0%" stop-color="#31e0c8" stop-opacity=".35"/><stop offset="100%" stop-color="#31e0c8" stop-opacity="0"/></linearGradient></defs>' +
    grid + gy + bars +
    '<polygon points="' + area + '" fill="url(#ga)"/>' +
    (aipts ? '<polyline points="' + aipts + '" fill="none" stroke="#a78bfa" stroke-width="1.6" stroke-dasharray="5 4" opacity=".8"/>' : '') +
    (tpts ? '<polyline points="' + tpts + '" fill="none" stroke="#4cc9f0" stroke-width="2" opacity=".85"/>' : '') +
    '<polyline points="' + pts + '" fill="none" stroke="#31e0c8" stroke-width="2.6"/>' +
    thrLine + dots + ticks + '</svg>' +
    '<div class="legend">' +
    '<span><i style="background:#31e0c8"></i>' + esc(t('Nota')) + '</span>' +
    (tpts ? '<span><i style="background:#4cc9f0"></i>' + esc(t('Evolução acumulada')) + '</span>' : '') +
    (aipts ? '<span><i style="background:#a78bfa"></i>' + esc(t('Nota da IA')) + '</span>' : '') +
    '<span><i style="background:#ffc857"></i>' + esc(t('Ponto de excelência')) + '</span>' +
    '<span class="xs">' + esc(t('Resultado')) + ': <i style="background:#3ddc84"></i>passou <i style="background:#ffc857"></i>parcial <i style="background:#a78bfa"></i>bloqueado <i style="background:#ff6b6b"></i>falhou</span>' +
    '</div>';
}

function donut(parts) {
  const total = parts.reduce((a, p) => a + p.n, 0);
  if (!total) return '<p class="mut sm">' + esc(t('Nenhum item')) + '</p>';
  const cx = 88, cy = 88, r = 62, sw = 22;
  let acc = 0, paths = '';
  parts.forEach(p => {
    const frac = p.n / total, a0 = acc * 2 * Math.PI - Math.PI / 2, a1 = (acc + frac) * 2 * Math.PI - Math.PI / 2;
    acc += frac;
    const large = frac > .5 ? 1 : 0;
    const x0 = cx + r * Math.cos(a0), y0 = cy + r * Math.sin(a0), x1 = cx + r * Math.cos(a1), y1 = cy + r * Math.sin(a1);
    paths += frac >= .9999
      ? '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" stroke="' + p.color + '" stroke-width="' + sw + '"/>'
      : '<path d="M ' + x0 + ' ' + y0 + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + x1 + ' ' + y1 + '" fill="none" stroke="' + p.color + '" stroke-width="' + sw + '" stroke-linecap="butt"/>';
  });
  return '<div class="row" style="gap:16px;align-items:center"><svg viewBox="0 0 176 176" style="width:168px;height:168px;flex:0 0 auto">' + paths +
    '<text x="88" y="84" text-anchor="middle" fill="#e6f6f7" font-size="26" font-weight="700">' + total + '</text>' +
    '<text x="88" y="103" text-anchor="middle" fill="#8fb3bb" font-size="11">' + esc(t('Total de itens')) + '</text></svg>' +
    '<div style="flex:1">' + parts.map(p => '<div class="row between sm" style="margin-bottom:5px"><span><i style="display:inline-block;width:11px;height:11px;border-radius:3px;background:' + p.color + ';margin-right:6px"></i>' + esc(t(p.k)) + '</span><b>' + p.n + ' · ' + Math.round(100 * p.n / total) + '%</b></div>').join('') + '</div></div>';
}

function hbars(rows, color) {
  if (!rows.length) return '<p class="mut sm">' + esc(t('Nenhum item')) + '</p>';
  return rows.map(r => {
    const pct = Math.max(0, Math.min(100, Number(r.rate == null ? 0 : r.rate)));
    const bad = pct < 70;
    return '<div style="margin-bottom:11px"><div class="row between sm"><span><b class="mono">' + esc(r.code || r.dimension || r.title || '—') + '</b> <span class="mut xs">' + esc(r.title || '') + '</span></span>' +
      '<span class="' + (bad ? 'b-no badge' : 'b-ok badge') + '">' + pct + '%</span></div>' +
      '<div class="bar' + (bad ? ' bad' : '') + '"><span style="width:' + pct + '%"></span></div>' +
      '<div class="xs mut">' + (r.total || r.n || 0) + ' ' + esc(t('Total de itens')) + '</div></div>';
  }).join('');
}

/* ---------------------------------------------------------------- */
/*  Dashboard                                                       */
/* ---------------------------------------------------------------- */
async function renderDashboard() {
  $('pane').innerHTML = '<div class="card"><span class="spin" style="border-top-color:#31e0c8"></span> ' + esc(t('Recarregar')) + '…</div>';
  let d;
  try { d = await API('stats&project_id=' + S.project.id, null); }
  catch (e) { $('pane').innerHTML = '<div class="card">' + esc(e.message) + '</div>'; return; }
  S.stats = d;

  const series = (d.series || []).map(s => Object.assign({}, s, { short: (s.label || '').slice(0, 10), trend: null }));
  let run = null;
  series.forEach((s, i) => { run = run === null ? s.score : ((run * i) + s.score) / (i + 1); s.trend = Math.round(run * 100) / 100; });

  const k = d.kpi, counts = k.counts;
  const sev = d.severity || [];
  const cSev = { critico: '#ff6b6b', alto: '#ff9f45', medio: '#ffc857', baixo: '#4cc9f0' };
  const sevTotal = sev.reduce((a, s) => a + Number(s.n), 0);
  const passRate = Number(k.pass_rate || 0);
  const gap = Math.round((k.avg_score == null ? 0 : k.avg_score) - k.threshold);

  $('pane').innerHTML =
    '<div class="grid g4" style="margin-bottom:14px">' +
      statCard(k.avg_score == null ? '—' : k.avg_score + '%', 'Aprovação média') +
      statCard(k.last_score == null ? '—' : k.last_score + '%', 'Nota') +
      statCard(passRate + '%', 'Taxa de aprovação') +
      statCard(k.entries, 'Registros') +
    '</div>' +
    '<div class="grid g4" style="margin-bottom:14px">' +
      statCard(k.best == null ? '—' : k.best + '%', 'Melhor resultado') +
      statCard(k.threshold + '%', 'Índice de excelência') +
      statCard(k.backlog_open, 'Backlog aberto') +
      statCard(k.backlog_done, 'Solucionado') +
    '</div>' +

    '<div class="card" style="margin-bottom:14px">' +
      '<div class="row between wrap"><h3 style="margin:0">' + esc(t('Evolução dos testes no tempo')) + '</h3>' +
      '<span class="badge ' + (gap >= 0 ? 'b-ok' : 'b-no') + '">' + (gap >= 0 ? '+' : '') + gap + '% vs ' + esc(t('Ponto de excelência')) + '</span></div>' +
      '<p class="mut xs">' + esc(t('Detectado automaticamente')) + ' — timestamp ' + esc(t('por @menção')) + '</p>' +
      lineChart(series, k.threshold, { h: 320 }) +
    '</div>' +

    '<div class="grid g3" style="margin-bottom:14px">' +
      '<div class="card"><h3>' + esc(t('Acertos / Falhas')) + '</h3>' +
        donut([{ k: 'passou', n: counts.pass, color: '#3ddc84' }, { k: 'parcial', n: counts.partial, color: '#ffc857' },
               { k: 'bloqueado', n: counts.blocked, color: '#a78bfa' }, { k: 'falhou', n: counts.fail, color: '#ff6b6b' }]) + '</div>' +
      '<div class="card"><h3>' + esc(t('Severidade das falhas')) + '</h3>' +
        donut(sev.map(s => ({ k: s.severity || 'n/d', n: Number(s.n), color: cSev[s.severity] || '#8fb3bb' }))) +
        '<div class="xs mut">' + sevTotal + ' ' + esc(t('Total de itens')) + '</div></div>' +
      '<div class="card"><h3>' + esc(t('Distribuição do backlog')) + '</h3>' +
        donut((d.kanban || []).map((c, i) => ({ k: c.title, n: c.n, color: ['#4cc9f0', '#31e0c8', '#ffc857', '#a78bfa', '#3ddc84'][i % 5] }))) + '</div>' +
    '</div>' +

    '<div class="grid g2" style="margin-bottom:14px">' +
      '<div class="card"><h3>' + esc(t('Por critério')) + '</h3>' + hbars(d.criteria || []) + '</div>' +
      '<div class="card"><h3>' + esc(t('Por dimensão')) + '</h3>' + hbars(d.dimensions || []) + '</div>' +
    '</div>' +

    '<div class="grid g2">' +
      '<div class="card"><h3>' + esc(t('Falhas mais recorrentes')) + '</h3>' +
        (d.problems || []).length ? '<table><thead><tr><th>' + esc(t('Problema')) + '</th><th>Crit.</th><th>Sev.</th><th>N</th></tr></thead><tbody>' +
          d.problems.map(p => '<tr><td class="sm">' + esc(p.problem) + '</td><td class="mono">' + esc(p.criterion || '') + '</td>' +
          '<td><span class="badge ' + (cls[p.severity] || '') + '">' + esc(t(p.severity || '')) + '</span></td><td><b>' + p.n + '</b></td></tr>').join('') + '</tbody></table>'
          : '<p class="mut sm">' + esc(t('Nenhum item')) + '</p></div>' +
      '<div class="card"><h3>' + esc(t('Testadores')) + '</h3>' +
        (d.testers || []).length ? '<table><thead><tr><th>Dev</th><th>' + esc(t('Registros')) + '</th><th>' + esc(t('Aprovação média')) + '</th></tr></thead><tbody>' +
          d.testers.map(x => '<tr><td>@' + esc(x.handle) + '<div class="xs mut">' + esc(x.name) + '</div></td><td>' + x.n + '</td><td><b>' + x.avg_score + '%</b></td></tr>').join('') + '</tbody></table>'
          : '<p class="mut sm">' + esc(t('Nenhum item')) + '</p>' + '</div>' +
    '</div>' +

    '<div class="card" style="margin-top:14px"><h3>' + esc(t('Anotações')) + '</h3>' +
      ((d.annotations || []).length ? d.annotations.slice(0, 12).map(a => '<div class="note' + (a.kind === 'ia' ? '' : '') + '"><div class="sm">' + esc(a.text) + '</div><div class="xs mut">' + esc(a.author_handle || 'sistema') + ' · ' + esc(a.kind) + ' · ' + esc(a.created_at) + '</div></div>').join('')
        : '<p class="mut sm">' + esc(t('Nenhum item')) + '</p>') + '</div>';

  if (S.autoTimer) clearInterval(S.autoTimer);
  const secs = 45;
  S.autoTimer = setInterval(() => { if (S.tab !== 'dashboard') { clearInterval(S.autoTimer); S.autoTimer = null; return; } renderDashboard(); }, secs * 1000);
}

/* ---------------------------------------------------------------- */
/*  Kanban persistente                                              */
/* ---------------------------------------------------------------- */
async function renderKanban() {
  $('pane').innerHTML = '<div class="card"><span class="spin" style="border-top-color:#31e0c8"></span>…</div>';
  let d;
  try { d = await API('kanban_list&project_id=' + S.project.id, null); }
  catch (e) { $('pane').innerHTML = '<div class="card">' + esc(e.message) + '</div>'; return; }
  S.kanban = d.cards || []; S.cols = d.columns || [];
  drawKanban();
}

function drawKanban() {
  const canEdit = S.user.role === 'admin' || S.user.role === 'solicitante' || S.user.role === 'testador';
  $('pane').innerHTML =
    '<div class="row between wrap" style="margin-bottom:12px">' +
      '<h3 style="margin:0">' + esc(t('Kanban (backlog)')) + '</h3>' +
      '<div class="row"><input id="kbTitle" placeholder="' + esc(t('Adicionar cartão')) + '" style="width:250px">' +
      '<button class="sm2" id="kbAdd">+ ' + esc(t('Adicionar cartão')) + '</button>' +
      '<button class="btn ghost sm2" id="kbSave">' + esc(t('Salvar')) + '</button>' +
      '<button class="btn ghost sm2" id="kbCsv">' + esc(t('Exportar CSV')) + '</button></div>' +
    '</div>' +
    '<p class="mut xs">' + esc(t('Cartão movido e salvo automaticamente')) + ' — arraste entre colunas.</p>' +
    '<div class="kb">' + S.cols.map(c => {
      const cards = S.kanban.filter(x => x.status === c.id);
      return '<div class="col" data-col="' + c.id + '"><h4><span>' + esc(t(c.title)) + '</span><span class="badge xs">' + cards.length + '</span></h4>' +
        cards.map(card => kcard(card)).join('') + '</div>';
    }).join('') + '</div>';

  $('kbAdd').onclick = async () => {
    const title = $('kbTitle').value.trim(); if (!title) return;
    try { await API('kanban_card_create', { project_id: S.project.id, title: title }); $('kbTitle').value = ''; await saveKanban(true); await renderKanban(); toast(t('Cartão criado')); }
    catch (e) { toast(e.message, true); }
  };
  $('kbSave').onclick = async () => { await saveKanban(); toast(t('Salvo')); };
  $('kbCsv').onclick = () => { window.location = 'api.php?action=export&project_id=' + S.project.id; };
  bindDrag();
}

function kcard(c) {
  return '<div class="kcard" draggable="true" data-id="' + c.id + '">' +
    '<div class="t">' + esc(c.title) + '</div>' +
    (c.description ? '<div class="d">' + esc(c.description) + '</div>' : '') +
    '<div class="chips"><span class="badge ' + (cls[c.severity] || '') + '">' + esc(t(c.severity || '')) + '</span>' +
    '<span class="badge">' + esc(c.priority || '') + '</span>' +
    (c.criterion ? '<span class="badge mono">' + esc(c.criterion) + '</span>' : '') +
    (c.category ? '<span class="badge">' + esc(c.category) + '</span>' : '') + '</div>' +
    '<div class="xs mut" style="margin-top:6px">' + esc(fdate(Date.parse((c.updated_at || '').replace(' ', 'T')) || Date.now())) + '</div>' +
    '<div class="row" style="margin-top:6px"><button class="btn ghost sm2" data-del="' + c.id + '">✕</button></div>' +
    '</div>';
}

function bindDrag() {
  let dragEl = null;
  document.querySelectorAll('.kcard').forEach(el => {
    el.addEventListener('dragstart', e => { dragEl = el; el.classList.add('dragging'); e.dataTransfer.setData('text/plain', el.dataset.id); });
    el.addEventListener('dragend', () => { el.classList.remove('dragging'); dragEl = null; document.querySelectorAll('.col').forEach(c => c.classList.remove('hot')); saveKanban(); });
    el.querySelectorAll('[data-del]').forEach(b => b.onclick = async (ev) => {
      ev.stopPropagation();
      if (!confirm(t('Confirmar exclusão') + '?')) return;
      try { await API('kanban_card_delete', { id: Number(b.dataset.del) }); await renderKanban(); } catch (e) { toast(e.message, true); }
    });
  });
  document.querySelectorAll('.col').forEach(col => {
    col.addEventListener('dragover', e => { e.preventDefault(); col.classList.add('hot'); });
    col.addEventListener('dragleave', () => col.classList.remove('hot'));
    col.addEventListener('drop', e => {
      e.preventDefault(); col.classList.remove('hot');
      if (!dragEl) return;
      col.appendChild(dragEl);
      const id = Number(dragEl.dataset.id), card = S.kanban.find(c => c.id === id);
      if (card) card.status = col.dataset.col;
      orderFromDom();
      saveKanban();
    });
  });
}

function orderFromDom() {
  document.querySelectorAll('.col').forEach(col => {
    const status = col.dataset.col;
    [...col.querySelectorAll('.kcard')].forEach((el, i) => {
      const c = S.kanban.find(x => x.id === Number(el.dataset.id));
      if (c) { c.status = status; c.position = i; }
    });
  });
}

async function saveKanban(silent) {
  orderFromDom();
  try {
    const r = await API('kanban_save', { project_id: S.project.id, cards: S.kanban.map(c => ({ id: c.id, status: c.status, position: c.position, title: c.title, description: c.description, severity: c.severity, priority: c.priority, category: c.category, criterion: c.criterion, evidence_note: c.evidence_note })) });
    if (!silent) toast(t('Cartão movido e salvo automaticamente') + ' (' + r.moved + ')');
    return r;
  } catch (e) { toast(e.message, true); }
}

/* ---------------------------------------------------------------- */
/*  Anotações                                                       */
/* ---------------------------------------------------------------- */
async function renderNotes() {
  $('pane').innerHTML = '<div class="card"><span class="spin" style="border-top-color:#31e0c8"></span>…</div>';
  let d, e;
  try { d = await API('annotations_list&project_id=' + S.project.id, null); } catch (err) { d = { annotations: [] }; }
  try { e = await API('feedback_list&project_id=' + S.project.id + '&limit=40', null); } catch (err) { e = { entries: [] }; }
  const ans = d.annotations || [], ents = e.entries || [];
  $('pane').innerHTML =
    '<div class="grid g2">' +
      '<div class="card"><h3>' + esc(t('Anotações')) + '</h3>' +
        '<label>' + esc(t('Adicionar cartão')) + '</label><textarea id="anText" style="min-height:80px"></textarea>' +
        '<button class="sm2" id="anAdd" style="margin-top:8px">' + esc(t('Salvar')) + '</button>' +
        '<div style="margin-top:14px">' + (ans.length ? ans.map(a =>
          '<div class="note"><div class="sm">' + esc(a.text) + '</div><div class="row between"><span class="xs mut">' + esc(a.author_handle || 'sistema') + ' · ' + esc(a.kind) + ' · ' + esc(a.created_at) + '</span><button class="btn ghost sm2" data-an="' + a.id + '">✕</button></div></div>').join('')
          : '<p class="mut sm">' + esc(t('Nenhum item')) + '</p>') + '</div></div>' +
      '<div class="card"><h3>' + esc(t('Registros')) + '</h3>' +
        (ents.length ? ents.map(x => {
          const st = x.structured || {};
          return '<div class="card" style="margin-bottom:9px;padding:11px"><div class="row between"><b class="sm">' + esc(x.verdict) + ' · ' + x.score + '%</b>' +
            '<span class="badge ' + (cls[x.verdict] || '') + '">' + esc(fdate(x.epoch_ms)) + '</span></div>' +
            '<div class="sm mut">' + esc(st.overall_summary || '') + '</div>' +
            '<div class="xs mut">@' + esc(x.tester_handle || '') + ' · pass ' + x.pass_n + ' / fail ' + x.fail_n + ' / parcial ' + x.partial_n + ' / bloq ' + x.blocked_n + '</div></div>';
        }).join('') : '<p class="mut sm">' + esc(t('Nenhum item')) + '</p>') + '</div>' +
    '</div>';
  $('anAdd').onclick = async () => {
    const txt = $('anText').value.trim(); if (!txt) return;
    try { await API('annotation_add', { project_id: S.project.id, text: txt }); $('anText').value = ''; renderNotes(); } catch (err) { toast(err.message, true); }
  };
  $('pane').querySelectorAll('[data-an]').forEach(b => b.onclick = async () => {
    try { await API('annotation_delete', { id: Number(b.dataset.an) }); renderNotes(); } catch (e) { toast(e.message, true); }
  });
}

/* ---------------------------------------------------------------- */
/*  Administração                                                   */
/* ---------------------------------------------------------------- */
async function renderAdmin() {
  const m = $('main');
  const tabs = [['params', 'Parâmetros do sistema'], ['users', 'Usuários'], ['ai', 'Diagnóstico de IA']];
  if (!S.adminTab) S.adminTab = 'params';
  m.innerHTML = '<h1>' + esc(t('Administração')) + '</h1><div class="tabs">' +
    tabs.map(x => '<div class="tab' + (S.adminTab === x[0] ? ' on' : '') + '" data-t="' + x[0] + '">' + esc(t(x[1])) + '</div>').join('') + '</div><div id="pane"></div>';
  m.querySelectorAll('.tab').forEach(n => n.onclick = () => { S.adminTab = n.dataset.t; renderAdmin(); });
  if (S.adminTab === 'params') return renderParams();
  if (S.adminTab === 'users') return renderUsers();
  return renderAiDiag();
}

async function renderParams() {
  $('pane').innerHTML = '<div class="card"><span class="spin" style="border-top-color:#31e0c8"></span>…</div>';
  let d; try { d = await API('settings_get'); } catch (e) { $('pane').innerHTML = '<div class="card">' + esc(e.message) + '</div>'; return; }
  const s = d.settings, w = JSON.parse(s.weights || '{}'), sev = JSON.parse(s.severity_scale || '{}'), pen = JSON.parse(s.severity_penalty || '{}'), rw = JSON.parse(s.result_weights || '{}');
  const wsum = Object.values(w).reduce((a, b) => a + Number(b), 0);

  $('pane').innerHTML =
    '<div class="grid g2">' +
      '<div class="card"><h3>' + esc(t('Índice de excelência')) + '</h3>' +
        '<div class="grid g2">' + fld('excellence_threshold', t('Meta de aprovação (%)'), s.excellence_threshold, 'number', '0', '100') +
        fld('score_policy', t('Política de nota'), s.score_policy, 'select', '', '', [['sistema', t('Recalculado por pesos do sistema')], ['ia', t('Nota da IA')]]) + '</div>' +
        '<h4 style="margin-top:14px">' + esc(t('Pesos por dimensão (%)')) + '</h4>' +
        '<p class="xs ' + (Math.abs(wsum - 100) > .01 ? '' : 'mut') + '">Σ = ' + wsum + ' (o sistema normaliza para 100)' + '</p>' +
        Object.keys(w).map(k => fld('w_' + k, k, w[k], 'number', '0', '100')).join('') +
      '</div>' +
      '<div class="card"><h3>' + esc(t('Escala de severidade')) + '</h3>' +
        Object.keys(sev).map(k => '<div class="grid g2">' + fld('sev_' + k, t(k) + ' — ' + t('Escala de severidade'), sev[k], 'number', '1', '10') +
          fld('pen_' + k, t('Problema') + ' ' + t(k) + ' (%)', pen[k], 'number', '0', '100') + '</div>').join('') +
        '<h4 style="margin-top:14px">' + esc(t('Resultado')) + '</h4>' +
        Object.keys(rw).map(k => fld('rw_' + k, t(k), rw[k], 'number', '0', '1')).join('') +
      '</div>' +
      '<div class="card"><h3>' + esc(t('Colunas do kanban')) + '</h3>' +
        JSON.parse(s.kanban_columns || '[]').map((c, i) => '<div class="grid g2">' + fld('col_id_' + i, 'id', c.id) + fld('col_t_' + i, 'título', c.title) + '</div>').join('') +
      '</div>' +
      '<div class="card"><h3>' + esc(t('Modelo de IA')) + '</h3>' +
        '<div class="grid g2">' +
          fld('ai_model', t('Modelo de IA'), s.ai_model) +
          fld('ai_temperature', t('Temperatura da IA'), s.ai_temperature, 'number', '0', '1', null, '0.05') +
        '</div>' +
        '<div class="grid g2">' +
          fld('ai_auto_backlog', t('Backlog automático'), s.ai_auto_backlog, 'select', '', '', [['1', 'ON'], ['0', 'OFF']]) +
          fld('ai_auto_annotations', t('Anotações automáticas'), s.ai_auto_annotations, 'select', '', '', [['1', 'ON'], ['0', 'OFF']]) +
          fld('ai_analyze_images', t('Analisar prints com IA'), s.ai_analyze_images, 'select', '', '', [['1', 'ON'], ['0', 'OFF']]) +
          fld('require_evidence', t('Exigir evidência'), s.require_evidence, 'select', '', '', [['1', 'ON'], ['0', 'OFF']]) +
          fld('max_upload_mb', t('Tamanho máx. de upload (MB)'), s.max_upload_mb, 'number', '1', '64') +
          fld('dashboard_refresh_s', t('Atualizar gráficos (s)'), s.dashboard_refresh_s, 'number', '10', '600') +
        '</div>' +
        '<h4 style="margin-top:14px">' + esc(t('Idiomas disponíveis')) + '</h4>' +
        fld('langs', 'pt-BR, es, en', (JSON.parse(s.langs || '[]')).join(', ')) +
      '</div>' +
    '</div>' +
    '<div class="row" style="margin-top:14px"><button id="btSaveSet">' + esc(t('Salvar alterações')) + '</button>' +
    '<span class="mut sm">' + esc(t('Parâmetro administrativo alterado')) + '</span></div>';

  $('btSaveSet').onclick = async () => {
    const g = (id) => { const el = $(id); return el ? el.value : null; };
    const patch = {
      excellence_threshold: g('excellence_threshold'), score_policy: g('score_policy'),
      ai_model: g('ai_model'), ai_temperature: g('ai_temperature'),
      ai_auto_backlog: g('ai_auto_backlog'), ai_auto_annotations: g('ai_auto_annotations'),
      ai_analyze_images: g('ai_analyze_images'), require_evidence: g('require_evidence'),
      max_upload_mb: g('max_upload_mb'), dashboard_refresh_s: g('dashboard_refresh_s'),
      langs: g('langs').split(',').map(x => x.trim()).filter(Boolean),
      weights: {}, severity_scale: {}, severity_penalty: {}, result_weights: {}, kanban_columns: [],
    };
    Object.keys(w).forEach(k => patch.weights[k] = Number(g('w_' + k)));
    Object.keys(sev).forEach(k => { patch.severity_scale[k] = Number(g('sev_' + k)); patch.severity_penalty[k] = Number(g('pen_' + k)); });
    Object.keys(rw).forEach(k => patch.result_weights[k] = Number(g('rw_' + k)));
    JSON.parse(s.kanban_columns || '[]').forEach((c, i) => patch.kanban_columns.push({ id: g('col_id_' + i), title: g('col_t_' + i) }));
    busy($('btSaveSet'), true);
    try { await API('settings_save', { settings: patch }); toast(t('Salvo')); renderParams(); }
    catch (e) { toast(e.message, true); busy($('btSaveSet'), false); }
  };
}

function fld(id, label, value, type, min, max, opts, step) {
  if (type === 'select') {
    return '<div><label>' + esc(label) + '</label><select id="' + id + '">' +
      (opts || []).map(o => '<option value="' + esc(o[0]) + '"' + (String(value) === String(o[0]) ? ' selected' : '') + '>' + esc(o[1]) + '</option>').join('') + '</select></div>';
  }
  return '<div><label>' + esc(label) + '</label><input id="' + id + '" type="' + (type || 'text') + '"' +
    (min !== undefined && min !== '' ? ' min="' + min + '"' : '') + (max !== undefined && max !== '' ? ' max="' + max + '"' : '') +
    (step ? ' step="' + step + '"' : '') + ' value="' + esc(value) + '"></div>';
}

async function renderUsers() {
  $('pane').innerHTML = '<div class="card"><span class="spin" style="border-top-color:#31e0c8"></span>…</div>';
  let d; try { d = await API('users_list'); } catch (e) { $('pane').innerHTML = '<div class="card">' + esc(e.message) + '</div>'; return; }
  const us = d.users || [];
  $('pane').innerHTML =
    '<div class="card" style="margin-bottom:14px"><h3>' + esc(t('Usuários')) + '</h3>' +
      '<table><thead><tr><th>Nome</th><th>@handle</th><th>' + esc(t('E-mail')) + '</th><th>' + esc(t('Perfil')) + '</th><th>Ativo</th><th></th></tr></thead><tbody>' +
      us.map(u => '<tr><td>' + esc(u.name) + '</td><td class="mono">@' + esc(u.handle) + '</td><td class="sm">' + esc(u.email) + '</td>' +
        '<td><span class="badge ' + (u.role === 'admin' ? 'b-partial' : (u.role === 'testador' ? 'b-pass' : '')) + '">' + esc(u.role) + '</span></td>' +
        '<td>' + (u.active ? '<span class="badge b-ok">ON</span>' : '<span class="badge b-no">OFF</span>') + '</td>' +
        '<td><button class="btn ghost sm2" data-role="' + esc(u.role) + '" data-handle="' + esc(u.handle) + '" data-email="' + esc(u.email) + '" data-name="' + esc(u.name) + '" data-id="' + u.id + '" data-active="' + u.active + '">' + esc(t('Salvar')) + '</button>' +
        '<button class="btn danger sm2" data-del="' + u.id + '">✕</button></td></tr>').join('') + '</tbody></table></div>' +
    '<div class="card"><h3>+ ' + esc(t('Usuários')) + '</h3><div class="grid g3">' +
      fld('nuName', t('Nome')) + fld('nuHandle', t('@handle (usado nas menções)')) + fld('nuEmail', t('E-mail')) +
      fld('nuPass', t('Senha (mín. 5)'), '', 'password') +
      '<div><label>' + esc(t('Perfil')) + '</label><select id="nuRole"><option value="solicitante">' + esc(t('Dev solicitante')) + '</option><option value="testador">' + esc(t('Dev testador')) + '</option><option value="admin">admin</option></select></div>' +
    '</div><button class="sm2" id="nuAdd" style="margin-top:10px">' + esc(t('Criar conta')) + '</button></div>';

  $('nuAdd').onclick = async () => {
    try {
      await API('user_save', { name: $('nuName').value, handle: $('nuHandle').value, email: $('nuEmail').value, password: $('nuPass').value, role: $('nuRole').value, active: 1 });
      toast(t('Usuário salvo')); renderUsers();
    } catch (e) { toast(e.message, true); }
  };
  $('pane').querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
    if (!confirm(t('Confirmar exclusão') + '?')) return;
    try { await API('user_delete', { id: Number(b.dataset.del) }); toast(t('Usuário excluído')); renderUsers(); } catch (e) { toast(e.message, true); }
  });
  $('pane').querySelectorAll('[data-role]').forEach(b => b.onclick = async () => {
    const name = prompt(t('Nome'), b.dataset.name); if (name === null) return;
    const role = prompt('admin | solicitante | testador', b.dataset.role); if (!role) return;
    const active = confirm(t('Ativo') + '?') ? 1 : 0;
    try { await API('user_save', { id: Number(b.dataset.id), name: name, email: b.dataset.email, handle: b.dataset.handle, role: role.trim(), active: active }); toast(t('Usuário salvo')); renderUsers(); }
    catch (e) { toast(e.message, true); }
  });
}

async function renderAiDiag() {
  $('pane').innerHTML = '<div class="card"><span class="spin" style="border-top-color:#31e0c8"></span> ' + esc(t('Processando com IA…')) + '</div>';
  let d; try { d = await API('ai_health'); } catch (e) { $('pane').innerHTML = '<div class="card">' + esc(e.message) + '</div>'; return; }
  const kp = d.keys_present || {};
  $('pane').innerHTML =
    '<div class="card" style="margin-bottom:14px"><h3>' + esc(t('Diagnóstico de IA')) + '</h3>' +
      '<div class="grid g3">' + Object.keys(kp).map(k => statCard(kp[k] ? 'OK' : '—', k)) .join('') + '</div>' +
      '<div class="ai-msg" style="margin-top:12px">' + (d.probe && d.probe.ok ? '✅ Provedor ativo: <b>' + esc(d.probe.provider) + '</b>' : '⚠ Nenhum provedor respondeu: ' + esc((d.probe || {}).error || 'erro')) + '</div>' +
    '</div>' +
    '<div class="card"><h3>Cadeia de provedores</h3><table><thead><tr><th>#</th><th>Rótulo</th><th>Tipo</th><th>Modelo</th><th>Endpoint</th><th>Chave</th></tr></thead><tbody>' +
      (d.providers || []).map((p, i) => '<tr><td>' + (i + 1) + '</td><td class="mono">' + esc(p.label) + '</td><td>' + esc(p.kind) + '</td><td class="mono">' + esc(p.model) + '</td><td class="sm mono">' + esc(p.base) + '</td><td class="mono xs">' + esc(p.key_masked) + '</td></tr>').join('') +
    '</tbody></table><p class="mut xs" style="margin-top:10px">As chaves ficam apenas no <code>.env</code> do servidor — nenhuma é enviada ao navegador.</p></div>';
}
