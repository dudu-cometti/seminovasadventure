<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/auth.php';

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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: ' . base_url('index.php'));
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM motos WHERE id = ?");
$stmt->execute([$id]);
$moto = $stmt->fetch();

if (!$moto || !in_array($moto['status'], ['disponivel','reservada'])) {
  http_response_code(404);
  $page_title = 'Moto não encontrada';
  include __DIR__ . '/inc/header.php';
  echo '<main class="container page"><div class="card card-pad text-center" style="max-width:520px;margin:60px auto;">';
  echo '<h2>Moto não encontrada</h2>';
  echo '<p class="text-muted mt-2">Esta moto pode ter sido vendida ou removida.</p>';
  echo '<a class="btn btn-primary mt-4" href="' . base_url('index.php') . '">Ver outras motos</a>';
  echo '</div></main>';
  include __DIR__ . '/inc/footer.php';
  exit;
}

$stmtF = $pdo->prepare("SELECT caminho FROM moto_fotos WHERE moto_id = ? ORDER BY is_cover DESC, id ASC");
$stmtF->execute([$id]);
$fotosRows = $stmtF->fetchAll();
$fotos = [];
foreach ($fotosRows as $f) $fotos[] = base_url('uploads/' . $f['caminho']);
if (!$fotos) $fotos[] = "https://placehold.co/1200x800?text=Moto+Seminova";

$whatsapp = setting_get_any($pdo, ['whatsapp_number','whatsapp','numero_whatsapp','telefone_whatsapp'], '5527999215754');
$nomeLoja = setting_get_any($pdo, ['marketplace_nome','loja_nome','nome_loja','site_nome'], 'Adventure Motos');

$nomeMoto = trim(($moto['titulo'] ?: $moto['modelo']) . '');
$km = number_format((int)$moto['quilometragem'], 0, ',', '.');
$valor = number_format((float)$moto['valor'], 2, ',', '.');

$msg = "Olá, tenho interesse na moto {$nomeMoto}. Ano/modelo {$moto['ano_modelo']}, {$km} km, cor {$moto['cor']}. Ainda está disponível?";
$wa_link = "https://wa.me/{$whatsapp}?text=" . urlencode($msg);

$page_title = $nomeMoto . ' — ' . $nomeLoja;

