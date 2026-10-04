/* =====================================================================
   TestForge AI — front-end
   Todo acesso à IA passa por api.php (as chaves nunca chegam aqui).
   Gráficos são SVG próprios (sem CDN) para funcionar offline.
   ===================================================================== */
'use strict';

/* ---------------------------------------------------------------- */
/*  i18n — PT-BR é a fonte; ES/EN são gerados pela IA e ficam em    */
/*  cache no SQLite do servidor (api.php?action=i18n).              */
/* ---------------------------------------------------------------- */
const SRC = [
  'Entrar','Cadastrar','E-mail ou @handle','Senha','Nome','@handle (usado nas menções)','E-mail',
  'Senha (mín. 5)','Perfil','Dev solicitante','Dev testador','Criar conta','Sair','Projetos','+ Novo',
  'Chaves de IA ficam apenas no servidor',
  'Iniciar módulo de testagem','Solicitações em texto livre (uma ou várias)','@ dev solicitante mencionado no texto',
  'Processar com IA e gerar plano','Descreva o que precisa ser testado, sem formato — a IA estrutura',
  'Arraste prints aqui ou clique para enviar','Enviar feedback de testes','Relato livre do testador',
  'Estruturar com IA','Plano de testagem','Critérios','Casos de teste','Riscos e ambiguidades',
  'Dashboard','Kanban (backlog)','Anotações','Administração','Parâmetros do sistema','Usuários',
  'Diagnóstico de IA','Evolução dos testes no tempo','Índice de excelência','Aprovação média','Registros',
  'Melhor resultado','Backlog aberto','Solucionado','Acertos / Falhas','Taxa de aprovação','Por critério',
  'Por dimensão','Severidade das falhas','Distribuição do backlog','Falhas mais recorrentes','Testadores',
  'Salvar','Salvar alterações','Adicionar cartão','Excluir','Exportar CSV','Fechar','Recarregar',
  'Nenhum projeto ainda','Crie o primeiro projeto descrevendo as solicitações de testagem em texto livre.',
  'Nenhum item','Enviando…','Processando com IA…','Analisando relato e prints…','Salvo',
  'Cartão movido e salvo automaticamente','Erro','Sucesso','Confirmar exclusão',
  'Meta de aprovação (%)','Pesos por dimensão (%)','Política de nota','Escala de severidade',
  'Colunas do kanban','Idiomas disponíveis','Modelo de IA','Temperatura da IA','Backlog automático',
  'Anotações automáticas','Analisar prints com IA','Exigir evidência','Tamanho máx. de upload (MB)',
  'Atualizar gráficos (s)','Fonte da nota','Recalculado por pesos do sistema','Nota da IA',
  'Detectado automaticamente','Sem acesso','Total de itens','Aprovado','Reprovado','Em testes',
  'A fazer','Em correção','Em reteste','Bloqueado','Solucionado','crítico','alto','médio','baixo',
  'passou','falhou','parcial','bloqueado','Nota','Meta','Resultado','Critério','Caso','Dimensão',
  'Problema','Evidência','Anotação da IA','Print','prints anexados','Anexos analisados pela IA',
  'Plano gerado por','Menções detectadas','Responsabilidades','Escopo','Fora de escopo','Resumo',
  'Identificadores de dev (@)','Parâmetro administrativo alterado','Usuário salvo','Usuário excluído',
  'Projeto excluído','Plano reprocessado com IA','Feedback estruturado e gravado','Cartão criado',
  'Ação não permitida para o seu perfil','Sessão expirada — faça login novamente',
  'Ponto de excelência','Evolução acumulada','Nota da IA','Limiar'
];
const I18N = { 'pt-BR': {} };
let LANG = localStorage.getItem('tf_lang') || 'pt-BR';
const t = (s) => (I18N[LANG] && I18N[LANG][s]) ? I18N[LANG][s] : s;
SRC.forEach(s => I18N['pt-BR'][s] = s);

