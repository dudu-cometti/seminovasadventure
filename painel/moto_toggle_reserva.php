<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

// precisa ter permissão de editar
if (function_exists('user_can') && !user_can('edit')) {
  http_response_code(403);
  exit('Sem permissão.');
}

$id = (int)($_GET['id'] ?? 0);
$to = $_GET['to'] ?? '';

if ($id <= 0 || !in_array($to, ['reservada','disponivel'], true)) {
  http_response_code(400);
  exit('Parâmetros inválidos.');
}

// Não permite mexer em vendida via esse botão
$stmt = $pdo->prepare("SELECT status FROM motos WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
  http_response_code(404);
  exit('Moto não encontrada.');
}
if ($row['status'] === 'vendida') {
  header('Location: ' . base_url('painel/motos.php'));
  exit;
}

$stmt = $pdo->prepare("UPDATE motos SET status=?, updated_at=NOW() WHERE id=?");
$stmt->execute([$to, $id]);

header('Location: ' . base_url('painel/motos.php'));
exit;
