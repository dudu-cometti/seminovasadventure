<?php
/**
 * Helpers compartilhados dos campos da ficha da moto.
 * Usado por painel/moto_form.php e painel/padroes.php.
 * (function_exists evita erro caso algo já tenha definido.)
 */

/**
 * Garante que as colunas novas existem no banco (auto-migração leve).
 * - moto_fotos.ordem        -> sequência das fotos (0 = capa)
 * - motos.valor_a_combinar  -> "sob consulta / valor a negociar"
 * Roda só uma vez por request e ignora erros silenciosamente.
 */
if (!function_exists('ensure_moto_schema')) {
  function ensure_moto_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
      $col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='moto_fotos' AND COLUMN_NAME='ordem'")->fetchColumn();
      if ((int)$col === 0) {
        $pdo->exec("ALTER TABLE moto_fotos ADD COLUMN ordem INT NOT NULL DEFAULT 0");
        // Inicializa a ordem respeitando a capa atual (capa primeiro, depois id)
        $motoIds = $pdo->query("SELECT DISTINCT moto_id FROM moto_fotos")->fetchAll(PDO::FETCH_COLUMN);
        $sel = $pdo->prepare("SELECT id FROM moto_fotos WHERE moto_id=? ORDER BY is_cover DESC, id ASC");
        $upd = $pdo->prepare("UPDATE moto_fotos SET ordem=? WHERE id=?");
        foreach ($motoIds as $mid) {
          $sel->execute([$mid]);
          $o = 0;
          foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $fid) $upd->execute([$o++, $fid]);
        }
      }
      $col2 = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='motos' AND COLUMN_NAME='valor_a_combinar'")->fetchColumn();
      if ((int)$col2 === 0) {
        $pdo->exec("ALTER TABLE motos ADD COLUMN valor_a_combinar TINYINT(1) NOT NULL DEFAULT 0");
      }
    } catch (Throwable $e) { /* ignora */ }
  }
}

/**
 * Grava uma sequência de fotos (array de ids na ordem desejada):
 * renumera ordem = 0,1,2... e marca a primeira como capa (is_cover=1).
 */
if (!function_exists('moto_fotos_aplicar_ordem')) {
  function moto_fotos_aplicar_ordem($pdo, $moto_id, array $idsEmOrdem) {
    $upd = $pdo->prepare("UPDATE moto_fotos SET ordem=?, is_cover=? WHERE id=? AND moto_id=?");
    foreach (array_values($idsEmOrdem) as $i => $fid) {
      $upd->execute([$i, $i === 0 ? 1 : 0, (int)$fid, $moto_id]);
    }
  }
}

/**
 * Garante a estrutura do registro de vendas (auto-migração leve).
 * - tabela vendas (moto_id, vendedor_id, valor_venda, created_at)
 * - motos.sold_at (data/hora da venda)
 * IMPORTANTE: chame ANTES de abrir transação (CREATE/ALTER dão commit implícito).
 */
if (!function_exists('ensure_vendas_schema')) {
  function ensure_vendas_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS vendas (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        moto_id      INT NOT NULL,
        vendedor_id  INT NULL,
        valor_venda  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_moto (moto_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      // Colunas extras (adiciona nas tabelas já existentes que ainda não têm)
      $extras = [
        'vendedor_nome'    => "VARCHAR(120) NOT NULL DEFAULT ''",
        'cliente_nome'     => "VARCHAR(160) NOT NULL DEFAULT ''",
        'cliente_telefone' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'cliente_email'    => "VARCHAR(160) NOT NULL DEFAULT ''",
        'cliente_doc'      => "VARCHAR(40) NOT NULL DEFAULT ''",
        'data_venda'       => "DATE NULL",
        'observacao'       => "TEXT NULL",
      ];
      foreach ($extras as $nome => $def) {
        $c = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vendas' AND COLUMN_NAME='$nome'")->fetchColumn();
        if ((int)$c === 0) $pdo->exec("ALTER TABLE vendas ADD COLUMN `$nome` $def");
      }

      $c = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='motos' AND COLUMN_NAME='sold_at'")->fetchColumn();
      if ((int)$c === 0) {
        $pdo->exec("ALTER TABLE motos ADD COLUMN sold_at DATETIME NULL");
      }
    } catch (Throwable $e) { /* ignora */ }
  }
}

/** Renumera as fotos da moto pela ordem atual (ordem, id) e fixa a capa. */
if (!function_exists('moto_fotos_reindex')) {
  function moto_fotos_reindex($pdo, $moto_id) {
    $stmt = $pdo->prepare("SELECT id FROM moto_fotos WHERE moto_id=? ORDER BY ordem ASC, id ASC");
    $stmt->execute([$moto_id]);
    moto_fotos_aplicar_ordem($pdo, $moto_id, $stmt->fetchAll(PDO::FETCH_COLUMN));
  }
}

