-- ============================================================
--  CRIAR BANCO DO ZERO — Seminovas Honca
--  Cria todas as tabelas vazias que o site usa.
--
--  COMO USAR:
--    1) Crie o banco novo no painel da Hostinger (dominio certo)
--    2) Abra o phpMyAdmin e SELECIONE esse banco novo na esquerda
--    3) Va na aba "SQL", cole TODO este arquivo e clique em "Executar"
--    4) Atualize o config.php (nome/usuario/senha do banco novo)
--    5) Crie os usuarios pelo proprio site (ver instrucoes no chat)
-- ============================================================

-- ------------------------------------------------------------
-- USUARIOS
-- ------------------------------------------------------------
CREATE TABLE users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(120) NOT NULL,
  email       VARCHAR(150) NOT NULL,
  senha_hash  VARCHAR(255) NOT NULL,
  role        ENUM('gerente','vendedor') NOT NULL DEFAULT 'vendedor',
  can_create  TINYINT(1) NOT NULL DEFAULT 0,
  can_edit    TINYINT(1) NOT NULL DEFAULT 0,
  can_delete  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- MOTOS
-- ------------------------------------------------------------
CREATE TABLE motos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  titulo         VARCHAR(200) NOT NULL DEFAULT '',
  modelo         VARCHAR(80)  NOT NULL,
  ano_modelo     VARCHAR(20)  NOT NULL,
  quilometragem  INT          NOT NULL DEFAULT 0,
  condicao       ENUM('','nova','seminova') NOT NULL DEFAULT '',
  cor            VARCHAR(40)  NOT NULL,
  valor          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  valor_a_combinar TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = mostra "Valor a combinar"
  valor_fipe     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  descricao      TEXT NULL,
  -- procedencia
  unico_dono          ENUM('','sim','nao') NOT NULL DEFAULT '',
  tem_manual          ENUM('','sim','nao') NOT NULL DEFAULT '',
  revisada_autorizada ENUM('','sim','nao') NOT NULL DEFAULT '',
  garantia_fabrica    ENUM('','sim','nao') NOT NULL DEFAULT '',
  chave_reserva       ENUM('','sim','nao') NOT NULL DEFAULT '',
  revisoes_regulares  ENUM('','sim','nao') NOT NULL DEFAULT '',
  historico_negativo  ENUM('','sim','nao') NOT NULL DEFAULT '',
  laudo_cautelar      ENUM('','sim','nao') NOT NULL DEFAULT '',
  -- conservacao / estetica
  conservacao         ENUM('','impecavel','excelente','muito_boa','boa') NOT NULL DEFAULT '',
  detalhe_estetico    VARCHAR(255) NOT NULL DEFAULT '',
  -- pneus
  pneu_dianteiro      TINYINT UNSIGNED NULL,
  pneu_traseiro       TINYINT UNSIGNED NULL,
  -- mecanica
  relacao             ENUM('','nova','boa','regular') NOT NULL DEFAULT '',
  freios              ENUM('','novos','bons','regular') NOT NULL DEFAULT '',
  -- diferencial
  diferencial         VARCHAR(500) NOT NULL DEFAULT '',
  -- condicoes comerciais
  aceita_troca        ENUM('','sim','nao') NOT NULL DEFAULT '',
  aceita_carta        ENUM('','sim','nao') NOT NULL DEFAULT '',
  financiamento       ENUM('','sim','nao') NOT NULL DEFAULT '',
  garantia_loja       ENUM('','sim','nao') NOT NULL DEFAULT '',
  status         ENUM('disponivel','reservada','vendida') NOT NULL DEFAULT 'disponivel',
  sold_at        DATETIME NULL,   -- data/hora da venda (preenchido ao vender)
  created_by     INT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NULL,
  KEY idx_status (status),
  KEY idx_created_by (created_by),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- FOTOS DAS MOTOS (filha de motos)
