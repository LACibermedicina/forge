<?php
/**
 * TestForge AI — API única (roteador JSON)
 * Todo o tráfego passa por aqui; nenhuma chave de IA chega ao navegador.
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/ai.php';

boot_session();
$action = (string)($_GET['action'] ?? param('action', ''));

/* ------------------------------------------------------------------ */
/*  Guarda de CSRF para métodos mutantes                               */
/* ------------------------------------------------------------------ */
function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
    if (!current_user()) { return; }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (string)param('csrf', '');
    $own  = $_SESSION['csrf'] ?? '';
    if ($own === '' || !hash_equals($own, (string)$sent)) {
        fail('Token CSRF inválido ou ausente. Recarregue a página.', 419);
    }
}

/* ------------------------------------------------------------------ */
/*  Helpers de projeto                                                 */
/* ------------------------------------------------------------------ */
function project_or_404(int $id): array
{
    $p = one('SELECT * FROM projects WHERE id = ?', [$id]);
    if (!$p) { fail('Projeto não encontrado.', 404); }
    return $p;
}

function project_mention(int $projectId, array $needles): bool
{
    $rows = all('SELECT dev_handle, user_id, role_in_proj FROM mentions WHERE project_id = ?', [$projectId]);
    foreach ($rows as $r) {
        foreach ($needles as $n) {
            $n = handle_norm((string)$n);
            if ($n !== '' && ($r['dev_handle'] === $n || (int)$r['user_id'] === (int)$n)) { return true; }
        }
    }
    return false;
}

function can_access(array $u, array $p, bool $write = false): bool
{
    if ($u['role'] === 'admin') { return true; }
    if ((int)$p['requester_id'] === (int)$u['id']) { return true; }
    return project_mention((int)$p['id'], [handle_norm($u['handle']), (string)$u['id']]);
}

function guard_project(int $id, bool $write = false): array
{
    $u = require_login();
    $p = project_or_404($id);
    if (!can_access($u, $p, $write)) { fail('Você não participa deste projeto.', 403); }
    return $p;
}

function extract_mentions(string $text): array
{
    preg_match_all('/@([\p{L}\p{N}][\p{L}\p{N}._-]{0,40})/u', $text, $m);
    $out = [];
    foreach ($m[1] as $raw) {
        $h = handle_norm($raw);
        if ($h !== '') { $out[$h] = '@' . ltrim($raw, '@'); }
    }
    return array_values($out);
}

