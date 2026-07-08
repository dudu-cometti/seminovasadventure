<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/auth.php';

if (is_logged_in()) {
    header('Location: ' . base_url('painel/dashboard.php'));
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $senha2 = $_POST['senha2'] ?? '';

    if (!$nome || !$email || !$senha || !$senha2) {
        $erro = 'Preencha todos os campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail inválido.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha deve ter no mínimo 6 caracteres.';
    } elseif ($senha !== $senha2) {
        $erro = 'As senhas não conferem.';
    } else {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $role = $count === 0 ? 'gerente' : 'vendedor';
        $can_create = $role === 'gerente' ? 1 : 0;
        $can_edit   = $role === 'gerente' ? 1 : 0;
        $can_delete = $role === 'gerente' ? 1 : 0;

        try {
            $stmt = $pdo->prepare("INSERT INTO users (nome, email, senha_hash, role, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $nome, $email, password_hash($senha, PASSWORD_DEFAULT),
                $role, $can_create, $can_edit, $can_delete
            ]);
            $sucesso = $role === 'gerente'
                ? 'Conta criada! Você é o GERENTE do sistema. Faça login para continuar.'
                : 'Conta criada! Aguarde o gerente liberar suas permissões.';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') $erro = 'Já existe uma conta com este e-mail.';
            else $erro = 'Erro ao criar conta: ' . $e->getMessage();
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

$total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$isPrimeiro = $total === 0;
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#d60000">
  <title>Criar conta — <?= htmlspecialchars($nomeLoja) ?></title>
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
      <h1><?= $isPrimeiro ? 'Configurar gerente' : 'Criar conta' ?></h1>
      <p>
        <?= $isPrimeiro
            ? 'Este é o primeiro cadastro do sistema. Você se tornará o gerente.'
            : 'Aguarde o gerente liberar suas permissões após o cadastro.' ?>
      </p>
    </div>

    <?php if ($erro): ?>
      <div class="alert alert-error"><span>⚠</span> <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($sucesso): ?>
      <div class="alert alert-success"><span>✓</span> <?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>

    <form method="post" class="form-grid">
      <div class="field">
        <label for="nome">Nome completo</label>
        <input type="text" name="nome" id="nome" required value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="email">E-mail</label>
        <input type="email" name="email" id="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>

      <div class="form-grid form-grid-2">
        <div class="field">
          <label for="senha">Senha</label>
          <input type="password" name="senha" id="senha" required minlength="6">
        </div>
        <div class="field">
          <label for="senha2">Confirmar senha</label>
          <input type="password" name="senha2" id="senha2" required minlength="6">
        </div>
      </div>

      <button class="btn btn-primary btn-lg btn-block" type="submit">Criar conta</button>
    </form>

    <div class="divider"></div>

    <p class="text-center text-sm text-muted">
      Já tem conta? <a href="<?= base_url('login.php') ?>" style="font-weight:700">Fazer login</a>
    </p>
    <p class="text-center mt-2">
      <a href="<?= base_url('index.php') ?>" class="text-sm text-muted">← Voltar pro marketplace</a>
    </p>
  </div>
</div>
</body>
</html>