-- ------------------------------------------------------------
CREATE TABLE moto_fotos (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  moto_id   INT NOT NULL,
  caminho   VARCHAR(255) NOT NULL,
  is_cover  TINYINT(1) NOT NULL DEFAULT 0,
  ordem     INT NOT NULL DEFAULT 0,   -- sequencia das fotos (0 = capa/1a)
  KEY idx_moto (moto_id),
  CONSTRAINT fk_fotos_motos FOREIGN KEY (moto_id)
    REFERENCES motos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- VENDAS (registro detalhado de cada venda)
-- ------------------------------------------------------------
CREATE TABLE vendas (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  moto_id           INT NOT NULL,
  vendedor_id       INT NULL,                          -- users.id do vendedor
  vendedor_nome     VARCHAR(120) NOT NULL DEFAULT '',
  cliente_nome      VARCHAR(160) NOT NULL DEFAULT '',
  cliente_telefone  VARCHAR(40)  NOT NULL DEFAULT '',
  cliente_email     VARCHAR(160) NOT NULL DEFAULT '',
  cliente_doc       VARCHAR(40)  NOT NULL DEFAULT '',   -- CPF
  valor_venda       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  data_venda        DATE NULL,
  observacao        TEXT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_moto (moto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- CONFIGURACOES (loja, whatsapp, logo...)
-- ------------------------------------------------------------
CREATE TABLE settings (
  `key`   VARCHAR(60) PRIMARY KEY,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tabela antiga "config" (mantida por compatibilidade)
CREATE TABLE config (
  nome   VARCHAR(60) PRIMARY KEY,
  valor  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- OPCOES REUTILIZAVEIS (tags) — ex.: Diferenciais
-- ------------------------------------------------------------
CREATE TABLE opcoes_moto (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  categoria VARCHAR(40)  NOT NULL,
  valor     VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_cat_valor (categoria, valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO opcoes_moto (categoria, valor) VALUES
  ('diferencial', 'Baixíssima quilometragem'),
  ('diferencial', 'Toda original'),
  ('diferencial', 'Pouco uso'),
  ('diferencial', 'Muito conservada'),
  ('diferencial', 'Revisões em concessionária'),
  ('diferencial', 'Muitos acessórios'),
  ('diferencial', 'Excelente para viagens'),
  ('diferencial', 'Excelente para trabalho');

-- ------------------------------------------------------------
-- PADRÕES (presets) de moto — ficha pronta em JSON
-- ------------------------------------------------------------
CREATE TABLE motos_padroes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nome       VARCHAR(80) NOT NULL,
  dados      TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ANALYTICS — eventos de pagina
-- ------------------------------------------------------------
CREATE TABLE page_events (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  session_key   VARCHAR(64) NOT NULL,
  event_type    VARCHAR(40) NOT NULL,
  page          VARCHAR(255) NULL,
  moto_id       INT NULL,
  referrer      VARCHAR(255) NULL,
  utm_source    VARCHAR(80)  NULL,
  utm_medium    VARCHAR(80)  NULL,
  utm_campaign  VARCHAR(120) NULL,
  utm_content   VARCHAR(120) NULL,
  utm_term      VARCHAR(120) NULL,
  created_at    DATETIME NOT NULL,
  ip            VARCHAR(45) NULL,
  KEY idx_event (event_type),
  KEY idx_created (created_at),
  KEY idx_moto (moto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ANALYTICS — sessoes ativas (online agora)
-- ------------------------------------------------------------
CREATE TABLE active_sessions (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  session_key  VARCHAR(64) NOT NULL,
  last_seen    DATETIME NOT NULL,
  first_seen   DATETIME NOT NULL,
  page         VARCHAR(255) NULL,
  moto_id      INT NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  UNIQUE KEY uq_session (session_key),
  KEY idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- (OPCIONAL) Configuracoes iniciais — edite os valores depois
-- pelo painel em Configuracoes. Deixe como esta se preferir.
-- ------------------------------------------------------------
INSERT INTO settings (`key`, `value`) VALUES
  ('marketplace_nome',   'Adventure Motos'),
  ('marketplace_cidade', 'São Silvano - ES'),
  ('whatsapp_number',    '5527999215754')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
