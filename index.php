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

ensure_settings_table($pdo);

$whatsapp = setting_get_any($pdo, ['whatsapp_number','whatsapp','numero_whatsapp','telefone_whatsapp'], '5527999215754');
$nomeLoja = setting_get_any($pdo, ['marketplace_nome','loja_nome','nome_loja','site_nome'], 'Adventure Motos');
$cidade   = setting_get_any($pdo, ['marketplace_cidade','loja_cidade','cidade_loja'], 'São Silvano - ES');

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
    ORDER BY moto_id ASC, is_cover DESC, id ASC
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

<main class="container">
  <section class="mkt-hero">
    <h1>Sua próxima moto está aqui</h1>
    <p>Motos seminovas selecionadas em <?= htmlspecialchars($cidade) ?>. Chame no WhatsApp e negocie direto.</p>
  </section>

  <section class="mkt-toolbar">
    <form method="get" class="mkt-toolbar-inner" id="filterForm">
      <div class="search-box">
        <input type="search" name="q" placeholder="Buscar por modelo, cor, descrição..." value="<?= htmlspecialchars($q) ?>" autocomplete="off">
      </div>

      <select name="marca" class="field-select" onchange="this.form.submit()" style="padding:11px 14px;border:1px solid var(--border);border-radius:var(--r-full);background:#fff;font-size:14px;font-weight:600;cursor:pointer;">
        <option value="">Todas as marcas</option>
        <?php foreach ($marcasDisponiveis as $m): ?>
          <option value="<?= htmlspecialchars($m) ?>" <?= $fmarca===$m?'selected':'' ?>><?= htmlspecialchars($m) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="ano" placeholder="Ano" value="<?= htmlspecialchars($fano) ?>" style="padding:11px 14px;border:1px solid var(--border);border-radius:var(--r-full);background:#fff;font-size:14px;font-weight:600;width:90px;">

      <select name="ordem" onchange="this.form.submit()" style="padding:11px 14px;border:1px solid var(--border);border-radius:var(--r-full);background:#fff;font-size:14px;font-weight:600;cursor:pointer;">
        <option value="recentes"   <?= $ordem==='recentes'?'selected':'' ?>>Mais recentes</option>
        <option value="preco_asc"  <?= $ordem==='preco_asc'?'selected':'' ?>>Menor preço</option>
        <option value="preco_desc" <?= $ordem==='preco_desc'?'selected':'' ?>>Maior preço</option>
        <option value="km_asc"     <?= $ordem==='km_asc'?'selected':'' ?>>Menor km</option>
      </select>

      <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
      <?php if ($q || $fmarca || $fano || $ordem !== 'recentes'): ?>
        <a href="<?= base_url('index.php') ?>" class="btn btn-ghost btn-sm">Limpar</a>
      <?php endif; ?>
    </form>
  </section>

  <?php if (!$motos): ?>
    <div class="card card-pad text-center" style="max-width:520px;margin:40px auto;">
      <div style="font-size:48px;margin-bottom:8px;">🏍️</div>
      <h2 style="font-size:18px;">Nenhuma moto encontrada</h2>
      <p class="text-muted text-sm mt-2">Tente ajustar os filtros ou volte mais tarde.</p>
    </div>
  <?php else: ?>
    <div class="text-muted text-sm mb-3">
      <?= count($motos) ?> moto<?= count($motos) === 1 ? '' : 's' ?> encontrada<?= count($motos) === 1 ? '' : 's' ?>
    </div>

    <div class="mkt-grid">
      <?php foreach ($motos as $m): ?>
        <?php
          $mid = (int)$m['id'];
          $fotos = $galerias[$mid] ?? [];
          $urls = [];
          foreach ($fotos as $c) $urls[] = base_url('uploads/' . $c);
          $foto0 = $urls[0] ?? "https://placehold.co/900x600?text=Moto+Seminova";
          $dataGaleria = htmlspecialchars(json_encode($urls), ENT_QUOTES, 'UTF-8');

          $nomeMoto = trim(($m['titulo'] ?: $m['modelo']) . '');
          $km = number_format((int)$m['quilometragem'], 0, ',', '.');

          $msg = "Olá, tenho interesse na moto {$nomeMoto}. Ano/modelo {$m['ano_modelo']}, {$km} km, cor {$m['cor']}. Ainda está disponível?";
          $wa_link = "https://wa.me/{$whatsapp}?text=" . urlencode($msg);

          $motoUrl = base_url('moto.php?id=' . $mid);
        ?>

        <article class="moto-card">
          <div class="moto-media" data-galeria="<?= $dataGaleria ?>" data-moto-id="<?= $mid ?>" style="--photo:url('<?= htmlspecialchars($foto0) ?>')">
            <?php if ($m['status'] === 'reservada'): ?>
              <div class="moto-ribbon">Reservada</div>
            <?php endif; ?>

            <button class="moto-fav" type="button" data-fav-id="<?= $mid ?>" aria-label="Favoritar">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
              </svg>
            </button>

            <a href="<?= htmlspecialchars($motoUrl) ?>" style="display:block;width:100%;height:100%;">
              <img src="<?= htmlspecialchars($foto0) ?>" alt="<?= htmlspecialchars($nomeMoto) ?>" loading="lazy">
            </a>

            <?php if (count($urls) > 1): ?>
              <button class="moto-gallery-prev" type="button" aria-label="Anterior">‹</button>
              <button class="moto-gallery-next" type="button" aria-label="Próxima">›</button>
              <div class="moto-dots">
                <?php for ($i = 0; $i < min(count($urls), 6); $i++): ?>
                  <span class="<?= $i === 0 ? 'active' : '' ?>"></span>
                <?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="moto-body">
            <?php if ($m['status'] === 'reservada'): ?>
              <span class="badge badge-warning">Reservada</span>
            <?php else: ?>
              <span class="badge badge-success">Disponível</span>
            <?php endif; ?>

            <a href="<?= htmlspecialchars($motoUrl) ?>" class="moto-title" style="color:inherit">
              <?= htmlspecialchars($nomeMoto) ?>
            </a>

            <div class="moto-specs">
              <div class="moto-spec"><b>Ano</b><?= htmlspecialchars($m['ano_modelo']) ?></div>
              <div class="moto-spec"><b>Km</b><?= $km ?></div>
              <div class="moto-spec"><b>Cor</b><?= htmlspecialchars($m['cor']) ?></div>
              <div class="moto-spec"><b>Marca</b><?= htmlspecialchars($m['modelo']) ?></div>
            </div>

            <div class="moto-price">
              <span class="moto-price-currency">R$</span>
              <span class="moto-price-value"><?= number_format((float)$m['valor'], 2, ',', '.') ?></span>
            </div>

            <div class="moto-actions">
              <a class="btn btn-whatsapp" data-wa="1" data-moto-id="<?= $mid ?>" target="_blank" rel="noopener" href="<?= htmlspecialchars($wa_link) ?>">
                <svg fill="currentColor" viewBox="0 0 24 24" width="18" height="18"><path d="M17.5 14.4c-.3-.1-1.6-.8-1.9-.9-.3-.1-.5-.1-.7.1s-.8.9-1 1.1c-.2.2-.4.2-.7.1-1.6-.8-2.7-1.4-3.7-3.2-.3-.5.3-.5.8-1.5.1-.2 0-.3 0-.5s-.7-1.7-1-2.3c-.3-.6-.5-.5-.7-.5s-.4 0-.6 0c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.2 2.4 3.7 6 5 .8.4 1.5.6 2 .8.8.3 1.6.2 2.2.1.7-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.1-.3-.2-.5-.3z"/><path d="M21 11.6c0 5.3-4.3 9.6-9.6 9.6-1.7 0-3.3-.4-4.7-1.2L2 21l1.1-4.5c-.9-1.5-1.5-3.2-1.5-5 0-5.3 4.3-9.6 9.6-9.6S21 6.3 21 11.6zm-9.6-7.8c-4.3 0-7.8 3.5-7.8 7.8 0 1.6.5 3.1 1.4 4.4l-.7 2.7 2.7-.7c1.2.8 2.7 1.2 4.3 1.2 4.3 0 7.8-3.5 7.8-7.8s-3.4-7.6-7.7-7.6z"/></svg>
                <span>WhatsApp</span>
              </a>
              <a href="<?= htmlspecialchars($motoUrl) ?>" class="btn btn-secondary" aria-label="Ver detalhes">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="18" height="18">
                  <path d="M5 12h14M13 5l7 7-7 7"/>
                </svg>
              </a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<!-- Lightbox compartilhado -->
