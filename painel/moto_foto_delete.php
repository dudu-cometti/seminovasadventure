<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/moto_fields.php';

require_login();
if (!user_can('edit')) {
  http_response_code(403);
  exit('Sem permissão.');
}

ensure_moto_schema($pdo);

$moto_id = (int)($_GET['moto_id'] ?? 0);
$foto_id = (int)($_GET['foto_id'] ?? 0);

if ($moto_id <= 0 || $foto_id <= 0) {
  http_response_code(400);
  exit('Parâmetros inválidos.');
}

$stmt = $pdo->prepare("SELECT id, moto_id, caminho FROM moto_fotos WHERE id=? AND moto_id=?");
$stmt->execute([$foto_id, $moto_id]);
$foto = $stmt->fetch();

if (!$foto) {
  http_response_code(404);
  exit('Foto não encontrada.');
}

$stmt = $pdo->prepare("DELETE FROM moto_fotos WHERE id=? AND moto_id=?");
$stmt->execute([$foto_id, $moto_id]);

$path = __DIR__ . '/../uploads/' . $foto['caminho'];
if (is_file($path)) @unlink($path);

// Renumera as fotos restantes (posição 1 volta a ser a capa)
moto_fotos_reindex($pdo, $moto_id);

header('Location: ' . base_url('painel/moto_form.php?id=' . $moto_id));
exit;
