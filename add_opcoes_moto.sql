-- ============================================================
--  OPÇÕES REUTILIZÁVEIS (tags) — ex.: Diferenciais da moto
--  Roda UMA vez no banco u243469785_adventure (aba SQL).
--  Guarda a lista de opções que aparecem no campo de tags.
--  O que o usuário digitar de novo no cadastro entra aqui sozinho.
-- ============================================================

CREATE TABLE IF NOT EXISTS opcoes_moto (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  categoria VARCHAR(40)  NOT NULL,
  valor     VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_cat_valor (categoria, valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Diferenciais iniciais (os exemplos que você passou)
INSERT IGNORE INTO opcoes_moto (categoria, valor) VALUES
  ('diferencial', 'Baixíssima quilometragem'),
  ('diferencial', 'Toda original'),
  ('diferencial', 'Pouco uso'),
  ('diferencial', 'Muito conservada'),
  ('diferencial', 'Revisões em concessionária'),
  ('diferencial', 'Muitos acessórios'),
  ('diferencial', 'Excelente para viagens'),
  ('diferencial', 'Excelente para trabalho');