<div id="lightbox" class="lightbox">
  <div class="lightbox-backdrop"></div>
  <div class="lightbox-content">
    <div class="lightbox-counter" id="lbCounter">1 / 1</div>
    <button type="button" class="lightbox-close" aria-label="Fechar">×</button>
    <button type="button" class="lightbox-prev" aria-label="Anterior">‹</button>
    <img id="lbImg" src="" alt="Foto da moto">
    <button type="button" class="lightbox-next" aria-label="Próxima">›</button>
  </div>
</div>

<script>
(function(){
  const wrappers = document.querySelectorAll('.moto-media[data-galeria]');
  const lightbox = document.getElementById('lightbox');
  const lbImg = document.getElementById('lbImg');
  const lbCounter = document.getElementById('lbCounter');

  let lbFotos = [];
  let lbIndex = 0;

  function abrirLightbox(fotos, idx){
    lbFotos = fotos || [];
    lbIndex = idx || 0;
    if (!lbFotos.length) return;
    render();
    lightbox.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function fechar(){
    lightbox.classList.remove('open');
    document.body.style.overflow = '';
    lbFotos = []; lbIndex = 0;
  }
  function render(){
    lbImg.src = lbFotos[lbIndex];
    lbCounter.textContent = (lbIndex + 1) + ' / ' + lbFotos.length;
  }
  function mover(delta){
    if (!lbFotos.length) return;
    lbIndex = (lbIndex + delta + lbFotos.length) % lbFotos.length;
    render();
  }

  function bindSwipe(el, onLeft, onRight){
    let startX = 0, startY = 0, distX = 0, distY = 0, tracking = false;
    el.addEventListener('touchstart', e => {
      if (!e.touches?.length) return;
      startX = e.touches[0].clientX; startY = e.touches[0].clientY;
      distX = 0; distY = 0; tracking = true;
    }, { passive: true });
    el.addEventListener('touchmove', e => {
      if (!tracking || !e.touches?.length) return;
      distX = e.touches[0].clientX - startX;
      distY = e.touches[0].clientY - startY;
      if (Math.abs(distX) > Math.abs(distY) && Math.abs(distX) > 8) e.preventDefault();
    }, { passive: false });
    el.addEventListener('touchend', () => {
      if (!tracking) return; tracking = false;
      if (Math.abs(distX) >= 35 && Math.abs(distY) <= 80) {
        if (distX < 0) onLeft && onLeft(); else onRight && onRight();
      }
    }, { passive: true });
  }

  lightbox.querySelector('.lightbox-close').addEventListener('click', fechar);
  lightbox.querySelector('.lightbox-backdrop').addEventListener('click', fechar);
  lightbox.querySelector('.lightbox-prev').addEventListener('click', e => { e.stopPropagation(); mover(-1); });
  lightbox.querySelector('.lightbox-next').addEventListener('click', e => { e.stopPropagation(); mover(1); });
  document.addEventListener('keydown', e => {
    if (!lightbox.classList.contains('open')) return;
    if (e.key === 'Escape') fechar();
    if (e.key === 'ArrowLeft') mover(-1);
    if (e.key === 'ArrowRight') mover(1);
  });
  bindSwipe(lightbox, () => mover(1), () => mover(-1));

  // Galeria nos cards
  wrappers.forEach(wrap => {
    let fotos = [];
    try { fotos = JSON.parse(wrap.getAttribute('data-galeria') || '[]'); } catch(e){}
    if (!Array.isArray(fotos) || !fotos.length) return;

    const img = wrap.querySelector('img');
    const btnPrev = wrap.querySelector('.moto-gallery-prev');
    const btnNext = wrap.querySelector('.moto-gallery-next');
    const dots = wrap.querySelectorAll('.moto-dots span');

    let idx = 0;
    function syncDots(){
      dots.forEach((d, i) => d.classList.toggle('active', i === idx % dots.length));
    }
    function setPhoto(){ wrap.style.setProperty('--photo', "url('" + fotos[idx] + "')"); }
    function next(){ idx = (idx+1) % fotos.length; img.src = fotos[idx]; setPhoto(); syncDots(); }
    function prev(){ idx = (idx-1+fotos.length) % fotos.length; img.src = fotos[idx]; setPhoto(); syncDots(); }

    btnNext?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); next(); });
    btnPrev?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); prev(); });
    bindSwipe(wrap, () => next(), () => prev());
  });

  // Favoritos (localStorage)
  const favs = new Set(JSON.parse(localStorage.getItem('moto_favs') || '[]'));
  document.querySelectorAll('.moto-fav').forEach(btn => {
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
