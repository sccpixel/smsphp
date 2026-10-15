# SMS Gateway — contexto para o Claude Code

API em PHP (Slim 4) que:

1. recebe pedidos de envio de SMS e coloca-os numa fila, que um worker passa ao programa de SMS;
2. gere **pedidos de aprovação** (descontos, movimentos de armazém): envia um SMS ao gestor, lê a resposta ("SIM 4821" / "NAO 4821") e devolve o resultado à APP.

O plano completo, as especificações e as fases estão em **`docs/PLANO.md`**. Lê esse ficheiro antes de começar qualquer fase.

## Como trabalhar comigo

- Sou programador júnior e este é um projeto do meu estágio. **Quero perceber o que está a ser feito**: explica cada ficheiro novo (o que faz e porquê) em português de Portugal.
- Faz **uma fase de cada vez**. No fim de cada fase: corre os testes de aceitação, mostra os resultados e **para**, à espera da minha revisão antes de passar à seguinte.
- Antes de decisões que não estejam no plano, **pergunta**. Não inventes requisitos.
- **Não acrescentes dependências do Composer** sem perguntar.
- Se encontrares um erro no plano, diz-mo em vez de o contornar em silêncio.

## Ambiente

- Windows, **XAMPP**: Apache 2.4 + PHP 8.2. MySQL/MariaDB local.
- Projeto em `C:\xampp\htdocs\smsphp`.
- Apache: virtual host na **porta 8080** com `DocumentRoot` em `public/` e `Require local`. A raiz do projeto tem um `.htaccess` com `Require all denied` (bloqueia o acesso pela porta 80 do htdocs).
- Exposição pública: **Cloudflare Tunnel** → `http://localhost:8080`.
- Terminal: **Linha de Comandos (cmd)**. Nos exemplos usa `curl.exe` (não `curl`), JSON com aspas escapadas (`-d "{\"to\":\"912...\"}"`) ou `-d @ficheiro.json`, e nunca `^` sem aspas.
- O programa de SMS ("SMS to DB") corre no mesmo PC: **lê a tabela `sms_out`** para enviar e **grava as recebidas em `sms_in`**.

## Stack

| Pacote                      | Uso                                 |
| --------------------------- | ----------------------------------- |
| `slim/slim` 4 + `slim/psr7` | rotas, middleware, PSR-7            |
| `php-di/php-di` 7           | container / injeção de dependências |
| `vlucas/phpdotenv`          | `.env`                              |
| `monolog/monolog` 3         | logs em `logs/`                     |

Acesso a dados com **PDO puro** (sem ORM).

## Convenções de código

- `declare(strict_types=1);` em todos os ficheiros; classes `final` sempre que possível.
- Namespace `App\` → `src/` (PSR-4).
- Identificadores (classes, métodos, variáveis, colunas) em **inglês**; comentários e mensagens para o utilizador em **português de Portugal**.
- **Dependências pelo construtor** (o container injeta). Nada de `new` de serviços/repositórios dentro de outras classes.
- Camadas, sem as saltar:
    - `src/Action/` — uma classe invocável por endpoint. Lê o pedido, chama um Service, devolve JSON com `App\Http\Json::write()`. **Sem SQL.**
    - `src/Domain/<Módulo>/` — `*Service` (regras de negócio) e `*Repository` (todo o SQL).
    - `src/Middleware/`, `src/Support/`, `src/Worker/`, `src/Database/`.
- SQL sempre com **prepared statements**. Nomes de tabelas vindos do `.env` são validados (`^[A-Za-z0-9_]+$`) antes de entrarem no SQL.
- **Não usar `SKIP LOCKED`** (o MariaDB do XAMPP pode não suportar). Só corre um worker de cada tipo; usar `SELECT ... FOR UPDATE` simples.
- Datas das aprovações calculadas em PHP (fuso `APP_TIMEZONE`) e passadas como parâmetro (`:now`), não com `NOW()`, para não depender do fuso do MySQL.
- Textos de SMS gerados pela aplicação **sem acentos** (mantém a codificação GSM-7 e 160 caracteres por SMS).
- Erros devolvidos em JSON: `{"error": "mensagem", "details": {...}}`. Detalhes técnicos só no log, nunca na resposta (exceto com `APP_DEBUG=true`).

## Regras invioláveis

- **Nunca alterar a estrutura de `sms_out` nem de `sms_in`** (são do programa de SMS). Em `sms_out` só se faz `INSERT` (e `SELECT` para ler o estado do envio); em `sms_in` só `SELECT`.
- `sms_queue` já existe (vem da versão anterior em Python) e é usada pela integração de um colega: **manter o esquema e o contrato da API de mensagens exatamente iguais**.
- Nunca enviar SMS de resposta a números que não sejam gestores ativos.
- Nunca commitar `.env`.

## Comandos úteis

```cmd
composer dump-autoload
curl.exe http://127.0.0.1:8080/health
php bin\dispatcher.php
php bin\approvals.php
php bin\dispatcher.php --once
```
