<?php
/**
 * TestForge AI — camada de IA (server side apenas)
 *
 * Cadeia de provedores (o primeiro que responder vence):
 *   1) Gemini  — GEMINI_API_KEY            (+ AI_MODEL)
 *   2) Gemini  — GEMINI_API_KEY_FALLBACK   (+ AI_MODEL_FALLBACK)  [fallback de chave]
 *   3) OpenAI-compatível — AI_INTEGRATIONS_OPENAI_BASE_URL/chat/completions
 *
 * Todas as chamadas pedem JSON e toleram cercas de código markdown.
 * Nenhuma chave sai do servidor.
 */
declare(strict_types=1);

function ai_providers(): array
{
    $list = [];
    $k1 = envv('GEMINI_API_KEY');
    $k2 = envv('GEMINI_API_KEY_FALLBACK');
    $m1 = (string)setting('ai_model', envv('AI_MODEL', 'gemini-2.5-flash'));
    $m2 = envv('AI_MODEL_FALLBACK', 'gemini-2.0-flash');

    if ($k1) { $list[] = ['kind'=>'gemini','label'=>'gemini/primary','key'=>$k1,'model'=>$m1]; }
    if ($k1 && $m2 && $m2 !== $m1) { $list[] = ['kind'=>'gemini','label'=>'gemini/primary-altmodel','key'=>$k1,'model'=>$m2]; }
    if ($k2) { $list[] = ['kind'=>'gemini','label'=>'gemini/fallback-key','key'=>$k2,'model'=>$m1]; }
    if ($k2 && $m2) { $list[] = ['kind'=>'gemini','label'=>'gemini/fallback-altmodel','key'=>$k2,'model'=>$m2]; }

    $base = rtrim(envv('AI_INTEGRATIONS_OPENAI_BASE_URL'), '/');
    $oa   = envv('AI_INTEGRATIONS_OPENAI_API_KEY');
    if ($base && $oa) {
        $list[] = ['kind'=>'openai','label'=>'openai-compat','key'=>$oa,'model'=>setting('ai_openai_model', $m1) ?: $m1,'base'=>$base];
    }
    return $list;
}

function ai_health(): array
{
    $out = ['providers' => [], 'keys_present' => [
        'GEMINI_API_KEY' => (bool)envv('GEMINI_API_KEY'),
        'GEMINI_API_KEY_FALLBACK' => (bool)envv('GEMINI_API_KEY_FALLBACK'),
        'OPENAI_COMPAT' => (bool)envv('AI_INTEGRATIONS_OPENAI_API_KEY'),
    ]];
    foreach (ai_providers() as $p) {
        $out['providers'][] = [
            'label' => $p['label'], 'kind' => $p['kind'], 'model' => $p['model'],
            'base'  => $p['base'] ?? 'https://generativelanguage.googleapis.com',
            'key_masked' => substr($p['key'], 0, 6) . '…' . substr($p['key'], -4),
        ];
    }
    $r = ai_json('Responda apenas com JSON.', 'Devolva {"status":"ok"}.', [], 12);
    $out['probe'] = ['ok' => $r['ok'], 'provider' => $r['provider'] ?? null, 'error' => $r['error'] ?? null];
    return $out;
}

function ai_http(string $url, array $headers, array $payload, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $resp === false ? '' : (string)$resp, 'code' => $code, 'curl_error' => $err];
}

function ai_gemini_call(array $p, string $system, string $user, array $images, float $temp, int $timeout): array
{
    $parts = [['text' => $user]];
    foreach ($images as $img) {
        if (empty($img['data'])) { continue; }
        $parts[] = ['inline_data' => ['mime_type' => $img['mime'] ?? 'image/png', 'data' => $img['data']]];
    }
    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['role' => 'user', 'parts' => $parts]],
        'generationConfig'   => [
            'temperature'      => $temp,
            'responseMimeType' => 'application/json',
            'maxOutputTokens'  => 16384,
        ],
    ];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($p['model']) . ':generateContent?key=' . urlencode($p['key']);
    $r = ai_http($url, ['Content-Type: application/json'], $payload, $timeout);
    if ($r['code'] !== 200) {
        $msg = $r['curl_error'] ?: mb_substr($r['body'], 0, 400);
        return ['ok' => false, 'error' => 'HTTP ' . $r['code'] . ' :: ' . $msg];
    }
    $j = json_decode($r['body'], true);
    $txt = '';
    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $part) {
        $txt .= (string)($part['text'] ?? '');
    }
    if ($txt === '') {
        return ['ok' => false, 'error' => 'resposta vazia :: ' . mb_substr($r['body'], 0, 300)];
    }
    return ['ok' => true, 'text' => $txt];
}