function project_payload(array $p): array
{
    $pid = (int)$p['id'];
    $p['plan']        = jdec($p['plan_json']);
    unset($p['plan_json']);
    $p['criteria']    = all('SELECT * FROM criteria WHERE project_id = ? ORDER BY weight DESC, id', [$pid]);
    $p['mentions']    = all('SELECT * FROM mentions WHERE project_id = ? ORDER BY id', [$pid]);
    $p['requester']   = one('SELECT id,name,handle,email FROM users WHERE id = ?', [(int)$p['requester_id']]);
    $agg = one('SELECT COUNT(*) n, ROUND(AVG(score),2) avg_score, MAX(epoch_ms) last_ms, MAX(score) best
                FROM feedback_entries WHERE project_id = ?', [$pid]) ?: [];
    $last = one('SELECT * FROM feedback_entries WHERE project_id = ? ORDER BY epoch_ms DESC LIMIT 1', [$pid]);
    $p['stats'] = [
        'entries'       => (int)($agg['n'] ?? 0),
        'avg_score'     => $agg['avg_score'] !== null ? (float)$agg['avg_score'] : null,
        'best_score'    => $agg['best'] !== null ? (float)$agg['best'] : null,
        'last_ms'       => $agg['last_ms'] !== null ? (int)$agg['last_ms'] : null,
        'last_score'    => ($last && $last['score'] !== null) ? (float)$last['score'] : null,
        'open_backlog'  => (int)(one("SELECT COUNT(*) c FROM kanban_cards WHERE project_id = ? AND status <> 'done'", [$pid])['c'] ?? 0),
        'done_backlog'  => (int)(one("SELECT COUNT(*) c FROM kanban_cards WHERE project_id = ? AND status = 'done'", [$pid])['c'] ?? 0),
    ];
    return $p;
}

/** Converte imagens do banco em partes base64 para a IA. */
function images_for_ai(array $ids, int $maxBytes = 4_000_000): array
{
    $out = [];
    foreach ($ids as $id) {
        $img = one('SELECT * FROM images WHERE id = ?', [(int)$id]);
        if (!$img) { continue; }
        $abs = APP_ROOT . '/' . ltrim((string)$img['path'], '/');
        if (!is_file($abs)) { continue; }
        $size = filesize($abs);
        if ($size === false || $size > $maxBytes) { continue; }
        $out[] = ['id' => (int)$img['id'], 'data' => base64_encode((string)file_get_contents($abs)),
                  'mime' => $img['mime'] ?: 'image/png', 'name' => $img['original_name']];
    }
    return $out;
}

/* ================================================================== */
/*  ROTEADOR                                                          */
/* ================================================================== */

/* ---------- Público ---------- */
if ($action === 'bootstrap') {
    $u = current_user();
    ok([
        'app'        => envv('APP_NAME', 'TestForge AI'),
        'version'    => APP_VERSION,
        'csrf'       => csrf_token(),
        'user'       => $u,
        'langs'      => setting_json('langs', ['pt-BR', 'es', 'en']),
        'default_lang' => (string)setting('default_lang', 'pt-BR'),
        'demo_hint'  => (int)(one('SELECT COUNT(*) c FROM users')['c'] ?? 0) <= 3 && (int)(one('SELECT COUNT(*) c FROM projects')['c'] ?? 0) === 0,
    ]);
}

if ($action === 'login') {
    require_csrf();
    $id   = trim((string)param('email', ''));
    $pass = (string)param('password', '');
    if ($id === '' || $pass === '') { fail('Informe e-mail (ou @handle) e senha.'); }
    $u = one('SELECT * FROM users WHERE (email = ? OR handle = ?) AND active = 1', [$id, handle_norm($id)]);
    if (!$u || !password_verify($pass, $u['pass_hash'])) { fail('Credenciais inválidas.', 401); }
    $_SESSION['uid'] = (int)$u['id'];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    audit((int)$u['id'], 'login', $u['email']);
    ok(['user' => $u, 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'register') {
    require_csrf();
    $name = s('name'); $email = mb_strtolower(s('email')); $pass = (string)param('password', '');
    $role = in_array(s('role'), ROLES, true) ? s('role') : 'testador';
    if ($name === '' || $email === '' || strlen($pass) < 5) { fail('Nome, e-mail e senha (mín. 5) obrigatórios.'); }
    if (one('SELECT id FROM users WHERE email = ?', [$email])) { fail('E-mail já cadastrado.'); }
    $handle = handle_norm(s('handle') !== '' ? s('handle') : strstr($email, '@', true));
    if ($handle === '') { $handle = 'dev' . random_int(1000, 9999); }
    if (one('SELECT id FROM users WHERE handle = ?', [$handle])) { $handle .= random_int(10, 99); }
    q('INSERT INTO users (name, handle, email, pass_hash, role, lang, active, created_at) VALUES (?,?,?,?,?,?,1,?)',
      [$name, $handle, $email, password_hash($pass, PASSWORD_DEFAULT), $role, (string)setting('default_lang', 'pt-BR'), now()]);
    $uid = (int)pdo()->lastInsertId();
    $_SESSION['uid'] = $uid;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    audit($uid, 'register', "$email ($role)");
    ok(['user' => one('SELECT * FROM users WHERE id = ?', [$uid]), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout') {
    $uid = $_SESSION['uid'] ?? null;
    audit($uid ? (int)$uid : null, 'logout');
    session_destroy();
    ok([]);
}

if ($action === 'i18n') {
    $lang = s('lang', 'pt-BR');
    $strings = param('strings', []);
    if (!is_array($strings) || !$strings) { fail('Envie "strings" como objeto {chave: texto}.'); }
    if (count($strings) > 400) { $strings = array_slice($strings, 0, 400, true); }
    $map = ($lang === 'pt-BR') ? array_map('strval', $strings) : ai_translate_batch(array_map('strval', $strings), $lang);
    ok(['lang' => $lang, 'map' => $map]);
}

if ($action === 'image') {
    $u = require_login();
    $img = one('SELECT * FROM images WHERE id = ?', [(int)param('id', 0)]);
    if (!$img) { fail('Imagem não encontrada.', 404); }
    if ($u['role'] !== 'admin' && $img['project_id'] && !can_access($u, project_or_404((int)$img['project_id']))) {
        fail('Sem acesso a esta imagem.', 403);
    }
    $abs = APP_ROOT . '/' . ltrim((string)$img['path'], '/');
    if (!is_file($abs)) { fail('Arquivo ausente no disco.', 410); }
    header('Content-Type: ' . ($img['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($abs));
    header('Cache-Control: private, max-age=3600');
    readfile($abs);
    exit;
}

/* ---------- Autenticado ---------- */
$USER = current_user();
if (!$USER) { fail('Autenticação necessária.', 401); }
require_csrf();

if ($action === 'me') { ok(['user' => $USER, 'csrf' => csrf_token()]); }

if ($action === 'ai_health') {
    require_role('admin');
    ok(ai_health());
}

/* ---------------- Configurações (área admin) ---------------- */
if ($action === 'settings_get') {
    require_role('admin');
    $out = [];
    foreach (settings_defaults() as $k => $v) { $out[$k] = setting($k, (string)$v); }
    ok(['settings' => $out, 'defaults' => settings_defaults()]);
}

if ($action === 'settings_save') {
    $u = require_role('admin');
    $patch = param('settings', []);
    if (!is_array($patch)) { fail('Formato inválido.'); }
    $allowed = array_keys(settings_defaults());
    $applied = [];
    foreach ($patch as $k => $v) {
        if (!in_array($k, $allowed, true)) { continue; }
        if (in_array($k, ['weights', 'severity_scale', 'severity_penalty', 'result_weights', 'kanban_columns', 'langs'], true)) {
            $dec = is_array($v) ? $v : json_decode((string)$v, true);
            if ($k === 'weights' && is_array($dec)) {
                $sum = array_sum(array_map('floatval', $dec));
                if ($sum > 0 && abs($sum - 100) > 0.01) {
                    foreach ($dec as $kk => $vv) { $dec[$kk] = round(((float)$vv / $sum) * 100, 2); }
                }
            }
            if ($k === 'langs' && (!is_array($dec) || !$dec)) { $dec = ['pt-BR', 'es', 'en']; }
            $v = json_encode($dec, JSON_UNESCAPED_UNICODE);
        }
        if ($k === 'excellence_threshold') { $v = max(0, min(100, (float)$v)); }
        set_setting($k, $v);
        $applied[] = $k;
    }
    audit((int)$u['id'], 'settings_save', implode(',', $applied));
    ok(['applied' => $applied]);
}

/* ---------------- Usuários ---------------- */
if ($action === 'users_list') {
    require_role('admin');
    ok(['users' => all('SELECT id,name,handle,email,role,lang,active,created_at FROM users ORDER BY role, name')]);
}

if ($action === 'user_save') {
    $adm = require_role('admin');
    $id = (int)param('id', 0);
    $name = s('name'); $email = mb_strtolower(s('email')); $role = s('role', 'testador');
    $handle = handle_norm(s('handle'));
    if ($name === '' || $email === '') { fail('Nome e e-mail são obrigatórios.'); }
    if (!in_array($role, ROLES, true)) { fail('Perfil inválido.'); }
    $active = (int)param('active', 1) ? 1 : 0;
    $pass = (string)param('password', '');
    if ($id > 0) {
        q('UPDATE users SET name=?, email=?, role=?, handle=?, active=? WHERE id=?',
          [$name, $email, $role, $handle !== '' ? $handle : 'dev' . $id, $active, $id]);
        if ($pass !== '') { q('UPDATE users SET pass_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]); }
    } else {
        if (strlen($pass) < 5) { fail('Senha mínima de 5 caracteres para novo usuário.'); }
        if (one('SELECT id FROM users WHERE email = ?', [$email])) { fail('E-mail já cadastrado.'); }
        q('INSERT INTO users (name,handle,email,pass_hash,role,lang,active,created_at) VALUES (?,?,?,?,?,?,?,?)',
          [$name, $handle !== '' ? $handle : 'dev' . random_int(1000, 9999), $email,
           password_hash($pass, PASSWORD_DEFAULT), $role, (string)setting('default_lang', 'pt-BR'), $active, now()]);
        $id = (int)pdo()->lastInsertId();
    }
    audit((int)$adm['id'], 'user_save', $email);
    ok(['id' => $id]);
}

if ($action === 'user_delete') {
    $adm = require_role('admin');
    $id = (int)param('id', 0);
    if ($id === (int)$adm['id']) { fail('Você não pode excluir a própria conta.'); }
    q('DELETE FROM users WHERE id = ?', [$id]);
    audit((int)$adm['id'], 'user_delete', (string)$id);
    ok([]);
}

/* ---------------- Projetos ---------------- */
if ($action === 'projects_list') {
    $u = require_login();
    if ($u['role'] === 'admin') {
        $rows = all('SELECT * FROM projects ORDER BY updated_at DESC');
    } else {
        $rows = all('SELECT DISTINCT p.* FROM projects p
                     LEFT JOIN mentions m ON m.project_id = p.id
                     WHERE p.requester_id = ? OR m.user_id = ? OR m.dev_handle = ?
                     ORDER BY p.updated_at DESC', [(int)$u['id'], (int)$u['id'], handle_norm($u['handle'])]);
    }
    ok(['projects' => array_map('project_payload', $rows)]);
}

if ($action === 'project_get') {
    $p = guard_project((int)param('id', 0));
    ok(['project' => project_payload($p)]);
}

if ($action === 'project_init') {
    $u = require_role('solicitante');
    $raw = s('raw_request');
    if (mb_strlen($raw) < 20) { fail('Descreva as solicitações de testagem em texto livre (mín. 20 caracteres).'); }

    $mentions  = extract_mentions($raw);
    $r = ai_json(prompt_plan(), $raw, [], 150);
    $degraded = false;
    if (!$r['ok']) {
        // contingência: quota de IA esgotada ou provedores fora — o módulo SEMPRE inicializa
        $r = ['ok' => true, 'data' => plan_fallback($raw), 'provider' => 'local-fallback',
              'model' => 'heurística determinística', 'error' => $r['error']];
        $degraded = true;
    }
    $plan = $r['data'];

    $name = trim((string)($plan['project_name'] ?? '')) ?: mb_substr(preg_replace('/\s+/', ' ', $raw) ?? 'Novo projeto', 0, 48);
    $now  = now();
    q('INSERT INTO projects (name, slug, requester_id, raw_request, plan_json, plan_source, threshold, status, created_at, updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$name, slugify($name), (int)$u['id'], $raw, json_encode($plan, JSON_UNESCAPED_UNICODE),
       $r['provider'] . '/' . $r['model'], (float)setting('excellence_threshold', 85), 'em_testes', $now, $now]);
    $pid = (int)pdo()->lastInsertId();

    // devs apontados no plano + menções cruas do texto
    $byHandle = [];
    foreach (($plan['devs'] ?? []) as $d) {
        $h = handle_norm((string)($d['handle'] ?? ''));
        if ($h === '') { continue; }
        $byHandle[$h] = ['handle' => $h, 'role' => ($d['role'] ?? 'testador') === 'solicitante' ? 'solicitante' : 'testador',
                         'duty' => (string)($d['responsibilities'] ?? '')];
    }
    $byHandle[handle_norm($u['handle'])] = ['handle' => handle_norm($u['handle']), 'role' => 'solicitante', 'duty' => 'Solicitante do módulo de testagem'];
    foreach ($mentions as $m) {
        $h = handle_norm($m);
        if (!isset($byHandle[$h])) {
            $known = one('SELECT id, role FROM users WHERE handle = ?', [$h]);
            $byHandle[$h] = ['handle' => $h, 'role' => ($known && $known['role'] === 'solicitante') ? 'solicitante' : 'testador',
                             'duty' => $known ? 'Dev mencionado no prompt inicial' : 'Dev mencionado (não cadastrado — será vinculado por @handle)'];
        }
    }
    foreach ($byHandle as $h => $info) {
        $known = one('SELECT id FROM users WHERE handle = ?', [$h]);
        q('INSERT INTO mentions (project_id, dev_handle, role_in_proj, user_id, duty, created_at) VALUES (?,?,?,?,?,?)',
          [$pid, $h, $info['role'], $known ? (int)$known['id'] : null, $info['duty'], $now]);
    }

    // critérios
    $wmap = [];
    foreach (($plan['criteria'] ?? []) as $c) {
        $code = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($c['code'] ?? 'CRIT')) ?: 'CRIT', 0, 12));
        $w = (float)($c['weight'] ?? 10);
        q('INSERT INTO criteria (project_id, code, title, description, category, weight) VALUES (?,?,?,?,?,?)',
          [$pid, $code, (string)($c['title'] ?? $code), (string)($c['description'] ?? ''),
           (string)($c['category'] ?? 'funcionalidade'), $w]);
        $wmap[$code] = $w;
    }
    if (!$wmap) {
        foreach (setting_json('weights') as $dim => $w) {
            $code = mb_strtoupper(substr($dim, 0, 6));
            q('INSERT INTO criteria (project_id, code, title, description, category, weight) VALUES (?,?,?,?,?,?)',
              [$pid, $code, ucfirst($dim), 'Critério derivado do peso padrão do sistema.', $dim, (float)$w]);
            $wmap[$code] = (float)$w;
        }
    }

    if (setting('ai_auto_annotations', 1)) {
        foreach (array_slice((array)($plan['ambiguities'] ?? []), 0, 5) as $i => $amb) {
            q('INSERT INTO annotations (project_id, target_type, author_id, kind, text, created_at) VALUES (?,?,?,?,?,?)',
              [$pid, 'plan', (int)$u['id'], 'ambiguidade', (string)$amb, $now]);
        }
    }
    audit((int)$u['id'], 'project_init', "projeto #$pid via {$r['provider']}");
    $p = project_or_404($pid);
    ok(['project' => project_payload($p), 'provider' => $r['provider'], 'model' => $r['model'],
        'degraded' => $degraded, 'ai_error' => $degraded ? ($r['error'] ?? '') : null,
        'mentions_detected' => array_values($mentions)]);
}

if ($action === 'project_save') {
    $u = require_login();
    $id = (int)param('id', 0);
    $p = guard_project($id);
    $fields = [];
    $args = [];
    foreach (['name', 'status'] as $f) {
        $v = param($f, null);
        if ($v !== null) { $fields[] = "$f = ?"; $args[] = (string)$v; }
    }
    if (param('threshold', null) !== null) {
        $fields[] = 'threshold = ?';
        $args[] = max(0, min(100, (float)param('threshold')));
    }
    if ($fields) {
        $args[] = $id;
        q('UPDATE projects SET ' . implode(', ', $fields) . ', updated_at = ? WHERE id = ?',
          array_merge(array_slice($args, 0, -1), [now(), $id]));
    }
    audit((int)$u['id'], 'project_save', (string)$id);
    ok(['project' => project_payload(project_or_404($id))]);
}

if ($action === 'project_delete') {
    $u = require_role('admin');
    $id = (int)param('id', 0);
    q('DELETE FROM projects WHERE id = ?', [$id]);
    audit((int)$u['id'], 'project_delete', (string)$id);
    ok([]);
}

if ($action === 'plan_reprocess') {
    $u = require_role('solicitante');
    $id = (int)param('id', 0);
    $p = guard_project($id);
    $raw = s('raw_request') ?: (string)$p['raw_request'];
    $r = ai_json(prompt_plan(), $raw, [], 150);
    if (!$r['ok']) {
        $r = ['ok' => true, 'data' => plan_fallback($raw), 'provider' => 'local-fallback', 'model' => 'heurística determinística'];
    }
    q('UPDATE projects SET plan_json = ?, plan_source = ?, raw_request = ?, updated_at = ? WHERE id = ?',
      [json_encode($r['data'], JSON_UNESCAPED_UNICODE), $r['provider'] . '/' . $r['model'], $raw, now(), $id]);
    q('DELETE FROM criteria WHERE project_id = ?', [$id]);
    foreach (($r['data']['criteria'] ?? []) as $c) {
        $code = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($c['code'] ?? 'CRIT')) ?: 'CRIT', 0, 12));
        q('INSERT INTO criteria (project_id, code, title, description, category, weight) VALUES (?,?,?,?,?,?)',
          [$id, $code, (string)($c['title'] ?? $code), (string)($c['description'] ?? ''),
           (string)($c['category'] ?? 'funcionalidade'), (float)($c['weight'] ?? 10)]);
    }
    audit((int)$u['id'], 'plan_reprocess', (string)$id);
    ok(['project' => project_payload(project_or_404($id))]);
}

/* ---------------- Upload de imagens (drag & drop) ---------------- */
if ($action === 'upload_image') {
    $u = require_login();
    if (empty($_FILES['file'])) { fail('Nenhum arquivo recebido.'); }
    $f = $_FILES['file'];
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) { fail('Falha no upload (código ' . $f['error'] . ').'); }
    $maxMb = (float)setting('max_upload_mb', 8);
    if ($f['size'] > $maxMb * 1024 * 1024) { fail('Arquivo acima do limite de ' . $maxMb . ' MB.'); }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($f['tmp_name']);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) { fail('Formato não suportado. Use PNG, JPG, WEBP ou GIF.'); }
    $ext = $allowed[$mime];
    $name = bin2hex(random_bytes(8)) . '_' . substr(sha1_file($f['tmp_name']) ?: '', 0, 8) . '.' . $ext;
    $rel = 'data/uploads/' . date('Y/m') . '/' . $name;
    $abs = APP_ROOT . '/' . $rel;
    if (!is_dir(dirname($abs))) { @mkdir(dirname($abs), 0775, true); }
    if (!move_uploaded_file($f['tmp_name'], $abs)) { fail('Não foi possível gravar o arquivo.', 500); }

    $pid = (int)param('project_id', 0) ?: null;
    q('INSERT INTO images (project_id, uploader_id, path, original_name, mime, size, caption, created_at)
       VALUES (?,?,?,?,?,?,?,?)',
      [$pid, (int)$u['id'], $rel, (string)($f['name'] ?? $name), $mime, (int)$f['size'], s('caption'), now()]);
    $iid = (int)pdo()->lastInsertId();
    audit((int)$u['id'], 'upload_image', $rel);
    ok(['image' => ['id' => $iid, 'url' => 'api.php?action=image&id=' . $iid,
                    'name' => (string)($f['name'] ?? $name), 'size' => (int)$f['size'], 'mime' => $mime,
                    'caption' => s('caption')]]);
}

if ($action === 'images_list') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    if ($pid) { guard_project($pid); }
    $rows = all('SELECT * FROM images WHERE project_id = ? ORDER BY id DESC LIMIT 200', [$pid]);
    foreach ($rows as &$r) { $r['url'] = 'api.php?action=image&id=' . (int)$r['id']; }
    ok(['images' => $rows]);
}

/* ---------------- Feedback do testador ---------------- */
if ($action === 'feedback_create') {
    $u = require_role('testador');
    $pid  = (int)param('project_id', 0);
    $p    = guard_project($pid);
    $raw  = s('raw_text');
    if (mb_strlen($raw) < 5 && !param('image_ids')) { fail('Escreva o relato de teste (ou anexe prints).'); }

    $ids = array_values(array_filter(array_map('intval', (array)param('image_ids', []))));
    $imgs = images_for_ai($ids);

    $ctx = json_encode([
        'project'  => ['name' => $p['name'], 'threshold' => (float)$p['threshold']],
        'plan'     => jdec($p['plan_json']),
        'criteria' => all('SELECT code,title,weight FROM criteria WHERE project_id = ?', [$pid]),
    ], JSON_UNESCAPED_UNICODE);

    $userMsg = "PLANO DE TESTAGEM (contexto):\n" . $ctx
             . "\n\nRELATO LIVRE DO DEV TESTADOR (@" . $u['handle'] . "):\n" . ($raw !== '' ? $raw : '(sem texto — interprete apenas os prints anexados)')
             . "\n\nPRINTS ANEXADOS: " . count($imgs)
             . "\nResponda em português do Brasil, no JSON especificado.";

    $r = ai_json(prompt_feedback(), $userMsg, $imgs, 180);
    $degraded = false;
    if (!$r['ok']) {
        // contingência: estrutura por heurística local para não perder o relato do testador
        $codes = [];
        foreach (all('SELECT code FROM criteria WHERE project_id = ?', [$pid]) as $c) { $codes[$c['code']] = true; }
        $r = ['ok' => true, 'data' => feedback_fallback($raw, $codes), 'provider' => 'local-fallback',
              'model' => 'heurística determinística', 'error' => $r['error']];
        $degraded = true;
    }
    $st = $r['data'];

    // itens normalizados
    $items = [];
    foreach ((array)($st['items'] ?? []) as $it) {
        if (!is_array($it)) { continue; }
        $res = strtolower((string)($it['result'] ?? 'fail'));
        if (!in_array($res, ['pass', 'fail', 'partial', 'blocked'], true)) { $res = 'fail'; }
        $items[] = [
            'criterion'  => mb_strtoupper((string)($it['criterion'] ?? '')),
            'test_case'  => (string)($it['test_case'] ?? ''),
            'result'     => $res,
            'severity'   => strtolower((string)($it['severity'] ?? 'medio')),
            'dimension'  => (string)($it['dimension'] ?? ''),
            'problem'    => (string)($it['problem'] ?? ''),
            'evidence'   => (string)($it['evidence'] ?? ''),
            'note'       => (string)($it['note'] ?? ''),
            'annotation' => (string)($it['annotation'] ?? ''),
        ];
    }
    $critW = [];
    foreach (all('SELECT code, weight FROM criteria WHERE project_id = ?', [$pid]) as $c) { $critW[$c['code']] = (float)$c['weight']; }
    $calc = weighted_score($items, $critW, setting_json('result_weights', ['pass'=>1,'partial'=>0.5,'fail'=>0,'blocked'=>0]));

    $policy  = (string)setting('score_policy', 'sistema');
    $aiScore = isset($st['ai_score']) ? max(0, min(100, (float)$st['ai_score'])) : $calc['score'];
    $score   = $policy === 'ia' ? round($aiScore, 2) : $calc['score'];
    $thr     = (float)$p['threshold'];
    $verdict = $score >= $thr ? 'aprovado' : 'reprovado';

    $epoch = now_ms();
    $happened = s('happened_at') !== '' ? s('happened_at') : now();
    q('INSERT INTO feedback_entries
        (project_id, tester_id, raw_text, structured_json, score, ai_score, threshold, verdict,
         pass_n, fail_n, partial_n, blocked_n, source_provider, created_at, happened_at, epoch_ms)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$pid, (int)$u['id'], $raw, json_encode($st, JSON_UNESCAPED_UNICODE), $score, $aiScore, $thr, $verdict,
       $calc['counts']['pass'], $calc['counts']['fail'], $calc['counts']['partial'], $calc['counts']['blocked'],
       $r['provider'] . '/' . $r['model'], now(), $happened, $epoch]);
    $eid = (int)pdo()->lastInsertId();

    foreach ($items as $it) {
        q('INSERT INTO feedback_items (entry_id, project_id, criterion, test_case, result, severity, dimension,
             problem, evidence, note, annotation, weight)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
          [$eid, $pid, $it['criterion'], $it['test_case'], $it['result'], $it['severity'], $it['dimension'],
           $it['problem'], $it['evidence'], $it['note'], $it['annotation'], (float)($critW[$it['criterion']] ?? 0)]);
    }

    // anotações relevantes
    if (setting('ai_auto_annotations', 1)) {
        foreach (array_slice(array_merge((array)($st['annotations'] ?? []),
                 array_filter(array_map(fn($i) => ($i['annotation'] ?? '') !== '' ? ('[' . ($i['criterion'] ?? '-') . '] ' . $i['annotation']) : null, $items))), 0, 20) as $an) {
            q('INSERT INTO annotations (project_id, target_type, target_id, author_id, kind, text, created_at)
               VALUES (?,?,?,?,?,?,?)',
              [$pid, 'entry', $eid, (int)$u['id'], 'ia', (string)$an, now()]);
        }
    }

    // backlog kanban dinâmico (dedup por impressão digital)
    $cards = 0;
    if (setting('ai_auto_backlog', 1)) {
        $backlog = (array)($st['backlog'] ?? []);
        if (!$backlog) {
            foreach ($items as $it) {
                if ($it['result'] !== 'pass') {
                    $backlog[] = ['title' => $it['problem'] !== '' ? $it['problem'] : ($it['test_case'] ?: 'Falha relatada'),
                                  'description' => $it['evidence'] ?: $it['note'], 'severity' => $it['severity'],
                                  'category' => $it['dimension'] ?: 'funcionalidade', 'priority' => 'media',
                                  'criterion' => $it['criterion']];
                }
            }
        }
        foreach ($backlog as $b) {
            if (!is_array($b)) { continue; }
            $title = trim((string)($b['title'] ?? ''));
            if ($title === '') { continue; }
            $fp = sha1($pid . '|' . mb_strtolower(preg_replace('/\s+/', ' ', $title) ?? $title));
            $ex = one('SELECT * FROM kanban_cards WHERE project_id = ? AND fingerprint = ?', [$pid, $fp]);
            $sev = strtolower((string)($b['severity'] ?? 'medio'));
            if ($ex && $ex['status'] !== 'done') {
                q('UPDATE kanban_cards SET description = ?, updated_at = ?, evidence_note = ? WHERE id = ?',
                  [trim((string)$ex['description'] . "\n— reincidência em " . now() . ' (' . $verdict . ' ' . $score . '%)'),
                   now(), (string)$ex['evidence_note'] . "\n[reincidência " . now() . ']', (int)$ex['id']]);
                continue;
            }
            $st2 = $sev === 'critico' ? 'doing' : 'todo';
            q('INSERT INTO kanban_cards (project_id, title, description, fingerprint, category, severity, priority,
                 status, criterion, origin_entry, position, evidence_note, image_ids, created_at, updated_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$pid, $title, (string)($b['description'] ?? ''), $fp, (string)($b['category'] ?? 'funcionalidade'),
               $sev, (string)($b['priority'] ?? 'media'), $st2, (string)($b['criterion'] ?? ''),
               $eid, 0, '', json_encode($ids), now(), now()]);
            $cards++;
        }
    }

    // legendas dos prints
    foreach ((array)($st['image_captions'] ?? []) as $cap) {
        $idx = (int)($cap['image_index'] ?? -1);
        if (!isset($imgs[$idx])) { continue; }
        q('UPDATE images SET ai_caption = ?, entry_id = ? WHERE id = ?',
          [trim((string)($cap['caption'] ?? '') . ' | vínculo: ' . (string)($cap['links_item'] ?? '-')), $eid, (int)$imgs[$idx]['id']]);
    }
    foreach ($ids as $iid) { q('UPDATE images SET entry_id = ?, project_id = COALESCE(project_id, ?) WHERE id = ?', [$eid, $pid, $iid]); }
    q('UPDATE projects SET updated_at = ? WHERE id = ?', [now(), $pid]);

    audit((int)$u['id'], 'feedback_create', "entry #$eid score $score -> $verdict");
    $entry = one('SELECT * FROM feedback_entries WHERE id = ?', [$eid]);
    $entry['structured'] = $st;
    $entry['items'] = all('SELECT * FROM feedback_items WHERE entry_id = ? ORDER BY id', [$eid]);
    $entry['images'] = array_map(function ($i) { $i['url'] = 'api.php?action=image&id=' . (int)$i['id']; return $i; },
                                 all('SELECT * FROM images WHERE entry_id = ?', [$eid]));
    $entry['calc'] = $calc;
    $entry['cards_created'] = $cards;
    $entry['degraded'] = $degraded;
    ok(['entry' => $entry]);
}

