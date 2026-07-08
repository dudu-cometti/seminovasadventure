<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function ip_addr() {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  return substr($ip, 0, 45);
}

function user_agent() {
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  return substr($ua, 0, 255);
}

function clean_str($v, $max=255){
  $v = trim((string)$v);
  if (strlen($v) > $max) $v = substr($v, 0, $max);
  return $v;
}

// aceita JSON ou POST normal
$raw = file_get_contents('php://input');
$data = [];
if ($raw) {
  $json = json_decode($raw, true);
  if (is_array($json)) $data = $json;
}
if (!$data) $data = $_POST;

$event = clean_str($data['event'] ?? '', 40);
$session_key = clean_str($data['session_key'] ?? '', 64);
$page = clean_str($data['page'] ?? '', 255);
$moto_id = isset($data['moto_id']) && $data['moto_id'] !== '' ? (int)$data['moto_id'] : null;
$referrer = clean_str($data['referrer'] ?? '', 255);

$utm_source = clean_str($data['utm_source'] ?? '', 80);
$utm_medium = clean_str($data['utm_medium'] ?? '', 80);
$utm_campaign = clean_str($data['utm_campaign'] ?? '', 120);
$utm_content = clean_str($data['utm_content'] ?? '', 120);
$utm_term = clean_str($data['utm_term'] ?? '', 120);

$allowed = ['page_view','view_moto','click_whatsapp','ping'];
if (!in_array($event, $allowed, true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_event']);
  exit;
}
if ($session_key === '' || strlen($session_key) < 10) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_session_key']);
  exit;
}

$now = date('Y-m-d H:i:s');
$ip = ip_addr();
$ua = user_agent();

// 1) Atualiza "online agora" (active_sessions)
try {
  // upsert manual (compatível com Hostinger)
  $stmt = $pdo->prepare("SELECT id FROM active_sessions WHERE session_key=? LIMIT 1");
  $stmt->execute([$session_key]);
  $existing = $stmt->fetchColumn();

  if ($existing) {
    $up = $pdo->prepare("
      UPDATE active_sessions
      SET last_seen=?, page=?, moto_id=?, ip=?, user_agent=?
      WHERE session_key=?
    ");
    $up->execute([$now, $page, $moto_id, $ip, $ua, $session_key]);
  } else {
    $ins = $pdo->prepare("
      INSERT INTO active_sessions (session_key, last_seen, first_seen, page, moto_id, ip, user_agent)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$session_key, $now, $now, $page, $moto_id, $ip, $ua]);
  }

  // limpa sessões antigas (mais de 24h sem ver)
  $pdo->exec("DELETE FROM active_sessions WHERE last_seen < (NOW() - INTERVAL 24 HOUR)");
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_active_sessions']);
  exit;
}

// 2) Grava evento (page_events) — só quando não for "ping"
if ($event !== 'ping') {
  try {
    $insE = $pdo->prepare("
      INSERT INTO page_events
        (session_key, event_type, page, moto_id, referrer, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at, ip)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insE->execute([
      $session_key,
      $event,
      $page,
      $moto_id,
      $referrer,
      $utm_source,
      $utm_medium,
      $utm_campaign,
      $utm_content,
      $utm_term,
      $now,
      $ip
    ]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db_page_events']);
    exit;
  }
}

echo json_encode(['ok'=>true]);