function ai_openai_call(array $p, string $system, string $user, array $images, float $temp, int $timeout): array
{
    $content = [['type' => 'text', 'text' => $user]];
    foreach ($images as $img) {
        if (empty($img['data'])) { continue; }
        $content[] = ['type' => 'image_url', 'image_url' => [
            'url' => 'data:' . ($img['mime'] ?? 'image/png') . ';base64,' . $img['data'],
        ]];
    }
    $payload = [
        'model'    => $p['model'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $content],
        ],
        'temperature'     => $temp,
        'response_format' => ['type' => 'json_object'],
    ];
    $url = rtrim($p['base'], '/') . '/chat/completions';
    $r = ai_http($url, ['Content-Type: application/json', 'Authorization: Bearer ' . $p['key']], $payload, $timeout);
    if ($r['code'] !== 200) {
        $msg = $r['curl_error'] ?: mb_substr($r['body'], 0, 400);
        return ['ok' => false, 'error' => 'HTTP ' . $r['code'] . ' :: ' . $msg];
    }
    $j = json_decode($r['body'], true);
    $txt = (string)($j['choices'][0]['message']['content'] ?? '');
    if ($txt === '') { return ['ok' => false, 'error' => 'resposta vazia']; }
    return ['ok' => true, 'text' => $txt];
}

/** Extrai o primeiro objeto/array JSON válido de um texto (tolerante a cercas). */
function ai_extract_json(string $text)
{
    $t = trim($text);
    $t = preg_replace('/^```(?:json)?/i', '', $t) ?? $t;
    $t = preg_replace('/```\s*$/', '', $t) ?? $t;
    $t = trim($t);
    $decoded = json_decode($t, true);
    if (is_array($decoded)) { return $decoded; }
    $start = null; $depth = 0; $inStr = false; $esc = false; $open = '';
    for ($i = 0, $n = strlen($t); $i < $n; $i++) {
        $c = $t[$i];
        if ($inStr) {
            if ($esc) { $esc = false; }
            elseif ($c === '\\') { $esc = true; }
            elseif ($c === '"') { $inStr = false; }
            continue;
        }
        if ($c === '"') { $inStr = true; continue; }
        if ($c === '{' || $c === '[') { if ($depth === 0) { $start = $i; $open = $c; } $depth++; continue; }
        if ($c === '}' || $c === ']') {
            $depth--;
            if ($depth === 0 && $start !== null) {
                $cand = substr($t, $start, $i - $start + 1);
                $d = json_decode($cand, true);
                if (is_array($d)) { return $d; }
                $start = null;
            }
        }
    }
    return null;
}

/**
 * Chamada JSON com fallback em cadeia.
 * @param array $images [['data'=>base64,'mime'=>'image/png'], ...]
 */
function ai_json(string $system, string $user, array $images = [], ?int $timeout = null): array
{
    $timeout   = $timeout ?? (int)envv('AI_TIMEOUT', '120');
    $temp      = (float)setting('ai_temperature', 0.25);
    $errors    = [];
    $providers = ai_providers();
    if (!$providers) {
        return ['ok' => false, 'error' => 'nenhum provedor de IA configurado', 'errors' => []];
    }
    $started = time();
    // 2 rodadas: provedores sobrecarregados (HTTP 503/429) normalmente respondem bem no retry.
    for ($round = 1; $round <= 2; $round++) {
        foreach ($providers as $p) {
            if (time() - $started > 420) { break 2; }
            $r = $p['kind'] === 'gemini'
                ? ai_gemini_call($p, $system, $user, $images, $temp, $timeout)
                : ai_openai_call($p, $system, $user, $images, $temp, $timeout);

            if (!$r['ok']) {
                $errors[] = 'r' . $round . ' ' . $p['label'] . ': ' . $r['error'];
                if ($round === 1 && preg_match('/HTTP (429|5\d\d)/', (string)$r['error'])) { usleep(700000); }
                continue;
            }
            $data = ai_extract_json($r['text']);
            if ($data === null) {
                $errors[] = 'r' . $round . ' ' . $p['label'] . ': JSON inválido (resposta possivelmente truncada)';
                continue;
            }
            return ['ok' => true, 'data' => $data, 'provider' => $p['label'], 'model' => $p['model'],
                    'round' => $round, 'raw' => $r['text']];
        }
        if ($round === 1) { sleep(2); }
    }
    return ['ok' => false, 'error' => mb_substr(implode(' | ', $errors), 0, 700), 'errors' => $errors];
}

