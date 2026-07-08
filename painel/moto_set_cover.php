<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

if (!function_exists('user_can')) {
  function user_can($perm) { return true; }
}

if (!user_can('edit')) {
  http_response_code(403);
  exit('Sem permissão.');
}

$moto_id = (int)($_GET['moto_id'] ?? 0);
$foto_id = (int)($_GET['foto_id'] ?? 0);

if ($moto_id <= 0 || $foto_id <= 0) {
  http_response_code(400);
  exit('Parâmetros inválidos.');
}

$stmt = $pdo->prepare("SELECT id FROM moto_fotos WHERE id=? AND moto_id=? LIMIT 1");
$stmt->execute([$foto_id, $moto_id]);

if (!$stmt->fetch()) {
  http_response_code(404);
  exit('Foto não encontrada para esta moto.');
}

$pdo->prepare("UPDATE moto_fotos SET is_cover=0 WHERE moto_id=?")->execute([$moto_id]);
$pdo->prepare("UPDATE moto_fotos SET is_cover=1 WHERE id=? AND moto_id=?")->execute([$foto_id, $moto_id]);

header('Location: ' . base_url('painel/moto_form.php?id=' . $moto_id));
exit;
