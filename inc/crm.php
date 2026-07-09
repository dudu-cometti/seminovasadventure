<?php
/**
 * CRM helpers e auto-migração do banco.
 * Padrão: funções compartilhadas, helpers de visualização, auto-schema.
 */

/**
 * Garante tabelas e colunas do CRM (auto-migração leve).
 * Roda só uma vez por request, ignora erros silenciosamente.
 */
if (!function_exists('ensure_crm_schema')) {
  function ensure_crm_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS crm_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(120) NOT NULL,
        telefone VARCHAR(30) NOT NULL,
        email VARCHAR(160) NULL,
        moto_id INT NULL,
        vendedor_id INT NULL,
        etapa ENUM('novo','contato','negociacao','proposta','fechado','perdido') NOT NULL DEFAULT 'novo',
        temperatura ENUM('frio','morno','quente') NOT NULL DEFAULT 'morno',
        valor_negociado DECIMAL(12,2) NULL,
        motivo_perda VARCHAR(60) NULL,
        motivo_perda_obs VARCHAR(255) NULL,
        origem VARCHAR(30) NOT NULL DEFAULT 'manual',
        utm_source VARCHAR(80) NULL,
        utm_medium VARCHAR(80) NULL,
        utm_campaign VARCHAR(120) NULL,
        utm_content VARCHAR(120) NULL,
        fbclid VARCHAR(255) NULL,
        fbp VARCHAR(64) NULL,
        fbc VARCHAR(128) NULL,
        landing_url VARCHAR(255) NULL,
        etapa_desde DATETIME NULL,
        fechado_at DATETIME NULL,
        venda_id INT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_etapa (etapa),
        INDEX idx_vendedor (vendedor_id),
        INDEX idx_telefone (telefone),
        INDEX idx_moto (moto_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS crm_interesses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        marca VARCHAR(60) NULL,
        modelo VARCHAR(120) NULL,
        ano_min INT NULL,
        ano_max INT NULL,
        valor_max DECIMAL(12,2) NULL,
        km_max INT NULL,
        observacao VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead (lead_id),
        CONSTRAINT fk_crm_int_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS crm_interacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        tipo ENUM('nota','ligacao','whatsapp','visita','proposta','email','sistema') NOT NULL DEFAULT 'nota',
        texto TEXT NOT NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead (lead_id),
        CONSTRAINT fk_crm_inter_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS crm_agendamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        vendedor_id INT NULL,
        tipo ENUM('ligacao','visita','test_ride','entrega','outro') NOT NULL DEFAULT 'ligacao',
        data_hora DATETIME NOT NULL,
        status ENUM('pendente','realizado','cancelado') NOT NULL DEFAULT 'pendente',
        observacao VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead (lead_id),
        INDEX idx_data (data_hora),
        CONSTRAINT fk_crm_agd_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS crm_match_dispensados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        moto_id INT NOT NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_lead_moto (lead_id, moto_id),
        INDEX idx_lead (lead_id),
        INDEX idx_moto (moto_id),
        CONSTRAINT fk_dispensados_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
        CONSTRAINT fk_dispensados_moto FOREIGN KEY (moto_id) REFERENCES motos(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS crm_ia_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(60) NOT NULL,
        hash CHAR(40) NOT NULL,
        lead_id INT NULL,
        entrada_resumo VARCHAR(255) NULL,
        resposta LONGTEXT NOT NULL,
        tokens_in INT NOT NULL DEFAULT 0,
        tokens_out INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_tipo_hash (tipo, hash),
        INDEX idx_lead (lead_id),
        CONSTRAINT fk_ia_cache_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      // Garante settings iniciais
      $motivos_default = json_encode(['Preço', 'Comprou em outra loja', 'Sem crédito/financiamento', 'Desistiu', 'Sem retorno', 'Trocou de ideia', 'Outro']);
      $s = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE key_name='crm_motivos_perda'");
      $s->execute();
      if ((int)$s->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?)");
        $ins->execute(['crm_motivos_perda', $motivos_default]);
      }
      foreach (['crm_pixel_id', 'crm_capi_token', 'crm_anthropic_key', 'crm_ia_modelo'] as $k) {
        $s->execute([$k]);
        if ((int)$s->fetchColumn() === 0) {
          $ins = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?)");
          $ins->execute([$k, ($k === 'crm_ia_modelo' ? 'claude-haiku-4-5-20251001' : '')]);
        }
      }
    } catch (Throwable $e) { /* ignora */ }
  }
}