/** Traduz um lote de strings, com cache em SQLite. */
function ai_translate_batch(array $strings, string $lang): array
{
    $lang = trim($lang);
    $out = [];
    $missing = [];
    foreach ($strings as $src) {
        $src = (string)$src;
        if ($src === '') { continue; }
        $h = sha1($src);
        $row = one('SELECT value FROM i18n WHERE src_hash = ? AND lang = ?', [$h, $lang]);
        if ($row) { $out[$src] = $row['value']; } else { $missing[$src] = $h; }
    }
    if ($missing) {
        $sys = 'Você é um tradutor técnico de interfaces de software. Traduza cada string da lista para '
             . ($lang === 'en' ? 'inglês (en-US)' : 'espanhol na variante brasileira (es-BR, o espanhol usado por '
                 . 'usuários brasileiros, com terminologia técnica corrente no Brasil)') . '. '
             . 'Regras: preserve placeholders {x}, tags HTML, nomes de campos técnicos, siglas e números. '
             . 'Não explique nada. Devolva APENAS o JSON {"translations":["...","..."]} '
             . 'com EXATAMENTE a mesma quantidade e a mesma ordem da lista recebida.';
        $list = array_keys($missing);
        $r = ai_json($sys, json_encode(['strings' => $list], JSON_UNESCAPED_UNICODE), [], 120);
        $tr = [];
        if ($r['ok']) {
            $d = $r['data'];
            if (isset($d['translations']) && is_array($d['translations'])) { $tr = array_values($d['translations']); }
            elseif (isset($d[0]) && is_array($d)) { $tr = array_values($d); }
            // também aceita {"1":"...", "2":"..."}
            if (!$tr) {
                for ($i = 1; $i <= count($list); $i++) {
                    if (isset($d[(string)$i])) { $tr[] = $d[(string)$i]; }
                }
            }
        }
        foreach ($list as $i => $src) {
            $cand = $tr[$i] ?? null;
            $val  = (is_string($cand) && trim($cand) !== '' && sha1($cand) !== $missing[$src]) ? trim($cand) : $src;
            $usable = $val !== $src;
            $out[$src] = $val;
            q('INSERT OR REPLACE INTO i18n (src_hash, lang, src, value, origin, created_at) VALUES (?,?,?,?,?,?)',
              [$missing[$src], $lang, $src, $val, $usable ? 'ai' : 'fallback', now()]);
        }
        // strings que caíram no fallback não ficam em cache, para tentar de novo depois
        foreach ($list as $i => $src) {
            if (($out[$src] ?? null) === $src) {
                q('DELETE FROM i18n WHERE src_hash = ? AND lang = ? AND origin = "fallback"', [$missing[$src], $lang]);
            }
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  PROMPTS DE DOMÍNIO                                                 */
/* ------------------------------------------------------------------ */

/**
 * PLANO DETERMINÍSTICO (contingência sem IA)
 * Usado quando TODOS os provedores falham (ex.: HTTP 429 de cota esgotada).
 * Garante que o módulo de testagem sempre inicializa; a origem fica
 * registrada como "local-fallback" para o usuário saber que não houve IA.
 */
function plan_fallback(string $raw): array
{
    $dims = setting_json('weights', ['funcionalidade'=>30,'seguranca'=>20,'desempenho'=>20,'usabilidade'=>15,'confiabilidade'=>15]);

    // 1) devs mencionados com @
    preg_match_all('/@([\p{L}\p{N}][\p{L}\p{N}._-]{0,40})/u', $raw, $m);
    $devs = [];
    foreach ($m[1] as $d) {
        $h = handle_norm($d);
        if ($h === '' || isset($devs[$h])) { continue; }
        $devs[$h] = ['handle' => '@' . $d, 'role' => 'testador', 'responsibilities' => 'Mencionado no prompt inicial de testagem.'];
    }

    // 2) critérios a partir dos pesos configurados
    $criteria = [];
    $codes = ['funcionalidade'=>'FUNC','seguranca'=>'SEC','desempenho'=>'PERF','usabilidade'=>'UX','confiabilidade'=>'CONF'];
    foreach ($dims as $dim => $w) {
        $criteria[] = [
            'code' => $codes[$dim] ?? mb_strtoupper(substr((string)$dim, 0, 4)),
            'title' => ucfirst((string)$dim),
            'description' => 'Critério derivado do peso padrão do sistema para ' . $dim . '.',
            'category' => (string)$dim,
            'weight' => (float)$w,
        ];
    }

    // 3) casos de teste a partir de frases com verbo de verificação
    $sentences = preg_split('/(?<=[.!?;\n])\s+/u', $raw) ?: [];
    $keywords = ['validar','garantir','testar','conferir','verificar','precisamos','deve','confirmar','assegurar','checar'];
    $cases = [];
    $i = 0;
    foreach ($sentences as $sent) {
        $sent = trim($sent);
        if (mb_strlen($sent) < 12) { continue; }
        $low = mb_strtolower($sent);
        $hit = false;
        foreach ($keywords as $kw) { if (str_contains($low, $kw)) { $hit = true; break; } }
        if (!$hit) { continue; }
        $i++;
        $crit = 'FUNC';
        if (preg_match('/seguran|senha|2fa|token|pci|autentica|brecha|crip/i', $low)) { $crit = 'SEC'; }
        elseif (preg_match('/segundo|desempenho|tempo|latên|concorr|usuarios simult|performance/i', $low)) { $crit = 'PERF'; }
        elseif (preg_match('/mensagem|usabilidade|tela|interface|erro claro/i', $low)) { $crit = 'UX'; }
        elseif (preg_match('/log|auditoria|estorno|retry|duplicad|consist|integridade/i', $low)) { $crit = 'CONF'; }
        $cases[] = [
            'id' => 'CT-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
            'title' => mb_strimwidth(preg_replace('/\s+/', ' ', $sent) ?? $sent, 0, 140, '…'),
            'criterion' => $crit,
            'type' => 'manual',
            'steps' => ['Reproduzir o cenário descrito pelo solicitante.', 'Executar o fluxo e observar o resultado.', 'Registrar evidência (print/log) e status.'],
            'expected' => 'O comportamento solicitante é atendido sem falhas nem regressões.',
            'severity_if_fail' => $crit === 'SEC' ? 'critico' : 'alto',
        ];
        if (count($cases) >= 14) { break; }
    }
    if (!$cases) {
        $cases[] = ['id' => 'CT-01', 'title' => 'Validação geral do escopo solicitado', 'criterion' => 'FUNC', 'type' => 'manual',
                    'steps' => ['Executar o fluxo principal descrito.'], 'expected' => 'Fluxo conclui conforme solicitado.',
                    'severity_if_fail' => 'alto'];
    }

    $name = mb_substr(preg_replace('/\s+/', ' ', trim($raw)) ?? 'Novo projeto', 0, 48);
    return [
        'project_name' => $name,
        'summary' => 'Plano de contingência gerado localmente (sem IA) a partir do texto livre: '
                     . mb_strimwidth(trim($raw), 0, 320, '…'),
        'test_scope' => 'Escopo derivado diretamente das solicitações registradas no prompt inicial.',
        'out_of_scope' => ['Itens não mencionados no prompt inicial.', 'Refatorações e mudanças de arquitetura.'],
        'devs' => array_values($devs),
        'criteria' => $criteria,
        'test_cases' => $cases,
        'risks' => ['Plano gerado em modo de contingência: revise os critérios antes de aceitar os resultados.'],
        'ambiguities' => ['A IA não estava disponível no momento da criação; o plano foi derivado por heurística local e deve ser revisado.'],
        'excellence_threshold' => (float)setting('excellence_threshold', 85),
    ];
}

/**
 * FEEDBACK DETERMINÍSTICO (contingência sem IA)
 * Classifica o relato por palavras-chave para que o dashboard e o kanban
 * continuem funcionando quando a cota de IA estiver esgotada.
 */
function feedback_fallback(string $raw, array $critCodes): array
{
    $failHints  = ['falhou','falha','erro','error','500','503','timeout','duplicad','duplicado','nao ','não ','sem ','quebrou','travou','pendente','abriu','vazou','brecha','acima de','diverg','inconsist','recusou','ausente','lento'];
    $passHints  = ['ok','funcionou','corrigido','correto','claras','claro','passou','conforme','atendeu','registrado','registrou','melhorou','dentro do prazo','sucesso'];
    $critRules  = [
        'SEC'  => '/seguran|2fa|senha|token|auth|pci|brecha|cript/i',
        'PERF' => '/desempenho|segundos|\ds\b|tempo|concorr|simult|latên|lent/i',
        'CONF' => '/estorno|retry|duplicad|log|auditoria|integrid|consist|prazo/i',
        'UX'   => '/mensagem|tela|interface|clara|usabilidade/i',
    ];

    $sentences = preg_split('/(?<=[.!?;\n])\s+/u', $raw) ?: [];
    $items = [];
    foreach ($sentences as $sent) {
        $sent = trim($sent);
        if (mb_strlen($sent) < 8) { continue; }
        $low = mb_strtolower($sent);
        $isFail = false; $isPass = false;
        foreach ($failHints as $h) { if (str_contains($low, $h)) { $isFail = true; break; } }
        foreach ($passHints as $h) { if (str_contains($low, $h)) { $isPass = true; break; } }
        if (!$isFail && !$isPass) { continue; }
        $result = $isFail ? 'fail' : 'pass';
        $crit = 'FUNC';
        foreach ($critRules as $code => $re) { if (preg_match($re, $low)) { $crit = $code; break; } }
        if ($crit !== 'FUNC' && !isset($critCodes[$crit]) && $critCodes) { $crit = array_key_first($critCodes); }
        $sev = 'medio';
        if ($isFail) {
            if (preg_match('/duplicad|brecha|cr[ií]tico|500|vazou|pci/i', $low)) { $sev = 'critico'; }
            elseif (preg_match('/timeout|estorno|pendente|acima de|falhou|quebrou/i', $low)) { $sev = 'alto'; }
            else { $sev = 'baixo'; }
        }
        $items[] = [
            'criterion' => $crit, 'test_case' => '', 'result' => $result, 'severity' => $sev,
            'dimension' => strtolower($crit), 'problem' => $isFail ? $sent : '',
            'evidence' => 'Relato textual do testador (modo contingência).', 'note' => $isPass ? $sent : '',
            'annotation' => $isFail ? 'Falha registrada por heurística local; revisar com IA quando a cota estiver livre.' : '',
        ];
        if (count($items) >= 40) { break; }
    }
    $pass = count(array_filter($items, fn($i) => $i['result'] === 'pass'));
    $fail = count(array_filter($items, fn($i) => $i['result'] === 'fail'));
    $backlog = [];
    foreach ($items as $it) {
        if ($it['result'] !== 'pass') {
            $backlog[] = ['title' => mb_strimwidth($it['problem'], 0, 110, '…'), 'description' => $it['evidence'],
                          'severity' => $it['severity'], 'category' => $it['dimension'], 'priority' => $it['severity'] === 'critico' ? 'alta' : 'media',
                          'criterion' => $it['criterion']];
        }
    }
    return [
        'overall_summary' => 'Feedback estruturado localmente (sem IA): ' . $pass . ' acerto(s) e ' . $fail . ' falha(s) detectada(s) no relato.',
        'ai_score' => 0,
        'items' => $items, 'hits' => [], 'problems' => $backlog, 'backlog' => $backlog,
        'annotations' => ['Estruturação feita em modo de contingência (cota de IA indisponível).'],
        'image_captions' => [],
        'stats' => ['pass' => $pass, 'fail' => $fail, 'partial' => 0, 'blocked' => 0],
        'confidence' => 0.3, 'fallback' => true,
    ];
}


function prompt_plan(): string
{
    $thr = setting('excellence_threshold', 85);
    $dims = implode(', ', array_keys(setting_json('weights', ['funcionalidade'=>30,'seguranca'=>20,'desempenho'=>20,'usabilidade'=>15,'confiabilidade'=>15])));
    return <<<TXT
Você é um arquiteto de QA sênior (ISTQB) especializado em planejamento de testes de software.
Receberá solicitações de testagem em formato LIVRE/NÃO ESTRUTURADO, escritas pelo dev solicitante,
e deve convertê-las em um PLANO DE TESTAGEM completo e executável.

Dimensões canônicas de avaliação: {$dims}.
Índice de excelência padrão do sistema: {$thr}% (o sistema pode ajustar).

Regras:
- Identifique TODOS os devs mencionados com "@" e classifique cada um como
  "solicitante" (quem pede a testagem) ou "testador" (quem vai executar/relatar).
- Derive critérios de aceite objetivos, com peso numérico; pesos devem somar 100.
- Crie casos de teste numerados (CT-01, CT-02...) com passos e resultado esperado.
- Seja fiel ao texto recebido: não invente funcionalidades que não foram solicitadas;
  quando algo estiver ambíguo, registre em "ambiguities" em vez de decidir por conta própria.
- Idioma de saída: o mesmo do texto recebido (se for misto, use português do Brasil).

Responda SOMENTE com JSON neste formato:
{
 "project_name": "string curta",
 "summary": "string",
 "test_scope": "string",
 "out_of_scope": ["string"],
 "devs": [{"handle":"@nome","role":"solicitante|testador","responsibilities":"string"}],
 "criteria": [{"code":"FUNC","title":"string","description":"string","category":"funcionalidade|seguranca|desempenho|usabilidade|confiabilidade","weight":30}],
 "test_cases": [{"id":"CT-01","title":"string","criterion":"FUNC","type":"manual|automatizado|exploratorio",
                 "steps":["string"],"expected":"string","severity_if_fail":"critico|alto|medio|baixo"}],
 "risks": ["string"],
 "ambiguities": ["string"],
 "excellence_threshold": {$thr}
}
TXT;
}

function prompt_feedback(): string
{
    $sev = json_encode(setting_json('severity_scale', ['critico'=>4,'alto'=>3,'medio'=>2,'baixo'=>1]));
    return <<<TXT
Você é um analista de QA que transforma o RELATO LIVRE de um dev testador em dados estruturados
de estatística e um backlog de demandas não solucionadas.

Você recebe: (a) o plano de testagem do projeto (critérios, casos, pesos), (b) o texto não
estruturado do testador, (c) opcionalmente prints anexados (analise-os e descreva a evidência).

Regras:
- Classifique CADA observação em um item com result = pass | fail | partial | blocked.
- Amarre cada item a um critério (código existente no plano) e, quando possível, a um caso (CT-xx).
- Atribua severidade {$sev} e a dimensão afetada.
- Separe claramente "problems" (falhas) de "hits" (acertos relevantes).
- Gere "backlog": apenas o que NÃO está solucionado, pronto para virar cartão kanban.
- "annotations": notas relevantes por erro/acerto, segundo os critérios do sistema.
- Se houver prints, use "image_captions" para descrever cada print e como ele comprova o item
  (numere na ordem em que os anexos aparecem: image_index 0,1,2...).
- Não invente falhas que o testador não relatou; o que não foi mencionado fica fora do JSON.

Responda SOMENTE com JSON neste formato:
{
 "overall_summary": "string",
 "ai_score": 0-100,
 "items": [{"criterion":"FUNC","test_case":"CT-01","result":"pass|fail|partial|blocked",
            "severity":"critico|alto|medio|baixo","dimension":"funcionalidade",
            "problem":"string","evidence":"string","note":"string","annotation":"string"}],
 "hits": [{"title":"string","criterion":"FUNC","impact":"string"}],
 "problems": [{"title":"string","severity":"alto","criterion":"FUNC","description":"string","repro":"string"}],
 "backlog": [{"title":"string","description":"string","severity":"alto","category":"funcionalidade","priority":"alta|media|baixa","criterion":"FUNC"}],
 "annotations": ["string"],
 "image_captions": [{"image_index": 0, "caption": "string", "links_item": "CT-01"}],
 "stats": {"pass":0,"fail":0,"partial":0,"blocked":0},
 "confidence": 0.0
}
TXT;
}
