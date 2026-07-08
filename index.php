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

  <?php
    $countFiltros = ($fmarca !== '' ? 1 : 0) + ($fano !== '' ? 1 : 0);
    $temAlgo = ($q !== '' || $countFiltros > 0 || $ordem !== 'recentes');
    $ordemLabels = ['recentes'=>'Mais recentes','preco_asc'=>'Menor preço','preco_desc'=>'Maior preço','km_asc'=>'Menor km'];
  ?>
  <form method="get" class="filterbar" id="filterForm">
    <input type="hidden" name="ordem" id="ordemInput" value="<?= htmlspecialchars($ordem) ?>">

    <div class="fb-search">
      <input type="search" name="q" placeholder="Pesquisar..." value="<?= htmlspecialchars($q) ?>" autocomplete="off">
      <button type="submit" class="fb-lupa" aria-label="Pesquisar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      </button>
    </div>

    <div class="fb-controls">
      <div class="fb-left">
        <button type="button" class="fb-btn <?= $countFiltros ? 'has-active' : '' ?>" id="fbFilterBtn" aria-expanded="false">
          <?php if ($countFiltros): ?><i class="fb-badge"><?= $countFiltros ?></i><?php endif; ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v6l-4 2v-8z"/></svg>
          <span>Filtrar</span>
        </button>
        <?php if ($temAlgo): ?>
          <a class="fb-icon-btn" href="<?= base_url('index.php') ?>" title="Limpar filtros" aria-label="Limpar filtros">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-2.6-6.4M21 3v6h-6"/></svg>
          </a>
        <?php endif; ?>
      </div>
      <button type="button" class="fb-btn" id="fbSortBtn" aria-expanded="false">
        <span>Ordenar</span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h12M3 12h8M3 18h5M17 6v12M17 18l3-3M17 18l-3-3"/></svg>
      </button>
    </div>

    <div class="fb-panel" id="fbFilterPanel">
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
      <div class="fb-actions">
        <button type="submit" class="fb-apply">Aplicar filtros</button>
      </div>
    </div>

    <div class="fb-panel fb-sort" id="fbSortPanel">
      <?php foreach ($ordemLabels as $val => $lab): ?>
        <button type="button" class="fb-sort-opt <?= $ordem===$val ? 'active' : '' ?>" data-ordem="<?= $val ?>"><?= $lab ?></button>
      <?php endforeach; ?>
    </div>
  </form>

  <?php if (!$motos): ?>
    <div class="mkt-empty">
      <div style="font-size:44px;">🏍️</div>
      <h2>Nenhuma moto encontrada</h2>
      <p>Tente ajustar os filtros ou volte mais tarde.</p>
    </div>
  <?php else: ?>
    <div class="mkt-count"><?= count($motos) ?> moto<?= count($motos) === 1 ? '' : 's' ?> encontrada<?= count($motos) === 1 ? '' : 's' ?></div>

    <div class="grid-motos">
      <?php foreach ($motos as $m): ?>
        <?php
          $mid = (int)$m['id'];
          $fotos = $galerias[$mid] ?? [];
          $urls = [];
          foreach ($fotos as $c) $urls[] = base_url('uploads/' . $c);
          if (!$urls) $urls[] = "https://placehold.co/600x450?text=Moto";
          $foto0 = $urls[0];
          $dataGaleria = htmlspecialchars(json_encode($urls), ENT_QUOTES, 'UTF-8');

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
          <div class="mcard-photo" data-galeria="<?= $dataGaleria ?>" style="--photo:url('<?= htmlspecialchars($foto0) ?>')">
            <a class="mcard-photo-link" href="<?= htmlspecialchars($motoUrl) ?>">
              <img src="<?= htmlspecialchars($foto0) ?>" alt="<?= htmlspecialchars($nomeMoto) ?>" loading="lazy">
            </a>
            <span class="mcard-ribbon <?= $reservada ? 'is-resv' : 'is-ok' ?>"><?= $reservada ? 'Reservada' : 'Disponível' ?></span>
            <button class="mcard-fav" type="button" data-fav-id="<?= $mid ?>" aria-label="Favoritar">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            </button>
            <?php if (count($urls) > 1): ?>
              <button class="mcard-nav prev" type="button" aria-label="Foto anterior">‹</button>
              <button class="mcard-nav next" type="button" aria-label="Próxima foto">›</button>
              <div class="mcard-dots">
                <?php for ($i = 0; $i < min(count($urls), 6); $i++): ?><span class="<?= $i===0?'active':'' ?>"></span><?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>
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
// Painéis de Filtrar e Ordenar (um abre, o outro fecha)
(function(){
  const form   = document.getElementById('filterForm');
  const fBtn   = document.getElementById('fbFilterBtn');
  const sBtn   = document.getElementById('fbSortBtn');
  const fPanel = document.getElementById('fbFilterPanel');
  const sPanel = document.getElementById('fbSortPanel');
  const ordem  = document.getElementById('ordemInput');
  if (!form) return;

  function toggle(panel, btn, other, otherBtn){
    const open = panel.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    other.classList.remove('open');
    otherBtn.setAttribute('aria-expanded', 'false');
  }
  fBtn && fBtn.addEventListener('click', () => toggle(fPanel, fBtn, sPanel, sBtn));
  sBtn && sBtn.addEventListener('click', () => toggle(sPanel, sBtn, fPanel, fBtn));

  // Escolher ordenação aplica na hora
  document.querySelectorAll('.fb-sort-opt').forEach(opt => {
    opt.addEventListener('click', () => { ordem.value = opt.dataset.ordem; form.submit(); });
  });

  // Fecha ao clicar fora
  document.addEventListener('click', (e) => {
    if (!form.contains(e.target)) {
      fPanel.classList.remove('open'); sPanel.classList.remove('open');
      fBtn && fBtn.setAttribute('aria-expanded','false');
      sBtn && sBtn.setAttribute('aria-expanded','false');
    }
  });
})();

