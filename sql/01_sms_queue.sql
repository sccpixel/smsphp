-- Fila de SMS a enviar (vem da versão anterior em Python).
-- CREATE TABLE IF NOT EXISTS: se a tabela já existir não faz nada.
-- Se mudares SMS_QUEUE_TABLE no .env, muda também o nome aqui.

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
