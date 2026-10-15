-- Utilizador MySQL próprio da aplicação, só com as permissões de que precisa.
-- Corre-o com um utilizador administrador (root), DEPOIS dos scripts 01, 02 e 03.
--
-- Antes de correr, troca:
--   * TROCAR_ESTA_PASSWORD  -> uma password longa e aleatória (vai para DB_PASSWORD no .env)
--   * smsphp                -> o nome da tua base de dados (DB_NAME), em todas as linhas
--   * se mudaste SMS_OUT_TABLE / SMS_IN_TABLE / SMS_QUEUE_TABLE no .env, os nomes aqui também
--
-- Depois, no .env:  DB_USER=smsphp_app

CREATE USER IF NOT EXISTS 'smsphp_app'@'localhost' IDENTIFIED BY 'TROCAR_ESTA_PASSWORD';

-- Fila de mensagens (a API insere, o dispatcher atualiza o estado)
GRANT SELECT, INSERT, UPDATE ON `smsphp`.`sms_queue`         TO 'smsphp_app'@'localhost';

-- Tabelas do programa de SMS: NUNCA se altera a estrutura nem se apaga nada
GRANT SELECT, INSERT         ON `smsphp`.`sms_out`           TO 'smsphp_app'@'localhost';   -- insere SMS e lê o estado do envio
GRANT SELECT                 ON `smsphp`.`sms_in`            TO 'smsphp_app'@'localhost';   -- só lê as respostas recebidas

-- Gestores e tipos de pedido: a aplicação só lê (a gestão é feita por SQL, por ti)
GRANT SELECT                 ON `smsphp`.`managers`          TO 'smsphp_app'@'localhost';
GRANT SELECT                 ON `smsphp`.`approval_types`    TO 'smsphp_app'@'localhost';

-- Pedidos de aprovação e cursor do worker
GRANT SELECT, INSERT, UPDATE ON `smsphp`.`approval_requests` TO 'smsphp_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON `smsphp`.`worker_state`      TO 'smsphp_app'@'localhost';

FLUSH PRIVILEGES;
