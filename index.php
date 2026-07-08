<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/auth.php';

function ensure_settings_table($pdo) {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
      `key` VARCHAR(60) PRIMARY KEY,
      `value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
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
function format_money($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

require_once __DIR__ . '/inc/moto_fields.php';
ensure_settings_table($pdo);
ensure_moto_schema($pdo); // colunas ordem / valor_a_combinar

$whatsapp = preg_replace('/\D+/', '', setting_get_any($pdo, ['whatsapp_number','whatsapp','numero_whatsapp','telefone_whatsapp'], '5527999215754'));
$nomeLoja = setting_get_any($pdo, ['marketplace_nome','loja_nome','nome_loja','site_nome'], 'Adventure Motos');
$cidade   = setting_get_any($pdo, ['marketplace_cidade','loja_cidade','cidade_loja'], 'São Silvano - ES');
$bannerAtivo   = setting_get_any($pdo, ['banner_ativo'], '0') === '1';
$bannerDesktop = setting_get_any($pdo, ['banner_desktop'], '');
$bannerMobile  = setting_get_any($pdo, ['banner_mobile'], '');
// fallback: se só uma imagem foi enviada, usa ela nos dois
$bDesk = $bannerDesktop ?: $bannerMobile;
$bMob  = $bannerMobile ?: $bannerDesktop;
$temBanner = $bannerAtivo && ($bDesk || $bMob);

// ===== Filtros =====
$q       = trim($_GET['q'] ?? '');
$fmarca  = trim($_GET['marca'] ?? '');
$fano    = trim($_GET['ano'] ?? '');
$ordem   = trim($_GET['ordem'] ?? 'recentes'); // recentes | preco_asc | preco_desc | km_asc

$where = ["status IN ('disponivel','reservada')"];
$params = [];

if ($q !== '') {
  $where[] = "(titulo LIKE ? OR modelo LIKE ? OR cor LIKE ? OR descricao LIKE ?)";
  $like = "%$q%";
  array_push($params, $like, $like, $like, $like);
}
if ($fmarca !== '') {
  $where[] = "modelo = ?";
  $params[] = $fmarca;
}
if ($fano !== '') {
  $where[] = "ano_modelo LIKE ?";
  $params[] = "%$fano%";
}

$order_sql = match ($ordem) {
  'preco_asc'  => 'valor ASC',
  'preco_desc' => 'valor DESC',
  'km_asc'     => 'quilometragem ASC',
  default      => 'created_at DESC, id DESC',
};

$sql = "SELECT * FROM motos WHERE " . implode(' AND ', $where) . " ORDER BY $order_sql";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$motos = $stmt->fetchAll();

// Lista de marcas existentes (pra montar o filtro)
$marcasDisponiveis = $pdo->query("
  SELECT DISTINCT modelo
  FROM motos
  WHERE status IN ('disponivel','reservada') AND modelo IS NOT NULL AND modelo != ''
  ORDER BY modelo ASC
")->fetchAll(PDO::FETCH_COLUMN);

// Fotos por moto (capa primeiro)
$galerias = [];
if ($motos) {
  $ids = array_column($motos, 'id');
  $place = implode(',', array_fill(0, count($ids), '?'));
  $stmtF = $pdo->prepare("
    SELECT moto_id, caminho FROM moto_fotos
    WHERE moto_id IN ($place)
    ORDER BY moto_id ASC, ordem ASC, id ASC
  ");
  $stmtF->execute($ids);
  foreach ($stmtF as $f) {
    $mid = (int)$f['moto_id'];
    if (!isset($galerias[$mid])) $galerias[$mid] = [];
    $galerias[$mid][] = $f['caminho'];
  }
}

$page_title = $nomeLoja . ' — Motos Seminovas';
include __DIR__ . '/inc/header.php';
?>

<main class="container mkt">
  <?php if ($temBanner): ?>
    <section class="mkt-banner">
      <picture>
        <source media="(max-width: 640px)" srcset="<?= base_url($bMob) ?>">
        <img src="<?= base_url($bDesk) ?>" alt="<?= htmlspecialchars($nomeLoja) ?>" loading="eager">
      </picture>
    </section>
  <?php else: ?>
    <section class="hero">
      <h1>Moto boa não fica parada.</h1>
      <p>Seminovas revisadas em <?= htmlspecialchars($cidade) ?>. Falou, fechou — direto no WhatsApp.</p>
    </section>
  <?php endif; ?>

  <?php $filtrosAtivos = ($fmarca !== '' || $fano !== '' || $ordem !== 'recentes'); ?>
  <form method="get" class="filterbar" id="filterForm">
    <div class="fb-row">
      <div class="fb-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input type="search" name="q" placeholder="Buscar modelo, cor…" value="<?= htmlspecialchars($q) ?>" autocomplete="off">
      </div>
      <button type="button" class="fb-toggle <?= $filtrosAtivos ? 'has-active' : '' ?>" id="fbToggle" aria-expanded="<?= $filtrosAtivos ? 'true' : 'false' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 5h16M7 12h10M10 19h4"/></svg>
        <span>Filtros</span>
        <?php if ($filtrosAtivos): ?><i class="fb-dot"></i><?php endif; ?>
      </button>
    </div>

    <div class="fb-panel <?= $filtrosAtivos ? 'open' : '' ?>" id="fbPanel">
      <div class="fb-field">
        <label>Marca</label>
        <select name="marca">
          <option value="">Todas as marcas</option>
          <?php foreach ($marcasDisponiveis as $m): ?>
            <option value="<?= htmlspecialchars($m) ?>" <?= $fmarca===$m?'selected':'' ?>><?= htmlspecialchars($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fb-field">
        <label>Ano</label>
        <input type="text" name="ano" placeholder="Ex: 2023" value="<?= htmlspecialchars($fano) ?>" inputmode="numeric">
      </div>
      <div class="fb-field">
        <label>Ordenar por</label>
        <select name="ordem">
          <option value="recentes"   <?= $ordem==='recentes'?'selected':'' ?>>Mais recentes</option>
          <option value="preco_asc"  <?= $ordem==='preco_asc'?'selected':'' ?>>Menor preço</option>
          <option value="preco_desc" <?= $ordem==='preco_desc'?'selected':'' ?>>Maior preço</option>
          <option value="km_asc"     <?= $ordem==='km_asc'?'selected':'' ?>>Menor km</option>
        </select>
      </div>
      <div class="fb-actions">
        <button type="submit" class="fb-apply">Aplicar filtros</button>
        <?php if ($q || $filtrosAtivos): ?>
          <a href="<?= base_url('index.php') ?>" class="fb-clear">Limpar</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if (!$motos): ?>
    <div class="mkt-empty">
      <div style="font-size:44px;">🏍️</div>
      <h2>Nenhuma moto encontrada</h2>
      <p>Tente ajustar os filtros ou volte mais tarde.</p>
    </div>
  <?php else: ?>
    <div class="mkt-count"><?= count($motos) ?> moto<?= count($motos) === 1 ? '' : 's' ?> à venda</div>

    <div class="grid-motos">
      <?php foreach ($motos as $m): ?>
        <?php
          $mid = (int)$m['id'];
          $fotos = $galerias[$mid] ?? [];
          $foto0 = !empty($fotos) ? base_url('uploads/' . $fotos[0]) : "https://placehold.co/600x450?text=Moto";

          $nomeMoto = trim(($m['titulo'] ?: $m['modelo']) . '');
          $km = number_format((int)$m['quilometragem'], 0, ',', '.');
          $aCombinar = !empty($m['valor_a_combinar']) || (float)$m['valor'] <= 0;
          $precoFmt = number_format((float)$m['valor'], 0, ',', '.'); // ex: 19.900

          // Preço "de/por": usa a FIPE como preço cheio riscado, quando maior que o de venda
          $fipe = (float)($m['valor_fipe'] ?? 0);
          $temDesconto = !$aCombinar && $fipe > (float)$m['valor'] && (float)$m['valor'] > 0;
          $fipeFmt = number_format($fipe, 0, ',', '.');

          // WhatsApp com mensagem específica da moto
          if ($aCombinar) {
            $msg = "Oi! Tenho interesse na {$nomeMoto} {$m['ano_modelo']}. Qual o valor?";
          } else {
            $msg = "Oi! Tenho interesse na {$nomeMoto} {$m['ano_modelo']} por R$ {$precoFmt}";
          }
          $wa_link = "https://wa.me/{$whatsapp}?text=" . rawurlencode($msg);

          $motoUrl = base_url('moto.php?id=' . $mid);
          $reservada = $m['status'] === 'reservada';
        ?>

        <article class="mcard">
          <a class="mcard-photo" href="<?= htmlspecialchars($motoUrl) ?>" style="--photo:url('<?= htmlspecialchars($foto0) ?>')">
            <img src="<?= htmlspecialchars($foto0) ?>" alt="<?= htmlspecialchars($nomeMoto) ?>" loading="lazy">
            <span class="mcard-ribbon <?= $reservada ? 'is-resv' : 'is-ok' ?>"><?= $reservada ? 'Reservada' : 'Disponível' ?></span>
            <button class="mcard-fav" type="button" data-fav-id="<?= $mid ?>" aria-label="Favoritar">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            </button>
          </a>
          <div class="mcard-body">
            <?php if (($m['condicao'] ?? '') === 'nova'): ?>
              <span class="mcard-cond cond-nova">0 km</span>
            <?php elseif (($m['condicao'] ?? '') === 'seminova'): ?>
              <span class="mcard-cond cond-semi">Seminova</span>
            <?php endif; ?>
            <a class="mcard-name" href="<?= htmlspecialchars($motoUrl) ?>"><?= htmlspecialchars($nomeMoto) ?></a>

            <div class="mcard-price <?= $aCombinar ? 'is-consulta' : '' ?>">
              <?php if ($aCombinar): ?>
                <span class="now">A combinar</span>
              <?php else: ?>
                <?php if ($temDesconto): ?><span class="old">R$ <?= $fipeFmt ?></span><?php endif; ?>
                <span class="now">R$ <?= $precoFmt ?></span>
              <?php endif; ?>
            </div>

            <div class="mcard-specs2">
              <div class="spec"><b>Ano</b><span><?= htmlspecialchars($m['ano_modelo']) ?></span></div>
              <div class="spec"><b>Km</b><span><?= $km ?></span></div>
              <div class="spec"><b>Cor</b><span><?= htmlspecialchars($m['cor']) ?></span></div>
              <div class="spec"><b>Marca</b><span><?= htmlspecialchars($m['modelo']) ?></span></div>
            </div>

            <div class="mcard-actions">
              <a class="mcard-zap" data-wa="1" data-moto-id="<?= $mid ?>" target="_blank" rel="noopener" href="<?= htmlspecialchars($wa_link) ?>">
                <svg fill="currentColor" viewBox="0 0 24 24" width="18" height="18"><path d="M17.5 14.4c-.3-.1-1.6-.8-1.9-.9-.3-.1-.5-.1-.7.1s-.8.9-1 1.1c-.2.2-.4.2-.7.1-1.6-.8-2.7-1.4-3.7-3.2-.3-.5.3-.5.8-1.5.1-.2 0-.3 0-.5s-.7-1.7-1-2.3c-.3-.6-.5-.5-.7-.5s-.4 0-.6 0c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.2 2.4 3.7 6 5 .8.4 1.5.6 2 .8.8.3 1.6.2 2.2.1.7-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.1-.3-.2-.5-.3z"/><path d="M21 11.6c0 5.3-4.3 9.6-9.6 9.6-1.7 0-3.3-.4-4.7-1.2L2 21l1.1-4.5c-.9-1.5-1.5-3.2-1.5-5 0-5.3 4.3-9.6 9.6-9.6S21 6.3 21 11.6zm-9.6-7.8c-4.3 0-7.8 3.5-7.8 7.8 0 1.6.5 3.1 1.4 4.4l-.7 2.7 2.7-.7c1.2.8 2.7 1.2 4.3 1.2 4.3 0 7.8-3.5 7.8-7.8s-3.4-7.6-7.7-7.6z"/></svg>
                Chamar no zap
              </a>
              <a class="mcard-more" href="<?= htmlspecialchars($motoUrl) ?>">Ver mais</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<script>
// Abre/fecha o painel de filtros
(function(){
  const btn = document.getElementById('fbToggle');
  const panel = document.getElementById('fbPanel');
  if (!btn || !panel) return;
  btn.addEventListener('click', () => {
    const open = panel.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();

// Favoritar (coração) — salva no navegador
(function(){
  const favs = new Set(JSON.parse(localStorage.getItem('moto_favs') || '[]'));
  document.querySelectorAll('.mcard-fav').forEach(btn => {
    const id = btn.dataset.favId;
    if (favs.has(id)) btn.classList.add('active');
    btn.addEventListener('click', e => {
      e.preventDefault(); e.stopPropagation();
      if (favs.has(id)) { favs.delete(id); btn.classList.remove('active'); }
      else { favs.add(id); btn.classList.add('active'); }
      localStorage.setItem('moto_favs', JSON.stringify([...favs]));
    });
  });
})();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
<script src="<?= base_url('assets/track.js?v=2') ?>"></script>