if (!function_exists('crm_etapas')) {
  function crm_etapas() {
    return [
      'novo' => ['rótulo' => 'Novo', 'cor' => '#726d64'],
      'contato' => ['rótulo' => 'Em contato', 'cor' => '#726d64'],
      'negociacao' => ['rótulo' => 'Negociação', 'cor' => '#c8291f'],
      'proposta' => ['rótulo' => 'Proposta', 'cor' => '#c8291f'],
      'fechado' => ['rótulo' => 'Fechado', 'cor' => '#2e8b47'],
      'perdido' => ['rótulo' => 'Perdido', 'cor' => '#666'],
    ];
  }
}

if (!function_exists('crm_normaliza_telefone')) {
  function crm_normaliza_telefone($tel) {
    $tel = preg_replace('/\D/', '', (string)$tel);
    if (strlen($tel) === 13 && substr($tel, 0, 2) === '55') {
      $tel = substr($tel, 2);
    }
    if (strlen($tel) === 12 && substr($tel, 0, 1) === '0') {
      $tel = substr($tel, 1);
    }
    return $tel;
  }
}

if (!function_exists('crm_formata_telefone')) {
  function crm_formata_telefone($tel) {
    $tel = crm_normaliza_telefone($tel);
    if (strlen($tel) === 11) {
      return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7);
    }
    return $tel;
  }
}

if (!function_exists('crm_whatsapp_link')) {
  function crm_whatsapp_link($telefone, $texto = '') {
    $tel = crm_normaliza_telefone($telefone);
    $url = 'https://wa.me/55' . $tel;
    if ($texto !== '') {
      $url .= '?text=' . urlencode($texto);
    }
    return $url;
  }
}