// Galeria nos cards: setas + arrastar o dedo (swipe)
(function(){
  document.querySelectorAll('.mcard-photo[data-galeria]').forEach(wrap => {
    let fotos = [];
    try { fotos = JSON.parse(wrap.getAttribute('data-galeria') || '[]'); } catch(e){}
    if (!Array.isArray(fotos) || fotos.length < 2) return;

    const img  = wrap.querySelector('img');
    const dots = wrap.querySelectorAll('.mcard-dots span');
    let idx = 0;

    function render(){
      img.src = fotos[idx];
      wrap.style.setProperty('--photo', "url('" + fotos[idx] + "')");
      dots.forEach((d, i) => d.classList.toggle('active', i === idx % dots.length));
    }
    function go(delta){ idx = (idx + delta + fotos.length) % fotos.length; render(); }

    wrap.querySelector('.mcard-nav.prev')?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); go(-1); });
    wrap.querySelector('.mcard-nav.next')?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); go(1); });

    // Swipe (arrastar o dedo)
    let x0 = 0, y0 = 0, dx = 0, dy = 0, tracking = false;
    wrap.addEventListener('touchstart', e => {
      if (!e.touches?.length) return;
      x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; dx = 0; dy = 0; tracking = true;
    }, { passive: true });
    wrap.addEventListener('touchmove', e => {
      if (!tracking || !e.touches?.length) return;
      dx = e.touches[0].clientX - x0; dy = e.touches[0].clientY - y0;
      if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) e.preventDefault();
    }, { passive: false });
    wrap.addEventListener('touchend', () => {
      if (!tracking) return; tracking = false;
      if (Math.abs(dx) >= 35 && Math.abs(dx) > Math.abs(dy)) go(dx < 0 ? 1 : -1);
    }, { passive: true });
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
