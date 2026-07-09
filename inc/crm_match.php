<?php
// CRM Match Engine — motor determinístico de score entre motos e interesses

// Remove acentos e converte pra lowercase pra comparação (ex: "CG 160" == "cg 160")
function crm_normaliza_comparacao($str) {
  $str = strtolower($str);
  // Remove acentos simples: á→a, é→e, etc
  $str = preg_replace('/[áàâã]/i', 'a', $str);
  $str = preg_replace('/[éèê]/i', 'e', $str);
  $str = preg_replace('/[íì]/i', 'i', $str);
  $str = preg_replace('/[óòôõ]/i', 'o', $str);
  $str = preg_replace('/[úù]/i', 'u', $str);
  $str = preg_replace('/[ç]/i', 'c', $str);
  return trim($str);
}

// Quebra string em tokens (palavras)
function crm_tokeniza($str) {
  $str = crm_normaliza_comparacao($str);
  return array_filter(preg_split('/\s+/', $str), fn($t) => !empty($t));
}

/**
 * Pontua match entre uma moto e um interesse do lead (0-100)
 *
 * @param array $moto    [id, titulo, modelo, valor, km, ano_modelo, ...]
 * @param array $interesse [id, marca, modelo, ano_min, ano_max, valor_max, observacao, ...]
 * @param array $lead    [id, moto_id, ...] — usado pra full-match se moto_id == esta moto
 *
 * @return int score 0-100
 */
function crm_match_score_moto_interesse($moto, $interesse, $lead) {
  // Full match: o lead já tem interesse nesta moto
  if (!empty($lead['moto_id']) && (int)$lead['moto_id'] === (int)($moto['id'] ?? 0)) {
    return 100;
  }

  $score = 0;

  // ===== MODELO/MARCA (até 45) =====
  if (!empty($interesse['modelo']) || !empty($interesse['marca'])) {
    $moto_tokens = crm_tokeniza($moto['titulo'] ?? '');
    $moto_modelo = crm_normaliza_comparacao($moto['modelo'] ?? '');
    $moto_ano = $moto['ano_modelo'] ?? '';

    $int_modelo = crm_normaliza_comparacao($interesse['modelo'] ?? '');
    $int_marca = crm_normaliza_comparacao($interesse['marca'] ?? '');

    // Modelo bate exatamente (ex: "CG 160" == "CG 160")
    if (!empty($int_modelo)) {
      $int_tokens = crm_tokeniza($int_modelo);
      $match_tokens = count(array_intersect($int_tokens, $moto_tokens));
      if ($match_tokens === count($int_tokens) && !empty($int_tokens)) {
        // Todos os tokens do interesse estão no título da moto
        $score += 45;
      } elseif ($int_modelo === $moto_modelo) {
        // Modelo exato
        $score += 45;
      } elseif (!empty($int_marca) && strpos($moto_modelo, $int_marca) !== false) {
        // Marca está no modelo
        $score += 20;
      }
    } elseif (!empty($int_marca)) {
      // Só marca preenchida
      if (stripos($moto['titulo'] ?? '', $int_marca) !== false ||
          stripos($moto_modelo, $int_marca) !== false) {
        $score += 20;
      }
    }

    // Se nenhuma pontuação no modelo, mas tem interesse com marca/modelo: neutro
    if ($score === 0 && (!empty($int_modelo) || !empty($int_marca))) {
      $score = 15;
    }
  } else {
    // Interesse SEM marca/modelo: neutro
    $score = 15;
  }

  // ===== VALOR (até 25) =====
  if (!empty($interesse['valor_max']) && $interesse['valor_max'] > 0) {
    $moto_valor = (float)($moto['valor'] ?? 0);
    $max_valor = (float)$interesse['valor_max'];

    if ($moto_valor <= $max_valor) {
      $score += 25;
    } elseif ($moto_valor <= $max_valor * 1.1) {
      // Até 10% acima
      $score += 15;
    } elseif ($moto_valor <= $max_valor * 1.2) {
      // Até 20% acima
      $score += 5;
    }
  } else {
    // Sem valor_max: neutro
    $score += 12;
  }

  // ===== ANO (até 15) =====
  if (!empty($interesse['ano_min']) || !empty($interesse['ano_max'])) {
    $moto_ano = (int)($moto['ano_modelo'] ?? 0);
    $ano_min = (int)($interesse['ano_min'] ?? 0);
    $ano_max = (int)($interesse['ano_max'] ?? 0);

    if ($ano_min && $ano_max) {
      if ($moto_ano >= $ano_min && $moto_ano <= $ano_max) {
        $score += 15;
      } elseif ($moto_ano === $ano_min - 1 || $moto_ano === $ano_max + 1) {
        $score += 8;
      }
    } else {
      // Faixa incompleta: neutro
      $score += 8;
    }
  } else {
    // Sem faixa de ano: neutro
    $score += 8;
  }

  // ===== KM (até 15) =====
  if (!empty($interesse['km_max'])) {
    $moto_km = (int)($moto['km'] ?? 0);
    $km_max = (int)$interesse['km_max'];

    if ($moto_km <= $km_max) {
      $score += 15;
    } elseif ($moto_km <= $km_max * 1.2) {
      // Até 20% acima
      $score += 8;
    }
  } else {
    // Sem km_max: neutro
    $score += 8;
  }

  return min((int)$score, 100);
}