if (!function_exists('crm_lead_get')) {
  function crm_lead_get($pdo, $id) {
    $stmt = $pdo->prepare("
      SELECT
        l.*,
        m.titulo as moto_titulo,
        m.valor as moto_valor,
        m.status as moto_status,
        m.quilometragem as moto_km,
        (SELECT caminho FROM moto_fotos WHERE moto_id=l.moto_id AND is_cover=1 LIMIT 1) as moto_foto,
        u.nome as vendedor_nome
      FROM crm_leads l
      LEFT JOIN motos m ON l.moto_id = m.id
      LEFT JOIN users u ON l.vendedor_id = u.id
      WHERE l.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('crm_pode_ver_lead')) {
  function crm_pode_ver_lead($user, $lead) {
    if ($user['role'] === 'gerente') return true;
    if ((int)$lead['vendedor_id'] === 0 || (int)$lead['vendedor_id'] === null) return true;
    return (int)$lead['vendedor_id'] === (int)($user['id'] ?? 0);
  }
}

if (!function_exists('crm_badge_novos')) {
  function crm_badge_novos($pdo, $user) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE etapa='novo'");
    if ($user['role'] === 'vendedor') {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE etapa='novo' AND (vendedor_id=? OR vendedor_id IS NULL)");
      $stmt->execute([$user['id']]);
    } else {
      $stmt->execute();
    }
    return (int)$stmt->fetchColumn();
  }
}

if (!function_exists('crm_registrar_interacao')) {
  function crm_registrar_interacao($pdo, $leadId, $tipo, $texto, $userId = null) {
    $stmt = $pdo->prepare("INSERT INTO crm_interacoes (lead_id, tipo, texto, user_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$leadId, $tipo, $texto, $userId]);
  }
}

if (!function_exists('crm_lead_move')) {
  function crm_lead_move($pdo, $id, $etapa, $userId, $extra = []) {
    $etapas_validas = ['novo', 'contato', 'negociacao', 'proposta', 'fechado', 'perdido'];
    if (!in_array($etapa, $etapas_validas, true)) {
      throw new Exception('Etapa inválida: ' . $etapa);
    }

    if ($etapa === 'perdido' && empty($extra['motivo_perda'])) {
      throw new Exception('Motivo da perda é obrigatório ao descartar um lead');
    }

    $lead = crm_lead_get($pdo, $id);
    if (!$lead) throw new Exception('Lead não encontrado');

    $fechado_at = null;
    if ($etapa === 'fechado' || $etapa === 'perdido') {
      $fechado_at = date('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare("
      UPDATE crm_leads
      SET etapa=?, etapa_desde=NOW(), fechado_at=?, motivo_perda=?, motivo_perda_obs=?, updated_at=NOW()
      WHERE id=?
    ");
    $stmt->execute([
      $etapa,
      $fechado_at,
      $extra['motivo_perda'] ?? null,
      $extra['motivo_perda_obs'] ?? null,
      $id
    ]);

    $user_stmt = $pdo->prepare("SELECT nome FROM users WHERE id=?");
    $user_stmt->execute([$userId]);
    $user_nome = $user_stmt->fetchColumn() ?: 'Sistema';

    $msg = "Etapa alterada de " . $lead['etapa'] . " para " . $etapa . " por " . $user_nome;
    crm_registrar_interacao($pdo, $id, 'sistema', $msg);

    return true;
  }
}

if (!function_exists('crm_import_vendas')) {
  function crm_import_vendas($pdo, $userId) {
    $vendas = $pdo->query("SELECT * FROM vendas ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $importados = 0;

    foreach ($vendas as $venda) {
      $tel_norm = crm_normaliza_telefone($venda['cliente_telefone']);
      if (empty($tel_norm)) continue;

      $existe = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE telefone LIKE ?");
      $existe->execute(['%' . $tel_norm . '%']);
      if ((int)$existe->fetchColumn() > 0) {
        continue;
      }

      $stmt = $pdo->prepare("
        INSERT INTO crm_leads
        (nome, telefone, email, moto_id, vendedor_id, etapa, origem, valor_negociado, fechado_at, venda_id, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, 'fechado', 'importado_venda', ?, ?, ?, ?, ?)
      ");
      $stmt->execute([
        $venda['cliente_nome'],
        $tel_norm,
        $venda['cliente_email'] ?: null,
        null,
        $venda['vendedor_id'],
        $venda['valor_venda'],
        $venda['data_venda'] . ' 12:00:00',
        (int)$venda['id'],
        $userId,
        $venda['created_at']
      ]);

      $lead_id = $pdo->lastInsertId();
      crm_registrar_interacao($pdo, $lead_id, 'sistema', 'Lead criado via importação de venda #' . $venda['id']);
      $importados++;
    }

    return $importados;
  }
}

if (!function_exists('crm_on_venda_registrada')) {
  function crm_on_venda_registrada($pdo, $vendaId) {
    try {
      $venda = $pdo->prepare("SELECT * FROM vendas WHERE id=?");
      $venda->execute([$vendaId]);
      $venda = $venda->fetch(PDO::FETCH_ASSOC);
      if (!$venda) return;

      $tel_norm = crm_normaliza_telefone($venda['cliente_telefone']);
      if (empty($tel_norm)) return;

      $lead_stmt = $pdo->prepare("
        SELECT id FROM crm_leads
        WHERE (telefone LIKE ? OR moto_id=?)
        AND etapa NOT IN ('fechado', 'perdido')
        ORDER BY id DESC LIMIT 1
      ");
      $lead_stmt->execute(['%' . $tel_norm . '%', $venda['moto_id']]);
      $lead_id = $lead_stmt->fetchColumn();

      if ($lead_id) {
        $upd = $pdo->prepare("
          UPDATE crm_leads
          SET etapa='fechado', fechado_at=?, valor_negociado=?, venda_id=?, updated_at=NOW()
          WHERE id=?
        ");
        $upd->execute([$venda['data_venda'] . ' 12:00:00', $venda['valor_venda'], $vendaId, $lead_id]);
        crm_registrar_interacao($pdo, $lead_id, 'sistema', 'Lead fechado via venda registrada (Venda #' . $vendaId . ')');
      }
    } catch (Throwable $e) {
      // Nunca quebra a venda por erro do CRM
    }
  }
}
