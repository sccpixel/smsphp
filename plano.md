# Plano — SMS Gateway (Slim 4)

> Documento de referência para o Claude Code. Contém a arquitetura, as especificações e as fases.
> As regras de trabalho e convenções estão em `CLAUDE.md`.

---

## 1. Visão geral

```
APP ──HTTPS──▶ Cloudflare Tunnel ──▶ Apache :8080 ──▶ Slim (public/index.php)
                                                            │
                         ┌──────────────── MySQL ───────────┤
                         │ sms_queue         (nossa)        │
                         │ managers          (nossa)        │
                         │ approval_types    (nossa)        │
                         │ approval_requests (nossa)        │
                         │ worker_state      (nossa)        │
                         │ sms_out  ◀── INSERT   (programa lê e envia)
                         │ sms_in   ──▶ SELECT   (programa grava as recebidas)
                         └──────────────────────────────────┘
                              ▲                        ▲
              bin/dispatcher.php             bin/approvals.php
              (sms_queue → sms_out)          (lê sms_in, decide, expira)
```

- **API**: só valida e grava na base de dados. Responde logo; nunca espera pelo envio.
- **Workers**: scripts PHP de linha de comandos em ciclo, que usam o mesmo container que a API.
- **Programa de SMS** ("SMS to DB"): não é alterado.

---

## 2. Estado do projeto (fases 1 a 7 concluídas; ver secção 9)

```
smsphp/
├── .htaccess              ← "Require all denied" (bloqueia a porta 80)
├── .env / .env.example
├── composer.json          ← autoload "App\\": "src/"
├── public/
│   ├── .htaccess          ← rewrite para index.php
│   └── index.php          ← monta a app: bootstrap → middleware → routes → run
├── config/
│   ├── bootstrap.php      ← autoload, .env, container; define APP_ROOT
│   ├── settings.php       ← array de configuração a partir do .env
│   ├── container.php      ← Settings, Connection, LoggerInterface (Monolog)
│   ├── middleware.php     ← body parsing, routing, ErrorMiddleware (JSON)
│   └── routes.php         ← GET /health
└── src/
    ├── Action/HealthAction.php     ← testa a BD; 200 ok / 503 degraded
    ├── Database/Connection.php     ← PDO lazy; ping(), lastError(), reset()
    ├── Http/Json.php               ← Json::write($response, $data, $status)
    └── Support/Settings.php        ← $settings->get('db.host')
```

---

## 3. Estrutura final

```
src/
├── Action/
│   ├── HealthAction.php
│   ├── Message/        CreateMessageAction, CreateMessageBatchAction, GetMessageAction
│   ├── Manager/        ListManagersAction
│   ├── ApprovalType/   ListApprovalTypesAction
│   └── Approval/       CreateApprovalAction, GetApprovalAction, CancelApprovalAction
├── Domain/
│   ├── Sms/            SmsService, SmsQueueRepository, SmsOutRepository, QueueStatus (enum)
│   ├── Manager/        ManagerRepository
│   ├── ApprovalType/   ApprovalTypeRepository
│   ├── Approval/       ApprovalService, ApprovalRepository, ApprovalStatus (enum),
│   │                   ReplyParser, ParsedReply, CodeGenerator
│   └── Inbox/          SmsInRepository, WorkerStateRepository
├── Exception/          ValidationException (422), NotFoundException (404), ConflictException (409)
├── Handler/            JsonErrorHandler
├── Middleware/         ApiAuthMiddleware
├── Support/            Settings, Phone, Validator, Clock, Text (toGsm)
└── Worker/             DispatcherWorker, ApprovalWorker, WorkerLoop
bin/
├── dispatcher.php
└── approvals.php
sql/
├── 01_sms_queue.sql
├── 02_managers_types.sql
└── 03_approvals.sql
```

---

## 4. Base de dados

### 4.1 Tabelas do programa de SMS (NÃO ALTERAR)

**`sms_out`** — o programa lê e envia (nome configurável em `SMS_OUT_TABLE`):

| Coluna           | Tipo              | Notas                                  |
| ---------------- | ----------------- | -------------------------------------- |
| id               | int PK AI         |                                        |
| recipient_number | varchar(50)       |                                        |
| sms_text         | varchar(255)      |                                        |
| msg_status       | int, default 0    | 0 = por enviar (inserimos sempre 0)    |
| instance_name    | varchar(255) NULL | **inserir NULL** se não vier no pedido |
| sent_dt          | datetime NULL     | preenchido pelo programa ao enviar     |