// Outras motos sugeridas (mesma marca)
$stmtSug = $pdo->prepare("
  SELECT m.*, (SELECT caminho FROM moto_fotos WHERE moto_id=m.id ORDER BY is_cover DESC, id ASC LIMIT 1) AS capa
  FROM motos m
  WHERE m.modelo = ? AND m.id != ? AND m.status IN ('disponivel','reservada')
  ORDER BY m.created_at DESC LIMIT 4
");
$stmtSug->execute([$moto['modelo'], $id]);
$sugestoes = $stmtSug->fetchAll();
?>
<?php include __DIR__ . '/inc/header.php'; ?>

<style>
  .moto-detail{
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    gap: var(--space-6);
    margin: var(--space-6) 0;
  }
  @media (max-width: 920px){
    .moto-detail{ grid-template-columns: 1fr; }
  }
  .gallery-main{
    aspect-ratio: 4 / 3;
    border-radius: var(--r-xl);
    overflow: hidden;
    background: var(--gray-900);
    position: relative;
    box-shadow: var(--shadow-md);
  }
  .gallery-main img{
    width: 100%; height: 100%;
    object-fit: cover;
    cursor: zoom-in;
  }
  .gallery-nav{
    position: absolute; top: 50%;
    transform: translateY(-50%);
    width: 44px; height: 44px;
    border-radius: 999px;
    background: rgba(0,0,0,.5);
    color: #fff;
    font-size: 22px;
    display: grid; place-items: center;
    transition: background var(--t-fast);
  }
  .gallery-nav:hover{ background: rgba(0,0,0,.75); }
  .gallery-prev{ left: 16px; }
  .gallery-next{ right: 16px; }
  .gallery-counter{
    position: absolute; top: 16px; right: 16px;
    background: rgba(0,0,0,.55);
    color: #fff;
    padding: 6px 12px;
    border-radius: var(--r-full);
    font-size: 12px; font-weight: 700;
  }
  .gallery-ribbon{
    position: absolute; top: 16px; left: 16px;
    background: var(--orange-500);
    color: #fff;
    padding: 6px 14px;
    border-radius: var(--r-full);
    font-size: 12px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .05em;
    box-shadow: var(--shadow-sm);
  }
  .gallery-thumbs{
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 8px;
    margin-top: 10px;
  }
  .gallery-thumb{
    aspect-ratio: 1;
    border-radius: var(--r-sm);
    overflow: hidden;
    cursor: pointer;
    border: 2px solid transparent;
    transition: border-color var(--t-fast);
    background: var(--gray-200);
  }
  .gallery-thumb img{ width: 100%; height: 100%; object-fit: cover; }
  .gallery-thumb.active{ border-color: var(--brand-600); }

  .detail-aside{
    display: flex; flex-direction: column;
    gap: var(--space-4);
  }
  .price-card{
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
    position: sticky; top: 90px;
  }
  .price-card h1{
    font-size: 22px;
    line-height: 1.25;
    letter-spacing: -.02em;
  }
  .price-main{
    margin: var(--space-4) 0;
    padding: var(--space-3) 0;
    border-top: 1px solid var(--border-soft);
    border-bottom: 1px solid var(--border-soft);
  }
  .price-main-currency{ font-size: 14px; color: var(--text-muted); font-weight: 700; }
  .price-main-value{
    font-size: 34px;
    font-weight: 900;
    letter-spacing: -.025em;
    margin-left: 4px;
  }
  .spec-list{
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin: var(--space-4) 0;
  }
  .spec-list .moto-spec{
    background: var(--surface-2);
    border-radius: var(--r-md);
    padding: 10px 12px;
  }
  .share-bar{
    display: flex; gap: 8px; margin-top: var(--space-3);
  }
  .share-bar .btn{ flex: 1; }

  .desc-card{
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    padding: var(--space-5);
  }
  .desc-card h2{ font-size: 18px; margin-bottom: 10px; }
  .desc-card p{ color: var(--text-soft); line-height: 1.7; white-space: pre-line; }

  .breadcrumb{
    display: flex; gap: 8px; align-items: center;
    font-size: 13px;
    color: var(--text-muted);
    margin: var(--space-4) 0;
  }
  .breadcrumb a{ color: var(--text-soft); }
  .breadcrumb a:hover{ color: var(--brand-600); }
</style>

<main class="container">
  <nav class="breadcrumb">
    <a href="<?= base_url('index.php') ?>">Marketplace</a>
    <span>›</span>
    <span><?= htmlspecialchars($nomeMoto) ?></span>
  </nav>

  <section class="moto-detail">
    <div>
      <div class="gallery-main" id="galleryMain">
        <?php if ($moto['status'] === 'reservada'): ?>
          <div class="gallery-ribbon">Reservada</div>
        <?php endif; ?>
        <div class="gallery-counter" id="galleryCounter">1 / <?= count($fotos) ?></div>
        <img id="galleryImg" src="<?= htmlspecialchars($fotos[0]) ?>" alt="<?= htmlspecialchars($nomeMoto) ?>">
        <?php if (count($fotos) > 1): ?>
          <button class="gallery-nav gallery-prev" type="button" aria-label="Anterior">‹</button>
          <button class="gallery-nav gallery-next" type="button" aria-label="Próxima">›</button>
        <?php endif; ?>
      </div>

      <?php if (count($fotos) > 1): ?>
        <div class="gallery-thumbs" id="galleryThumbs">
          <?php foreach ($fotos as $i => $url): ?>
            <div class="gallery-thumb <?= $i===0?'active':'' ?>" data-idx="<?= $i ?>">
              <img src="<?= htmlspecialchars($url) ?>" alt="Foto <?= $i+1 ?>" loading="lazy">
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($moto['descricao'])): ?>
        <div class="desc-card mt-4">
          <h2>Descrição</h2>
          <p><?= htmlspecialchars($moto['descricao']) ?></p>
        </div>
      <?php endif; ?>

      <?php
        // ===== Monta a ficha (procedência, ficha técnica e comercial) =====
        $consMap  = ['impecavel'=>'Impecável','excelente'=>'Excelente','muito_boa'=>'Muito boa','boa'=>'Boa'];
        $relMap   = ['nova'=>'Nova','boa'=>'Boa','regular'=>'Regular'];
        $freioMap = ['novos'=>'Novos','bons'=>'Bons','regular'=>'Regular'];

        // Itens positivos quando = "sim"
        $procPos = [
          'unico_dono'          => 'Único dono',
          'tem_manual'          => 'Com manual do proprietário',
          'revisada_autorizada' => 'Revisada em concessionária autorizada',
          'garantia_fabrica'    => 'Em garantia de fábrica',
          'chave_reserva'       => 'Com chave reserva',
          'revisoes_regulares'  => 'Revisões feitas regularmente',
          'laudo_cautelar'      => 'Laudo cautelar aprovado',
        ];
        $checks = [];
        foreach ($procPos as $col => $txt) {
          if (($moto[$col] ?? '') === 'sim') $checks[] = $txt;
        }
        if (($moto['historico_negativo'] ?? '') === 'nao') {
          $checks[] = 'Sem histórico de leilão, sinistro ou recuperação';
        }

        // Condições comerciais (só as marcadas "sim")
        $comPos = [
          'aceita_troca'  => 'Aceita troca',
          'aceita_carta'  => 'Aceita carta de crédito',
          'financiamento' => 'Financiamento disponível',
          'garantia_loja' => 'Com garantia',
        ];
        $comercial = [];
        foreach ($comPos as $col => $txt) {
          if (($moto[$col] ?? '') === 'sim') $comercial[] = $txt;
        }

        // Ficha técnica
        $condMap = ['nova'=>'Nova (0 km)','seminova'=>'Seminova'];
        $ficha = [];
        if (!empty($moto['condicao']) && isset($condMap[$moto['condicao']])) $ficha['Condição'] = $condMap[$moto['condicao']];
        if (!empty($moto['conservacao']) && isset($consMap[$moto['conservacao']])) $ficha['Conservação'] = $consMap[$moto['conservacao']];
        if (($moto['pneu_dianteiro'] ?? '') !== '' && $moto['pneu_dianteiro'] !== null) $ficha['Pneu dianteiro'] = (int)$moto['pneu_dianteiro'] . '%';
        if (($moto['pneu_traseiro'] ?? '') !== '' && $moto['pneu_traseiro'] !== null)   $ficha['Pneu traseiro']  = (int)$moto['pneu_traseiro'] . '%';
        if (!empty($moto['relacao']) && isset($relMap[$moto['relacao']]))   $ficha['Relação'] = $relMap[$moto['relacao']];
        if (!empty($moto['freios']) && isset($freioMap[$moto['freios']]))   $ficha['Freios']  = $freioMap[$moto['freios']];
        if (!empty($moto['detalhe_estetico'])) $ficha['Detalhe estético'] = $moto['detalhe_estetico'];

        $temHistoricoRuim = (($moto['historico_negativo'] ?? '') === 'sim');
        $temFichaAlgo = $checks || $comercial || $ficha || !empty($moto['diferencial']) || $temHistoricoRuim;
      ?>
      <?php if ($temFichaAlgo): ?>
        <div class="desc-card mt-4">
          <h2>Ficha &amp; procedência</h2>

          <?php
            $difItens = array_filter(array_map('trim', explode(',', (string)($moto['diferencial'] ?? ''))), function ($v) { return $v !== ''; });
          ?>
          <?php if ($difItens): ?>
            <div style="background:#fff5f5;border:1px solid #ffe0e0;border-radius:var(--r-md);padding:12px 14px;margin:6px 0 16px;">
              <div style="font-weight:800;font-size:12px;color:#b00;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">⭐ Diferenciais</div>
              <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($difItens as $d): ?>
                  <span style="display:inline-flex;align-items:center;gap:5px;background:#fff;border:1px solid #ffd0d0;color:#b00;border-radius:999px;padding:5px 12px;font-size:13px;font-weight:700;">⭐ <?= htmlspecialchars($d) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($checks): ?>
            <ul style="list-style:none;padding:0;margin:0 0 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;">
              <?php foreach ($checks as $c): ?>
                <li style="display:flex;gap:8px;align-items:flex-start;font-size:14px;">
                  <span style="color:#16a34a;font-weight:900;flex:none;">✓</span>
                  <span><?= htmlspecialchars($c) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($temHistoricoRuim): ?>
            <div style="display:flex;gap:8px;align-items:center;font-size:14px;color:#b45309;margin-bottom:16px;">
              <span>⚠️</span><span>Possui histórico de leilão, sinistro ou recuperação.</span>
            </div>
          <?php endif; ?>

          <?php if ($ficha): ?>
            <div class="spec-list" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin:0;">
              <?php foreach ($ficha as $k => $v): ?>
                <div class="moto-spec"><b><?= htmlspecialchars($k) ?></b><?= htmlspecialchars($v) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($comercial): ?>
            <div style="margin-top:16px;">
              <div style="font-weight:700;font-size:13px;color:var(--text-muted);margin-bottom:8px;">Condições comerciais</div>
              <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($comercial as $c): ?>
                  <span class="badge badge-success"><?= htmlspecialchars($c) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <aside class="detail-aside">
      <div class="price-card">
        <span class="badge <?= $moto['status'] === 'reservada' ? 'badge-warning' : 'badge-success' ?> mb-3"><?= $moto['status'] === 'reservada' ? 'Reservada' : 'Disponível' ?></span>
        <?php if (!empty($moto['condicao'])): ?>
          <span class="badge badge-info mb-3"><?= $moto['condicao'] === 'nova' ? '0 km · Nova' : 'Seminova' ?></span>
        <?php endif; ?>

        <h1><?= htmlspecialchars($nomeMoto) ?></h1>

        <div class="price-main">
          <span class="price-main-currency">R$</span>
          <span class="price-main-value"><?= $valor ?></span>
        </div>
        <?php if ((float)($moto['valor_fipe'] ?? 0) > 0): ?>
          <div style="font-size:13px;color:var(--text-muted);margin:-6px 0 6px;">
            Tabela FIPE: R$ <?= number_format((float)$moto['valor_fipe'], 2, ',', '.') ?>
            <?php
              $vf = (float)$moto['valor_fipe']; $vv = (float)$moto['valor'];
              if ($vf > 0 && $vv > 0 && $vv < $vf):
                $economia = round((1 - $vv / $vf) * 100);
                if ($economia >= 1):
            ?>
              · <span style="color:#16a34a;font-weight:700;"><?= $economia ?>% abaixo da FIPE</span>
            <?php endif; endif; ?>
          </div>
        <?php endif; ?>

        <div class="spec-list">
          <div class="moto-spec"><b>Marca</b><?= htmlspecialchars($moto['modelo']) ?></div>
          <div class="moto-spec"><b>Ano/Modelo</b><?= htmlspecialchars($moto['ano_modelo']) ?></div>
          <div class="moto-spec"><b>Km</b><?= $km ?></div>
          <div class="moto-spec"><b>Cor</b><?= htmlspecialchars($moto['cor']) ?></div>
        </div>

        <a class="btn btn-whatsapp btn-block btn-lg" data-wa="1" data-moto-id="<?= (int)$moto['id'] ?>" target="_blank" rel="noopener" href="<?= htmlspecialchars($wa_link) ?>">
          <svg fill="currentColor" viewBox="0 0 24 24" width="20" height="20"><path d="M17.5 14.4c-.3-.1-1.6-.8-1.9-.9-.3-.1-.5-.1-.7.1s-.8.9-1 1.1c-.2.2-.4.2-.7.1-1.6-.8-2.7-1.4-3.7-3.2-.3-.5.3-.5.8-1.5.1-.2 0-.3 0-.5s-.7-1.7-1-2.3c-.3-.6-.5-.5-.7-.5s-.4 0-.6 0c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.2 2.4 3.7 6 5 .8.4 1.5.6 2 .8.8.3 1.6.2 2.2.1.7-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.1-.3-.2-.5-.3z"/><path d="M21 11.6c0 5.3-4.3 9.6-9.6 9.6-1.7 0-3.3-.4-4.7-1.2L2 21l1.1-4.5c-.9-1.5-1.5-3.2-1.5-5 0-5.3 4.3-9.6 9.6-9.6S21 6.3 21 11.6zm-9.6-7.8c-4.3 0-7.8 3.5-7.8 7.8 0 1.6.5 3.1 1.4 4.4l-.7 2.7 2.7-.7c1.2.8 2.7 1.2 4.3 1.2 4.3 0 7.8-3.5 7.8-7.8s-3.4-7.6-7.7-7.6z"/></svg>
          Chamar no WhatsApp
        </a>

        <div class="share-bar">
          <button type="button" class="btn btn-secondary btn-sm" id="btnShare">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="16" height="16">
              <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
              <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
            </svg>
            Compartilhar
          </button>
          <button type="button" class="btn btn-secondary btn-sm" id="btnCopy">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="16" height="16">
              <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
            </svg>
            Copiar link
          </button>
        </div>

        <p class="text-xs text-muted mt-3 text-center">
          Vendedor responde em minutos via WhatsApp.
        </p>
      </div>
    </aside>
  </section>

  <?php if ($sugestoes): ?>
    <section style="margin-top: var(--space-10);">
      <h2 style="margin-bottom: var(--space-4);">Mais motos <?= htmlspecialchars($moto['modelo']) ?></h2>
      <div class="mkt-grid">
        <?php foreach ($sugestoes as $s): ?>
          <?php
            $smid = (int)$s['id'];
            $sFoto = $s['capa'] ? base_url('uploads/' . $s['capa']) : "https://placehold.co/600x400?text=Moto";
            $sNome = trim($s['titulo'] ?: $s['modelo']);
            $sKm = number_format((int)$s['quilometragem'], 0, ',', '.');
          ?>
          <article class="moto-card">
            <a href="<?= base_url('moto.php?id=' . $smid) ?>" style="color:inherit;display:flex;flex-direction:column;height:100%;">
              <div class="moto-media">
                <img src="<?= htmlspecialchars($sFoto) ?>" alt="<?= htmlspecialchars($sNome) ?>" loading="lazy">
              </div>
              <div class="moto-body">
                <div class="moto-title"><?= htmlspecialchars($sNome) ?></div>
                <div class="text-sm text-muted"><?= htmlspecialchars($s['ano_modelo']) ?> · <?= $sKm ?> km</div>
                <div class="moto-price">
                  <span class="moto-price-currency">R$</span>
                  <span class="moto-price-value"><?= number_format((float)$s['valor'], 2, ',', '.') ?></span>
                </div>
              </div>
            </a>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>