/**
 * Motos disponíveis que combinam com interesses do lead
 *
 * @param PDO $pdo
 * @param int $leadId
 * @param int $minScore  score mínimo (default 50)
 * @param int $limit     máximo de resultados
 *
 * @return array [
 *   [
 *     'moto_id' => id,
 *     'titulo' => '...',
 *     'modelo' => '...',
 *     'ano_modelo' => 2025,
 *     'km' => 1000,
 *     'valor' => 25000.00,
 *     'foto_capa' => 'uploads/...',
 *     'score' => 85,
 *     'motivo' => 'Modelo bate · Dentro do orçamento'
 *   ],
 *   ...
 * ]
 */
function crm_match_motos_para_lead($pdo, $leadId, $minScore = 50, $limit = 6) {
  try {
    $lead = $pdo->prepare("SELECT id, moto_id FROM crm_leads WHERE id=?");
    $lead->execute([$leadId]);
    $lead = $lead->fetch(PDO::FETCH_ASSOC);
    if (!$lead) return [];

    // Busca interesses do lead
    $stmt_int = $pdo->prepare("
      SELECT id, marca, modelo, ano_min, ano_max, valor_max, km_max, observacao
      FROM crm_interesses
      WHERE lead_id=?
      ORDER BY id DESC
    ");
    $stmt_int->execute([$leadId]);
    $interesses = $stmt_int->fetchAll(PDO::FETCH_ASSOC);
    if (empty($interesses)) return [];

    // Busca motos disponíveis com foto capa
    $stmt_motos = $pdo->prepare("
      SELECT m.id, m.titulo, m.modelo, m.ano_modelo, m.km, m.valor,
             (SELECT caminho FROM moto_fotos WHERE moto_id=m.id ORDER BY ordem ASC, id ASC LIMIT 1) as foto_capa
      FROM motos m
      WHERE m.status='disponivel'
      ORDER BY m.id DESC
    ");
    $stmt_motos->execute();
    $motos = $stmt_motos->fetchAll(PDO::FETCH_ASSOC);

    $resultados = [];
    foreach ($motos as $moto) {
      // Excluir a moto do próprio interesse do lead
      if ((int)$moto['id'] === (int)($lead['moto_id'] ?? 0)) {
        continue;
      }

      $max_score = 0;
      $motivos = [];
      foreach ($interesses as $int) {
        $score = crm_match_score_moto_interesse($moto, $int, $lead);
        if ($score > $max_score) {
          $max_score = $score;
          $motivos = [];
        }

        if ($score === $max_score && $score > 0) {
          // Monta motivos
          if (!empty($int['modelo']) || !empty($int['marca'])) {
            if (crm_normaliza_comparacao($int['modelo'] ?? '') === crm_normaliza_comparacao($moto['modelo'] ?? '') ||
                stripos($moto['titulo'] ?? '', $int['modelo'] ?? '') !== false) {
              $motivos[] = 'Modelo bate';
            }
          }
          if (!empty($int['valor_max']) && (float)$moto['valor'] <= (float)$int['valor_max']) {
            $motivos[] = 'Dentro do orçamento';
          }
          if (!empty($int['ano_min']) && !empty($int['ano_max']) &&
              (int)$moto['ano_modelo'] >= (int)$int['ano_min'] &&
              (int)$moto['ano_modelo'] <= (int)$int['ano_max']) {
            $motivos[] = 'Ano ok';
          }
          if (!empty($int['km_max']) && (int)$moto['km'] <= (int)$int['km_max']) {
            $motivos[] = 'Km ok';
          }
        }
      }

      if ($max_score >= $minScore) {
        $resultados[] = [
          'moto_id' => (int)$moto['id'],
          'titulo' => $moto['titulo'],
          'modelo' => $moto['modelo'],
          'ano_modelo' => (int)$moto['ano_modelo'],
          'km' => (int)$moto['km'],
          'valor' => (float)$moto['valor'],
          'foto_capa' => !empty($moto['foto_capa']) ? ('uploads/' . $moto['foto_capa']) : null,
          'score' => (int)$max_score,
          'motivo' => implode(' · ', array_unique($motivos))
        ];
      }
    }

    // Ordena por score desc
    usort($resultados, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($resultados, 0, $limit);
  } catch (Throwable $e) {
    error_log('crm_match_motos_para_lead: ' . $e->getMessage());
    return [];
  }
}

/**
 * Leads ativos que combinam com uma moto (inverso)
 *
 * @param PDO $pdo
 * @param int $motoId
 * @param int $minScore
 * @param int $limit
 * @param array|null $user  opcional: filtra por visibilidade do usuário
 *
 * @return array [
 *   [
 *     'lead_id' => id,
 *     'nome' => '...',
 *     'telefone' => '...',
 *     'vendedor_nome' => '...' ou null,
 *     'temperatura' => 'quente|morno|frio',
 *     'score' => 90,
 *     'motivo' => '...'
 *   ],
 *   ...
 * ]
 */
function crm_match_leads_para_moto($pdo, $motoId, $minScore = 50, $limit = 10, $user = null) {
  try {
    $moto = $pdo->prepare("SELECT id, titulo, modelo, ano_modelo, km, valor FROM motos WHERE id=?");
    $moto->execute([$motoId]);
    $moto = $moto->fetch(PDO::FETCH_ASSOC);
    if (!$moto) return [];

    // Leads em etapa ativa
    $where = "l.etapa IN ('novo', 'contato', 'negociacao', 'proposta')";
    $params = [$motoId];

    // Visibilidade: vendedor vê só seus + sem vendedor
    if ($user && $user['role'] === 'vendedor') {
      $where .= " AND (l.vendedor_id=? OR l.vendedor_id IS NULL)";
      $params[] = $user['id'];
    }

    $stmt = $pdo->prepare("
      SELECT l.id, l.nome, l.telefone, l.moto_id, l.temperatura, u.nome as vendedor_nome
      FROM crm_leads l
      LEFT JOIN users u ON l.vendedor_id=u.id
      WHERE $where
      ORDER BY l.id DESC
    ");
    $stmt->execute($params);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultados = [];
    foreach ($leads as $lead) {
      $score = 0;
      $motivos = [];

      // Full match: lead já apontado pra esta moto
      if ((int)$lead['moto_id'] === (int)$motoId) {
        $score = 100;
        $motivos[] = 'Interesse registrado';
      } else {
        // Busca interesses do lead e calcula score
        $stmt_int = $pdo->prepare("
          SELECT id, marca, modelo, ano_min, ano_max, valor_max, km_max, observacao
          FROM crm_interesses WHERE lead_id=?
        ");
        $stmt_int->execute([$lead['id']]);
        $interesses = $stmt_int->fetchAll(PDO::FETCH_ASSOC);

        if (empty($interesses)) {
          continue; // Lead sem interesse: pula
        }

        foreach ($interesses as $int) {
          $s = crm_match_score_moto_interesse($moto, $int, $lead);
          if ($s > $score) {
            $score = $s;
            $motivos = [];
          }

          if ($s === $score && $s > 0) {
            if (!empty($int['modelo']) && stripos($moto['titulo'], $int['modelo']) !== false) {
              $motivos[] = 'Modelo bate';
            }
            if (!empty($int['valor_max']) && (float)$moto['valor'] <= (float)$int['valor_max']) {
              $motivos[] = 'Preço ok';
            }
          }
        }
      }

      if ($score >= $minScore) {
        $resultados[] = [
          'lead_id' => (int)$lead['id'],
          'nome' => $lead['nome'],
          'telefone' => $lead['telefone'],
          'vendedor_nome' => $lead['vendedor_nome'],
          'temperatura' => $lead['temperatura'],
          'score' => (int)$score,
          'motivo' => implode(' · ', array_unique($motivos))
        ];
      }
    }

    // Ordena: score desc, depois temperatura (quente > morno > frio)
    $temp_order = ['quente' => 0, 'morno' => 1, 'frio' => 2];
    usort($resultados, function($a, $b) use ($temp_order) {
      if ($a['score'] !== $b['score']) {
        return $b['score'] <=> $a['score'];
      }
      $ta = $temp_order[$a['temperatura']] ?? 3;
      $tb = $temp_order[$b['temperatura']] ?? 3;
      return $ta <=> $tb;
    });

    return array_slice($resultados, 0, $limit);
  } catch (Throwable $e) {
    error_log('crm_match_leads_para_moto: ' . $e->getMessage());
    return [];
  }
}

/**
 * Verifica se um lead tem match ≥80 com alguma moto disponível
 * (usado no kanban pra ícone ⚡)
 */
function crm_lead_tem_match_forte($pdo, $leadId) {
  try {
    $oportunidades = crm_match_motos_para_lead($pdo, $leadId, 80, 1);
    return !empty($oportunidades);
  } catch (Throwable $e) {
    return false;
  }
}