**`sms_in`** — o programa grava as recebidas (nome configurável em `SMS_IN_TABLE`). Só `SELECT`. Não tem coluna de estado.

| Coluna        | Notas                                      |
| ------------- | ------------------------------------------ |
| id            | crescente                                  |
| sms_text      | texto recebido                             |
| sender_number | formato pode variar: `+351…`, `351…`, `9…` |
| sent_dt       | data                                       |

### 4.2 `sms_queue` (já existe — manter exatamente)

```sql
CREATE TABLE IF NOT EXISTS sms_queue (
    id               INT          NOT NULL AUTO_INCREMENT,
    recipient_number VARCHAR(50)  NOT NULL,
    sms_text         VARCHAR(255) NOT NULL,
    instance_name    VARCHAR(255) NULL,
    status           TINYINT      NOT NULL DEFAULT 0  COMMENT '0=pending, 1=dispatched, 2=failed',
    send_id          INT          NULL                COMMENT 'id gerado em sms_out',
    error_message    VARCHAR(255) NULL,
    api_client       VARCHAR(50)  NULL                COMMENT 'prefixo da chave ou ip:x.x.x.x',
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dispatched_at    DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_status_id (status, id),
    KEY idx_send_id (send_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.3 `managers`

```sql
CREATE TABLE IF NOT EXISTS managers (
    id            INT          NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    phone_number  VARCHAR(50)  NOT NULL,             -- normalizado (ver 6.1)
    phone_key     VARCHAR(20)  NOT NULL,             -- últimos 9 dígitos
    is_default    TINYINT(1)   NOT NULL DEFAULT 0,   -- usado quando a APP não indica gestor
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_phone_key (phone_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Só pode haver **um** gestor com `is_default = 1` (garantido no código quando houver interface; por agora gere-se por SQL).

### 4.4 `approval_types`

```sql
CREATE TABLE IF NOT EXISTS approval_types (
    id          INT          NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,     -- usado pela APP: 'discount', 'stock_move'
    label       VARCHAR(50)  NOT NULL,     -- aparece no SMS, SEM acentos: 'Desconto', 'Mov. armazem'
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO approval_types (code, label) VALUES
  ('discount',   'Desconto'),
  ('stock_move', 'Mov. armazem');
```

### 4.5 `approval_requests`

```sql
CREATE TABLE IF NOT EXISTS approval_requests (
    id                INT          NOT NULL AUTO_INCREMENT,
    code              CHAR(4)      NOT NULL,             -- 4 dígitos, ver 7.3
    approval_type_id  INT          NOT NULL,
    manager_id        INT          NOT NULL,
    description       VARCHAR(120) NOT NULL,
    reference         VARCHAR(100) NULL,                 -- id interno na APP
    requested_by      VARCHAR(100) NULL,                 -- operador que pediu
    status            VARCHAR(20)  NOT NULL DEFAULT 'pending',
    callback_url      VARCHAR(255) NULL,                 -- a APP é avisada aqui quando o pedido é decidido (ver 8.3)
    callback_status   VARCHAR(10)  NOT NULL DEFAULT 'none',  -- none | pending | sent | failed
    callback_attempts INT          NOT NULL DEFAULT 0,
    callback_next_at  DATETIME     NULL,
    callback_sent_at  DATETIME     NULL,
    callback_error    VARCHAR(255) NULL,
    created_at        DATETIME     NOT NULL,
    expires_at        DATETIME     NOT NULL,
    responded_at      DATETIME     NULL,
    response_text     VARCHAR(255) NULL,                 -- resposta original do gestor
    response_sms_id   INT          NULL,                 -- id da resposta em sms_in
    queue_id          INT          NULL,                 -- id do SMS enviado em sms_queue
    api_client        VARCHAR(50)  NULL,
    PRIMARY KEY (id),
    KEY idx_manager_status (manager_id, status),
    KEY idx_code_status (code, status),
    KEY idx_status_expires (status, expires_at),
    CONSTRAINT fk_ar_type    FOREIGN KEY (approval_type_id) REFERENCES approval_types (id),
    CONSTRAINT fk_ar_manager FOREIGN KEY (manager_id)       REFERENCES managers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Estados (`ApprovalStatus`): `pending`, `approved`, `rejected`, `expired`, `cancelled`.

```
pending ──▶ approved   (gestor respondeu SIM)
        ──▶ rejected   (gestor respondeu NAO)
        ──▶ expired    (passou expires_at)
        ──▶ cancelled  (a APP cancelou)
```

Só se sai de `pending`; os outros estados são finais.

### 4.6 `worker_state`

```sql
CREATE TABLE IF NOT EXISTS worker_state (
    name        VARCHAR(50) NOT NULL,     -- ex.: 'sms_in'
    last_id     INT         NOT NULL DEFAULT 0,
    updated_at  DATETIME    NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 5. Configuração (`.env`)

```ini
# Aplicação
APP_NAME="SMS Gateway"
APP_DEBUG=false
APP_TIMEZONE=Europe/Lisbon
LOG_LEVEL=info

# Base de dados
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=sms
DB_USER=root
DB_PASSWORD=

# Tabelas do programa de SMS
SMS_OUT_TABLE=sms_out
SMS_IN_TABLE=sms_in
SMS_QUEUE_TABLE=sms_queue

# Segurança da API
AUTH_ENABLED=true            # false = aceita pedidos sem chave
API_KEYS=                    # várias separadas por vírgula
ALLOWED_IPS=                 # vazio = qualquer IP

# Mensagens
MAX_TEXT_LENGTH=255

# Dispatcher (sms_queue → sms_out)
DISPATCH_INTERVAL_SECONDS=5
DISPATCH_BATCH_SIZE=10

# Aprovações
APPROVAL_EXPIRY_MINUTES=10
APPROVAL_POLL_SECONDS=3
APPROVAL_SEND_FEEDBACK=true
APPROVAL_INBOX_BATCH=50

# Workers
WORKER_MAX_CONSECUTIVE_ERRORS=10
```

Tudo passa por `config/settings.php`, organizado em secções (`app`, `db`, `log`, `tables`, `auth`, `sms`, `dispatcher`, `approvals`, `worker`).

---

## 6. Regras transversais

### 6.1 Números de telefone (`App\Support\Phone`)

- `normalize(string): string` — o número guardado e enviado ao programa de SMS **nunca leva `+351`**: o resultado são só dígitos (ex.: `912345678`). A API aceita `+351912345678`, `00351912345678`, `351912345678` ou `912345678` e todos dão `912345678`.
    1. tira espaços, `-`, `(`, `)`, `.`;
    2. remove o prefixo `+351` ou `00351`; remove também `351` se o resto tiver exatamente 9 dígitos;
    3. se ainda começar por `+` ou `00` (outro indicativo) → `ValidationException` (só se aceitam números portugueses);
    4. valida `^\d{6,15}$`; se for inválido → `ValidationException`.
- `key(string): string` — só os dígitos, e destes os **últimos 9**. Serve para comparar o `sender_number` do `sms_in`, que pode vir como `+351912345678`, `351912345678` ou `912345678`: todos dão `912345678`.

### 6.2 Autenticação (`ApiAuthMiddleware`, aplicado ao grupo `/api`)

1. **IP real**: se `REMOTE_ADDR` for `127.0.0.1` ou `::1` (pedido vindo do cloudflared), usar o header `CF-Connecting-IP`; caso contrário usar `REMOTE_ADDR`. Nunca confiar no header se o pedido não vier da própria máquina.
2. Se `ALLOWED_IPS` não estiver vazio e o IP não estiver na lista → **403** `"IP não autorizado"`.
3. Se `AUTH_ENABLED=false` → deixa passar; o cliente fica identificado como `ip:<ip>`.
4. Se `AUTH_ENABLED=true` e `API_KEYS` estiver vazio → **503** `"API sem chaves configuradas"` (falha fechada).
5. A chave vem **só** no header `X-API-Key` (a query string `?api_key=` foi retirada: a URL fica nos logs do Apache e do Cloudflare). Comparar com `hash_equals()`. Se for inválida ou faltar → **401**.
6. Guardar o identificador do cliente (os primeiros 8 caracteres da chave, ou `ip:<ip>`) no atributo do request `api_client`.

`/health` fica **fora** da autenticação.

### 6.3 Erros (`JsonErrorHandler`)

| Exceção                                       | HTTP | Corpo                                                        |
| --------------------------------------------- | ---- | ------------------------------------------------------------ |
| `ValidationException`                         | 422  | `{"error":"Dados inválidos","details":{"campo":"mensagem"}}` |
| `NotFoundException` / `HttpNotFoundException` | 404  | `{"error":"..."}`                                            |
| `ConflictException`                           | 409  | `{"error":"..."}`                                            |
| `HttpMethodNotAllowedException`               | 405  | `{"error":"Método não permitido"}`                           |
| outras                                        | 500  | `{"error":"Erro interno"}` (+ detalhes se `APP_DEBUG=true`)  |

Os erros 4xx vão para o log como `warning`, sem stack trace; os 5xx vão como `error`, com stack trace.

### 6.4 Datas

Todas as datas são calculadas em PHP (`Clock`, fuso `APP_TIMEZONE`) e passadas como parâmetro (`:now`), **incluindo** `sms_queue.created_at` e `dispatched_at`. Nunca `NOW()`/`CURRENT_TIMESTAMP` nas nossas queries, para as datas das várias tabelas serem comparáveis. (O `DEFAULT CURRENT_TIMESTAMP` da `sms_queue` fica só como rede de segurança.)

### 6.5 Leitura do body

Aceitar JSON e `application/x-www-form-urlencoded`. Se o `Content-Type` faltar e `getParsedBody()` vier vazio, tentar `json_decode` do body em bruto.

---

## 7. Especificação da API

Todas as rotas `/api/*` passam pelo `ApiAuthMiddleware`.

### 7.1 Mensagens (contrato igual ao da versão Python — NÃO MUDAR)

**`POST /api/messages`**

```json
{ "to": "912345678", "text": "Olá", "instance_name": null }
```

- `to`: obrigatório, normalizado com `Phone::normalize`.
- `text`: obrigatório; `trim`; entre 1 e `MAX_TEXT_LENGTH` caracteres (`mb_strlen`).
- `instance_name`: opcional, até 255 caracteres; se faltar → **NULL**.
- Resposta **201**: `{"id": 15, "status": "pending", "created_at": "2026-10-15T10:00:00"}`

**`POST /api/messages/batch`**

```json
{ "messages": [ { "to": "...", "text": "..." }, ... ] }
```

- Entre 1 e 500 mensagens. Se alguma for inválida → 422 e **nenhuma** é inserida (tudo numa transação). Erros com chave `messages.3.to`.
- Resposta **201**: `{"count": 2, "ids": [16, 17]}`

**`GET /api/messages/{id}`** → 200 ou 404

```json
{
    "id": 15,
    "to": "912345678",
    "text": "Olá",
    "instance_name": null,
    "queue_status": "pending|dispatched|failed",
    "send_status": 0,
    "sent_at": null,
    "created_at": "...",
    "dispatched_at": null,
    "error": null
}
```

`send_status` e `sent_at` vêm de `sms_out` (`msg_status`, `sent_dt`) através do `send_id` (**só `SELECT`**); são `null` enquanto a mensagem não tiver sido despachada. O programa de SMS põe `msg_status = 2` quando o envio falha.

### 7.2 Gestores e tipos (só leitura, por agora)

- **`GET /api/managers`** → `[{"id":1,"name":"João","is_default":true}]`. Só os ativos. **Não devolver o número de telefone.**
- **`GET /api/approval-types`** → `[{"code":"discount","label":"Desconto"}]`. Só os ativos.

### 7.3 Aprovações

**`POST /api/approvals`**

```json
{
    "type": "discount",
    "description": "15% na venda 1234, cliente Silva",
    "reference": "VENDA-1234",
    "requested_by": "Operador Ana",
    "manager_id": 1,
    "callback_url": null
}
```

- `type`: obrigatório; tem de existir em `approval_types` e estar ativo → senão 422.
- `description`: obrigatória, entre 1 e 120 caracteres.
- `reference` (≤100), `requested_by` (≤100), `callback_url` (≤255, URL válida): opcionais.
- `manager_id`: **obrigatório** (a API serve várias apps independentes, por isso não há gestor por defeito). Tem de ser um inteiro; se faltar, ou se o gestor não existir ou estiver inativo → 422.
- Numa **transação**:
    1. gerar o código (ver abaixo);
    2. inserir em `approval_requests` com `created_at = now` e `expires_at = now + APPROVAL_EXPIRY_MINUTES`;
    3. inserir o SMS em `sms_queue` (`instance_name` NULL, `api_client` do pedido);
    4. guardar o `queue_id`.
- Resposta **201**: `{"id": 7, "code": "4821", "status": "pending", "expires_at": "..."}`

**Código**: `random_int(1000, 9999)`, como string. Tem de ser único entre os pedidos `pending`; se houver colisão, gerar outro (máximo 20 tentativas, depois lança exceção).

**Texto do SMS** (sem acentos nem caracteres especiais, até 255 caracteres; a descrição passa por `Text::toGsm()`, que tira acentos e caracteres fora do GSM-7, e é cortada se for preciso):

```
Pedido 4821 - Desconto: 15% na venda 1234, cliente Silva. Responda SIM 4821 ou NAO 4821. Valido 10 min.
```

**`GET /api/approvals/{id}`** → 200 ou 404

```json
{
    "id": 7,
    "code": "4821",
    "type": "discount",
    "type_label": "Desconto",
    "description": "...",
    "reference": "VENDA-1234",
    "requested_by": "Operador Ana",
    "manager": { "id": 1, "name": "João" },
    "status": "pending|approved|rejected|expired|cancelled",
    "created_at": "...",
    "expires_at": "...",
    "responded_at": null,
    "response_text": null,
    "sms_status": "pending|sent|failed"
}
```

`sms_status` diz se o SMS **ao gestor** chegou a ser enviado, para a APP saber se o pedido nunca chegou ao gestor. Calcula-se a partir de `queue_id`: `sms_queue.status = 2` → `failed`; `status = 0` → `pending`; `status = 1` → olha para `sms_out.msg_status` (0 → `pending`, 2 → `failed`, outro → `sent`). Se falhar, o pedido **continua `pending`** (não há estado novo) e acaba por expirar.

Se estiver `pending` mas `expires_at <= now`, devolver `expired` (o worker persiste a mudança).

**`POST /api/approvals/{id}/cancel`** → 200 com o pedido atualizado; 404 se não existir; **409** se já não estiver `pending`.

A APP sabe o resultado **consultando o `GET`** de poucos em poucos segundos, ou sendo avisada pelo **callback** (ver 8.3) se indicou um `callback_url`.

---

## 8. Workers

Comum aos dois (`WorkerLoop`):

- `bin/*.php` faz `require config/bootstrap.php` e obtém o worker a partir do container.
- Ciclo: executa um "tick"; dorme o que faltar do intervalo; repete.
- Opção `--once`: executa um tick e termina (útil para testes).
- Erro num tick → log `error`, `Connection::reset()` e continua. Ao fim de `WORKER_MAX_CONSECUTIVE_ERRORS` erros seguidos → termina com código 1 (o gestor de serviços volta a arrancá-lo).
- `set_time_limit(0)`. Logs também para o ecrã (já configurado para CLI).

### 8.1 Dispatcher (`bin/dispatcher.php`)

A cada `DISPATCH_INTERVAL_SECONDS`, numa transação (`sms_out` e `sms_queue` são InnoDB):

1. `SELECT ... FROM sms_queue WHERE status = 0 ORDER BY id LIMIT :batch FOR UPDATE`
2. para cada uma: `INSERT` em `sms_out` (`msg_status = 0`, `sent_dt = NULL`) e depois `UPDATE sms_queue SET status = 1, send_id = ?, dispatched_at = :now`
3. `COMMIT`. Se houver erro → `ROLLBACK`; as mensagens continuam pendentes e voltam a ser tentadas no ciclo seguinte.

Log `info` com o número de mensagens despachadas (só quando for > 0).

### 8.2 Aprovações (`bin/approvals.php`)

A cada `APPROVAL_POLL_SECONDS`:

**a) Expirar**: `UPDATE approval_requests SET status='expired' WHERE status='pending' AND expires_at <= :now`.

**b) Ler o `sms_in`**:

1. Cursor `worker_state.name = 'sms_in'`. **Na primeira execução**, se não existir, criá-lo com `last_id = MAX(id)` do `sms_in` (ignora as mensagens antigas) e terminar o tick. Se `MAX(id) < last_id` (tabela esvaziada, só acontece em testes), repor o cursor com `MAX(id)` e terminar o tick.
2. `SELECT id, sms_text, sender_number, sent_dt FROM sms_in WHERE id > :last ORDER BY id LIMIT :batch`.
3. **Cada mensagem na sua própria transação**: bloquear o cursor (`FOR UPDATE`); ignorar se `id <= last_id`; processar; atualizar `last_id`; `COMMIT`. Se der erro → `ROLLBACK`, log e parar o tick (tenta de novo no seguinte).

**c) Interpretar a resposta (`ReplyParser`)** — classe pura, sem acesso à base de dados. O formato é **estrito**:

