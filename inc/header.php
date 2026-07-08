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

// WhatsApp + faixa de confiança (editáveis nas Configurações)
$whatsappHead = preg_replace('/\D+/', '', _settings_lookup($pdo, ['whatsapp_number','whatsapp','numero_whatsapp','telefone_whatsapp'], '5527999215754'));
$waHeadLink   = 'https://wa.me/' . $whatsappHead . '?text=' . rawurlencode('Oi! Vim pelo site e quero saber das motos disponíveis.');
$faixa1 = _settings_lookup($pdo, ['faixa_1'], '★★★★★ Bem avaliada no Google');
$faixa2 = _settings_lookup($pdo, ['faixa_2'], 'Loja física em ' . $cidade);
$faixa3 = _settings_lookup($pdo, ['faixa_3'], 'Financiamento e troca na hora');

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
  <meta name="theme-color" content="#c8291f">
  <title><?= htmlspecialchars($page_title ?: $nomeLoja) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800;900&family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

  <link rel="icon" href="<?= base_url('favicon.ico') ?>" type="image/x-icon">
  <link rel="stylesheet" href="<?= base_url('assets/style.css?v=2017') ?>">
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
      <?php if ($isLogged): ?>
        <a href="<?= base_url('index.php') ?>" class="<?= $current === 'index.php' ? 'active' : '' ?>">Marketplace</a>
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

    <?php if ($isLogged): ?>
    <div class="nav-actions">
      <a class="btn btn-sm btn-outline" href="<?= base_url('logout.php') ?>">Sair</a>
      <button class="nav-toggle" type="button" id="navToggle" aria-label="Abrir menu">
        <svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
          <line x1="3" y1="6" x2="21" y2="6"/>
          <line x1="3" y1="12" x2="21" y2="12"/>
          <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
    </div>
    <?php elseif (!$inPainel): ?>
    <div class="nav-actions">
      <a class="btn-zap-head" href="<?= htmlspecialchars($waHeadLink) ?>" target="_blank" rel="noopener">
        <svg fill="currentColor" viewBox="0 0 24 24" width="18" height="18"><path d="M17.5 14.4c-.3-.1-1.6-.8-1.9-.9-.3-.1-.5-.1-.7.1s-.8.9-1 1.1c-.2.2-.4.2-.7.1-1.6-.8-2.7-1.4-3.7-3.2-.3-.5.3-.5.8-1.5.1-.2 0-.3 0-.5s-.7-1.7-1-2.3c-.3-.6-.5-.5-.7-.5s-.4 0-.6 0c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.2 2.4 3.7 6 5 .8.4 1.5.6 2 .8.8.3 1.6.2 2.2.1.7-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.1-.3-.2-.5-.3z"/><path d="M21 11.6c0 5.3-4.3 9.6-9.6 9.6-1.7 0-3.3-.4-4.7-1.2L2 21l1.1-4.5c-.9-1.5-1.5-3.2-1.5-5 0-5.3 4.3-9.6 9.6-9.6S21 6.3 21 11.6zm-9.6-7.8c-4.3 0-7.8 3.5-7.8 7.8 0 1.6.5 3.1 1.4 4.4l-.7 2.7 2.7-.7c1.2.8 2.7 1.2 4.3 1.2 4.3 0 7.8-3.5 7.8-7.8s-3.4-7.6-7.7-7.6z"/></svg>
        <span>Chamar no zap</span>
      </a>
    </div>
    <?php endif; ?>
  </div>
</header>

<?php
$faixaItens = array_values(array_filter([$faixa1, $faixa2, $faixa3], fn($x) => trim((string)$x) !== ''));
if (!$inPainel && $faixaItens): ?>
<div class="trust-strip">
  <div class="trust-strip-inner">
    <?php foreach ($faixaItens as $fi): ?>
      <span><?= str_replace(['★','☆'], ['<i class="star">★</i>','<i class="star">☆</i>'], htmlspecialchars($fi)) ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

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

  // Esconde a barra de busca ao rolar para baixo e mostra ao subir
  (function(){
    let lastY = window.scrollY || 0;
    let ticking = false;
    const THRESHOLD = 90; // só começa a esconder depois de descer um pouco
    function update(){
      const y = window.scrollY || 0;
      if (y > lastY && y > THRESHOLD) {
        document.body.classList.add('chrome-hidden');    // descendo
      } else if (y < lastY) {
        document.body.classList.remove('chrome-hidden');  // subindo
      }
      lastY = y;
      ticking = false;
    }
    window.addEventListener('scroll', function(){
      if (!ticking){ requestAnimationFrame(update); ticking = true; }
    }, { passive: true });
  })();
</script>
