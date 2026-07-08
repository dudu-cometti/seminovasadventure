-- ============================================================
--  CONDIÇÃO + VALOR FIPE + PADRÕES DE MOTO
--  Roda UMA vez no banco u243469785_adventure (aba SQL).
-- ============================================================

-- 1) Novas colunas na tabela motos
ALTER TABLE motos
  ADD COLUMN condicao   ENUM('','nova','seminova') NOT NULL DEFAULT '' AFTER quilometragem,
  ADD COLUMN valor_fipe DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER valor;

-- 2) Tabela de PADRÕES (presets) — guardam a ficha pronta em JSON
CREATE TABLE IF NOT EXISTS motos_padroes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nome       VARCHAR(80) NOT NULL,
  dados      TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
