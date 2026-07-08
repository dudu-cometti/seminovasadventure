<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$user = current_user();
$page_title = 'Analytics — acessos';

$marketFilterSessions = " (page IS NULL OR page NOT LIKE '/painel/%') ";
$marketFilterEvents   = " (page IS NULL OR page NOT LIKE '/painel/%') ";

$online = (int)$pdo->query("
  SELECT COUNT(*) FROM active_sessions
  WHERE last_seen >= (NOW() - INTERVAL 3 MINUTE) AND $marketFilterSessions
")->fetchColumn();

$visitasHoje = (int)$pdo->query("
  SELECT COUNT(*) FROM page_events
  WHERE event_type='page_view' AND DATE(created_at)=CURDATE() AND $marketFilterEvents
")->fetchColumn();

$whatsHoje = (int)$pdo->query("
  SELECT COUNT(*) FROM page_events
  WHERE event_type='click_whatsapp' AND DATE(created_at)=CURDATE() AND $marketFilterEvents
")->fetchColumn();

$visitas7 = $pdo->query("
  SELECT DATE(created_at) dia, COUNT(*) total
  FROM page_events
  WHERE event_type='page_view'
    AND created_at >= (CURDATE() - INTERVAL 6 DAY) AND $marketFilterEvents
  GROUP BY DATE(created_at)
  ORDER BY dia ASC
")->fetchAll();

// Preenche dias sem dados
$serie7 = [];
for ($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-$i days"));
  $serie7[$d] = ['dia' => $d, 'total' => 0];
}
foreach ($visitas7 as $v) $serie7[$v['dia']]['total'] = (int)$v['total'];
$serie7 = array_values($serie7);

$topVistas = $pdo->query("
  SELECT m.id, COALESCE(NULLIF(m.titulo,''), m.modelo) as nome, COUNT(*) total
  FROM page_events e
  JOIN motos m ON m.id = e.moto_id
  WHERE e.event_type='view_moto' AND e.moto_id IS NOT NULL
    AND e.created_at >= (NOW() - INTERVAL 30 DAY)
    AND (e.page IS NULL OR e.page NOT LIKE '/painel/%')
  GROUP BY e.moto_id ORDER BY total DESC LIMIT 10
")->fetchAll();

$topLeads = $pdo->query("
  SELECT m.id, COALESCE(NULLIF(m.titulo,''), m.modelo) as nome, COUNT(*) total
  FROM page_events e
  JOIN motos m ON m.id = e.moto_id
  WHERE e.event_type='click_whatsapp' AND e.moto_id IS NOT NULL
    AND e.created_at >= (NOW() - INTERVAL 30 DAY)
    AND (e.page IS NULL OR e.page NOT LIKE '/painel/%')
  GROUP BY e.moto_id ORDER BY total DESC LIMIT 10
")->fetchAll();

include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">

    <div class="page-header">
      <div>
        <h1 class="page-title">Analytics</h1>
        <p class="page-subtitle">Acessos do marketplace público — atualizado em tempo real.</p>
      </div>
    </div>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon stat-icon-green" style="position:relative;">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span style="position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:999px;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.25);animation:pulse 1.6s infinite;"></span>
        </div>
        <div class="stat-label">Online agora</div>
        <div class="stat-value"><?= $online ?></div>
        <div class="stat-sub">últimos 3 minutos</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-blue">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </div>
        <div class="stat-label">Visitas hoje</div>
        <div class="stat-value"><?= $visitasHoje ?></div>
        <div class="stat-sub">page views</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:#dcfce7;color:#16a34a;">
          <svg fill="currentColor" viewBox="0 0 24 24"><path d="M17.5 14.4c-.3-.1-1.6-.8-1.9-.9-.3-.1-.5-.1-.7.1s-.8.9-1 1.1c-.2.2-.4.2-.7.1-1.6-.8-2.7-1.4-3.7-3.2-.3-.5.3-.5.8-1.5.1-.2 0-.3 0-.5s-.7-1.7-1-2.3c-.3-.6-.5-.5-.7-.5s-.4 0-.6 0c-.2 0-.6.1-.9.4-.3.3-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.2 2.4 3.7 6 5 .8.4 1.5.6 2 .8.8.3 1.6.2 2.2.1.7-.1 2-.8 2.3-1.6.3-.8.3-1.5.2-1.6-.1-.1-.3-.2-.5-.3z"/></svg>
        </div>
        <div class="stat-label">Cliques no WhatsApp hoje</div>
        <div class="stat-value"><?= $whatsHoje ?></div>
        <div class="stat-sub">leads gerados</div>
      </div>
    </div>

    <div class="card card-pad mb-6">
      <h2 style="font-size:16px;margin-bottom:var(--space-3);">Visitas — últimos 7 dias</h2>
      <div style="position:relative;height:260px;width:100%;">
        <canvas id="chartVisitas"></canvas>
      </div>
    </div>

    <div class="form-grid form-grid-2">
      <div class="card">
        <div class="card-pad" style="border-bottom:1px solid var(--border-soft);">
          <h2 style="font-size:16px;">🔥 Top motos mais vistas</h2>
          <p class="text-sm text-muted mt-1">últimos 30 dias</p>
        </div>
        <div class="table-wrap" style="border:none;box-shadow:none;border-radius:0;">
          <table class="table">
            <thead><tr><th>Moto</th><th style="text-align:right;">Views</th></tr></thead>
            <tbody>
              <?php if (!$topVistas): ?>
                <tr><td colspan="2" class="empty">Sem dados ainda.</td></tr>
              <?php else: foreach ($topVistas as $r): ?>
                <tr>
                  <td>
                    <a href="<?= base_url('moto.php?id=' . (int)$r['id']) ?>" target="_blank" style="font-weight:700;">
                      <?= htmlspecialchars($r['nome']) ?>
                    </a>
                    <small class="text-muted">#<?= (int)$r['id'] ?></small>
                  </td>
                  <td style="text-align:right;font-weight:800;"><?= (int)$r['total'] ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-pad" style="border-bottom:1px solid var(--border-soft);">
          <h2 style="font-size:16px;">💬 Top motos com mais leads</h2>
          <p class="text-sm text-muted mt-1">cliques no WhatsApp · 30 dias</p>
        </div>
        <div class="table-wrap" style="border:none;box-shadow:none;border-radius:0;">
          <table class="table">
            <thead><tr><th>Moto</th><th style="text-align:right;">Cliques</th></tr></thead>
            <tbody>
              <?php if (!$topLeads): ?>
                <tr><td colspan="2" class="empty">Sem dados ainda.</td></tr>
              <?php else: foreach ($topLeads as $r): ?>
                <tr>
                  <td>
                    <a href="<?= base_url('moto.php?id=' . (int)$r['id']) ?>" target="_blank" style="font-weight:700;">
                      <?= htmlspecialchars($r['nome']) ?>
                    </a>
                    <small class="text-muted">#<?= (int)$r['id'] ?></small>
                  </td>
                  <td style="text-align:right;font-weight:800;color:var(--green-600);"><?= (int)$r['total'] ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<style>
  @keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(34,197,94,.25); }
    50% { box-shadow: 0 0 0 6px rgba(34,197,94,.0); }
  }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const serie7 = <?= json_encode($serie7) ?>;
const labels = serie7.map(s => {
  const [y,m,d] = s.dia.split('-');
  return d + '/' + m;
});
const data = serie7.map(s => parseInt(s.total));

const ctx = document.getElementById('chartVisitas');
if (ctx && window.Chart) {
  const grad = ctx.getContext('2d').createLinearGradient(0, 0, 0, 280);
  grad.addColorStop(0, 'rgba(59, 130, 246, .35)');
  grad.addColorStop(1, 'rgba(59, 130, 246, 0)');
  new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets: [{
      data, backgroundColor: '#3b82f6', borderRadius: 8, maxBarThickness: 36
    }]},
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { beginAtZero: true, ticks: { font: { size: 11 }, stepSize: 1 }, grid: { color: 'rgba(0,0,0,.05)' } }
      }
    }
  });
}
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
