<?php
/**
 * Cliente de IA para CRM — Anthropic Claude API
 * Princípios: custo controlado, sem IA em load de página, cache agressivo,
 * falhas nunca quebram tela.
 */

if (!function_exists('crm_ia_enabled')) {
  function crm_ia_enabled($pdo) {
    try {
      $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name='crm_anthropic_key' LIMIT 1");
      $stmt->execute();
      $chave = trim((string)$stmt->fetchColumn());
      return !empty($chave);
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('crm_ia_get_modelo')) {
  function crm_ia_get_modelo($pdo) {
    try {
      $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name='crm_ia_modelo' LIMIT 1");
      $stmt->execute();
      $modelo = trim((string)$stmt->fetchColumn());
      return !empty($modelo) ? $modelo : 'claude-haiku-4-5-20251001';
    } catch (Throwable $e) {
      return 'claude-haiku-4-5-20251001';
    }
  }
}

if (!function_exists('crm_ia_chamar')) {
  /**
   * Chama API Anthropic com cache local.
   * Retorna: {ok: true, resposta: '...'} ou {ok: false, msg: '...'}
   *
   * @param PDO $pdo
   * @param string $tipo ('mensagem_oportunidade', 'resumo_lead', 'followup_perdido')
   * @param array $contexto Dados para montar o prompt
   * @param bool $forcar Ignora cache se true
   * @return array
   */
  function crm_ia_chamar($pdo, $tipo, $contexto = [], $forcar = false) {
    if (!crm_ia_enabled($pdo)) {
      return ['ok' => false, 'msg' => 'IA não configurada'];
    }

    try {
      require_once __DIR__ . '/crm.php';
      ensure_crm_schema($pdo);

      // Monta hash do contexto para cache
      $contexto_json = json_encode($contexto, JSON_SORT_KEYS | JSON_UNESCAPED_UNICODE);
      $hash = sha1($tipo . $contexto_json);

      // Checa cache (se não forçar)
      if (!$forcar) {
        $stmt_cache = $pdo->prepare("SELECT resposta FROM crm_ia_cache WHERE tipo=? AND hash=? LIMIT 1");
        $stmt_cache->execute([$tipo, $hash]);
        if ($resposta_cacheada = $stmt_cache->fetchColumn()) {
          return ['ok' => true, 'resposta' => $resposta_cacheada, 'do_cache' => true];
        }
      }

      // Monta prompt baseado no tipo
      $prompt = crm_ia_montar_prompt($tipo, $contexto, $pdo);
      if (empty($prompt)) {
        return ['ok' => false, 'msg' => 'Contexto inválido'];
      }

      // Monta system prompt
      $nomeLoja = _settings_lookup($pdo, ['marketplace_nome', 'loja_nome', 'nome_loja'], 'Adventure Motos');
      $system = "Você é o assistente de vendas da $nomeLoja, especializada em motos seminovas. "
        . "Escreva em português brasileiro, tom simpático e direto de WhatsApp, sem formalidade excessiva, "
        . "sem emojis em excesso (máx 2), sem inventar dados: use APENAS as informações fornecidas. "
        . "Nunca prometa preço, desconto ou condição que não esteja nos dados. Respostas curtas.";

      // Chama API via cURL
      $ch = curl_init('https://api.anthropic.com/v1/messages');
      curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
          'Content-Type: application/json',
          'x-api-key: ' . crm_ia_get_chave($pdo),
          'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
          'model' => crm_ia_get_modelo($pdo),
          'max_tokens' => ($tipo === 'resumo_lead') ? 400 : 500,
          'system' => $system,
          'messages' => [
            ['role' => 'user', 'content' => $prompt]
          ]
        ], JSON_UNESCAPED_UNICODE),
      ]);

      $resposta_raw = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curl_erro = curl_error($ch);
      curl_close($ch);

      if ($curl_erro) {
        return ['ok' => false, 'msg' => 'Erro de conexão com IA'];
      }

      if ($http_code !== 200) {
        $resposta_json = json_decode($resposta_raw, true);
        $msg_erro = $resposta_json['error']['message'] ?? 'Erro na API (HTTP ' . $http_code . ')';
        return ['ok' => false, 'msg' => $msg_erro];
      }

      $resposta_json = json_decode($resposta_raw, true);
      if (empty($resposta_json['content'][0]['text'])) {
        return ['ok' => false, 'msg' => 'Resposta vazia da IA'];
      }

      $texto_resposta = $resposta_json['content'][0]['text'];
      $tokens_in = $resposta_json['usage']['input_tokens'] ?? 0;
      $tokens_out = $resposta_json['usage']['output_tokens'] ?? 0;

      // Salva no cache
      $entrada_resumo = substr($contexto_json, 0, 255);
      $stmt_ins = $pdo->prepare("
        INSERT INTO crm_ia_cache (tipo, hash, lead_id, entrada_resumo, resposta, tokens_in, tokens_out, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE resposta=?, tokens_in=?, tokens_out=?, created_at=NOW()
      ");
      $lead_id = $contexto['lead_id'] ?? null;
      $stmt_ins->execute([
        $tipo, $hash, $lead_id, $entrada_resumo, $texto_resposta, $tokens_in, $tokens_out,
        $texto_resposta, $tokens_in, $tokens_out
      ]);

      return ['ok' => true, 'resposta' => $texto_resposta];
    } catch (Throwable $e) {
      return ['ok' => false, 'msg' => 'Erro ao processar IA: ' . $e->getMessage()];
    }
  }
}