async function loadI18n(lang) {
  if (lang === 'pt-BR' || I18N[lang]) return;
  try {
    const r = await API('i18n', { lang: lang, strings: SRC });
    if (r && r.map) I18N[lang] = r.map;
  } catch (e) { I18N[lang] = {}; }
}
function applyI18n(root) {
  (root || document).querySelectorAll('[data-i18n]').forEach(el => { el.textContent = t(el.dataset.i18n); });
  (root || document).querySelectorAll('[data-i18n-ph]').forEach(el => { el.placeholder = t(el.dataset.i18nPh); });
}

/* ---------------------------------------------------------------- */
/*  Estado                                                          */
/* ---------------------------------------------------------------- */
const S = { csrf: '', user: null, projects: [], project: null, stats: null, kanban: [], cols: [], entries: [], tab: 'overview', imgs: [], charts: null, autoTimer: null };

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fdate = (ms) => { if (!ms) return '—'; const d = new Date(Number(ms)); return d.toLocaleString(LANG === 'en' ? 'en-US' : (LANG === 'es' ? 'es-ES' : 'pt-BR'), { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }); };
const cls = { pass: 'b-pass', fail: 'b-fail', partial: 'b-partial', blocked: 'b-blocked', critico: 'b-critico', alto: 'b-alto', medio: 'b-medio', baixo: 'b-baixo', aprovado: 'b-ok', reprovado: 'b-no' };

function toast(msg, isErr) {
  const d = document.createElement('div');
  if (isErr) d.className = 'err';
  d.textContent = msg;
  $('toast').appendChild(d);
  setTimeout(() => d.remove(), isErr ? 8000 : 4200);
}