- Normalizar: `trim`, maiúsculas, tirar acentos (`NÃO` → `NAO`).
- Tem de corresponder exatamente a `^(SIM|NAO) (\d{4})$` (maiúsculas/minúsculas e o acento do "não" são a única variação aceite). Qualquer outra coisa (`SIM` sozinho, `OK 4821`, `SIM 4821 obrigado`, `SIM 4821 4822`) → **não reconhecida**.
- Devolve `ParsedReply { bool $approve, string $code }` ou `null` se não for reconhecida.

**d) Aplicar** (`ApprovalService::handleReply`):

1. Gestor = `managers` com `phone_key = Phone::key(sender_number)` e `active = 1`. **Se não for gestor → ignorar** (log `info`), sem resposta.
2. Resposta **não reconhecida** → ignorar (log `info`), **sem enviar SMS**.
3. Resposta reconhecida: procurar o pedido `pending` deste gestor com esse código e `expires_at > :now`.
    - encontrado → aplicar;
    - existe um pedido deste gestor com esse código mas já fechado (o mais recente) → `Pedido 4821 ja estava <estado>.` (estados em PT sem acentos: aprovado, rejeitado, expirado, cancelado);
    - não existe → `Pedido 4821 nao encontrado.`
4. Aplicar = `UPDATE ... SET status = approved|rejected, responded_at = :now, response_text = <texto, máx. 255>, response_sms_id = <id sms_in> WHERE id = ? AND status = 'pending'`. Depois responder `Pedido 4821 aprovado.` ou `Pedido 4821 rejeitado.`
5. As respostas ao gestor só são enviadas se `APPROVAL_SEND_FEEDBACK=true`. São inseridas em `sms_queue`, para o número **guardado em `managers.phone_number`**, com `api_client = 'approvals'`.