if (!function_exists('yn')) {
  function yn($v) {
    $v = trim((string)$v);
    return ($v === 'sim' || $v === 'nao') ? $v : '';
  }
}
if (!function_exists('opt_val')) {
  function opt_val($v, array $allowed) {
    $v = trim((string)$v);
    return in_array($v, $allowed, true) ? $v : '';
  }
}
if (!function_exists('pct_val')) {
  function pct_val($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $n = (int)$v;
    if ($n < 0)   $n = 0;
    if ($n > 100) $n = 100;
    return $n;
  }
}
// Renderiza um select Sim/Não
if (!function_exists('field_yn')) {
  function field_yn($label, $name, $cur) {
    $cur = (string)$cur;
    return '<div class="field"><label>' . htmlspecialchars($label) . '</label>'
         . '<select name="' . $name . '">'
         . '<option value="">— não informado —</option>'
         . '<option value="sim" ' . ($cur === 'sim' ? 'selected' : '') . '>Sim</option>'
         . '<option value="nao" ' . ($cur === 'nao' ? 'selected' : '') . '>Não</option>'
         . '</select></div>';
  }
}
// Renderiza um select de opções ($opts = ['valor' => 'Texto'])
if (!function_exists('field_opt')) {
  function field_opt($label, $name, $cur, array $opts) {
    $cur = (string)$cur;
    $h = '<div class="field"><label>' . htmlspecialchars($label) . '</label>'
       . '<select name="' . $name . '"><option value="">— não informado —</option>';
    foreach ($opts as $val => $txt) {
      $h .= '<option value="' . htmlspecialchars($val) . '" '
          . ($cur === (string)$val ? 'selected' : '') . '>' . htmlspecialchars($txt) . '</option>';
    }
    return $h . '</select></div>';
  }
}

/**
 * Tags do Diferencial vindas do POST (string separada por vírgula) -> array único.
 */
if (!function_exists('moto_diferencial_tags')) {
  function moto_diferencial_tags($post) {
    $raw = $post['diferencial'] ?? '';
    $tags = array_filter(array_map('trim', explode(',', (string)$raw)), function ($v) { return $v !== ''; });
    $out = [];
    foreach ($tags as $t) {
      $t = mb_substr($t, 0, 120);
      if (!in_array($t, $out, true)) $out[] = $t;
    }
    return $out;
  }
}

/**
 * Coleta e sanitiza TODOS os campos da ficha a partir do $_POST.
 * Retorna um array coluna => valor (serve pro INSERT da moto e pro JSON do padrão).
 */
if (!function_exists('moto_ficha_collect')) {
  function moto_ficha_collect($post) {
    return [
      'condicao'            => opt_val($post['condicao'] ?? '', ['nova','seminova']),
      // procedencia
      'unico_dono'          => yn($post['unico_dono'] ?? ''),
      'tem_manual'          => yn($post['tem_manual'] ?? ''),
      'revisada_autorizada' => yn($post['revisada_autorizada'] ?? ''),
      'garantia_fabrica'    => yn($post['garantia_fabrica'] ?? ''),
      'chave_reserva'       => yn($post['chave_reserva'] ?? ''),
      'revisoes_regulares'  => yn($post['revisoes_regulares'] ?? ''),
      'historico_negativo'  => yn($post['historico_negativo'] ?? ''),
      'laudo_cautelar'      => yn($post['laudo_cautelar'] ?? ''),
      // conservacao / estetica
      'conservacao'         => opt_val($post['conservacao'] ?? '', ['impecavel','excelente','muito_boa','boa']),
      'detalhe_estetico'    => mb_substr(trim($post['detalhe_estetico'] ?? ''), 0, 255),
      // pneus
      'pneu_dianteiro'      => pct_val($post['pneu_dianteiro'] ?? ''),
      'pneu_traseiro'       => pct_val($post['pneu_traseiro'] ?? ''),
      // mecanica
      'relacao'             => opt_val($post['relacao'] ?? '', ['nova','boa','regular']),
      'freios'              => opt_val($post['freios'] ?? '', ['novos','bons','regular']),
      // diferencial (string)
      'diferencial'         => mb_substr(implode(', ', moto_diferencial_tags($post)), 0, 500),
      // comercial
      'aceita_troca'        => yn($post['aceita_troca'] ?? ''),
      'aceita_carta'        => yn($post['aceita_carta'] ?? ''),
      'financiamento'       => yn($post['financiamento'] ?? ''),
      'garantia_loja'       => yn($post['garantia_loja'] ?? ''),
    ];
  }
}

/** Valores-padrão (todos vazios) dos campos da ficha. */
if (!function_exists('moto_ficha_defaults')) {
  function moto_ficha_defaults() {
    return [
      'condicao' => '',
      'unico_dono' => '', 'tem_manual' => '', 'revisada_autorizada' => '', 'garantia_fabrica' => '',
      'chave_reserva' => '', 'revisoes_regulares' => '', 'historico_negativo' => '', 'laudo_cautelar' => '',
      'conservacao' => '', 'detalhe_estetico' => '',
      'pneu_dianteiro' => '', 'pneu_traseiro' => '',
      'relacao' => '', 'freios' => '',
      'diferencial' => '',
      'aceita_troca' => '', 'aceita_carta' => '', 'financiamento' => '', 'garantia_loja' => '',
    ];
  }
}