async function API(action, payload, isForm) {
  const url = 'api.php?action=' + encodeURIComponent(action);
  const opt = { method: (payload || isForm) ? 'POST' : 'GET', headers: {} };
  if (S.csrf) opt.headers['X-CSRF-Token'] = S.csrf;
  if (payload) {
    if (isForm) { opt.body = payload; }
    else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(payload); }
  }
  const res = await fetch(url, opt);
  let j = null;
  try { j = await res.json(); } catch (e) { throw new Error('Resposta inválida do servidor (HTTP ' + res.status + ')'); }
  if (j && j.csrf) S.csrf = j.csrf;
  if (!j || j.ok !== true) {
    const msg = (j && j.error) ? j.error : ('HTTP ' + res.status);
    if (res.status === 401) { showLogin(); }
    throw new Error(msg);
  }
  return j.data;
}
const busy = (btn, on, label) => {
  if (!btn) return;
  if (on) { btn.dataset.old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spin"></span> ' + (label || t('Enviando…')); }
  else { btn.disabled = false; btn.innerHTML = btn.dataset.old || btn.innerHTML; }
};

/* ---------------------------------------------------------------- */
/*  Boot                                                            */
/* ---------------------------------------------------------------- */
(async function boot() {
  buildLangSeg('langSegLogin');
  bindLogin();
  try {
    const b = await API('bootstrap');
    S.csrf = b.csrf;
    $('verTag').textContent = 'v' + b.version;
    if (b.demo_hint) {
      $('demoBox').innerHTML = 'Demo: <code>admin@testforge.local / admin123</code> · ' +
        '<code>solicitante@testforge.local / dev123</code> · <code>testador@testforge.local / dev123</code>';
    }
    if (b.user) { S.user = b.user; await enterApp(); }
    else showLogin();
  } catch (e) { showLogin(); toast(e.message, true); }
})();

function showLogin() { $('login').style.display = 'grid'; $('app').style.display = 'none'; }
function showApp() { $('login').style.display = 'none'; $('app').style.display = 'block'; }

function bindLogin() {
  $('tbLogin').onclick = () => { $('tbLogin').classList.add('on'); $('tbReg').classList.remove('on'); $('paneLogin').classList.remove('hide'); $('paneReg').classList.add('hide'); };
  $('tbReg').onclick = () => { $('tbReg').classList.add('on'); $('tbLogin').classList.remove('on'); $('paneReg').classList.remove('hide'); $('paneLogin').classList.add('hide'); };
  $('btLogin').onclick = async () => {
    busy($('btLogin'), true); 
    try { const d = await API('login', { email: $('liEmail').value, password: $('liPass').value }); S.user = d.user; await enterApp(); }
    catch (e) { toast(e.message, true); } finally { busy($('btLogin'), false); }
  };
  $('liPass').onkeydown = (e) => { if (e.key === 'Enter') $('btLogin').click(); };
  $('btReg').onclick = async () => {
    busy($('btReg'), true);
    try {
      const d = await API('register', { name: $('rgName').value, handle: $('rgHandle').value, email: $('rgEmail').value, password: $('rgPass').value, role: $('rgRole').value });
      S.user = d.user; await enterApp();
    } catch (e) { toast(e.message, true); } finally { busy($('btReg'), false); }
  };
}

function buildLangSeg(id) {
  const el = $(id); if (!el) return;
  el.innerHTML = ['pt-BR', 'es', 'en'].map(l => '<button data-l="' + l + '" class="' + (LANG === l ? 'on' : '') + '">' + (l === 'pt-BR' ? 'PT' : l.toUpperCase()) + '</button>').join('');
  el.querySelectorAll('button').forEach(b => b.onclick = async () => {
    LANG = b.dataset.l; localStorage.setItem('tf_lang', LANG);
    el.querySelectorAll('button').forEach(x => x.classList.toggle('on', x.dataset.l === LANG));
    document.querySelectorAll('.seg[id^=langSeg] button').forEach(x => x.classList.toggle('on', x.dataset.l === LANG));
    busy(b, true, 'IA…');
    await loadI18n(LANG);
    busy(b, false);
    applyI18n(); renderSide(); renderMain();
  });
}

async function enterApp() {
  showApp();
  $('meName').textContent = S.user.name;
  $('meRole').textContent = '@' + S.user.handle + ' · ' + S.user.role;
  $('btLogout').onclick = async () => { try { await API('logout', {}); } catch (e) {} location.reload(); };
  $('btNew').onclick = () => { S.project = null; S.tab = 'new'; renderMain(); };
  $('btNew').style.display = (S.user.role === 'admin' || S.user.role === 'solicitante') ? '' : 'none';
  buildLangSeg('langSeg');
  await loadI18n(LANG);
  applyI18n();
  await loadProjects();
  if (S.project) renderMain(); else if (S.user.role === 'admin') { S.tab = 'admin'; renderMain(); }
}

async function loadProjects() {
  try {
    const d = await API('projects_list');
    S.projects = d.projects || [];
    renderSide();
  } catch (e) { toast(e.message, true); }
}

function renderSide() {
  const el = $('plist');
  if (!S.projects.length) { el.innerHTML = '<div class="mut sm" style="padding:8px">' + esc(t('Nenhum projeto ainda')) + '</div>'; return; }
  el.innerHTML = S.projects.map(p => {
    const st = p.stats || {};
    const sc = st.avg_score == null ? '—' : Math.round(st.avg_score) + '%';
    const on = S.project && S.project.id === p.id ? ' on' : '';
    return '<div class="pitem' + on + '" data-id="' + p.id + '">' +
      '<div class="row between"><b class="sm">' + esc(p.name) + '</b><span class="badge xs">' + esc(sc) + '</span></div>' +
      '<div class="xs mut">' + (st.entries || 0) + ' ' + esc(t('Registros')) + ' · ' + (st.open_backlog || 0) + ' backlog · ' + esc(p.status || '') + '</div>' +
      '</div>';
  }).join('');
  el.querySelectorAll('.pitem').forEach(n => n.onclick = () => openProject(Number(n.dataset.id)));
}

async function openProject(id) {
  try {
    const d = await API('project_get&id=' + id, null);
    S.project = d.project; S.entries = []; S.stats = null; S.kanban = [];
    S.tab = (S.user.role === 'testador') ? 'feedback' : 'overview';
    renderSide(); await renderMain();
  } catch (e) { toast(e.message, true); }
}

/* ---------------------------------------------------------------- */
/*  Render principal                                                */
/* ---------------------------------------------------------------- */
async function renderMain() {
  const m = $('main');
  if (S.tab === 'admin' && !S.project) { return renderAdmin(); }
  if (S.tab === 'new') { return renderNewProject(); }
  if (!S.project) {
    m.innerHTML = '<div class="card" style="text-align:center;padding:44px">' +
      '<h2>' + esc(t('Nenhum projeto ainda')) + '</h2><p class="mut">' + esc(t('Crie o primeiro projeto descrevendo as solicitações de testagem em texto livre.')) + '</p>' +
      ((S.user.role === 'admin' || S.user.role === 'solicitante') ? '<button onclick="S.tab=\'new\';renderMain()">' + esc(t('Iniciar módulo de testagem')) + '</button>' : '') +
      '</div>';
    return;
  }
  const p = S.project;
  const tabs = [['overview', 'Plano de testagem']];
  if (S.user.role === 'admin' || S.user.role === 'testador') tabs.push(['feedback', 'Enviar feedback de testes']);
  tabs.push(['dashboard', 'Dashboard'], ['kanban', 'Kanban (backlog)'], ['notes', 'Anotações']);
  if (S.user.role === 'admin') tabs.push(['admin', 'Administração']);
  if (tabs.every(x => x[0] !== S.tab)) S.tab = 'overview';

  m.innerHTML =
    '<div class="row between wrap">' +
      '<div><h1 style="margin-bottom:2px">' + esc(p.name) + '</h1>' +
      '<div class="mut sm">' + esc(t('Plano gerado por')) + ' <span class="mono xs">' + esc(p.plan_source || '—') + '</span> · ' +
      esc(t('Índice de excelência')) + ': <b>' + p.threshold + '%</b></div></div>' +
      '<div class="row"><span class="badge ' + (p.status === 'em_testes' ? 'b-medio' : 'b-ok') + '">' + esc(p.status) + '</span>' +
      '<button class="btn ghost sm2" id="btReload">' + esc(t('Recarregar')) + '</button>' +
      '<button class="btn ghost sm2" id="btCsv">' + esc(t('Exportar CSV')) + '</button>' +
      (S.user.role === 'admin' ? '<button class="btn danger sm2" id="btDelProj">' + esc(t('Excluir')) + '</button>' : '') +
      '</div>' +
    '</div>' +
    '<div class="tabs">' + tabs.map(x => '<div class="tab' + (S.tab === x[0] ? ' on' : '') + '" data-t="' + x[0] + '">' + esc(t(x[1])) + '</div>').join('') + '</div>' +
    '<div id="pane"></div>';

  m.querySelectorAll('.tab').forEach(n => n.onclick = () => { S.tab = n.dataset.t; renderMain(); });
  $('btReload').onclick = () => openProject(p.id);
  $('btCsv').onclick = () => { window.location = 'api.php?action=export&project_id=' + p.id; };
  if ($('btDelProj')) $('btDelProj').onclick = async () => {
    if (!confirm(t('Confirmar exclusão') + '?')) return;
    try { await API('project_delete', { id: p.id }); S.project = null; await loadProjects(); renderMain(); toast(t('Projeto excluído')); }
    catch (e) { toast(e.message, true); }
  };

  if (S.tab === 'overview') renderOverview();
  else if (S.tab === 'feedback') renderFeedback();
  else if (S.tab === 'dashboard') await renderDashboard();
  else if (S.tab === 'kanban') await renderKanban();
  else if (S.tab === 'notes') await renderNotes();
  else if (S.tab === 'admin') renderAdmin();
}

/* ---------------------------- Plano ------------------------------ */
function renderOverview() {
  const p = S.project, plan = p.plan || {};
  const devs = (p.mentions || []).map(d => '<span class="badge ' + (d.role_in_proj === 'solicitante' ? 'b-partial' : 'b-pass') + '">@' + esc(d.dev_handle) + ' · ' + esc(d.role_in_proj) + '</span>').join(' ') || '<span class="mut sm">—</span>';
  const crit = (p.criteria || []).map(c =>
    '<tr><td class="mono">' + esc(c.code) + '</td><td><b>' + esc(c.title) + '</b><div class="xs mut">' + esc(c.description || '') + '</div></td>' +
    '<td>' + esc(c.category || '') + '</td><td>' + c.weight + '%</td></tr>').join('') || '<tr><td colspan="4" class="mut">' + esc(t('Nenhum item')) + '</td></tr>';
  const tcs = (plan.test_cases || []).map(tc =>
    '<tr><td class="mono">' + esc(tc.id || '') + '</td><td><b>' + esc(tc.title || '') + '</b>' +
    '<div class="xs mut">' + esc((tc.steps || []).map((s, i) => (i + 1) + ') ' + s).join(' ')) + '</div>' +
    '<div class="xs"><span class="badge">' + esc(t('Meta')) + ': ' + esc(tc.expected || '') + '</span></div></td>' +
    '<td class="mono">' + esc(tc.criterion || '') + '</td><td><span class="badge ' + (cls[tc.severity_if_fail] || '') + '">' + esc(t(tc.severity_if_fail || '')) + '</span></td></tr>').join('')
    || '<tr><td colspan="4" class="mut">' + esc(t('Nenhum item')) + '</td></tr>';

  $('pane').innerHTML =
    '<div class="grid g4" style="margin-bottom:14px">' +
      statCard(S.project.stats.avg_score == null ? '—' : Math.round(S.project.stats.avg_score) + '%', 'Aprovação média') +
      statCard(S.project.stats.entries || 0, 'Registros') +
      statCard(S.project.stats.open_backlog || 0, 'Backlog aberto') +
      statCard(S.project.stats.done_backlog || 0, 'Solucionado') +
    '</div>' +
    '<div class="grid g2">' +
      '<div class="card"><h3>' + esc(t('Resumo')) + '</h3><p>' + esc(plan.summary || '—') + '</p>' +
        '<h4>' + esc(t('Escopo')) + '</h4><p class="sm">' + esc(plan.test_scope || '—') + '</p>' +
        '<h4>' + esc(t('Fora de escopo')) + '</h4><ul class="sm mut">' + (plan.out_of_scope || []).map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' +
      '</div>' +
      '<div class="card"><h3>' + esc(t('Identificadores de dev (@)')) + '</h3><div class="chips">' + devs + '</div>' +
        '<h4 style="margin-top:14px">' + esc(t('Riscos e ambiguidades')) + '</h4>' +
        '<ul class="sm">' + (plan.risks || []).map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' +
        (plan.ambiguities || []).length ? '<div class="ai-msg">' + (plan.ambiguities || []).map(x => '⚠ ' + esc(x)).join('<br>') + '</div>' : '' +
      '</div>' +
    '</div>' +
    '<div class="card" style="margin-top:14px"><h3>' + esc(t('Critérios')) + '</h3>' +
      '<table><thead><tr><th>Código</th><th>' + esc(t('Critério')) + '</th><th>' + esc(t('Dimensão')) + '</th><th>Peso</th></tr></thead><tbody>' + crit + '</tbody></table></div>' +
    '<div class="card" style="margin-top:14px"><h3>' + esc(t('Casos de teste')) + '</h3>' +
      '<table><thead><tr><th>ID</th><th>Descrição</th><th>' + esc(t('Critério')) + '</th><th>Severidade</th></tr></thead><tbody>' + tcs + '</tbody></table></div>' +
    '<div class="card" style="margin-top:14px"><h3>' + esc(t('Solicitações originais (texto livre)')) + '</h3><pre>' + esc(p.raw_request || '') + '</pre>' +
      (S.user.role === 'admin' || S.user.id === p.requester_id ?
        '<div style="margin-top:10px"><textarea id="rePlan" placeholder="' + esc(t('Descreva o que precisa ser testado, sem formato — a IA estrutura')) + '"></textarea>' +
        '<button class="sm2" id="btReplan">' + esc(t('Reprocessar plano com IA')) + '</button></div>' : '') + '</div>';

  if ($('btReplan')) $('btReplan').onclick = async () => {
    const txt = $('rePlan').value.trim();
    busy($('btReplan'), true, t('Processando com IA…'));
    try { const d = await API('plan_reprocess', { id: S.project.id, raw_request: txt }); S.project = d.project; toast(t('Plano reprocessado com IA')); renderMain(); }
    catch (e) { toast(e.message, true); busy($('btReplan'), false); }
  };
}
const statCard = (v, k) => '<div class="stat"><div class="k">' + esc(t(k)) + '</div><div class="v">' + esc(v) + '</div></div>';

/* --------------------------- Novo projeto ------------------------ */
function renderNewProject() {
  $('main').innerHTML =
    '<h1>' + esc(t('Iniciar módulo de testagem')) + '</h1>' +
    '<p class="mut sm">' + esc(t('Descreva o que precisa ser testado, sem formato — a IA estrutura')) + '</p>' +
    '<div class="card" style="margin-top:14px">' +
      '<label>' + esc(t('Solicitações em texto livre (uma ou várias)')) + '</label>' +
      '<textarea id="rawReq" style="min-height:230px" placeholder="' + esc(t('@ dev solicitante mencionado no texto')) + ' — ex.: &quot;@ana pediu para validar login com 2FA, o @bruno vai testar o fluxo de pagamento; precisamos garantir que..." ></textarea>' +
      '<div style="margin-top:12px">' +
        '<label>' + esc(t('Arraste prints aqui ou clique para enviar')) + '</label>' +
        '<div class="dz" id="dzNew">🖼 ' + esc(t('Arraste prints aqui ou clique para enviar')) + '<input type="file" id="fNew" multiple accept="image/*" class="hide"></div>' +
        '<div class="thumbs" id="thNew"></div>' +
      '</div>' +
      '<div class="row" style="margin-top:14px"><button id="btInit">' + esc(t('Processar com IA e gerar plano')) + '</button>' +
      '<span class="mut sm">' + esc(t('Detectado automaticamente')) + ': @menções, critérios, casos de teste, pesos</span></div>' +
    '</div>';
  const up = bindDropzone($('dzNew'), $('fNew'), $('thNew'));
  $('btInit').onclick = async () => {
    const raw = $('rawReq').value.trim();
    if (raw.length < 20) return toast('Descreva as solicitações com mais detalhe.', true);
    busy($('btInit'), true, t('Processando com IA…'));
    try {
      const d = await API('project_init', { raw_request: raw, image_ids: up.ids() });
      S.project = d.project; S.tab = 'overview';
      await loadProjects();
      toast(t('Sucesso') + ' · ' + (d.mentions_detected || []).join(' '));
      renderMain();
    } catch (e) { toast(e.message, true); busy($('btInit'), false); }
  };
}

/* --------------------------- Feedback --------------------------- */
function renderFeedback() {
  $('pane').innerHTML =
    '<div class="grid g2">' +
      '<div class="card"><h3>' + esc(t('Relato livre do testador')) + '</h3>' +
        '<label>@' + esc(S.user.handle) + ' — ' + esc(t('Estruturar com IA')) + '</label>' +
        '<textarea id="fbText" style="min-height:260px" placeholder="Escreva como quiser: o que passou, o que falhou, onde travou..."></textarea>' +
        '<div style="margin-top:12px">' +
          '<label>' + esc(t('Arraste prints aqui ou clique para enviar')) + '</label>' +
          '<div class="dz" id="dzFb">📸 ' + esc(t('Arraste prints aqui ou clique para enviar')) + '<input type="file" id="fFb" multiple accept="image/*" class="hide"></div>' +
          '<div class="thumbs" id="thFb"></div>' +
        '</div>' +
        '<div class="row" style="margin-top:14px"><button id="btFb">' + esc(t('Estruturar com IA')) + '</button>' +
        '<span class="mut xs">' + esc(t('Analisando relato e prints…')) + '</span></div>' +
      '</div>' +
      '<div class="card"><h3>' + esc(t('Resultado estruturado')) + '</h3><div id="fbOut" class="mut sm">' + esc(t('Nenhum item')) + '</div></div>' +
    '</div>';
  const up = bindDropzone($('dzFb'), $('fFb'), $('thFb'));
  $('btFb').onclick = async () => {
    const txt = $('fbText').value.trim(), ids = up.ids();
    if (!txt && !ids.length) return toast('Escreva o relato ou anexe prints.', true);
    busy($('btFb'), true, t('Analisando relato e prints…'));
    try {
      const d = await API('feedback_create', { project_id: S.project.id, raw_text: txt, image_ids: ids });
      const e = d.entry;
      const items = (e.items || []).map(i =>
        '<tr><td class="mono">' + esc(i.criterion || '—') + '<div class="xs mut">' + esc(i.test_case || '') + '</div></td>' +
        '<td><span class="badge ' + (cls[i.result] || '') + '">' + esc(t(i.result)) + '</span></td>' +
        '<td><span class="badge ' + (cls[i.severity] || '') + '">' + esc(t(i.severity)) + '</span></td>' +
        '<td class="sm">' + esc(i.problem || i.note || '') + (i.annotation ? '<div class="xs mut">💡 ' + esc(i.annotation) + '</div>' : '') + '</td></tr>').join('');
      $('fbOut').innerHTML =
        '<div class="grid g2" style="margin-bottom:10px">' + statCard(e.score + '%', 'Nota') + statCard(e.verdict, e.verdict) + '</div>' +
        '<div class="sm">' + esc((e.structured || {}).overall_summary || '') + '</div>' +
        '<table style="margin-top:10px"><thead><tr><th>' + esc(t('Critério')) + '</th><th>' + esc(t('Resultado')) + '</th><th>Severidade</th><th>' + esc(t('Problema')) + '</th></tr></thead><tbody>' + items + '</tbody></table>' +
        ((e.images || []).length ? '<h4 style="margin-top:12px">' + esc(t('Anexos analisados pela IA')) + '</h4><div class="thumbs">' +
          e.images.map(im => '<div class="thumb"><img src="' + im.url + '"><div class="nm">' + esc(im.ai_caption || im.original_name || '') + '</div></div>').join('') + '</div>' : '') +
        '<p class="sm mut" style="margin-top:10px">Cartões criados no backlog: <b>' + (e.cards_created || 0) + '</b></p>';
      toast(t('Feedback estruturado e gravado'));
      S.entries = []; S.stats = null; await loadProjects();
      up.clear();
    } catch (err) { toast(err.message, true); }
    busy($('btFb'), false);
  };
}

/* --------------------------- Dropzone --------------------------- */
function bindDropzone(dz, input, thumbs) {
  const ids = [];
  const add = (f) => {
    const fd = new FormData();
    fd.append('file', f);
    if (S.project) fd.append('project_id', S.project.id);
    const el = document.createElement('div');
    el.className = 'thumb'; el.innerHTML = '<div class="nm">…</div>';
    thumbs.appendChild(el);
    API('upload_image', fd, true).then(d => {
      ids.push(d.image.id);
      el.innerHTML = '<img src="' + d.image.url + '"><div class="nm">' + esc(d.image.name) + '</div><div class="x">✕</div>';
      el.querySelector('.x').onclick = () => { const i = ids.indexOf(d.image.id); if (i >= 0) ids.splice(i, 1); el.remove(); };
    }).catch(e => { el.remove(); toast(e.message, true); });
  };
  dz.onclick = () => input.click();
  input.onchange = () => { [...input.files].forEach(add); input.value = ''; };
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('hot'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('hot'));
  dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('hot'); [...(e.dataTransfer.files || [])].forEach(f => { if (f.type.startsWith('image/')) add(f); }); });
  return { ids: () => ids, clear: () => { ids.length = 0; thumbs.innerHTML = ''; } };
}
