# TestForge AI — plataforma de testagem com IA + SQLite

Plataforma **standalone em PHP 8 + SQLite** para feedback de testes em projetos, com
planejamento de testagem gerado por IA a partir de texto livre, dashboard estatístico,
backlog kanban persistente e área administrativa.

---

## 1. Como subir

```bash
# 1) dependências (extensões PHP)
php -m | grep -E "pdo_sqlite|curl|gd|mbstring|fileinfo|zip"   # todas devem aparecer

# 2) copiar as chaves (NUNCA comitar este arquivo)
cp .env.example .env   # ou edite o .env já incluído

# 3) subir o servidor
php -S 0.0.0.0:8080 -t .
# acesse http://localhost:8080
```

O banco (`data/testforge.sqlite`), o schema e o usuário administrador são criados
automaticamente no primeiro acesso. **Nenhuma migration é necessária.**

### Estrutura

```
.
├── index.html            # SPA (login + shell)
├── api.php               # roteador JSON único — TODO acesso à IA passa por aqui
├── config.php            # bootstrap, .env, sessão, helpers de perfil
├── db.php                # PDO/SQLite, schema, settings, cálculo de nota
├── ai.php                # cadeia de provedores de IA + prompts de domínio
├── assets/
│   ├── app.js            # auth, i18n, projetos, feedback, drag & drop
│   └── views.js          # dashboard, kanban, anotações, administração
├── .env                  # CHAVES (git-ignore isto)
└── data/                 # banco + uploads (criado em runtime)
```

---

## 2. Credenciais iniciais

| Perfil | E-mail | Senha | O que vê |
|---|---|---|---|
| administrador | `admin@testforge.local` | `admin123` | tudo + parâmetros, usuários, IA |
| @dev solicitante | `solicitante@testforge.local` | `dev123` | plano + dashboard + kanban |
| @dev testador | `testador@testforge.local` | `dev123` | plano + envio de feedback + dashboard |

> Troque as três senhas em **Administração → Usuários** antes de usar de verdade.

---

## 3. Fluxo de uso

1. **@dev solicitante** → *+ Novo* → cola as solicitações de testagem em **texto livre**
   (mencionando os devs com `@nome`) → *Processar com IA e gerar plano*.
   A IA devolve: nome do projeto, escopo, **critérios com peso**, **casos de teste**,
   riscos, ambiguidades e a lista de `@devs` classificados como solicitante/testador.
2. **@dev testador** → *Enviar feedback de testes* → escreve o relato livre, **arrasta os
   prints** (PNG/JPG/WEBP/GIF) → *Estruturar com IA*.
   A IA classifica cada observação (`pass` / `fail` / `partial` / `blocked`), atribui
   severidade e dimensão, descreve os prints e **gera o backlog kanban**, deduplicando
   por impressão digital: uma falha reincidente **incrementa** o cartão existente em vez
   de duplicá-lo.
3. **Dashboard** → série temporal com timestamp de cada entrada, linha de excelência,
   donuts de resultado/severidade/backlog, taxa por critério e por dimensão, falhas mais
   recorrentes, ranking de testadores e anotações da IA.
4. **Kanban** → arraste os cartões entre colunas; cada movimento é **salvo no SQLite**
   automaticamente. Colunas e comportamento são configuráveis no admin.
5. **Administração** → limiar de excelência, pesos por dimensão, política de nota,
   escala/penalidade de severidade, colunas do kanban, modelo e temperatura da IA,
   automações, tamanho de upload, intervalo de refresh e idiomas.

---

## 4. Cálculo da nota (transparente e auditável)

```
nota = Σ(peso_critério × peso_resultado) / Σ(peso_critério) × 100
peso_resultado: pass=1 · partial=0.5 · fail=0 · blocked=0     (configurável)
veredito = (nota ≥ limiar_do_projeto) ? "aprovado" : "reprovado"
```

* Por padrão a nota é **recalculada pelo sistema** (`score_policy = sistema`), de forma
  determinística. O admin pode trocar para `ia`, usando a nota subjetiva do modelo.
