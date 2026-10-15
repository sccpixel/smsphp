-- Pedidos de aprovação e estado do worker que lê o sms_in.
-- Corre depois do 02_managers_types.sql (há chaves estrangeiras para managers e approval_types).
-- Podes correr mais do que uma vez: não apaga nem duplica nada.

CREATE TABLE IF NOT EXISTS approval_requests (
    id                INT          NOT NULL AUTO_INCREMENT,
    code              CHAR(4)      NOT NULL,             -- 4 dígitos, único entre os pedidos pendentes
    approval_type_id  INT          NOT NULL,
    manager_id        INT          NOT NULL,
    description       VARCHAR(120) NOT NULL,
    reference         VARCHAR(100) NULL,                 -- id interno na APP
    requested_by      VARCHAR(100) NULL,                 -- operador que pediu
    status            VARCHAR(20)  NOT NULL DEFAULT 'pending',
    callback_url      VARCHAR(255) NULL,                 -- guardado, ainda não usado (futuro)
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

CREATE TABLE IF NOT EXISTS worker_state (
    name        VARCHAR(50) NOT NULL,     -- ex.: 'sms_in'
    last_id     INT         NOT NULL DEFAULT 0,
    updated_at  DATETIME    NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
