-- Callbacks: a API avisa a APP (POST ao callback_url do pedido) quando o pedido é aprovado, rejeitado ou expira.
-- Só para quem JÁ criou a tabela approval_requests (sql\03) antes de existir esta funcionalidade.
-- Uma instalação nova usa o sql\03_approvals.sql, que já traz estas colunas, e NÃO precisa deste ficheiro.
-- Corre UMA vez, com um utilizador administrador. Se aparecer "Duplicate column name", já está feito.

ALTER TABLE approval_requests
    ADD COLUMN callback_status   VARCHAR(10)  NOT NULL DEFAULT 'none'   AFTER callback_url,   -- none | pending | sent | failed
    ADD COLUMN callback_attempts INT          NOT NULL DEFAULT 0        AFTER callback_status,
    ADD COLUMN callback_next_at  DATETIME     NULL                      AFTER callback_attempts,
    ADD COLUMN callback_sent_at  DATETIME     NULL                      AFTER callback_next_at,
    ADD COLUMN callback_error    VARCHAR(255) NULL                      AFTER callback_sent_at,
    ADD KEY idx_callback (callback_status, callback_next_at);