### 8.3 Callbacks (`bin/callbacks.php`)

Se o pedido tem `callback_url`, o gateway avisa a APP (POST) quando ele é decidido. É um worker **à parte** (`sms-callbacks`), para uma APP lenta nunca atrasar a leitura das respostas dos gestores.

- **Quando**: `approved`, `rejected` e `expired`. O cancelamento não gera aviso (foi a APP que o pediu). O `respond()` e o `expireOverdue()` marcam `callback_status = 'pending'` na mesma instrução que muda o estado (se houver `callback_url`).
- **Ciclo** (`CALLBACK_INTERVAL_SECONDS`): `findDueCallbacks` (`callback_status = 'pending' AND callback_next_at <= :now`) → envia → `sent`, ou agenda nova tentativa, ou `failed`.
- **Pedido**: `POST` JSON (o mesmo corpo do `GET /api/approvals/{id}` mais `event`, sem `callback_status`). Cabeçalhos `X-Gateway-Event`, `X-Gateway-Delivery` (`<id>-<tentativa>`), `X-Gateway-Timestamp` e `X-Gateway-Signature: sha256=<HMAC-SHA256 de "<timestamp>.<corpo>" com a chave de API da APP>`. Sem chave (modo de testes sem autenticação), não há assinatura.
- **Sucesso** = resposta 2xx. Qualquer outra coisa (4xx, 5xx, 3xx, timeout, ligação recusada) é falha. **Não segue redirecionamentos.** Timeout `CALLBACK_TIMEOUT_SECONDS` (5).
- **Tentativas**: a 1.ª é logo; depois uma por cada valor de `CALLBACK_RETRY_MINUTES` (por defeito 1, 2, 5, 10, 20, 30 = 7 tentativas em cerca de 1 hora). Depois disso `failed`. Entrega "pelo menos uma vez": a APP tem de tratar avisos repetidos.
- **Segurança (SSRF)**: por defeito (`CALLBACK_ALLOW_INTERNAL=false`) só `https` e endereços **públicos**; recusa `localhost`, redes privadas, link-local (169.254.x.x) e CGNAT. Valida-se ao criar o pedido (422) e **outra vez no envio**, resolvendo o DNS e ligando ao IP verificado (contra *DNS rebinding*). Um destino proibido falha logo, sem repetir; um DNS que não resolve repete. Sem utilizador e password no URL.