if ($action === 'feedback_list') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    guard_project($pid);
    $rows = all('SELECT e.*, u.name tester_name, u.handle tester_handle
                 FROM feedback_entries e LEFT JOIN users u ON u.id = e.tester_id
                 WHERE e.project_id = ? ORDER BY e.epoch_ms DESC LIMIT ' . (int)param('limit', 100), [$pid]);
    foreach ($rows as &$r) { $r['structured'] = jdec($r['structured_json']); }
    ok(['entries' => $rows]);
}

if ($action === 'feedback_get') {
    $u = require_login();
    $e = one('SELECT * FROM feedback_entries WHERE id = ?', [(int)param('id', 0)]);
    if (!$e) { fail('Registro não encontrado.', 404); }
    guard_project((int)$e['project_id']);
    $e['structured'] = jdec($e['structured_json']);
    $e['items'] = all('SELECT * FROM feedback_items WHERE entry_id = ? ORDER BY id', [(int)$e['id']]);
    $e['images'] = array_map(function ($i) { $i['url'] = 'api.php?action=image&id=' . (int)$i['id']; return $i; },
                             all('SELECT * FROM images WHERE entry_id = ?', [(int)$e['id']]));
    ok(['entry' => $e]);
}

/* ---------------- Kanban persistente ---------------- */
if ($action === 'kanban_list') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    guard_project($pid);
    ok([
        'cards'   => all('SELECT * FROM kanban_cards WHERE project_id = ? ORDER BY position, id', [$pid]),
        'columns' => setting_json('kanban_columns'),
    ]);
}