* A **nota da IA** é sempre gravada em paralelo (`ai_score`), então a divergência entre
  as duas fica visível no gráfico.
* O limiar do projeto é copiado do parâmetro global no momento da criação e pode ser
  ajustado por projeto.

---

## 5. Cadeia de provedores de IA (com fallback)

Toda chamada passa por `ai_json()`, que tenta em ordem e **repete uma segunda rodada**
quando o provedor devolve HTTP 429/5xx:

| # | Origem | Chave | Modelo |
|---|---|---|---|
| 1 | Gemini `generateContent` | `GEMINI_API_KEY` | `AI_MODEL` |
| 2 | Gemini | `GEMINI_API_KEY` | `AI_MODEL_FALLBACK` |
| 3 | Gemini | `GEMINI_API_KEY_FALLBACK` | `AI_MODEL` |
| 4 | Gemini | `GEMINI_API_KEY_FALLBACK` | `AI_MODEL_FALLBACK` |
| 5 | OpenAI-compatível | `AI_INTEGRATIONS_OPENAI_API_KEY` | `AI_MODEL` |

* `responseMimeType: application/json` + parser tolerante (aceita cercas ```json```).
* Prints são enviados como `inline_data` base64, respeitando o limite de bytes.
* **Nenhuma chave chega ao navegador** — o front só conversa com `api.php`.
* ⚠️ `AI_INTEGRATIONS_OPENAI_BASE_URL=http://localhost:1106/...` só funciona quando o
  PHP roda **na mesma máquina** do serviço (ex.: Replit/container). Em produção troque
  por um endpoint público — senão esse provedor falha e a cadeia cai para o Gemini.
* Diagnóstico em **Administração → Diagnóstico de IA**: mostra a cadeia, as chaves
  mascaradas e dispara uma sondagem real.

---

## 6. Idiomas

Fonte única em **português do Brasil**. Ao trocar para **ES** ou **EN**, as strings são
traduzidas por IA em lote e **cacheadas na tabela `i18n` do SQLite** — a tradução não se
repete a cada visita. Alterar o idioma na tela de login e dentro do app funciona igual.

---

## 7. Segurança

* Chaves apenas no `.env` (server-side); `.env` deve ficar fora do controle de versão.
* Sessão com cookie `HttpOnly` + `SameSite=Lax`, regeneração de ID no login.
* **CSRF** obrigatório em toda requisição mutante (`X-CSRF-Token`).
* Controle de acesso por perfil em cada rota (`admin` / `solicitante` / `testador`).
* Upload validado por MIME real (`finfo`), nome aleatório, extensão derivada do MIME.
* Imagens servidas por rota autenticada — nunca por caminho direto.
* `audit_log` registra logins, criação de projetos, feedbacks, movimentos de kanban e
  alterações administrativas.

---

## 8. Rotação de chaves (faça isto)

As chaves foram **transmitidas em texto claro** no pedido original; trate-as como
comprometidas.

1. Gere novas chaves nos consoles Google AI Studio / do provedor OpenAI-compatível.
2. Invalide (revogue) as chaves antigas nos respectivos consoles.
3. Atualize o `.env` e reinicie o PHP (ou recarregue o pool do PHP-FPM).
4. Confirme em **Administração → Diagnóstico de IA** que a sondagem passou com a chave nova.
5. Mantenha o `.env` fora do git e com permissão `chmod 600`.

---

## 9. Teste de fumaça

`smoke.php` sobe sessões isoladas por perfil e valida o fluxo ponta a ponta:
login, CSRF, geração de plano por IA, upload multipart, estruturação do feedback,
série temporal, persistência do kanban (grava → relê do SQLite), tradução por IA,
export CSV, parâmetros do admin e bloqueio de acesso por perfil.

```bash
php -S 127.0.0.1:8891 -t . &
php smoke.php
```

---

## 10. Backup

O estado inteiro é **um arquivo**: `data/testforge.sqlite` (+ `data/uploads/`).
Copie ambos para backup; restaurar é só devolver os arquivos no lugar.