---

## 9. Fases

> Uma fase por sessão. No fim de cada uma: correr os testes de aceitação, mostrar os resultados e **parar** para revisão.

### ✅ Fase 1 — Projeto inicial

Composer, `public/index.php`, `.htaccess`, virtual host na porta 8080.

### ✅ Fase 2 — Estrutura, container e base de dados

`config/*`, `Settings`, `Connection`, Monolog, `HealthAction`.

### ✅ Fase 3 — Erros, validação e autenticação

- `src/Exception/*`, `src/Handler/JsonErrorHandler.php` (substitui o handler por defeito em `config/middleware.php`).
- `src/Support/Phone.php`, `src/Support/Validator.php` (pequeno: `required`, `string`, `maxLength`, `int`, `url`; junta os erros todos e lança `ValidationException`).
- `src/Support/Clock.php` (`now(): DateTimeImmutable` no fuso da app; injetável, para os testes).
- `.htaccess` na raiz (`Require all denied`) e `.env.example`.
- `src/Middleware/ApiAuthMiddleware.php` + grupo `/api` em `config/routes.php`.
- Novas secções em `settings.php`: `auth`, `tables` (validar os nomes), `sms`.
- Rota temporária `GET /api/ping` → `{"client":"<api_client>"}`, para testar a autenticação (remover na fase 4).