if ($action === 'kanban_save') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    guard_project($pid);
    $cards = param('cards', []);
    if (!is_array($cards)) { fail('Formato inválido.'); }
    $cols = array_column(setting_json('kanban_columns'), 'id');
    $seen = [];
    $moved = 0;
    foreach ($cards as $c) {
        $id = (int)($c['id'] ?? 0);
        if ($id <= 0) { continue; }
        $row = one('SELECT * FROM kanban_cards WHERE id = ? AND project_id = ?', [$id, $pid]);
        if (!$row) { continue; }
        $status = (string)($c['status'] ?? $row['status']);
        if ($cols && !in_array($status, $cols, true)) { $status = $row['status']; }
        $pos = (int)($c['position'] ?? 0);
        $seen[] = $id;
        $changed = ($status !== $row['status']) || ($pos !== (int)$row['position']);
        q('UPDATE kanban_cards SET status=?, position=?, title=?, description=?, severity=?, priority=?, category=?,
             criterion=?, evidence_note=?, updated_at=? WHERE id=? AND project_id=?',
          [$status, $pos, (string)($c['title'] ?? $row['title']), (string)($c['description'] ?? $row['description']),
           (string)($c['severity'] ?? $row['severity']), (string)($c['priority'] ?? $row['priority']),
           (string)($c['category'] ?? $row['category']), (string)($c['criterion'] ?? $row['criterion']),
           (string)($c['evidence_note'] ?? $row['evidence_note']), now(), $id, $pid]);
        if ($changed) { $moved++; }
    }
    audit((int)$u['id'], 'kanban_save', "projeto $pid, $moved movimento(s)");
    ok(['saved' => count($seen), 'moved' => $moved]);
}

