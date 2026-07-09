<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';
require_login();

$user = current_user();
$page_title = 'Dashboard — Adventure Motos';

function money($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

// ====== PERÍODO ======
$quick = trim($_GET['quick'] ?? 'mes');
$de    = trim($_GET['de'] ?? '');
$ate   = trim($_GET['ate'] ?? '');

$today = date('Y-m-d');
$firstDayMonth = date('Y-m-01');
$lastDayMonth  = date('Y-m-t');

if ($quick === 'hoje')       { $de = $today; $ate = $today; }
elseif ($quick === '7d')     { $de = date('Y-m-d', strtotime('-6 days')); $ate = $today; }
elseif ($quick === '30d')    { $de = date('Y-m-d', strtotime('-29 days')); $ate = $today; }
elseif ($quick === 'custom') {
  if ($de === '' || $ate === '') { $de = $firstDayMonth; $ate = $lastDayMonth; $quick = 'mes'; }
} else { $de = $firstDayMonth; $ate = $lastDayMonth; $quick = 'mes'; }

if ($de > $ate) { $tmp = $de; $de = $ate; $ate = $tmp; }
$deDT  = $de . ' 00:00:00';
$ateDT = $ate . ' 23:59:59';

// ====== Queries ======
$total = (int)$pdo->query("SELECT COUNT(*) c FROM motos")->fetch()['c'];
$disp  = (int)$pdo->query("SELECT COUNT(*) c FROM motos WHERE status='disponivel'")->fetch()['c'];
$resv  = (int)$pdo->query("SELECT COUNT(*) c FROM motos WHERE status='reservada'")->fetch()['c'];
$vend  = (int)$pdo->query("SELECT COUNT(*) c FROM motos WHERE status='vendida'")->fetch()['c'];

$stmtCad = $pdo->prepare("SELECT COUNT(*) c FROM motos WHERE created_at BETWEEN ? AND ?");
$stmtCad->execute([$deDT, $ateDT]);
$cad_periodo = (int)$stmtCad->fetch()['c'];

$stmtVend = $pdo->prepare("
  SELECT COUNT(*) c, COALESCE(SUM(valor),0) s
  FROM motos
  WHERE status='vendida' AND updated_at IS NOT NULL
    AND updated_at BETWEEN ? AND ?
");
$stmtVend->execute([$deDT, $ateDT]);
$rowV = $stmtVend->fetch();
$vendas_periodo = (int)$rowV['c'];
$faturamento_periodo = (float)$rowV['s'];
$ticket_medio = $vendas_periodo > 0 ? ($faturamento_periodo / $vendas_periodo) : 0;

$stmtTop = $pdo->prepare("
  SELECT
    UPPER(COALESCE(NULLIF(titulo,''), modelo)) AS nome,
    COUNT(*) AS qtd,
    COALESCE(SUM(valor),0) AS total_valor
  FROM motos
  WHERE status='vendida' AND updated_at IS NOT NULL
    AND updated_at BETWEEN ? AND ?
  GROUP BY UPPER(COALESCE(NULLIF(titulo,''), modelo))
  ORDER BY total_valor DESC, qtd DESC
  LIMIT 10
");
$stmtTop->execute([$deDT, $ateDT]);
$tops = $stmtTop->fetchAll();

// Série temporal — vendas diárias no período (para gráfico)
$stmtSerie = $pdo->prepare("
  SELECT DATE(updated_at) dia, COUNT(*) qtd, COALESCE(SUM(valor),0) total
  FROM motos
  WHERE status='vendida' AND updated_at IS NOT NULL
    AND updated_at BETWEEN ? AND ?
  GROUP BY DATE(updated_at)
  ORDER BY dia ASC
");
$stmtSerie->execute([$deDT, $ateDT]);
$serieRaw = $stmtSerie->fetchAll();

// Estatísticas do CRM
$crm_novos = 0;
$crm_negociacao = 0;
$crm_fechados_mes = 0;
$crm_criados_mes = 0;
$crm_conversao_mes = 0;
$agendamentos_hoje = 0;
$agendamentos_atrasados = 0;
$opp_total = 0;
try {
  ensure_crm_schema($pdo);
  $crm_novos = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE etapa='novo'")->fetchColumn();
  $crm_negociacao = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE etapa IN ('contato','negociacao','proposta')")->fetchColumn();
  $stmtCRM = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE etapa='fechado' AND fechado_at BETWEEN ? AND ?");
  $stmtCRM->execute([$deDT, $ateDT]);
  $crm_fechados_mes = (int)$stmtCRM->fetchColumn();

  // Leads criados no mês para taxa de conversão
  $stmtCriados = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE created_at BETWEEN ? AND ?");
  $stmtCriados->execute([$deDT, $ateDT]);
  $crm_criados_mes = (int)$stmtCriados->fetchColumn();
  $crm_conversao_mes = ($crm_criados_mes > 0) ? round(($crm_fechados_mes / $crm_criados_mes) * 100, 1) : 0;

  // Agendamentos
  $agendamentos_hoje = (int)$pdo->query("SELECT COUNT(*) FROM crm_agendamentos WHERE DATE(data_hora)=CURDATE() AND status='pendente'")->fetchColumn();
  $agendamentos_atrasados = (int)$pdo->query("SELECT COUNT(*) FROM crm_agendamentos WHERE data_hora<NOW() AND status='pendente'")->fetchColumn();

  // Oportunidades (mesmo cálculo que no header, mas simplificado para o dashboard)
  require_once __DIR__ . '/../inc/crm_match.php';
  $stmt_motos = $pdo->prepare("SELECT id FROM motos WHERE status='disponivel'");
  $stmt_motos->execute();
  foreach ($stmt_motos as $m_row) {
    $leads = crm_match_leads_para_moto($pdo, (int)$m_row['id'], 65, 100, $user);
    if (empty($leads)) continue;
    $lead_ids = array_map(fn($l) => $l['lead_id'], $leads);
    $moto_id_safe = (int)$m_row['id'];
    $place = implode(',', array_fill(0, count($lead_ids), '?'));
    $stmt_disp = $pdo->prepare("SELECT COUNT(*) FROM crm_match_dispensados WHERE moto_id=? AND lead_id IN ($place)");
    $stmt_disp->execute(array_merge([$moto_id_safe], $lead_ids));
    $dispensados = (int)$stmt_disp->fetchColumn();
    $opp_total += count($leads) - $dispensados;
  }
} catch (Throwable $e) {}

// Monta série completa com zero nos dias sem venda
$serie = [];
$cursor = strtotime($de);
$end = strtotime($ate);
while ($cursor <= $end) {
  $d = date('Y-m-d', $cursor);
  $serie[$d] = ['dia' => $d, 'qtd' => 0, 'total' => 0];
  $cursor = strtotime('+1 day', $cursor);
}
foreach ($serieRaw as $r) {
  $serie[$r['dia']] = $r;
}
$serie = array_values($serie);

$periodLabel = ($de === $ate)
  ? date('d/m/Y', strtotime($de))
  : (date('d/m/Y', strtotime($de)) . ' — ' . date('d/m/Y', strtotime($ate)));

include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">

    <div class="page-header">
      <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">Visão geral das motos e vendas · <?= htmlspecialchars($periodLabel) ?></p>
      </div>
    </div>

    <!-- Filtros de período -->
    <div class="card card-pad mb-6">
      <div class="chip-row mb-3">
        <a class="chip <?= $quick==='hoje'?'active':'' ?>" href="?quick=hoje">Hoje</a>
        <a class="chip <?= $quick==='7d'?'active':'' ?>" href="?quick=7d">7 dias</a>
        <a class="chip <?= $quick==='30d'?'active':'' ?>" href="?quick=30d">30 dias</a>
        <a class="chip <?= $quick==='mes'?'active':'' ?>" href="?quick=mes">Mês atual</a>
      </div>

      <form method="get" class="row" style="gap: var(--space-3);">
        <input type="hidden" name="quick" value="custom">
        <div class="field" style="flex:1;min-width:140px;">
          <label>De</label>
          <input type="date" name="de" value="<?= htmlspecialchars($de) ?>">
        </div>
        <div class="field" style="flex:1;min-width:140px;">
          <label>Até</label>
          <input type="date" name="ate" value="<?= htmlspecialchars($ate) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Aplicar</button>
        <a href="?quick=mes" class="btn btn-ghost">Limpar</a>
      </form>
    </div>

    <!-- KPIs -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon stat-icon-green">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-label">Faturamento</div>
        <div class="stat-value"><?= money($faturamento_periodo) ?></div>
        <div class="stat-sub">no período selecionado</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-brand">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div class="stat-label">Vendas</div>
        <div class="stat-value"><?= $vendas_periodo ?></div>
        <div class="stat-sub">motos vendidas</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-blue">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <div class="stat-label">Ticket médio</div>
        <div class="stat-value"><?= money($ticket_medio) ?></div>
        <div class="stat-sub">por venda no período</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-orange">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        </div>
        <div class="stat-label">Cadastros</div>
        <div class="stat-value"><?= $cad_periodo ?></div>
        <div class="stat-sub">no período</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-brand">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="stat-label">Estoque total</div>
        <div class="stat-value"><?= $total ?></div>
        <div class="stat-sub">todas as situações</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-green">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="stat-label">Disp / Resv / Vend</div>
        <div class="stat-value" style="font-size:22px;"><?= $disp ?> / <?= $resv ?> / <?= $vend ?></div>
        <div class="stat-sub">estoque atual</div>
      </div>
    </div>

    <!-- Resumo CRM -->
    <div class="card card-pad mb-6">
      <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
        <h2 style="font-size:16px;margin:0;">CRM — Pipeline de Vendas</h2>
        <a href="<?= base_url('painel/crm.php') ?>" class="btn btn-ghost" style="font-size:12px;">Ver pipeline →</a>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--space-3);">
        <div style="background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:var(--space-3);">
          <div style="font-size:12px;color:var(--muted);font-weight:600;margin-bottom:4px;">Leads Novos</div>
          <div style="font-size:28px;font-weight:900;color:var(--ink);"><?= $crm_novos ?></div>
        </div>
        <div style="background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:var(--space-3);">
          <div style="font-size:12px;color:var(--muted);font-weight:600;margin-bottom:4px;">Em Negociação</div>
          <div style="font-size:28px;font-weight:900;color:var(--red);"><?= $crm_negociacao ?></div>
        </div>
        <div style="background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:var(--space-3);">
          <div style="font-size:12px;color:var(--muted);font-weight:600;margin-bottom:4px;">Fechados (<?= htmlspecialchars($periodLabel) ?>)</div>
          <div style="font-size:28px;font-weight:900;color:var(--ok);"><?= $crm_fechados_mes ?></div>
        </div>
      </div>
      <div style="border-top:1px solid var(--border);padding-top:var(--space-3);margin-top:var(--space-3);font-size:13px;color:var(--text-muted);">
        <a href="<?= base_url('painel/crm_agenda.php') ?>" style="color:inherit;text-decoration:none;">📅 Agendamentos hoje: <strong><?= $agendamentos_hoje ?></strong> <?php if ($agendamentos_atrasados > 0): ?><span style="color:var(--red);">(<?= $agendamentos_atrasados ?> atrasados)</span><?php endif; ?></a>
        <div style="margin-top:8px;">
          <a href="<?= base_url('painel/crm_oportunidades.php') ?>" style="color:inherit;text-decoration:none;">⚡ Oportunidades de venda: <strong><?= $opp_total ?></strong> pares lead-moto</a>
        </div>
        <div style="margin-top:8px;">
          <a href="<?= base_url('painel/crm_relatorios.php') ?>" style="color:inherit;text-decoration:none;">📊 Taxa de conversão: <strong><?= $crm_conversao_mes ?>%</strong> neste mês</a>
        </div>
      </div>
    </div>

    <!-- Gráfico de vendas -->
    <div class="card card-pad mb-6">
      <h2 style="font-size:16px;margin-bottom:var(--space-3);">Faturamento por dia</h2>
      <div style="position:relative;height:280px;width:100%;">
        <canvas id="chartVendas"></canvas>
      </div>
    </div>

    <!-- Top 10 modelos -->
    <div class="card mb-6">
      <div class="card-pad" style="border-bottom:1px solid var(--border-soft);">
        <h2 style="font-size:16px;">Top 10 — Maior faturamento por modelo</h2>
        <p class="text-sm text-muted mt-1">no período selecionado</p>
      </div>
      <div class="table-wrap" style="border:none;box-shadow:none;border-radius:0;">
        <table class="table">
          <thead>
            <tr>
              <th>Modelo</th>
              <th style="text-align:center;">Vendas</th>
              <th style="text-align:right;">Faturamento</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$tops): ?>
            <tr><td colspan="3" class="empty">Sem vendas registradas no período.</td></tr>
          <?php else: ?>
            <?php foreach ($tops as $t): ?>
              <tr>
                <td style="font-weight:700;"><?= htmlspecialchars($t['nome']) ?></td>
                <td style="text-align:center;font-weight:700;"><?= (int)$t['qtd'] ?></td>
                <td style="text-align:right;font-weight:800;color:var(--green-600);"><?= money($t['total_valor']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const serie = <?= json_encode($serie) ?>;
const labels = serie.map(s => {
  const [y, m, d] = s.dia.split('-');
  return d + '/' + m;
});
const dataTotal = serie.map(s => parseFloat(s.total));

const ctx = document.getElementById('chartVendas');
if (ctx && window.Chart) {
  const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 280);
  gradient.addColorStop(0, 'rgba(214, 0, 0, .35)');
  gradient.addColorStop(1, 'rgba(214, 0, 0, 0)');

  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Faturamento',
        data: dataTotal,
        borderColor: '#d60000',
        backgroundColor: gradient,
        borderWidth: 2.5,
        tension: 0.35,
        fill: true,
        pointRadius: serie.length <= 31 ? 3 : 0,
        pointBackgroundColor: '#d60000',
        pointHoverRadius: 6,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => 'R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2})
          }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: {
          beginAtZero: true,
          ticks: {
            font: { size: 11 },
            callback: v => v >= 1000 ? 'R$ ' + (v/1000).toFixed(0) + 'k' : 'R$ ' + v
          },
          grid: { color: 'rgba(0,0,0,.05)' }
        }
      }
    }
  });
}
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