**Aceitação**

```cmd
curl.exe http://127.0.0.1:8080/health                                  → 200, sem chave
curl.exe http://127.0.0.1:8080/api/ping                                → 401
curl.exe -H "X-API-Key: <chave>" http://127.0.0.1:8080/api/ping        → 200
curl.exe "http://127.0.0.1:8080/api/ping?api_key=<chave>"              → 401 (query string já não é aceite)
(ALLOWED_IPS=1.2.3.4)  pedido local                                    → 403
(AUTH_ENABLED=true, API_KEYS vazio)                                    → 503
curl.exe -X DELETE http://127.0.0.1:8080/health                        → 405 em JSON
```

### ✅ Fase 4 — Envio de SMS

- `sql/01_sms_queue.sql` (`CREATE TABLE IF NOT EXISTS`: não mexe se já existir).
- `Domain/Sms/*`, `Action/Message/*`, rotas `/api/messages`.
- `Worker/WorkerLoop.php`, `Worker/DispatcherWorker.php`, `bin/dispatcher.php` (com `--once`).
- Secção `dispatcher` e `worker` em `settings.php`.
- Remover `/api/ping`.

**Aceitação**

- `POST /api/messages` válido → 201 e linha em `sms_queue` com `status = 0` e `instance_name` NULL.
- Número inválido / texto vazio / texto com 256 caracteres → 422 com `details`.
- Body enviado como formulário (`-d "to=912345678&text=Ola"`) → 201.
- Batch com 3 mensagens, uma delas inválida → 422 e **0** inseridas.
- `php bin\dispatcher.php --once` com `DISPATCH_BATCH_SIZE=2` e 3 pendentes → passa 2 para `sms_out`; à segunda execução passa a terceira.
- `GET /api/messages/{id}` depois do dispatch → `queue_status: "dispatched"`, `send_status: 0`.
- Acentos ("Olá, marcação") chegam intactos a `sms_out`.
- O snippet PHP da integração antiga continua a funcionar sem alterações.

