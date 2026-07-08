<?php
if (!isset($page_title)) $page_title = '';
require_once __DIR__ . '/../config.php';

$user = function_exists('current_user') ? current_user() : null;
$isLogged  = (bool)$user;
$role      = $user['role'] ?? '';
$isGerente = $isLogged && ($role === 'gerente');
$canUsers  = function_exists('user_can') ? (user_can('users') || user_can('manage_users')) : false;
$canConfig = function_exists('user_can') ? user_can('config') : false;

// Pega config do marketplace (nome + logo) já carregada em config.php
function _settings_lookup($pdo, $keys, $default = ''){
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

$nomeLoja = _settings_lookup($pdo, ['marketplace_nome','loja_nome','nome_loja','site_nome'], 'Adventure Motos');
$cidade   = _settings_lookup($pdo, ['marketplace_cidade','loja_cidade','cidade_loja'], 'São Silvano - ES');

$logoConf = _settings_lookup($pdo, ['marketplace_logo','logo','logo_path','logo_url','site_logo'], '');
$logoUrl = '';
if ($logoConf) {
  $v = trim($logoConf);
  if (preg_match('#^https?://#i', $v)) {
    $logoUrl = $v;
  } else {
    $v = ltrim($v, '/');
    if (stripos($v, 'uploads/') === 0) $v = substr($v, 8);
    $logoUrl = base_url('uploads/' . $v);
  }
}

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
$inPainel = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/painel/') !== false);

$avatarLetter = mb_strtoupper(mb_substr(trim($user['nome'] ?? 'U'), 0, 1, 'UTF-8'), 'UTF-8');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#d60000">
  <title><?= htmlspecialchars($page_title ?: $nomeLoja) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

  <link rel="icon" href="<?= base_url('favicon.ico') ?>" type="image/x-icon">
  <link rel="stylesheet" href="<?= base_url('assets/style.css?v=2004') ?>">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= base_url('index.php') ?>" style="color:inherit">
      <div class="brand-logo">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($nomeLoja) ?>" onerror="this.parentElement.innerHTML='<span style=\'font-weight:900;color:var(--brand-600)\'>'+'<?= htmlspecialchars(mb_substr($nomeLoja,0,1,'UTF-8')) ?>'+'</span>'">
        <?php else: ?>
          <span style="font-weight:900;color:var(--brand-600);font-size:18px">
            <?= htmlspecialchars(mb_substr($nomeLoja, 0, 1, 'UTF-8')) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="brand-text">
        <div class="brand-name"><?= htmlspecialchars($nomeLoja) ?></div>
        <div class="brand-sub"><?= htmlspecialchars($cidade) ?></div>
      </div>
    </a>

    <nav class="topnav" id="topnav">
      <a href="<?= base_url('index.php') ?>" class="<?= $current === 'index.php' ? 'active' : '' ?>">Marketplace</a>

      <?php if ($isLogged): ?>
        <a href="<?= base_url('painel/dashboard.php') ?>" class="<?= $current === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
        <a href="<?= base_url('painel/motos.php') ?>" class="<?= ($current === 'motos.php' || $current === 'moto_form.php') ? 'active' : '' ?>">Motos</a>
        <a href="<?= base_url('painel/analytics.php') ?>" class="<?= $current === 'analytics.php' ? 'active' : '' ?>">Analytics</a>

        <?php if ($isGerente || $canUsers): ?>
          <a href="<?= base_url('painel/users.php') ?>" class="<?= $current === 'users.php' ? 'active' : '' ?>">Usuários</a>
        <?php endif; ?>
        <?php if ($isGerente): ?>
          <a href="<?= base_url('painel/padroes.php') ?>" class="<?= $current === 'padroes.php' ? 'active' : '' ?>">Padrões</a>
        <?php endif; ?>
        <?php if ($isGerente || $canConfig): ?>
          <a href="<?= base_url('painel/config.php') ?>" class="<?= $current === 'config.php' ? 'active' : '' ?>">Config</a>
        <?php endif; ?>

        <span class="topnav-user">
          <span class="avatar"><?= htmlspecialchars($avatarLetter) ?></span>
          <span><?= htmlspecialchars($user['nome'] ?? 'Usuário') ?></span>
        </span>
      <?php endif; ?>
    </nav>

    <div class="nav-actions">
      <?php if ($isLogged): ?>
        <a class="btn btn-sm btn-outline" href="<?= base_url('logout.php') ?>">Sair</a>
      <?php else: ?>
        <a class="btn btn-sm btn-outline auth-btn-desktop" href="<?= base_url('login.php') ?>">Acessar painel</a>
      <?php endif; ?>
      <button class="nav-toggle" type="button" id="navToggle" aria-label="Abrir menu">
        <svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
          <line x1="3" y1="6" x2="21" y2="6"/>
          <line x1="3" y1="12" x2="21" y2="12"/>
          <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
    </div>
  </div>
</header>

<script>
  (function(){
    const btn = document.getElementById('navToggle');
    const nav = document.getElementById('topnav');
    if(!btn || !nav) return;
    btn.addEventListener('click', () => nav.classList.toggle('open'));
    document.addEventListener('click', (e) => {
      if (!nav.contains(e.target) && !btn.contains(e.target)) nav.classList.remove('open');
    });
  })();
</script>
