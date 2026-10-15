# SMS Gateway — documentação da API

API REST em JSON para **enviar SMS** e **pedir aprovações a gestores por SMS**
(descontos, movimentos de armazém, etc.). Destinada a quem desenvolve APPs que a utilizam.

- [1. Conceitos](#1-conceitos)
- [2. Começar](#2-começar)
- [3. Mensagens (SMS)](#3-mensagens-sms)
- [4. Aprovações](#4-aprovações)
- [5. Gestores e tipos de pedido](#5-gestores-e-tipos-de-pedido)
- [6. Erros](#6-erros)
- [7. Exemplos de integração](#7-exemplos-de-integração)
- [8. Boas práticas](#8-boas-práticas)

---

## 1. Conceitos

**A API não envia o SMS na hora.** Valida o pedido, guarda-o numa fila e responde logo
(`201`). Um processo à parte passa a mensagem ao programa de SMS, que a envia pelo modem.
Por isso, o `201` quer dizer "recebido e na fila", não "entregue". Para saber o que
aconteceu a seguir, consulta o estado (`GET`).

**Aprovações.** A APP pede a aprovação de algo; a API envia um SMS ao gestor com um código
de 4 dígitos; o gestor responde `SIM 4821` ou `NAO 4821`; a APP consulta o resultado.

```
APP ──POST /api/approvals──▶ API ──SMS "Pedido 4821 ... Responda SIM 4821 ou NAO 4821"──▶ Gestor
APP ◀── 201 {id, code, expires_at}                                                        │
                                                                                          │ "SIM 4821"
APP ──GET /api/approvals/{id}  (de poucos em poucos segundos)                             ▼
APP ◀── {"status":"approved", ...} ◀──────────────────────────────────────────────────── API
```

---

## 2. Começar

### URL base

| Ambiente | URL |
| --- | --- |
| Produção | `https://<subdomínio-do-tunnel>` (indicado pelo administrador) |
| Testes locais | `http://127.0.0.1:8080` |

### Autenticação

Todas as rotas `/api/*` exigem uma chave no header **`X-API-Key`**.

```
X-API-Key: <a-sua-chave>
```

- A chave pede-se ao administrador. Cada aplicação deve ter a sua.
- **Só no header.** A chave na URL (`?api_key=...`) **não é aceite**, porque ficaria nos logs.
- Guarda a chave no servidor da APP; nunca a coloques em código que corra no browser ou no telemóvel do utilizador final.
- O administrador pode também limitar os IPs que podem usar a API.

`GET /health` é público (não pede chave).

### Formato dos pedidos

- Enviar JSON com `Content-Type: application/json`. Também são aceites formulários (`application/x-www-form-urlencoded`).
- As respostas são sempre JSON em UTF-8.
- **Datas:** `AAAA-MM-DDTHH:MM:SS`, sem fuso horário, **hora de Portugal** (ex.: `2026-10-15T10:00:00`).

### Números de telefone

Qualquer um destes formatos é aceite e dá o mesmo resultado (`912345678`):

`+351912345678` · `00351912345678` · `351912345678` · `912345678` · `+351 912 345 678` (espaços, hífenes e parênteses são ignorados)

Só números portugueses: um número com outro indicativo (`+44...`) é rejeitado com `422`.
A API guarda e envia o número **sem** `+351`.

### Verificar que está tudo vivo

```
GET /health
```
```json
{ "status": "ok", "app": "SMS Gateway", "database": true, "time": "2026-10-15T10:00:00+01:00" }
```
`200` se estiver tudo bem; `503` com `"status": "degraded"` se a base de dados não responder.

---

## 3. Mensagens (SMS)

### Criar uma mensagem

```
POST /api/messages
```

| Campo | Tipo | Obrigatório | Regras |
| --- | --- | --- | --- |
| `to` | texto | sim | número português (ver acima) |
| `text` | texto | sim | 1 a 255 caracteres (espaços nas pontas são removidos) |
| `instance_name` | texto | não | até 255 caracteres; identifica o modem/instância, se houver mais de um. Se faltar, fica sem valor e é o programa de SMS que decide |

```json
{ "to": "+351 912 345 678", "text": "Olá, a sua marcação está confirmada.", "instance_name": null }
```

**Resposta `201`**
```json
{ "id": 15, "status": "pending", "created_at": "2026-10-15T10:00:00" }
```

Guarda o `id`: serve para consultar o estado.

> Um SMS com mais de 160 caracteres (ou 70, se tiver acentos ou símbolos fora do alfabeto
> GSM) é enviado em várias partes pelo operador. A API aceita até 255 caracteres.

### Criar várias mensagens (lote)

```
POST /api/messages/batch
```

```json
{
  "messages": [
    { "to": "912345678", "text": "Primeira" },
    { "to": "913456789", "text": "Segunda", "instance_name": "modem1" }
  ]
}
```

- Entre 1 e 500 mensagens.
- **Tudo ou nada:** se alguma for inválida, **nenhuma** é gravada e recebes `422`. Os erros identificam a posição (começa em 0): `"messages.2.to": "Número de telefone inválido"`.

**Resposta `201`**
```json
{ "count": 2, "ids": [16, 17] }
```

### Consultar uma mensagem

```
GET /api/messages/{id}
```

**Resposta `200`**
```json
{
  "id": 15,
  "to": "912345678",
  "text": "Olá, a sua marcação está confirmada.",
  "instance_name": null,
  "queue_status": "dispatched",
  "send_status": 1,
  "sent_at": "2026-10-15T10:00:07",
  "created_at": "2026-10-15T10:00:00",
  "dispatched_at": "2026-10-15T10:00:05",
  "error": null
}
```

| Campo | Significado |
| --- | --- |
| `queue_status` | `pending` — ainda não foi passada ao programa de SMS · `dispatched` — já foi passada · `failed` — não foi possível passá-la (ver `error`) |
| `send_status` | Estado do envio pelo programa de SMS: `null` enquanto não foi passada; `0` por enviar; `2` erro no envio. Outros valores são do programa de SMS (o envio concluído aparece com `sent_at` preenchido) |
| `sent_at` | Quando o programa de SMS a enviou (`null` até lá) |
| `error` | Motivo, se `queue_status` for `failed` |

Dá `404` se o `id` não existir.

**Como saber se foi mesmo enviada?** Quando `sent_at` está preenchido. Se `send_status = 2`, o envio falhou. Enquanto `send_status` for `0`, ainda está à espera do modem.

---

## 4. Aprovações

### Estados de um pedido

| `status` | Significado | Final? |
| --- | --- | --- |
| `pending` | À espera da resposta do gestor | não |
| `approved` | O gestor respondeu `SIM` | sim |
| `rejected` | O gestor respondeu `NAO` | sim |
| `expired` | Passou o prazo sem resposta | sim |
| `cancelled` | A APP cancelou | sim |

Só se sai de `pending`; os restantes são finais.

### Criar um pedido

```
POST /api/approvals
```

| Campo | Tipo | Obrigatório | Regras |
| --- | --- | --- | --- |
| `type` | texto | sim | código de um tipo ativo (ver [tipos](#tipos-de-pedido)), ex.: `discount`, `stock_move` |
| `description` | texto | sim | 1 a 120 caracteres; é o que o gestor lê no SMS |
| `reference` | texto | não | até 100 caracteres; o identificador do pedido na vossa APP (ex.: `VENDA-1234`) |
| `requested_by` | texto | não | até 100 caracteres; quem pediu (ex.: o operador) |
| `manager_id` | inteiro | **sim** | id do gestor que decide, obtido em [GET /api/managers](#gestores). Tem de existir e estar ativo. **Não há gestor por defeito**: a API serve várias aplicações e cada pedido diz sempre a quem vai |
| `callback_url` | URL | não | até 255 caracteres. Endereço da APP que o gateway avisa (`POST`) quando o pedido é aprovado, rejeitado ou expira. Tem de ser `https://` e **público** (ver [Avisos automáticos](#avisos-automáticos-callback)) |

```json
{
  "type": "discount",
  "description": "15% na venda 1234, cliente Silva",
  "reference": "VENDA-1234",
  "requested_by": "Operador Ana",
  "manager_id": 1
}
```

**Resposta `201`**
```json
{ "id": 7, "code": "4821", "status": "pending", "expires_at": "2026-10-15T10:10:00" }
```

O gestor recebe:

> `Pedido 4821 - Desconto: 15% na venda 1234, cliente Silva. Responda SIM 4821 ou NAO 4821. Valido 10 min.`

- O SMS vai para o telefone **guardado no gestor**; a APP nunca indica números.
- O texto do SMS **não leva acentos nem caracteres especiais** (`João` → `Joao`), para caber num só SMS. A `description` guardada e devolvida pela API mantém o texto original.
- O `code` é único entre os pedidos pendentes. Não é preciso que a APP o mostre ao utilizador, mas pode ser útil para o operador.
- O prazo (`expires_at`) é configurado no servidor (por defeito, 10 minutos).

### Consultar um pedido

```
GET /api/approvals/{id}
```

**Resposta `200`**
```json
{
  "id": 7,
  "code": "4821",
  "type": "discount",
  "type_label": "Desconto",
  "description": "15% na venda 1234, cliente Silva",
  "reference": "VENDA-1234",
  "requested_by": "Operador Ana",
  "manager": { "id": 1, "name": "João" },
  "status": "approved",
  "created_at": "2026-10-15T10:00:00",
  "expires_at": "2026-10-15T10:10:00",
  "responded_at": "2026-10-15T10:02:31",
  "response_text": "SIM 4821",
  "sms_status": "sent",
  "callback_status": "sent"
}
```

| Campo | Significado |
| --- | --- |
| `status` | Ver tabela de estados. Se o prazo já passou e ninguém respondeu, vem logo `expired` |
| `responded_at`, `response_text` | Quando e o que o gestor respondeu (`null` se ainda não respondeu) |
| `sms_status` | Estado do **SMS enviado ao gestor**: `pending` — ainda não saiu · `sent` — enviado · `failed` — **nunca chegou ao gestor** |
| `callback_status` | Estado do **aviso à tua APP**: `none` — o pedido não tem `callback_url` (ou ainda não foi decidido) · `pending` — à espera de ser enviado ou de nova tentativa · `sent` — a APP respondeu 2xx · `failed` — o gateway desistiu (a APP continua a poder consultar o pedido) |

**Importante:** se `sms_status` for `failed`, o gestor nunca viu o pedido. O `status` continua
`pending` até expirar, por isso a APP deve tratar este caso (avisar o operador, cancelar e
tentar de novo, escolher outro gestor).

Dá `404` se o `id` não existir.

### Saber o resultado

Há duas formas, que podem ser usadas em conjunto:

1. **Consultar**: a APP chama `GET /api/approvals/{id}` de **3 em 3 segundos** (não menos), até o `status`
   deixar de ser `pending`, ou até o utilizador desistir. Funciona sempre, em qualquer APP.
2. **Ser avisada** (callback): se o pedido tiver `callback_url`, o gateway chama esse endereço assim que o
   pedido é decidido. Evita consultas constantes. Ver a secção seguinte.

### Avisos automáticos (callback)

Ao criar o pedido, indica o `callback_url` (`https://`, público). Quando o pedido passa a **`approved`**,
**`rejected`** ou **`expired`**, o gateway faz um `POST` a esse endereço. Um cancelamento **não** gera
aviso (foi a própria APP que o pediu).

**O que a APP recebe**

```
POST /o/teu/endereco
Content-Type: application/json; charset=utf-8
X-Gateway-Event: approval.updated
X-Gateway-Delivery: 7-1
X-Gateway-Timestamp: 1760000000
X-Gateway-Signature: sha256=3a9f...
```

```json
{
  "event": "approval.updated",
  "id": 7,
  "code": "4821",
  "type": "discount",
  "type_label": "Desconto",
  "description": "15% na venda 1234, cliente Silva",
  "reference": "VENDA-1234",
  "requested_by": "Operador Ana",
  "manager": { "id": 1, "name": "João" },
  "status": "approved",
  "created_at": "2026-10-15T10:00:00",
  "expires_at": "2026-10-15T10:10:00",
  "responded_at": "2026-10-15T10:02:31",
  "response_text": "SIM 4821",
  "sms_status": "sent"
}
```

O corpo é o mesmo de `GET /api/approvals/{id}`, mais o campo `event`. Usa o `reference` para saber a que
operação da tua APP o pedido corresponde.

**O que a APP tem de fazer**

- **Responder com HTTP 2xx** (por exemplo `200`), em menos de **5 segundos**. Qualquer outra resposta, ou
  falta de resposta, conta como falha. Os redirecionamentos (`301`, `302`...) **não** são seguidos.
- **Verificar a assinatura** antes de confiar no conteúdo (qualquer pessoa pode fazer um `POST` ao teu
  endereço). A assinatura é um HMAC-SHA256 de `<timestamp>.<corpo>` com **a chave de API da tua APP** como
  segredo. Usa sempre o corpo **em bruto**, tal como chegou, antes de o converter em JSON. Exemplos abaixo.
- **Tratar avisos repetidos**: o gateway garante a entrega "pelo menos uma vez". Se a APP responder tarde e o gateway
  repetir, o mesmo aviso chega duas vezes. Faz o tratamento de forma que receber o mesmo `status` outra vez não
  estrague nada (o `X-Gateway-Delivery` identifica cada tentativa: `<id do pedido>-<n.º da tentativa>`).

**Exemplo de verificação (PHP)**

```php
$body      = file_get_contents('php://input');                    // o corpo EXATAMENTE como chegou
$timestamp = $_SERVER['HTTP_X_GATEWAY_TIMESTAMP'] ?? '';
$received  = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $suaChaveDeApi);

if (!hash_equals($expected, $received) || abs(time() - (int) $timestamp) > 300) {
    http_response_code(401);   // assinatura errada, ou aviso com mais de 5 minutos
    exit;
}
$pedido = json_decode($body, true);
// ... atualiza a operação com $pedido['reference'] e $pedido['status'] ...
http_response_code(200);
```

**Exemplo de verificação (Node.js)**

```js
const crypto = require('crypto');

function assinaturaValida(corpoBruto, timestamp, recebida, chave) {
  const esperada = 'sha256=' + crypto.createHmac('sha256', chave).update(timestamp + '.' + corpoBruto).digest('hex');
  const a = Buffer.from(esperada), b = Buffer.from(recebida || '');
  const fresco = Math.abs(Date.now() / 1000 - Number(timestamp)) <= 300;   // até 5 minutos
  return fresco && a.length === b.length && crypto.timingSafeEqual(a, b);
}
```

**Se a APP falhar:** o gateway repete 6 vezes, com esperas de 1, 2, 5, 10, 20 e 30 minutos (7 tentativas em cerca de
1 hora). Se mesmo assim não conseguir, desiste (`callback_status: "failed"`) e a APP continua a poder consultar o pedido
com `GET`. Por isso, **não dependas só do callback** para pedidos importantes: confirma com um `GET` se passou muito tempo.

**Regras para o endereço** (recusadas com `422` ao criar o pedido):

- tem de começar por `https://`;
- tem de ser **público**: não pode ser `localhost`, um IP privado (`192.168.x.x`, `10.x.x.x`, `172.16-31.x.x`) nem um
  nome interno (sem ponto, `.local`, `.lan`);
- não pode ter utilizador e password no endereço (`https://user:pass@...`);
- até 255 caracteres.

Se a tua APP está na rede interna da empresa e não tem um endereço público, o callback não a alcança: fala com o
administrador (existe uma opção para aceitar redes internas) ou usa só a consulta (`GET`).

### Cancelar um pedido

```
POST /api/approvals/{id}/cancel
```

Sem corpo. Responde `200` com o pedido já atualizado (`"status": "cancelled"`).

- `404` — o pedido não existe.
- `409` — o pedido já não está pendente (já foi respondido, expirou ou foi cancelado).

Cancelar **não** envia nenhum SMS ao gestor. Se ele responder depois, recebe `Pedido 4821 ja estava cancelado.`

### O que o gestor pode responder

O gestor responde por SMS ao número que enviou o pedido:

| Resposta | Efeito |
| --- | --- |
| `SIM 4821` | aprova |
| `NAO 4821` ou `NÃO 4821` | rejeita |

- Maiúsculas/minúsculas e o acento de "não" são indiferentes.
- O formato é **exato**: `SIM` sozinho, `OK 4821`, `SIM 4821 obrigado`, `SIM 4821 4822` e semelhantes **não são reconhecidos** e são ignorados (o gestor não recebe resposta).
- Só respostas vindas de **gestores ativos** são consideradas; SMS de outros números são ignorados.
- O gestor recebe uma confirmação: `Pedido 4821 aprovado.` / `Pedido 4821 rejeitado.`, ou um aviso (`ja estava aprovado/expirado/cancelado`, `nao encontrado`).

---

## 5. Gestores e tipos de pedido

Só leitura. A gestão (criar, desativar, alterar) é feita pelo administrador.

### Gestores

```
GET /api/managers
```
```json
[
  { "id": 1, "name": "João", "is_default": true },
  { "id": 2, "name": "Maria", "is_default": false }
]
```

Só os ativos. **Não devolve o número de telefone.**

Cada pedido de aprovação tem de indicar o `manager_id` de um destes gestores (é obrigatório).
O campo `is_default` existe só por compatibilidade e **não tem efeito nos pedidos**: ignora-o.

### Tipos de pedido

```
GET /api/approval-types
```
```json
[
  { "code": "discount", "label": "Desconto" },
  { "code": "stock_move", "label": "Mov. armazem" }
]
```

Usa o `code` no campo `type` do pedido. Só os tipos ativos.

---

## 6. Erros

Todos os erros têm o mesmo formato:

```json
{ "error": "Dados inválidos", "details": { "to": "Número de telefone inválido", "text": "Campo obrigatório" } }
```

`details` só existe nos erros de validação (`422`) e tem uma entrada por campo inválido.

| Código | Quando | `error` |
| --- | --- | --- |
| `200` | Consulta ou cancelamento com sucesso | — |
| `201` | Criado (mensagem, lote, pedido) | — |
| `401` | Falta a chave ou é inválida | `Chave de API inválida ou em falta` |
| `403` | O IP não está autorizado | `IP não autorizado` |
| `404` | Rota ou recurso inexistente | `Não encontrado`, `Mensagem não encontrada`, `Pedido não encontrado` |
| `405` | Método HTTP errado para a rota | `Método não permitido` |
| `409` | Cancelar um pedido que já não está pendente | `O pedido já não está pendente (estado: ...)` |
| `422` | Dados inválidos (ver `details`) | `Dados inválidos` |
| `500` | Erro interno | `Erro interno` |
| `503` | A API não está configurada, ou `/health` sem base de dados | `API sem chaves configuradas` |

**Mensagens de validação comuns (`details`):**

| Mensagem | Quando |
| --- | --- |
| `Campo obrigatório` | falta o campo ou está vazio |
| `Máximo N caracteres` | texto demasiado longo |
| `Tem de ser texto` / `Tem de ser um número inteiro` | tipo errado |
| `Número de telefone inválido` | `to` não é um número português válido |
| `URL inválido` | `callback_url` não é um URL |
| `O endereço do callback tem de começar por https://.` | `callback_url` sem `https` |
| `O endereço do callback tem de ser público (...)` | `callback_url` aponta para `localhost` ou uma rede interna |
| `Tipo de pedido inexistente ou inativo` | `type` desconhecido |
| `Gestor inexistente ou inativo` | `manager_id` não existe ou está inativo |

Um `5xx` é um problema do servidor, não do pedido: tenta mais tarde e, se persistir, avisa o administrador.

---

## 7. Exemplos de integração

### curl (Windows `cmd`)

```cmd
curl.exe -X POST https://<dominio>/api/messages ^
  -H "X-API-Key: <chave>" -H "Content-Type: application/json" ^
  -d "{\"to\":\"912345678\",\"text\":\"Ola\"}"

curl.exe -H "X-API-Key: <chave>" https://<dominio>/api/messages/15

curl.exe -X POST https://<dominio>/api/approvals ^
  -H "X-API-Key: <chave>" -H "Content-Type: application/json" ^
  -d "{\"type\":\"discount\",\"description\":\"15%% na venda 1234\",\"reference\":\"VENDA-1234\",\"manager_id\":1}"
```

> No `cmd`, o `%` escreve-se `%%` quando está dentro de um ficheiro `.bat`; na linha de comandos escreve-se `%`.

### PHP

```php
<?php
const BASE = 'https://<dominio>';
const KEY  = '<chave>';

function api(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'data' => json_decode((string) $response, true)];
}

// 1) Enviar um SMS
$r = api('POST', '/api/messages', ['to' => '912345678', 'text' => 'Olá!']);
// $r['status'] === 201  →  $r['data']['id']

// 2) Pedir uma aprovação e esperar o resultado
$r = api('POST', '/api/approvals', [
    'type'        => 'discount',
    'description' => '15% na venda 1234, cliente Silva',
    'reference'   => 'VENDA-1234',
    'manager_id'  => 1,          // obrigatório: o gestor que decide (de GET /api/managers)
]);
if ($r['status'] !== 201) {
    exit('Erro: ' . json_encode($r['data']));
}
$id = $r['data']['id'];

do {
    sleep(3);                                   // não consultar mais depressa que isto
    $a = api('GET', "/api/approvals/{$id}")['data'];

    if ($a['sms_status'] === 'failed') {        // o gestor nunca recebeu o pedido
        api('POST', "/api/approvals/{$id}/cancel");
        exit('O SMS ao gestor falhou.');
    }
} while ($a['status'] === 'pending');

echo match ($a['status']) {
    'approved' => 'Aprovado',
    'rejected' => 'Rejeitado',
    'expired'  => 'Sem resposta a tempo',
    default    => $a['status'],
};
```

### JavaScript (no servidor, Node 18+)

```js
const BASE = 'https://<dominio>';
const KEY = '<chave>';

async function api(method, path, body) {
  const res = await fetch(BASE + path, {
    method,
    headers: { 'X-API-Key': KEY, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, data: await res.json() };
}

const created = await api('POST', '/api/approvals', {
  type: 'stock_move',
  description: 'Transferir 20 un. do armazém 1 para o 2',
  manager_id: 2,
});
console.log(created.status, created.data); // 201 { id, code, status: 'pending', expires_at }
```

---

## 8. Boas práticas

- **Verifica sempre o código HTTP** antes de usar a resposta. Em `422`, mostra ao utilizador os `details`.
- **Consulta o estado, não assumas.** `201` significa "na fila". Para confirmar o envio, consulta `GET /api/messages/{id}`; para saber a decisão, `GET /api/approvals/{id}`.
- **Consulta de 3 em 3 segundos**, no máximo, e para quando o estado for final.
- **Trata `sms_status: "failed"`** nas aprovações: o gestor nunca recebeu o pedido.
- **Não repitas pedidos às cegas.** A API **não** tem proteção contra duplicados: se repetires um `POST` por não teres recebido a resposta, podes criar duas mensagens ou dois pedidos. Antes de repetir, confirma se o primeiro chegou (por exemplo, guardando a `reference` do pedido do lado da APP).
- **Não uses a API para enviar a números que não controlas em massa** sem falar com o administrador: tudo passa por um modem 3G, com limites do operador.
- **Timeout de 10 segundos** nas chamadas à API é suficiente; a API responde de imediato (nunca espera pelo envio).
- **Guarda a chave em segredo** e pede outra ao administrador se suspeitares que foi exposta.
