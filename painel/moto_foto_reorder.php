<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/moto_fields.php';

require_login();

if (!function_exists('user_can')) {
  function user_can($perm) { return true; }
}

if (!user_can('edit')) {
  http_response_code(403);
  exit('Sem permissão.');
}

ensure_moto_schema($pdo);

$moto_id = (int)($_GET['moto_id'] ?? 0);
$foto_id = (int)($_GET['foto_id'] ?? 0);
$dir     = ($_GET['dir'] ?? '') === 'depois' ? 'depois' : 'antes';

if ($moto_id <= 0 || $foto_id <= 0) {
  http_response_code(400);
  exit('Parâmetros inválidos.');
}

// Sequência atual
$stmt = $pdo->prepare("SELECT id FROM moto_fotos WHERE moto_id=? ORDER BY ordem ASC, id ASC");
$stmt->execute([$moto_id]);
$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$pos = array_search($foto_id, $ids, true);
if ($pos === false) {
  http_response_code(404);
  exit('Foto não encontrada para esta moto.');
}

$alvo = $dir === 'depois' ? $pos + 1 : $pos - 1;
if ($alvo >= 0 && $alvo < count($ids)) {
  // troca de lugar com o vizinho
  [$ids[$pos], $ids[$alvo]] = [$ids[$alvo], $ids[$pos]];
  moto_fotos_aplicar_ordem($pdo, $moto_id, $ids);
}

header('Location: ' . base_url('painel/moto_form.php?id=' . $moto_id));
exit;
