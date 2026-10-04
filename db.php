<?php
/**
 * TestForge AI — camada de dados (SQLite / PDO)
 * Cria o schema, semeia o administrador e mantém os parâmetros
 * globais (área administrativa) na tabela `settings`.
 */
declare(strict_types=1);

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $fresh = !is_file(DB_PATH);
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($fresh) { init_db($pdo); }
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = pdo()->prepare($sql);
    $st->execute($args);
    return $st;
}
function one(string $sql, array $args = []): ?array
{
    $r = q($sql, $args)->fetch();
    return $r === false ? null : $r;
}
function all(string $sql, array $args = []): array
{
    return q($sql, $args)->fetchAll();
}
function jdec(?string $json, $default = []): array
{
    if ($json === null || $json === '') { return is_array($default) ? $default : []; }
    $v = json_decode($json, true);
    return is_array($v) ? $v : (is_array($default) ? $default : []);
}

function init_db(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  handle     TEXT NOT NULL UNIQUE,
  email      TEXT NOT NULL UNIQUE,
  pass_hash  TEXT NOT NULL,
  role       TEXT NOT NULL CHECK (role IN ('admin','solicitante','testador')),
  lang       TEXT NOT NULL DEFAULT 'pt-BR',
  active     INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  key        TEXT PRIMARY KEY,
  value      TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS projects (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  slug          TEXT NOT NULL,
  requester_id  INTEGER NOT NULL REFERENCES users(id),
  raw_request   TEXT NOT NULL,
  plan_json     TEXT,
  plan_source   TEXT,
  threshold     REAL NOT NULL DEFAULT 85,
  status        TEXT NOT NULL DEFAULT 'em_testes',
  created_at    TEXT NOT NULL,
  updated_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mentions (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  dev_handle   TEXT NOT NULL,
  role_in_proj TEXT NOT NULL DEFAULT 'solicitante',
  user_id      INTEGER REFERENCES users(id),
  duty         TEXT,
  created_at   TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS criteria (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  code        TEXT NOT NULL,
  title       TEXT NOT NULL,
  description TEXT,
  category    TEXT,
  weight      REAL NOT NULL DEFAULT 10
);

CREATE TABLE IF NOT EXISTS feedback_entries (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id      INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  tester_id       INTEGER NOT NULL REFERENCES users(id),
  raw_text        TEXT NOT NULL,
  structured_json TEXT,
  score           REAL,
  ai_score        REAL,
  threshold       REAL,
  verdict         TEXT,
  pass_n          INTEGER DEFAULT 0,
  fail_n          INTEGER DEFAULT 0,
  partial_n       INTEGER DEFAULT 0,
  blocked_n       INTEGER DEFAULT 0,
  source_provider TEXT,
  created_at      TEXT NOT NULL,
  happened_at     TEXT NOT NULL,
  epoch_ms        INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS feedback_items (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_id     INTEGER NOT NULL REFERENCES feedback_entries(id) ON DELETE CASCADE,
  project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  criterion    TEXT,
  test_case    TEXT,
  result       TEXT,
  severity     TEXT,
  dimension    TEXT,
  problem      TEXT,
  evidence     TEXT,
  note         TEXT,
  annotation   TEXT,
  weight       REAL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS images (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id    INTEGER REFERENCES projects(id) ON DELETE CASCADE,
  entry_id      INTEGER REFERENCES feedback_entries(id) ON DELETE CASCADE,
  uploader_id   INTEGER REFERENCES users(id),
  path          TEXT NOT NULL,
  original_name TEXT,
  mime          TEXT,
  size          INTEGER,
  caption       TEXT,
  ai_caption    TEXT,
  created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS kanban_cards (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id     INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  title          TEXT NOT NULL,
  description    TEXT,
  fingerprint    TEXT,
  category       TEXT,
  severity       TEXT DEFAULT 'medio',
  priority       TEXT DEFAULT 'media',
  status         TEXT NOT NULL DEFAULT 'todo',
  criterion      TEXT,
  origin_entry   INTEGER REFERENCES feedback_entries(id) ON DELETE SET NULL,
  origin_item    INTEGER,
  position       INTEGER DEFAULT 0,
  evidence_note  TEXT,
  image_ids      TEXT,
  created_at     TEXT NOT NULL,
  updated_at     TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS annotations (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  target_type TEXT NOT NULL,
  target_id   INTEGER,
  author_id   INTEGER REFERENCES users(id),
  kind        TEXT DEFAULT 'nota',
  text        TEXT NOT NULL,
  created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER,
  action     TEXT NOT NULL,
  detail     TEXT,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS i18n (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  src_hash   TEXT NOT NULL,
  lang       TEXT NOT NULL,
  src        TEXT NOT NULL,
  value      TEXT NOT NULL,
  origin     TEXT DEFAULT 'ai',
  created_at TEXT NOT NULL,
  UNIQUE (src_hash, lang)
);

CREATE INDEX IF NOT EXISTS ix_fb_project ON feedback_entries(project_id, epoch_ms);
CREATE INDEX IF NOT EXISTS ix_items_entry ON feedback_items(entry_id);
CREATE INDEX IF NOT EXISTS ix_kb_project  ON kanban_cards(project_id, status);
CREATE INDEX IF NOT EXISTS ix_img_project ON images(project_id);
SQL);

    seed_settings();
    seed_users();

    $n = (int)(one('SELECT COUNT(*) c FROM settings')['c'] ?? 0);
    audit(null, 'db_init', 'schema criado com ' . $n . ' parâmetros');
}

function settings_defaults(): array
{
    return [
        // Excelência: meta global de aprovação (editável no admin)
        'excellence_threshold'  => 85,
        // Pesos percentuais por dimensão (o sistema recalcula se não somarem 100)
        'weights'               => json_encode([
            'funcionalidade' => 30, 'seguranca' => 20, 'desempenho' => 20,
            'usabilidade'    => 15, 'confiabilidade' => 15,
        ]),
        'severity_scale'        => json_encode(['critico' => 4, 'alto' => 3, 'medio' => 2, 'baixo' => 1]),
        'severity_penalty'      => json_encode(['critico' => 100, 'alto' => 60, 'medio' => 30, 'baixo' => 10]),
        'result_weights'        => json_encode(['pass' => 1, 'partial' => 0.5, 'fail' => 0, 'blocked' => 0]),
        'kanban_columns'        => json_encode([
            ['id' => 'todo', 'title' => 'A fazer'],
            ['id' => 'doing', 'title' => 'Em correção'],
            ['id' => 'review', 'title' => 'Em reteste'],
            ['id' => 'blocked', 'title' => 'Bloqueado'],
            ['id' => 'done', 'title' => 'Solucionado'],
        ]),
        'langs'                 => json_encode(['pt-BR', 'es', 'en']),
        'default_lang'          => 'pt-BR',
        'ai_model'              => envv('AI_MODEL', 'gemini-2.5-flash'),
        'ai_temperature'        => 0.25,
        'ai_auto_backlog'       => 1,
        'ai_auto_annotations'   => 1,
        'ai_analyze_images'     => 1,
        'require_evidence'      => 0,
        'score_policy'          => 'sistema', // sistema | ia
        'dashboard_refresh_s'   => 30,
        'max_upload_mb'         => 8,
        'theme'                 => 'dark-teal',
    ];
}

function seed_settings(): void
{
    $now = now();
    foreach (settings_defaults() as $k => $v) {
        q('INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?,?,?)', [$k, (string)$v, $now]);
    }
}

function seed_users(): void
{
    if ((int)(one('SELECT COUNT(*) c FROM users')['c'] ?? 0) > 0) { return; }
    $now = now();
    $rows = [
        ['Administrador do Sistema', 'admin',      'admin@testforge.local',      'admin123',      'admin'],
        ['Dev Solicitante (exemplo)', 'solicitante', 'solicitante@testforge.local', 'dev123',      'solicitante'],
        ['Dev Testador (exemplo)',    'testador',    'testador@testforge.local',    'dev123',      'testador'],
    ];
    foreach ($rows as [$name, $handle, $email, $pass, $role]) {
        q('INSERT INTO users (name, handle, email, pass_hash, role, lang, active, created_at)
           VALUES (?,?,?,?,?,?,1,?)',
           [$name, $handle, $email, password_hash($pass, PASSWORD_DEFAULT), $role, 'pt-BR', $now]);
    }
}

function setting(string $key, $default = null)
{
    $r = one('SELECT value FROM settings WHERE key = ?', [$key]);
    if (!$r) { return $default ?? (settings_defaults()[$key] ?? null); }
    return $r['value'];
}
function setting_json(string $key, array $default = []): array
{
    return jdec((string)setting($key, json_encode($default)), $default);
}
function set_setting(string $key, $value): void
{
    q('INSERT INTO settings (key, value, updated_at) VALUES (?,?,?)
       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
       [$key, is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE), now()]);
}

/* ------------------------------------------------------------------ */
/*  Utilitários                                                        */
/* ------------------------------------------------------------------ */
function now(): string { return date('Y-m-d H:i:s'); }
function now_ms(): int { return (int)round(microtime(true) * 1000); }

function audit(?int $uid, string $action, string $detail = ''): void
{
    try {
        q('INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (?,?,?,?)',
          [$uid, $action, mb_substr($detail, 0, 500), now()]);
    } catch (Throwable $e) { /* log nunca deve derrubar a requisição */ }
}

/** Normaliza "@Dev.Solicitante" -> "devsolicitante" para casar @menções. */
function handle_norm(string $h): string
{
    $h = ltrim(trim($h), '@');
    $h = mb_strtolower($h);
    $h = preg_replace('/[^a-z0-9à-ÿ]+/u', '', $h) ?? '';
    return $h;
}

function slugify(string $s): string
{
    $s = mb_strtolower(trim($s));
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ú'=>'u','ù'=>'u','ç'=>'c','ñ'=>'n'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'projeto';
}

function weighted_score(array $items, array $criteriaWeights, array $resultWeights): array
{
    $totalW = 0.0; $earned = 0.0; $counts = ['pass'=>0,'fail'=>0,'partial'=>0,'blocked'=>0,'other'=>0];
    foreach ($items as $it) {
        $res  = strtolower((string)($it['result'] ?? 'other'));
        $crit = (string)($it['criterion'] ?? '');
        $w    = (float)($criteriaWeights[$crit] ?? 10.0);
        // peso do item: usa o peso da dimensão do critério; se ausente, média das dimensões
        if ($w <= 0) {
            $w = $criteriaWeights ? array_sum($criteriaWeights) / max(1, count($criteriaWeights)) : 10.0;
        }
        $rw = (float)($resultWeights[$res] ?? 0.0);
        if (!isset($counts[$res])) { $counts['other']++; } else { $counts[$res]++; }
        $totalW += $w;
        $earned += $w * $rw;
    }
    $score = $totalW > 0 ? round(($earned / $totalW) * 100, 2) : 0.0;
    return ['score' => $score, 'counts' => $counts, 'total_weight' => round($totalW, 2)];
}