<!-- Lightbox -->
<div id="lightbox" class="lightbox">
  <div class="lightbox-backdrop"></div>
  <div class="lightbox-content">
    <div class="lightbox-counter" id="lbCounter">1 / 1</div>
    <button type="button" class="lightbox-close" aria-label="Fechar">×</button>
    <button type="button" class="lightbox-prev" aria-label="Anterior">‹</button>
    <img id="lbImg" src="" alt="Foto">
    <button type="button" class="lightbox-next" aria-label="Próxima">›</button>
    <a id="lbDownload" class="lightbox-download" href="#" download>⬇ Baixar foto</a>
  </div>
</div>

<script>
(function(){
  const fotos = <?= json_encode($fotos) ?>;
  const galleryImg = document.getElementById('galleryImg');
  const counter = document.getElementById('galleryCounter');
  const thumbs = document.querySelectorAll('.gallery-thumb');
  let idx = 0;

  function show(i){
    idx = (i + fotos.length) % fotos.length;
    galleryImg.src = fotos[idx];
    counter.textContent = (idx + 1) + ' / ' + fotos.length;
    thumbs.forEach((t, ti) => t.classList.toggle('active', ti === idx));
  }
  document.querySelector('.gallery-prev')?.addEventListener('click', () => show(idx - 1));
  document.querySelector('.gallery-next')?.addEventListener('click', () => show(idx + 1));
  thumbs.forEach(t => t.addEventListener('click', () => show(parseInt(t.dataset.idx))));

  // Lightbox
  const lightbox = document.getElementById('lightbox');
  const lbImg = document.getElementById('lbImg');
  const lbCounter = document.getElementById('lbCounter');
  const lbDownload = document.getElementById('lbDownload');
  let lbIdx = 0;

  function lbShow(i){
    lbIdx = (i + fotos.length) % fotos.length;
    lbImg.src = fotos[lbIdx];
    lbDownload.href = fotos[lbIdx];
    lbCounter.textContent = (lbIdx + 1) + ' / ' + fotos.length;
  }
  function lbOpen(i){ lbShow(i); lightbox.classList.add('open'); document.body.style.overflow='hidden'; }
  function lbClose(){ lightbox.classList.remove('open'); document.body.style.overflow=''; }

  galleryImg.addEventListener('click', () => lbOpen(idx));
  lightbox.querySelector('.lightbox-close').addEventListener('click', lbClose);
  lightbox.querySelector('.lightbox-backdrop').addEventListener('click', lbClose);
  lightbox.querySelector('.lightbox-prev').addEventListener('click', e => { e.stopPropagation(); lbShow(lbIdx - 1); });
  lightbox.querySelector('.lightbox-next').addEventListener('click', e => { e.stopPropagation(); lbShow(lbIdx + 1); });

  document.addEventListener('keydown', e => {
    if (lightbox.classList.contains('open')) {
      if (e.key === 'Escape') lbClose();
      if (e.key === 'ArrowLeft') lbShow(lbIdx - 1);
      if (e.key === 'ArrowRight') lbShow(lbIdx + 1);
    } else {
      if (e.key === 'ArrowLeft') show(idx - 1);
      if (e.key === 'ArrowRight') show(idx + 1);
    }
  });

  // Share
  const titulo = <?= json_encode($nomeMoto) ?>;
  const url = window.location.href;

  document.getElementById('btnShare')?.addEventListener('click', async () => {
    if (navigator.share) {
      try { await navigator.share({ title: titulo, url }); } catch(e){}
    } else {
      navigator.clipboard.writeText(url);
      alert('Link copiado!');
    }
  });
  document.getElementById('btnCopy')?.addEventListener('click', () => {
    navigator.clipboard.writeText(url).then(() => {
      const btn = document.getElementById('btnCopy');
      const orig = btn.innerHTML;
      btn.innerHTML = '✓ Copiado';
      setTimeout(() => btn.innerHTML = orig, 1500);
    });
  });

  // Track view
  if (window.trackMotoView) window.trackMotoView(<?= (int)$moto['id'] ?>);
})();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
<script src="<?= base_url('assets/track.js?v=2') ?>"></script>