if (!function_exists('crm_ia_get_chave')) {
  function crm_ia_get_chave($pdo) {
    try {
      $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name='crm_anthropic_key' LIMIT 1");
      $stmt->execute();
      return trim((string)$stmt->fetchColumn());
    } catch (Throwable $e) {
      return '';
    }
  }
}

if (!function_exists('crm_ia_montar_prompt')) {
  /**
   * Monta o prompt específico baseado no tipo.
   */
  function crm_ia_montar_prompt($tipo, $contexto, $pdo) {
    switch ($tipo) {
      case 'mensagem_oportunidade':
        $lead_nome = htmlspecialchars_decode($contexto['lead_nome'] ?? '');
        $temperatura = $contexto['temperatura'] ?? 'morno';
        $interesses = $contexto['interesses'] ?? []; // array de interesse_resumo
        $moto_titulo = htmlspecialchars_decode($contexto['moto_titulo'] ?? '');
        $moto_ano = $contexto['moto_ano'] ?? '';
        $moto_km = $contexto['moto_km'] ?? 0;
        $moto_valor = $contexto['moto_valor'] ?? 0;
        $moto_cor = $contexto['moto_cor'] ?? '';
        $motivo_match = htmlspecialchars_decode($contexto['motivo_match'] ?? '');
        $ultimas_interacoes = $contexto['ultimas_interacoes'] ?? [];

        $interesses_txt = !empty($interesses) ? implode(', ', array_map('htmlspecialchars_decode', $interesses)) : 'não informado';
        $interacoes_txt = '';
        if (!empty($ultimas_interacoes)) {
          $interacoes_txt = "\n\nÚltimas conversas:\n";
          foreach (array_slice($ultimas_interacoes, 0, 5) as $i) {
            $interacoes_txt .= "- {$i}\n";
          }
        }

        return "Gere 1 mensagem curta (máx 3 linhas) de WhatsApp para o cliente " . $lead_nome
          . " oferecendo a moto " . $moto_titulo . " (" . $moto_ano . ", " . $moto_km . " km, "
          . "R$ " . number_format($moto_valor, 0, ',', '.') . ", cor " . $moto_cor . "). "
          . "Cliente procura: " . $interesses_txt . ". Motivo da sugestão: " . $motivo_match
          . ". Temperatura: " . $temperatura . "."
          . $interacoes_txt
          . "\n\nTermine com uma pergunta de avanço (fotos? test drive?). "
          . "Não invente dados. Respostas diretas e amigáveis.";

      case 'resumo_lead':
        $lead_nome = htmlspecialchars_decode($contexto['lead_nome'] ?? '');
        $etapa = $contexto['etapa'] ?? '';
        $dias_na_etapa = $contexto['dias_na_etapa'] ?? 0;
        $interacoes = $contexto['interacoes'] ?? []; // array de interação_resumo
        $agendamentos = $contexto['agendamentos'] ?? []; // array de agendamento_resumo
        $moto_interesse = htmlspecialchars_decode($contexto['moto_interesse'] ?? '(nenhuma)');
        $valor_negociado = $contexto['valor_negociado'] ?? 0;

        $interacoes_txt = !empty($interacoes) ? "Interações: " . implode(' | ', array_map('htmlspecialchars_decode', $interacoes)) : '';
        $agendamentos_txt = !empty($agendamentos) ? "Agendamentos: " . implode(' | ', array_map('htmlspecialchars_decode', $agendamentos)) : '';

        return "Analise este lead e responda COMO JSON VÁLIDO (sem ```json):\n\n"
          . "Lead: " . $lead_nome . "\n"
          . "Etapa: " . $etapa . " (há " . $dias_na_etapa . " dias)\n"
          . "Moto de interesse: " . $moto_interesse . "\n"
          . "Valor negociado: R$ " . number_format($valor_negociado, 0, ',', '.') . "\n"
          . $interacoes_txt . "\n"
          . $agendamentos_txt . "\n\n"
          . "Retorne JSON com 3 campos exatos:\n"
          . "{\"resumo\": \"3 frases resumindo a situação atual\", "
          . "\"objecoes\": [\"objeção1\", \"objeção2\"], "
          . "\"proximo_passo\": \"1 ação concreta para avançar\"}\n"
          . "Sem comentários fora do JSON.";

      case 'followup_perdido':
        $lead_nome = htmlspecialchars_decode($contexto['lead_nome'] ?? '');
        $moto_titulo = htmlspecialchars_decode($contexto['moto_titulo'] ?? 'a moto');
        $motivo_perda = htmlspecialchars_decode($contexto['motivo_perda'] ?? 'circunstâncias');
        $dias_perda = $contexto['dias_perda'] ?? 0;

        return "Gere 1 mensagem curta (máx 2 linhas) de WhatsApp de reaproximação para " . $lead_nome
          . " que perdemos há " . $dias_perda . " dias pela seguinte razão: '" . $motivo_perda . "'. "
          . "A moto que negociava era: " . $moto_titulo . ". "
          . "Tom: amigável, SEM insistência. Adeque à razão da perda (ex: se foi preço, "
          . "avise de novas opções; se foi crédito, convide a reavaliar). Respostas diretas.";

      case 'teste_conexao':
        return "Responda apenas com a palavra OK.";

      default:
        return '';
    }
  }
}

if (!function_exists('crm_ia_consumo_mes')) {
  /**
   * Soma tokens consumidos no mês (hoje e anteriores neste mês).
   */
  function crm_ia_consumo_mes($pdo) {
    try {
      $primeiro_dia = date('Y-m-01');
      $stmt = $pdo->prepare("
        SELECT
          COUNT(*) as chamadas,
          SUM(tokens_in) as tokens_in,
          SUM(tokens_out) as tokens_out
        FROM crm_ia_cache
        WHERE created_at >= ?
      ");
      $stmt->execute([$primeiro_dia . ' 00:00:00']);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return [
        'chamadas' => (int)($row['chamadas'] ?? 0),
        'tokens_in' => (int)($row['tokens_in'] ?? 0),
        'tokens_out' => (int)($row['tokens_out'] ?? 0),
        'total' => (int)(($row['tokens_in'] ?? 0) + ($row['tokens_out'] ?? 0))
      ];
    } catch (Throwable $e) {
      return ['chamadas' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'total' => 0];
    }
  }
}
