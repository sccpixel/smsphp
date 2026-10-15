-- Gestores que aprovam pedidos e tipos de pedido.
-- Podes correr este script mais do que uma vez: não apaga nem duplica nada.

CREATE TABLE IF NOT EXISTS managers (
    id            INT          NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    phone_number  VARCHAR(50)  NOT NULL,             -- normalizado, sem +351 (ex.: 912345678)
    phone_key     VARCHAR(20)  NOT NULL,             -- últimos 9 dígitos, para comparar com o sms_in
    is_default    TINYINT(1)   NOT NULL DEFAULT 0,   -- usado quando a APP não indica gestor
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_phone_key (phone_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_types (
    id          INT          NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,     -- usado pela APP: 'discount', 'stock_move'
    label       VARCHAR(50)  NOT NULL,     -- aparece no SMS, SEM acentos
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO approval_types (code, label) VALUES
    ('discount',   'Desconto'),
    ('stock_move', 'Mov. armazem');

-- Exemplo para acrescentar um gestor (só pode haver UM com is_default = 1):
-- INSERT INTO managers (name, phone_number, phone_key, is_default)
-- VALUES ('Joao', '912345678', '912345678', 1);