### ✅ Fase 5 — Gestores e tipos de pedido

- `sql/02_managers_types.sql` (tabelas + seed dos tipos).
- `Domain/Manager/ManagerRepository`, `Domain/ApprovalType/ApprovalTypeRepository`.
- `GET /api/managers`, `GET /api/approval-types`.

**Aceitação**

- Inserir 2 gestores por SQL (um `is_default`, um inativo) → `GET /api/managers` devolve só o ativo, sem o número.
- `GET /api/approval-types` devolve os dois tipos.

### ✅ Fase 6 — Pedidos de aprovação (API)

- `sql/03_approvals.sql` (`approval_requests` + `worker_state`).
- `Domain/Approval/*` (sem o parser), `Action/Approval/*`, `Support/Text` (`toGsm`), rotas.

**Aceitação**

- `POST /api/approvals` com `manager_id` → 201; código com 4 dígitos; SMS em `sms_queue` para o telefone desse gestor, com o texto do formato 7.3. **Sem** `manager_id` → 422.
- Tipo inexistente / gestor inativo / descrição com 121 caracteres → 422.
- Descrição com acentos ("João, açúcar") → o SMS em `sms_queue` sai sem acentos.
- `GET /api/approvals/{id}` → `pending`, com `sms_status`; com `APPROVAL_EXPIRY_MINUTES=0` → `expired`; se o SMS ficar `failed` → `sms_status: "failed"`.
- `POST .../cancel` → 200 `cancelled`; segundo cancel → 409.
- 50 pedidos seguidos → nenhum código repetido entre os pendentes.

