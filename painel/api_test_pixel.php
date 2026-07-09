<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/pixel.php';

require_login();
if (!user_can('config')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'Acesso negado']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

$resp = ['ok' => false, 'msg' => 'Erro desconhecido'];

try {
  $user = current_user();
  $event_id = pixel_event_id();

  $user_data = [
    'fn' => hash_pii($user['nome'] ?? 'Teste'),
    'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
  ];

  $success = capi_send($pdo, 'Lead', $user_data, [], $event_id, $_SERVER['HTTP_HOST'] ?? 'unknown');

  if ($success) {
    $resp = ['ok' => true, 'msg' => 'Evento enviado com sucesso'];
  } else {
    $resp = ['ok' => false, 'msg' => 'Falha ao enviar. Verifique o token e configurações.'];
  }
} catch (Throwable $e) {
  $resp = ['ok' => false, 'msg' => 'Erro: ' . $e->getMessage()];
}

echo json_encode($resp);
