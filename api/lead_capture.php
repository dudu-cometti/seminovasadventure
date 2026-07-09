<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/crm.php';
require_once __DIR__ . '/../inc/pixel.php';

header('Content-Type: application/json; charset=utf-8');

// Sem sessão/login requerido (endpoint público)
ensure_crm_schema($pdo);

$resp = ['ok' => false, 'msg' => 'Erro desconhecido'];

try {
  // Parse JSON
  $input = json_decode(file_get_contents('php://input'), true);
  if (!$input) {
    $input = $_POST; // fallback form-data
  }

  $nome = trim($input['nome'] ?? '');
  $telefone = trim($input['telefone'] ?? '');
  $moto_id = (int)($input['moto_id'] ?? 0);
  $honeypot = trim($input['site'] ?? '');

  // Validações
  if (strlen($nome) < 2 || strlen($nome) > 120) {
    $resp['msg'] = 'Nome inválido';
    http_response_code(400);
    echo json_encode($resp);
    exit;
  }

  $tel_norm = crm_normaliza_telefone($telefone);
  if (strlen($tel_norm) < 10 || strlen($tel_norm) > 11) {
    $resp['msg'] = 'Telefone inválido';
    http_response_code(400);
    echo json_encode($resp);
    exit;
  }

  // Honeypot: se preenchido, fingi sucesso mas não grava
  if (!empty($honeypot)) {
    $resp = ['ok' => true];
    echo json_encode($resp);
    exit;
  }

  // Rate limit: max 5 por IP por hora
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $hour_ago = time() - 3600;
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM page_events WHERE ip=? AND event='lead_capture' AND created_at > FROM_UNIXTIME(?)");
  $stmt->execute([$ip, $hour_ago]);
  $count = (int)$stmt->fetchColumn();
  if ($count >= 5) {
    // Fingi sucesso (anti-bot)
    $resp = ['ok' => true];
    echo json_encode($resp);
    exit;
  }

  // Dedup: telefone normalizado com lead ATIVO
  $lead = null;
  $stmt = $pdo->prepare("SELECT id, nome, moto_id FROM crm_leads WHERE telefone=? AND etapa NOT IN ('fechado','perdido') LIMIT 1");
  $stmt->execute([$tel_norm]);
  $lead = $stmt->fetch(PDO::FETCH_ASSOC);

  $lead_id = null;
  if ($lead) {
    // Lead ativo existe: atualizar
    $lead_id = (int)$lead['id'];

    // Se moto mudou, registrar interação
    if ($moto_id > 0 && $moto_id !== (int)$lead['moto_id']) {
      $moto_stmt = $pdo->prepare("SELECT titulo FROM motos WHERE id=? LIMIT 1");
      $moto_stmt->execute([$moto_id]);
      $moto_titulo = $moto_stmt->fetchColumn() ?: 'Moto ' . $moto_id;

      crm_registrar_interacao($pdo, $lead_id, 'sistema', 'Novo interesse via site: ' . htmlspecialchars($moto_titulo));

      // Atualizar moto se lead não tinha
      if (empty($lead['moto_id'])) {
        $upd = $pdo->prepare("UPDATE crm_leads SET moto_id=?, updated_at=NOW() WHERE id=?");
        $upd->execute([$moto_id, $lead_id]);
      }
    }
  } else {
    // Criar novo lead
    $track_data = [];
    try {
      $track_json = localStorage_get('am_track'); // função helper abaixo
      if ($track_json) {
        $track_data = json_decode($track_json, true) ?: [];
      }
    } catch (Throwable $e) {}

    $moto_titulo = '';
    if ($moto_id > 0) {
      $moto_stmt = $pdo->prepare("SELECT titulo FROM motos WHERE id=? LIMIT 1");
      $moto_stmt->execute([$moto_id]);
      $moto_titulo = $moto_stmt->fetchColumn() ?: '';
    }

    $stmt = $pdo->prepare("
      INSERT INTO crm_leads (
        nome, telefone, email, moto_id, etapa, temperatura, origem,
        utm_source, utm_medium, utm_campaign, utm_content, fbclid, _fbp, _fbc, landing_url,
        created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
      $nome,
      $tel_norm,
      $input['email'] ?? null,
      $moto_id > 0 ? $moto_id : null,
      'novo',
      'morno',
      'site',
      $track_data['utm_source'] ?? null,
      $track_data['utm_medium'] ?? null,
      $track_data['utm_campaign'] ?? null,
      $track_data['utm_content'] ?? null,
      $track_data['fbclid'] ?? null,
      $track_data['_fbp'] ?? null,
      $track_data['_fbc'] ?? null,
      $track_data['landing_url'] ?? $_SERVER['HTTP_REFERER'] ?? null
    ]);

    $lead_id = $pdo->lastInsertId();

    // Registrar interação
    $msg = 'Lead capturado no botão de WhatsApp';
    if (!empty($moto_titulo)) {
      $msg .= ' — ' . htmlspecialchars($moto_titulo);
    }
    crm_registrar_interacao($pdo, $lead_id, 'sistema', $msg);
  }

  // Log de evento
  try {
    $pdo->prepare("INSERT INTO page_events (ip, event, created_at) VALUES (?, ?, NOW())")
      ->execute([$ip, 'lead_capture']);
  } catch (Throwable $e) {}

  // CAPI Lead event
  $event_id = pixel_event_id();
  $user_data = [];
  if (!empty($nome)) {
    $parts = explode(' ', trim($nome));
    if (count($parts) >= 1) {
      $user_data['fn'] = hash_pii($parts[0]);
    }
  }
  if (!empty($tel_norm)) {
    $user_data['ph'] = hash_pii(normalize_phone_for_capi($tel_norm));
  }
  $user_data['client_ip_address'] = $ip;
  if (!empty($_SERVER['HTTP_USER_AGENT'])) {
    $user_data['client_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
  }

  // Adicionar fbp/fbc se disponível
  $fbp = $_COOKIE['_fbp'] ?? '';
  $fbc = $_COOKIE['_fbc'] ?? '';
  if (!empty($fbp)) $user_data['fbp'] = $fbp;
  if (!empty($fbc)) $user_data['fbc'] = $fbc;

  $source_url = $_SERVER['HTTP_REFERER'] ?? $_SERVER['HTTP_HOST'] ?? 'unknown';
  capi_send($pdo, 'Lead', $user_data, [], $event_id, $source_url);

  $resp = ['ok' => true, 'event_id' => $event_id];
} catch (Throwable $e) {
  error_log('lead_capture error: ' . $e->getMessage());
  $resp['msg'] = 'Erro ao processar';
  http_response_code(500);
}

echo json_encode($resp);
exit;