if ($action === 'kanban_card_create') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    guard_project($pid);
    $title = s('title');
    if ($title === '') { fail('Informe o título do cartão.'); }
    q('INSERT INTO kanban_cards (project_id, title, description, fingerprint, category, severity, priority, status,
         criterion, position, evidence_note, image_ids, created_at, updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$pid, $title, s('description'), sha1($pid . '|' . mb_strtolower($title)), s('category', 'funcionalidade'),
       s('severity', 'medio'), s('priority', 'media'), s('status', 'todo'), s('criterion'), 0, s('evidence_note'), '[]', now(), now()]);
    ok(['card' => one('SELECT * FROM kanban_cards WHERE id = ?', [(int)pdo()->lastInsertId()])]);
}

if ($action === 'kanban_card_delete') {
    $u = require_login();
    $c = one('SELECT * FROM kanban_cards WHERE id = ?', [(int)param('id', 0)]);
    if (!$c) { fail('Cartão não encontrado.', 404); }
    guard_project((int)$c['project_id']);
    q('DELETE FROM kanban_cards WHERE id = ?', [(int)$c['id']]);
    audit((int)$u['id'], 'kanban_card_delete', (string)$c['id']);
    ok([]);
}

/* ---------------- Anotações ---------------- */
if ($action === 'annotations_list') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    guard_project($pid);
    ok(['annotations' => all('SELECT a.*, u.name author_name, u.handle author_handle
                              FROM annotations a LEFT JOIN users u ON u.id = a.author_id
                              WHERE a.project_id = ? ORDER BY a.id DESC LIMIT 200', [$pid])]);
}

if ($action === 'annotation_add') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    guard_project($pid);
    $txt = s('text');
    if ($txt === '') { fail('Texto vazio.'); }
    q('INSERT INTO annotations (project_id, target_type, target_id, author_id, kind, text, created_at) VALUES (?,?,?,?,?,?,?)',
      [$pid, s('target_type', 'project'), (int)param('target_id', 0) ?: null, (int)$u['id'], s('kind', 'nota'), $txt, now()]);
    ok(['annotation' => one('SELECT * FROM annotations WHERE id = ?', [(int)pdo()->lastInsertId()])]);
}

if ($action === 'annotation_delete') {
    $u = require_login();
    $a = one('SELECT * FROM annotations WHERE id = ?', [(int)param('id', 0)]);
    if (!$a) { fail('Anotação não encontrada.', 404); }
    guard_project((int)$a['project_id']);
    q('DELETE FROM annotations WHERE id = ?', [(int)$a['id']]);
    ok([]);
}

/* ---------------- Estatísticas / Dashboard ---------------- */
if ($action === 'stats') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    $p   = guard_project($pid);

    $entries = all('SELECT id, score, ai_score, threshold, verdict, pass_n, fail_n, partial_n, blocked_n,
                           epoch_ms, happened_at, created_at, tester_id
                    FROM feedback_entries WHERE project_id = ? ORDER BY epoch_ms ASC', [$pid]);
    $series = []; $kpi = ['pass'=>0,'fail'=>0,'partial'=>0,'blocked'=>0];
    $running = null; $trend = [];
    foreach ($entries as $i => $e) {
        $kpi['pass']    += (int)$e['pass_n'];
        $kpi['fail']    += (int)$e['fail_n'];
        $kpi['partial'] += (int)$e['partial_n'];
        $kpi['blocked'] += (int)$e['blocked_n'];
        $running = $running === null ? (float)$e['score'] : (($running * $i) + (float)$e['score']) / ($i + 1);
        $trend[] = round($running, 2);
        $series[] = ['t' => (int)$e['epoch_ms'], 'label' => $e['happened_at'], 'score' => (float)$e['score'],
                     'ai_score' => $e['ai_score'] !== null ? (float)$e['ai_score'] : null,
                     'threshold' => (float)$e['threshold'], 'verdict' => $e['verdict'],
                     'pass' => (int)$e['pass_n'], 'fail' => (int)$e['fail_n'],
                     'partial' => (int)$e['partial_n'], 'blocked' => (int)$e['blocked_n'],
                     'id' => (int)$e['id']];
    }
    $avg = $entries ? round(array_sum(array_map(fn($e) => (float)$e['score'], $entries)) / count($entries), 2) : null;

    $byCrit = all('SELECT i.criterion code, c.title, COUNT(*) total,
                          SUM(CASE WHEN i.result="pass" THEN 1 ELSE 0 END) passed,
                          ROUND(100.0*SUM(CASE WHEN i.result="pass" THEN 1 ELSE 0 END)/COUNT(*),1) rate
                   FROM feedback_items i LEFT JOIN criteria c ON c.project_id = i.project_id AND c.code = i.criterion
                   WHERE i.project_id = ? GROUP BY i.criterion ORDER BY rate DESC', [$pid]);
    $bySev = all('SELECT severity, COUNT(*) n FROM feedback_items WHERE project_id = ? GROUP BY severity', [$pid]);
    $byDim = all('SELECT COALESCE(NULLIF(dimension,""), "n/d") dimension, COUNT(*) n,
                         ROUND(100.0*SUM(CASE WHEN result="pass" THEN 1 ELSE 0 END)/COUNT(*),1) rate
                  FROM feedback_items WHERE project_id = ? GROUP BY dimension', [$pid]);
    $kb = all('SELECT status, COUNT(*) n FROM kanban_cards WHERE project_id = ? GROUP BY status', [$pid]);
    $kbCols = setting_json('kanban_columns');
    $kbDist = [];
    foreach ($kbCols as $c) {
        $n = 0;
        foreach ($kb as $k) { if ($k['status'] === $c['id']) { $n = (int)$k['n']; } }
        $kbDist[] = ['id' => $c['id'], 'title' => $c['title'], 'n' => $n];
    }
    $topProblems = all('SELECT problem, severity, criterion, COUNT(*) n FROM feedback_items
                        WHERE project_id = ? AND result IN ("fail","partial","blocked") AND problem <> ""
                        GROUP BY problem ORDER BY n DESC, severity DESC LIMIT 10', [$pid]);
    $testers = all('SELECT u.name, u.handle, COUNT(*) n, ROUND(AVG(e.score),2) avg_score
                    FROM feedback_entries e JOIN users u ON u.id = e.tester_id
                    WHERE e.project_id = ? GROUP BY e.tester_id ORDER BY n DESC', [$pid]);

    ok([
        'project'  => ['id' => $pid, 'name' => $p['name'], 'threshold' => (float)$p['threshold'], 'status' => $p['status']],
        'kpi'      => [
            'entries' => count($entries), 'avg_score' => $avg,
            'last_score' => $entries ? (float)end($entries)['score'] : null,
            'best' => $entries ? max(array_map(fn($e) => (float)$e['score'], $entries)) : null,
            'threshold' => (float)$p['threshold'],
            'trend' => $trend, 'counts' => $kpi,
            'pass_rate' => array_sum($kpi) > 0 ? round(100 * $kpi['pass'] / array_sum($kpi), 1) : 0,
            'backlog_open' => (int)(one("SELECT COUNT(*) c FROM kanban_cards WHERE project_id=? AND status<>'done'", [$pid])['c'] ?? 0),
            'backlog_done' => (int)(one("SELECT COUNT(*) c FROM kanban_cards WHERE project_id=? AND status='done'", [$pid])['c'] ?? 0),
        ],
        'series'   => $series,
        'criteria' => $byCrit,
        'severity' => $bySev,
        'dimensions' => $byDim,
        'kanban'   => $kbDist,
        'problems' => $topProblems,
        'testers'  => $testers,
        'annotations' => all('SELECT a.*, u.handle author_handle FROM annotations a LEFT JOIN users u ON u.id=a.author_id
                              WHERE a.project_id = ? ORDER BY a.id DESC LIMIT 60', [$pid]),
    ]);
}

/* ---------------- Exportação ---------------- */
if ($action === 'export') {
    $u = require_login();
    $pid = (int)param('project_id', 0);
    $p = guard_project($pid);
    $cards = all('SELECT * FROM kanban_cards WHERE project_id = ? ORDER BY status, position, id', [$pid]);
    $name = slugify($p['name']) . '-backlog-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'titulo', 'status', 'severidade', 'prioridade', 'categoria', 'criterio', 'descricao', 'atualizado_em'], ';');
    foreach ($cards as $c) {
        fputcsv($out, [$c['id'], $c['title'], $c['status'], $c['severity'], $c['priority'], $c['category'],
                        $c['criterion'], preg_replace('/\s+/', ' ', (string)$c['description']), $c['updated_at']], ';');
    }
    fclose($out);
    exit;
}

fail('Ação desconhecida: ' . $action, 404);
