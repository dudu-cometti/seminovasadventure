<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/auth.php';

if (is_logged_in()) {
    header('Location: ' . base_url('painel/dashboard.php'));
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (!$email || !$senha) {
        $erro = 'Preencha e-mail e senha.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($senha, $user['senha_hash'])) {
            $_SESSION['user'] = $user;
            header('Location: ' . base_url('painel/dashboard.php'));
            exit;
        } else {
            $erro = 'E-mail ou senha inválidos.';
        }
    }
}

function setting_get_any($pdo, $keys, $default=''){
  try {
    $place = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT `key`, value FROM settings WHERE `key` IN ($place)");
    $stmt->execute($keys);
    foreach ($stmt as $row) {
      if (trim((string)$row['value']) !== '') return $row['value'];
    }
  } catch (Throwable $e) {}
  return $default;
}
$nomeLoja = setting_get_any($pdo, ['marketplace_nome','loja_nome','nome_loja','site_nome'], 'Adventure Motos');
$logoConf = setting_get_any($pdo, ['marketplace_logo','logo','logo_path','logo_url','site_logo'], '');
$logoUrl = '';
if ($logoConf) {
  $v = trim($logoConf);
  if (preg_match('#^https?://#i', $v)) $logoUrl = $v;
  else {
    $v = ltrim($v, '/');
    if (stripos($v, 'uploads/') === 0) $v = substr($v, 8);
    $logoUrl = base_url('uploads/' . $v);
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#d60000">
  <title>Acessar painel — <?= htmlspecialchars($nomeLoja) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="icon" href="<?= base_url('favicon.ico') ?>" type="image/x-icon">
  <link rel="stylesheet" href="<?= base_url('assets/style.css?v=2005') ?>">
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="brand-logo">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($nomeLoja) ?>">
        <?php else: ?>
          <span style="font-weight:900;color:var(--brand-600);font-size:22px">
            <?= htmlspecialchars(mb_substr($nomeLoja, 0, 1, 'UTF-8')) ?>
          </span>
        <?php endif; ?>
      </div>
      <h1>Bem-vindo de volta</h1>
      <p>Acesse o painel do <?= htmlspecialchars($nomeLoja) ?></p>
    </div>

    <?php if ($erro): ?>
      <div class="alert alert-error">
        <span>⚠</span> <?= htmlspecialchars($erro) ?>
      </div>
    <?php endif; ?>

    <form method="post" class="form-grid">
      <div class="field">
        <label for="email">E-mail</label>
        <input type="email" name="email" id="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="senha">Senha</label>
        <input type="password" name="senha" id="senha" required>
      </div>

      <button class="btn btn-primary btn-lg btn-block" type="submit">Entrar</button>
    </form>

    <div class="divider"></div>

    <p class="text-center text-sm text-muted">
      Não tem conta? <a href="<?= base_url('register.php') ?>" style="font-weight:700">Criar conta</a>
    </p>
    <p class="text-center mt-2">
      <a href="<?= base_url('index.php') ?>" class="text-sm text-muted">← Voltar pro marketplace</a>
    </p>
  </div>
</div>
</body>
</html>
