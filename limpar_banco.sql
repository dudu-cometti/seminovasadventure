-- ============================================================
--  LIMPEZA DO BANCO — Seminovas Honca
--  Objetivo:
--    1) Apagar TODAS as motos e fotos
--    2) Zerar analytics (visitas e leads)
--    3) Manter as configuracoes (config e settings)
--    4) Deixar SOMENTE 2 usuarios (Eduardo e Rendrix), ambos GERENTE
--
--  COMO USAR:
--    - Abra o phpMyAdmin na Hostinger
--    - Selecione o banco u243469785_seminovas
--    - Va na aba "SQL", cole TODO este arquivo e clique em "Executar"
--
--  >>> ANTES DE RODAR, CONFIRME OS 2 E-MAILS ABAIXO <<<
--  (so serao mantidos os usuarios com EXATAMENTE estes e-mails)
-- ============================================================

SET @EMAIL_EDUARDO = 'eduardocometti7@gmail.com';   -- <<< CONFIRME o e-mail do Eduardo
SET @EMAIL_RENDRIX = 'rendrix@exemplo.com';          -- <<< TROQUE pelo e-mail real do Rendrix

-- ------------------------------------------------------------
-- 1) APAGAR MOTOS E FOTOS
-- ------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE moto_fotos;
TRUNCATE TABLE motos;

-- ------------------------------------------------------------
-- 2) ZERAR ANALYTICS (visitas, sessoes e leads)
-- ------------------------------------------------------------
TRUNCATE TABLE page_events;
TRUNCATE TABLE active_sessions;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 3) USUARIOS: apagar todos, EXCETO Eduardo e Rendrix
-- ------------------------------------------------------------
DELETE FROM users
WHERE email NOT IN (@EMAIL_EDUARDO, @EMAIL_RENDRIX);

-- ------------------------------------------------------------
-- 4) Garantir que os dois sejam GERENTE com todas as permissoes
-- ------------------------------------------------------------
UPDATE users
SET role = 'gerente',
    can_create = 1,
    can_edit   = 1,
    can_delete = 1
WHERE email IN (@EMAIL_EDUARDO, @EMAIL_RENDRIX);

-- ------------------------------------------------------------
-- CONFERENCIA (opcional): rode estas linhas depois para checar
-- ------------------------------------------------------------
-- SELECT id, nome, email, role, can_create, can_edit, can_delete FROM users;
-- SELECT COUNT(*) AS total_motos FROM motos;
-- SELECT COUNT(*) AS total_eventos FROM page_events;
