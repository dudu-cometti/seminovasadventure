-- ============================================================
--  NOVOS CAMPOS DA MOTO — Procedencia, ficha tecnica e comercial
--  Roda UMA vez no banco u243469785_adventure (aba SQL do phpMyAdmin).
--  Se rodar de novo dara erro de "coluna ja existe" (e normal).
-- ============================================================

ALTER TABLE motos
  -- ---- Procedencia ----
  ADD COLUMN unico_dono          ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER descricao,
  ADD COLUMN tem_manual          ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER unico_dono,
  ADD COLUMN revisada_autorizada ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER tem_manual,
  ADD COLUMN garantia_fabrica    ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER revisada_autorizada,
  ADD COLUMN chave_reserva       ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER garantia_fabrica,
  ADD COLUMN revisoes_regulares  ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER chave_reserva,
  ADD COLUMN historico_negativo  ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER revisoes_regulares,
  ADD COLUMN laudo_cautelar      ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER historico_negativo,
  -- ---- Conservacao / estetica ----
  ADD COLUMN conservacao         ENUM('','impecavel','excelente','muito_boa','boa') NOT NULL DEFAULT '' AFTER laudo_cautelar,
  ADD COLUMN detalhe_estetico    VARCHAR(255) NOT NULL DEFAULT '' AFTER conservacao,
  -- ---- Pneus ----
  ADD COLUMN pneu_dianteiro      TINYINT UNSIGNED NULL AFTER detalhe_estetico,
  ADD COLUMN pneu_traseiro       TINYINT UNSIGNED NULL AFTER pneu_dianteiro,
  -- ---- Mecanica ----
  ADD COLUMN relacao             ENUM('','nova','boa','regular') NOT NULL DEFAULT '' AFTER pneu_traseiro,
  ADD COLUMN freios              ENUM('','novos','bons','regular') NOT NULL DEFAULT '' AFTER relacao,
  -- ---- Diferencial ----
  ADD COLUMN diferencial         VARCHAR(500) NOT NULL DEFAULT '' AFTER freios,
  -- ---- Condicoes comerciais ----
  ADD COLUMN aceita_troca        ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER diferencial,
  ADD COLUMN aceita_carta        ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER aceita_troca,
  ADD COLUMN financiamento       ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER aceita_carta,
  ADD COLUMN garantia_loja       ENUM('','sim','nao') NOT NULL DEFAULT '' AFTER financiamento;
