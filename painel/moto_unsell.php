<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/moto_fields.php';
require_login();

if (!user_can('edit')) {
  http_response_code(403);
  exit('Sem permissão.');
}

ensure_vendas_schema($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) exit('ID inválido');

$stmt = $pdo->prepare("SELECT * FROM motos WHERE id=?");
$stmt->execute([$id]);
$moto = $stmt->fetch();
if (!$moto) exit('Moto não encontrada');

if ($moto['status'] !== 'vendida') {
  header('Location: ' . base_url('painel/motos.php'));
  exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['gerente_email'] ?? '');
  $senha = $_POST['gerente_senha'] ?? '';

  $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'gerente' LIMIT 1");
  $stmt->execute([$email]);
  $gerente = $stmt->fetch();

  if (!$gerente || !password_verify($senha, $gerente['senha_hash'])) {
    $erro = 'E-mail ou senha de gerente inválidos. A autorização de um gerente é obrigatória para cancelar uma venda.';
  } else {
    $pdo->beginTransaction();
    try {
      $pdo->prepare("DELETE FROM vendas WHERE moto_id=?")->execute([$id]);
      $pdo->prepare("UPDATE motos SET status='disponivel', sold_at=NULL, updated_at=NOW() WHERE id=?")->execute([$id]);
      $pdo->commit();
      header('Location: ' . base_url('painel/motos.php'));
      exit;
    } catch (Exception $e) {
      $pdo->rollBack();
      $erro = 'Erro ao cancelar venda: ' . $e->getMessage();
    }
  }
}

$nomeMoto = trim($moto['titulo'] ?: $moto['modelo']);
$page_title = 'Cancelar venda';
include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">
    <div class="page-header">
      <div>
        <h1 class="page-title">Cancelar venda</h1>
        <p class="page-subtitle">
          Moto: <strong><?= htmlspecialchars($nomeMoto) ?></strong>
          · <?= htmlspecialchars($moto['ano_modelo']) ?>
          · <?= htmlspecialchars($moto['cor']) ?>
        </p>
      </div>
      <a href="<?= base_url('painel/motos.php') ?>" class="btn btn-ghost">← Voltar</a>
    </div>

    <?php if ($erro): ?>
      <div class="alert alert-error"><span>⚠</span> <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form method="post" class="form-card form-card-narrow">
      <div class="alert alert-warning" style="margin-bottom:16px;">
        <span>⚠</span> Isto vai <b>reverter a venda</b>: a moto volta para <b>disponível</b> e o registro da venda é apagado. Requer autorização de um <b>gerente</b>.
      </div>

      <div class="field mb-4">
        <label>E-mail do gerente *</label>
        <input type="email" name="gerente_email" required placeholder="gerente@exemplo.com" autocomplete="off">
      </div>
      <div class="field mb-4">
        <label>Senha do gerente *</label>
        <input type="password" name="gerente_senha" required placeholder="••••••••" autocomplete="off">
      </div>

      <div class="row" style="gap:8px;">
        <button class="btn btn-danger btn-lg" type="submit">Cancelar venda</button>
        <a href="<?= base_url('painel/motos.php') ?>" class="btn btn-ghost btn-lg">Voltar</a>
      </div>
    </form>
  </div>
</main>

<?php include __DIR__ . '/../inc/footer.php'; ?>
