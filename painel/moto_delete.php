<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
if (!user_can('delete')) {
    http_response_code(403);
    echo 'Acesso negado';
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    // Apaga fotos físicas também
    $stmt = $pdo->prepare("SELECT caminho FROM moto_fotos WHERE moto_id = ?");
    $stmt->execute([$id]);
    $fotos = $stmt->fetchAll();
    foreach ($fotos as $f) {
        $path = __DIR__ . '/../uploads/' . $f['caminho'];
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $stmt = $pdo->prepare("DELETE FROM motos WHERE id = ?");
    $stmt->execute([$id]);
}

header('Location: ' . base_url('painel/motos.php'));
exit;