### ✅ Fase 7 — Worker de aprovações

- `Domain/Approval/ReplyParser`, `ParsedReply`; `Domain/Inbox/*`; `Worker/ApprovalWorker`; `bin/approvals.php`.
- Testes com `phpunit/phpunit` (`require-dev`, aprovado): `tests/` para `ReplyParser`, `Phone` e `Text`; correr com `composer test`.

**Aceitação** (simular respostas com `INSERT INTO sms_in (sms_text, sender_number, sent_dt) VALUES (...)`)

- Primeira execução: cursor criado com `MAX(id)`; as mensagens antigas são ignoradas.
- `"SIM 4821"` do gestor → `approved`, `response_text` guardado, resposta `Pedido 4821 aprovado.` em `sms_queue`.
- `"nao 4821"` e `"NÃO 4821"` → `rejected`.
- `"sim"`, `"OK 4821"`, `"SIM 4821 obrigado"`, `"SIM 4821 4822"`, `"blabla"` do gestor → nada muda e **nenhum** SMS enviado.
- `"SIM 4821"` de um número desconhecido → nada muda; **nenhum** SMS enviado.
- `"SIM 4821"` depois de expirado → resposta `ja estava expirado`; `"SIM 9999"` (código inexistente) → `nao encontrado`.
- `"+351912345678"` vs `"912345678"` no `sender_number` → reconhecido como o mesmo gestor.
- Apagar tudo do `sms_in` (só em testes) → o cursor é reposto.
- Correr o worker duas vezes seguidas → nenhuma resposta processada em duplicado.
- Parser: `"Sim 4821"`, `" sim 4821 "`, `"NÃO 4821"`, `"nao 4821"` → reconhecidos; `"SIM"`, `"s 4821"`, `"sim 482"`, `"aprovo 4821"` → `null`.

### Fase 8 — Operação (preparada: README, scripts e SQL feitos; falta instalar no servidor e fazer o teste de reinício)

- `README.md`: instalação no XAMPP, `.env`, ordem dos scripts SQL, virtual host, tunnel, exemplos `curl.exe`.
- Migração a partir do Python: parar o uvicorn e o dispatcher Python; a `sms_queue` mantém-se; arrancar `bin/dispatcher.php`.
- Correr os workers como serviços do Windows com **NSSM** (`nssm install sms-dispatcher C:\xampp\php\php.exe C:\xampp\htdocs\smsphp\bin\dispatcher.php`, com o diretório de trabalho e o reinício automático configurados), e o mesmo para o `approvals`.
- Utilizador MySQL próprio com permissões mínimas (`SELECT/INSERT/UPDATE` nas nossas tabelas, `INSERT` + `SELECT` em `sms_out`, `SELECT` em `sms_in`).

**Aceitação**: reiniciar o PC → a API, os dois workers e o tunnel arrancam sozinhos; `GET https://<subdominio>/health` → 200.

### Fase 9 (futuro, fora do âmbito atual) — Interface de gestão

Grupo `/admin` com login (tabela `users`, sessão PHP, `password_hash`), `slim/twig-view`, Bootstrap 5 + jQuery. Ecrãs: gestores (CRUD, gestor por defeito), tipos de pedido, lista de pedidos com filtros, fila de SMS. Reutiliza os Services existentes. Envio do `callback_url` quando um pedido muda de estado.

---

## 10. Pontos a confirmar antes da fase 4

- [x] Nome real da tabela de envio: `sms_out` (onde se inserem os SMS a enviar); `sms_in` é onde chegam as recebidas. Ambas InnoDB.
- [ ] Parar a API e o dispatcher em Python antes de arrancar o dispatcher PHP (nunca os dois ao mesmo tempo).
