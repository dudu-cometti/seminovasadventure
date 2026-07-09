<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';
require_login();

ensure_crm_schema($pdo);

$user = current_user();
$page_title = '📊 Relatórios CRM';

// ===== PERÍODO =====
$quick = trim($_GET['quick'] ?? 'mes');
$de = trim($_GET['de'] ?? '');
$ate = trim($_GET['ate'] ?? '');

$today = date('Y-m-d');
$firstDayMonth = date('Y-m-01');
$lastDayMonth = date('Y-m-t');
$firstDayYear = date('Y-01-01');

if ($quick === 'hoje') { $de = $today; $ate = $today; }
elseif ($quick === '7d') { $de = date('Y-m-d', strtotime('-6 days')); $ate = $today; }
elseif ($quick === '30d') { $de = date('Y-m-d', strtotime('-29 days')); $ate = $today; }
elseif ($quick === '90d') { $de = date('Y-m-d', strtotime('-89 days')); $ate = $today; }
elseif ($quick === 'ano') { $de = $firstDayYear; $ate = $today; }
elseif ($quick === 'custom') {
  if ($de === '' || $ate === '') { $de = $firstDayMonth; $ate = $lastDayMonth; $quick = 'mes'; }
} else { $de = $firstDayMonth; $ate = $lastDayMonth; $quick = 'mes'; }

if ($de > $ate) { $tmp = $de; $de = $ate; $ate = $tmp; }
$deDT = $de . ' 00:00:00';
$ateDT = $ate . ' 23:59:59';

// Período anterior para comparação
$dias = (strtotime($ate) - strtotime($de)) / 86400;
$de_ant = date('Y-m-d', strtotime($de . ' -' . ceil($dias) . ' days'));
$ate_ant = date('Y-m-d', strtotime($de . ' -1 days'));
$de_ant_dt = $de_ant . ' 00:00:00';
$ate_ant_dt = $ate_ant . ' 23:59:59';

// Filtro de vendedor (gerente vê todos, vendedor vê só dele)
$where_vendor = '';
$params_vendor = [];
if ($user['role'] === 'vendedor') {
  $where_vendor = 'l.vendedor_id = ?';
  $params_vendor = [$user['id']];
} elseif (!empty($_GET['vendedor_id'])) {
  $where_vendor = 'l.vendedor_id = ?';
  $params_vendor = [(int)$_GET['vendedor_id']];
}

$periodLabel = ($de === $ate)
  ? date('d/m/Y', strtotime($de))
  : (date('d/m/Y', strtotime($de)) . ' — ' . date('d/m/Y', strtotime($ate)));

// ===== QUERIES KPIs =====
$kpi = [
  'criados' => 0,
  'fechados_qtde' => 0,
  'fechados_valor' => 0,
  'perdidos' => 0,
  'dias_fechamento' => 0,
];
$kpi_ant = ['criados' => 0, 'fechados_qtde' => 0, 'fechados_valor' => 0, 'perdidos' => 0];

// Leads criados no período
$sql = "SELECT COUNT(*) FROM crm_leads WHERE created_at BETWEEN ? AND ?";
if ($where_vendor) $sql .= " AND $where_vendor";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));
$kpi['criados'] = (int)$stmt->fetchColumn();

$sql = "SELECT COUNT(*) FROM crm_leads WHERE created_at BETWEEN ? AND ?";
if ($where_vendor) $sql .= " AND $where_vendor";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$de_ant_dt, $ate_ant_dt], $params_vendor));
$kpi_ant['criados'] = (int)$stmt->fetchColumn();

// Leads fechados no período
$sql = "SELECT COUNT(*) c, COALESCE(SUM(valor_negociado), 0) v FROM crm_leads WHERE etapa='fechado' AND fechado_at BETWEEN ? AND ?";
if ($where_vendor) $sql .= " AND $where_vendor";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$kpi['fechados_qtde'] = (int)$row['c'];
$kpi['fechados_valor'] = (float)$row['v'];

$sql = "SELECT COUNT(*) c FROM crm_leads WHERE etapa='fechado' AND fechado_at BETWEEN ? AND ?";
if ($where_vendor) $sql .= " AND $where_vendor";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$de_ant_dt, $ate_ant_dt], $params_vendor));
$kpi_ant['fechados_qtde'] = (int)$stmt->fetchColumn();

// Leads perdidos no período
$sql = "SELECT COUNT(*) FROM crm_leads WHERE etapa='perdido' AND fechado_at BETWEEN ? AND ?";
if ($where_vendor) $sql .= " AND $where_vendor";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));
$kpi['perdidos'] = (int)$stmt->fetchColumn();

$sql = "SELECT COUNT(*) FROM crm_leads WHERE etapa='perdido' AND fechado_at BETWEEN ? AND ?";
if ($where_vendor) $sql .= " AND $where_vendor";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$de_ant_dt, $ate_ant_dt], $params_vendor));
$kpi_ant['perdidos'] = (int)$stmt->fetchColumn();

// Taxa de conversão
$kpi['conversao'] = ($kpi['criados'] > 0) ? round(($kpi['fechados_qtde'] / $kpi['criados']) * 100, 1) : 0;
$kpi_ant['conversao'] = ($kpi_ant['criados'] > 0) ? round(($kpi_ant['fechados_qtde'] / $kpi_ant['criados']) * 100, 1) : 0;

// Ticket médio
$kpi['ticket'] = ($kpi['fechados_qtde'] > 0) ? ($kpi['fechados_valor'] / $kpi['fechados_qtde']) : 0;
$kpi_ant['ticket'] = ($kpi_ant['fechados_qtde'] > 0) ? (
  $pdo->prepare("SELECT COALESCE(AVG(valor_negociado), 0) FROM crm_leads WHERE etapa='fechado' AND fechado_at BETWEEN ? AND ?")
    ->execute(array_merge([$de_ant_dt, $ate_ant_dt], $params_vendor))
    ? ($pdo->query("SELECT COALESCE(AVG(valor_negociado), 0) FROM crm_leads WHERE etapa='fechado' AND fechado_at BETWEEN '$de_ant_dt' AND '$ate_ant_dt'" . ($where_vendor ? " AND $where_vendor" : ""))->fetchColumn() ?: 0)
    : 0
) : 0;

// Tempo médio de fechamento
$sql = "SELECT COALESCE(AVG(DATEDIFF(fechado_at, created_at)), 0) FROM crm_leads WHERE etapa='fechado' AND fechado_at BETWEEN ? AND ?";
if ($where_vendor) $sql .= " AND $where_vendor";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));
$kpi['dias_fechamento'] = (float)$stmt->fetchColumn();

include __DIR__ . '/../inc/header.php';
?>

<style>
  .rel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); flex-wrap: wrap; gap: var(--space-3); }
  .rel-period-chips { display: flex; gap: 8px; flex-wrap: wrap; }
  .chip { padding: 6px 12px; border-radius: 20px; border: 1px solid var(--border); background: white; cursor: pointer; font-size: 13px; font-weight: 600; transition: all var(--t-fast); color: var(--text-muted); }
  .chip.active { background: var(--brand); color: white; border-color: var(--brand); }
  .chip:hover { border-color: var(--brand); }

  .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--space-3); margin-bottom: var(--space-6); }
  .kpi-card { background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-4); }
  .kpi-label { font-size: 12px; color: var(--text-muted); font-weight: 600; margin-bottom: 4px; }
  .kpi-value { font-family: 'JetBrains Mono', monospace; font-size: 28px; font-weight: 900; color: var(--ink); }
  .kpi-sub { font-size: 12px; color: var(--text-muted); margin-top: 8px; }
  .kpi-delta { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; margin-left: 6px; }
  .delta-up { color: var(--green-600, #16a34a); }
  .delta-down { color: var(--red); }

  .rel-section { margin-bottom: var(--space-6); }
  .rel-section h2 { font-size: 18px; font-weight: 800; margin-bottom: var(--space-3); }
  .rel-card { background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-4); }

  .funil-bar { display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-3); }
  .funil-label { min-width: 120px; font-size: 13px; font-weight: 600; }
  .funil-track { flex: 1; background: var(--bg); border-radius: 4px; overflow: hidden; height: 28px; position: relative; }
  .funil-fill { background: linear-gradient(90deg, var(--brand-50), var(--brand)); height: 100%; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px; font-size: 11px; font-weight: 700; color: var(--brand-700); transition: width var(--t-fast); }
  .funil-info { min-width: 120px; font-size: 13px; font-weight: 600; text-align: right; }

  .table-export { display: flex; gap: 8px; margin-bottom: var(--space-3); }
  .btn-export { padding: 6px 10px; font-size: 12px; background: var(--bg); border: 1px solid var(--line); border-radius: 4px; cursor: pointer; font-weight: 600; color: var(--text); transition: all var(--t-fast); }
  .btn-export:hover { background: var(--bg-secondary); }

  .table-scroll { overflow-x: auto; }
  .rel-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .rel-table th { background: var(--bg); padding: var(--space-2) var(--space-3); text-align: left; font-weight: 700; border-bottom: 1px solid var(--line); color: var(--text-muted); }
  .rel-table td { padding: var(--space-2) var(--space-3); border-bottom: 1px solid var(--border-soft); }
  .rel-table tbody tr:hover { background: var(--bg); }
  .rel-table tfoot { background: var(--bg); font-weight: 700; border-top: 2px solid var(--line); }
  .rel-table tfoot td { padding: var(--space-3) var(--space-3); }

  .destaque-alto { background: #fef3c7; }
  .destaque-critico { background: #fee2e2; }

  .page-grid { max-width: 1400px; margin: 0 auto; }
</style>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">

    <div class="page-header">
      <div>
        <h1 class="page-title">📊 Relatórios CRM</h1>
        <p class="page-subtitle">Análise de leads, vendas e desempenho · <?= htmlspecialchars($periodLabel) ?></p>
      </div>
    </div>

    <!-- Filtros de período -->
    <div class="card card-pad mb-6">
      <div class="rel-header">
        <div class="rel-period-chips">
          <a class="chip <?= $quick==='mes'?'active':'' ?>" href="?quick=mes">Este mês</a>
          <a class="chip <?= $quick==='30d'?'active':'' ?>" href="?quick=30d">Últimos 30 dias</a>
          <a class="chip <?= $quick==='90d'?'active':'' ?>" href="?quick=90d">Últimos 90 dias</a>
          <a class="chip <?= $quick==='ano'?'active':'' ?>" href="?quick=ano">Este ano</a>
        </div>
      </div>

      <form method="get" class="row" style="gap: var(--space-3); margin-top: var(--space-4);">
        <input type="hidden" name="quick" value="custom">
        <div class="field" style="flex:1;min-width:140px;">
          <label>De</label>
          <input type="date" name="de" value="<?= htmlspecialchars($de) ?>">
        </div>
        <div class="field" style="flex:1;min-width:140px;">
          <label>Até</label>
          <input type="date" name="ate" value="<?= htmlspecialchars($ate) ?>">
        </div>
        <?php if ($user['role'] === 'gerente'): ?>
        <div class="field" style="flex:1;min-width:200px;">
          <label>Vendedor</label>
          <select name="vendedor_id">
            <option value="">Todos os vendedores</option>
            <?php
              $vendedores = $pdo->query("SELECT id, nome FROM users WHERE role='vendedor' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
              foreach ($vendedores as $v) {
                $sel = ((int)($_GET['vendedor_id'] ?? 0) === (int)$v['id']) ? 'selected' : '';
                echo "<option value=\"" . (int)$v['id'] . "\" $sel>" . htmlspecialchars($v['nome']) . "</option>";
              }
            ?>
          </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="align-self: flex-end;">Aplicar</button>
        <a href="?quick=mes" class="btn btn-ghost" style="align-self: flex-end;">Limpar</a>
      </form>
    </div>

    <!-- 1. KPIs -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Leads Criados</div>
        <div class="kpi-value">
          <?= $kpi['criados'] ?>
          <?php
            $delta = $kpi['criados'] - $kpi_ant['criados'];
            $pct = ($kpi_ant['criados'] > 0) ? round(($delta / $kpi_ant['criados']) * 100, 0) : 0;
            if ($delta !== 0) {
              $class = ($delta > 0) ? 'delta-up' : 'delta-down';
              $arrow = ($delta > 0) ? '▲' : '▼';
              echo "<span class=\"kpi-delta $class\">$arrow {$pct}%</span>";
            }
          ?>
        </div>
        <div class="kpi-sub">no período</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Fechados (Qtde)</div>
        <div class="kpi-value">
          <?= $kpi['fechados_qtde'] ?>
          <?php
            $delta = $kpi['fechados_qtde'] - $kpi_ant['fechados_qtde'];
            $pct = ($kpi_ant['fechados_qtde'] > 0) ? round(($delta / $kpi_ant['fechados_qtde']) * 100, 0) : 0;
            if ($delta !== 0) {
              $class = ($delta > 0) ? 'delta-up' : 'delta-down';
              $arrow = ($delta > 0) ? '▲' : '▼';
              echo "<span class=\"kpi-delta $class\">$arrow {$pct}%</span>";
            }
          ?>
        </div>
        <div class="kpi-sub">R$ <?= number_format($kpi['fechados_valor'], 2, ',', '.') ?></div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Perdidos</div>
        <div class="kpi-value">
          <?= $kpi['perdidos'] ?>
          <?php
            $delta = $kpi['perdidos'] - $kpi_ant['perdidos'];
            $pct = ($kpi_ant['perdidos'] > 0) ? round(($delta / $kpi_ant['perdidos']) * 100, 0) : 0;
            if ($delta !== 0) {
              $class = ($delta > 0) ? 'delta-down' : 'delta-up';
              $arrow = ($delta > 0) ? '▲' : '▼';
              echo "<span class=\"kpi-delta $class\">$arrow {$pct}%</span>";
            }
          ?>
        </div>
        <div class="kpi-sub">no período</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Taxa de Conversão</div>
        <div class="kpi-value">
          <?= round($kpi['conversao'], 1) ?>%
          <?php
            $delta = $kpi['conversao'] - $kpi_ant['conversao'];
            if (abs($delta) > 0.1) {
              $class = ($delta > 0) ? 'delta-up' : 'delta-down';
              $arrow = ($delta > 0) ? '▲' : '▼';
              echo "<span class=\"kpi-delta $class\">$arrow " . round(abs($delta), 1) . "%</span>";
            }
          ?>
        </div>
        <div class="kpi-sub"><?= $kpi['fechados_qtde'] ?> ÷ <?= $kpi['criados'] ?></div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Ticket Médio</div>
        <div class="kpi-value" style="font-size:22px;">
          R$ <?= number_format($kpi['ticket'], 0, ',', '.') ?>
        </div>
        <div class="kpi-sub">por venda</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Tempo Médio</div>
        <div class="kpi-value" style="font-size:22px;">
          <?= round($kpi['dias_fechamento'], 0) ?> dias
        </div>
        <div class="kpi-sub">criação → fechamento</div>
      </div>
    </div>

    <!-- 2. Funil de Conversão -->
    <div class="rel-section">
      <h2>Funil de Conversão</h2>
      <div class="rel-card">
        <div class="table-export">
          <button class="btn-export" onclick="exportarCSV('funil')">📥 Exportar</button>
        </div>

        <?php
          // Leads por etapa
          $etapas_ordem = ['novo', 'contato', 'negociacao', 'proposta', 'fechado'];
          $etapas_dados = [];
          $perdidos_total = $kpi['perdidos'];

          foreach ($etapas_ordem as $etapa) {
            $sql = "SELECT COUNT(*) FROM crm_leads WHERE etapa=? AND created_at BETWEEN ? AND ?";
            if ($where_vendor) $sql .= " AND $where_vendor";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$etapa, $deDT, $ateDT], $params_vendor));
            $qtd = (int)$stmt->fetchColumn();
            $etapas_dados[$etapa] = $qtd;
          }

          $etapas_labels = [
            'novo' => '🆕 Novo',
            'contato' => '📞 Contato',
            'negociacao' => '💬 Negociação',
            'proposta' => '📋 Proposta',
            'fechado' => '✅ Fechado'
          ];

          $max = max(array_merge($etapas_dados, [1]));
          foreach ($etapas_ordem as $etapa) {
            $qtd = $etapas_dados[$etapa];
            $pct_preenchimento = ($max > 0) ? ($qtd / $max) * 100 : 0;
            $pct_passagem = ($etapa !== 'fechado' && isset($etapas_dados[$etapas_ordem[array_search($etapa, $etapas_ordem) + 1]]))
              ? round(($etapas_dados[$etapas_ordem[array_search($etapa, $etapas_ordem) + 1]] / max($qtd, 1)) * 100, 0)
              : 0;
            ?>
            <div class="funil-bar">
              <div class="funil-label"><?= htmlspecialchars($etapas_labels[$etapa]) ?></div>
              <div class="funil-track">
                <div class="funil-fill" style="width: <?= $pct_preenchimento ?>%;">
                  <?php if ($pct_preenchimento > 15): echo htmlspecialchars((int)$qtd); endif; ?>
                </div>
              </div>
              <div class="funil-info">
                <?= (int)$qtd ?>
                <?php if ($etapa !== 'fechado' && $pct_passagem > 0): ?>
                  <br><span style="font-size:11px;color:var(--text-muted);">→ <?= (int)$pct_passagem ?>%</span>
                <?php endif; ?>
              </div>
            </div>
            <?php
          }

          if ($perdidos_total > 0) {
            $pct_perdidos = round(($perdidos_total / ($kpi['criados'] + $perdidos_total)) * 100, 1);
            ?>
            <div class="funil-bar" style="margin-top: var(--space-4); padding-top: var(--space-4); border-top: 1px solid var(--border);">
              <div class="funil-label" style="color: var(--red);">❌ Perdidos</div>
              <div class="funil-track">
                <div class="funil-fill" style="background: linear-gradient(90deg, #fee2e2, var(--red)); width: <?= min($pct_perdidos * 3, 100) ?>%;">
                  <?php if ($pct_perdidos > 5): echo htmlspecialchars((int)$perdidos_total); endif; ?>
                </div>
              </div>
              <div class="funil-info" style="color: var(--red);">
                <?= (int)$perdidos_total ?> (<?= $pct_perdidos ?>%)
              </div>
            </div>
            <?php
          }
        ?>
      </div>
    </div>

    <!-- 3. Desempenho por Vendedor (gerente only) -->
    <?php if ($user['role'] === 'gerente'): ?>
    <div class="rel-section">
      <h2>Desempenho por Vendedor</h2>
      <div class="rel-card">
        <div class="table-export">
          <button class="btn-export" onclick="exportarCSV('vendedores')">📥 Exportar</button>
        </div>
        <div class="table-scroll">
          <table class="rel-table">
            <thead>
              <tr>
                <th>Vendedor</th>
                <th style="text-align:right;">Leads</th>
                <th style="text-align:right;">Em Aberto</th>
                <th style="text-align:right;">Fechados</th>
                <th style="text-align:right;">R$ Fechado</th>
                <th style="text-align:right;">Conversão %</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $vendedores = $pdo->query("SELECT id, nome FROM users WHERE role='vendedor' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
                $total_leads = 0;
                $total_abertos = 0;
                $total_fechados = 0;
                $total_valor = 0;

                foreach ($vendedores as $v) {
                  $v_id = (int)$v['id'];

                  // Leads totais
                  $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE vendedor_id=? AND created_at BETWEEN ? AND ?");
                  $stmt->execute([$v_id, $deDT, $ateDT]);
                  $v_leads = (int)$stmt->fetchColumn();

                  // Em aberto
                  $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE vendedor_id=? AND etapa IN ('novo','contato','negociacao','proposta') AND created_at BETWEEN ? AND ?");
                  $stmt->execute([$v_id, $deDT, $ateDT]);
                  $v_abertos = (int)$stmt->fetchColumn();

                  // Fechados
                  $stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(valor_negociado), 0) v FROM crm_leads WHERE vendedor_id=? AND etapa='fechado' AND fechado_at BETWEEN ? AND ?");
                  $stmt->execute([$v_id, $deDT, $ateDT]);
                  $row = $stmt->fetch(PDO::FETCH_ASSOC);
                  $v_fechados = (int)$row['c'];
                  $v_valor = (float)$row['v'];

                  $v_conversao = ($v_leads > 0) ? round(($v_fechados / $v_leads) * 100, 1) : 0;

                  $total_leads += $v_leads;
                  $total_abertos += $v_abertos;
                  $total_fechados += $v_fechados;
                  $total_valor += $v_valor;
              ?>
              <tr>
                <td><strong><?= htmlspecialchars($v['nome']) ?></strong></td>
                <td style="text-align:right;"><?= $v_leads ?></td>
                <td style="text-align:right;"><?= $v_abertos ?></td>
                <td style="text-align:right;"><?= $v_fechados ?></td>
                <td style="text-align:right;">R$ <?= number_format($v_valor, 2, ',', '.') ?></td>
                <td style="text-align:right;"><?= $v_conversao ?>%</td>
              </tr>
              <?php } ?>
            </tbody>
            <tfoot>
              <tr>
                <td><strong>TOTAL</strong></td>
                <td style="text-align:right;"><strong><?= $total_leads ?></strong></td>
                <td style="text-align:right;"><strong><?= $total_abertos ?></strong></td>
                <td style="text-align:right;"><strong><?= $total_fechados ?></strong></td>
                <td style="text-align:right;"><strong>R$ <?= number_format($total_valor, 2, ',', '.') ?></strong></td>
                <td style="text-align:right;"><strong><?= ($total_leads > 0) ? round(($total_fechados / $total_leads) * 100, 1) : 0 ?>%</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- 4. Motivos de Perda -->
    <div class="rel-section">
      <h2>Motivos de Perda</h2>
      <div class="rel-card">
        <div class="table-export">
          <button class="btn-export" onclick="exportarCSV('perdas')">📥 Exportar</button>
        </div>

        <?php
          // Contagem por motivo
          $sql = "SELECT motivo_perda, COUNT(*) c FROM crm_leads WHERE etapa='perdido' AND fechado_at BETWEEN ? AND ?";
          if ($where_vendor) $sql .= " AND $where_vendor";
          $sql .= " GROUP BY motivo_perda ORDER BY c DESC";
          $stmt = $pdo->prepare($sql);
          $stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));
          $motivos_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

          if (empty($motivos_data)): ?>
            <p style="color:var(--text-muted);">Nenhuma perda registrada no período.</p>
          <?php else:
            $total_perdidos = array_sum(array_column($motivos_data, 'c'));
            foreach ($motivos_data as $m):
              $pct = round(($m['c'] / $total_perdidos) * 100, 1);
              $pct_width = ($pct > 5) ? $pct : 100 / count($motivos_data);
            ?>
            <div style="margin-bottom: var(--space-4);">
              <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                <strong><?= htmlspecialchars($m['motivo_perda'] ?: '(sem motivo)') ?></strong>
                <span style="font-size:12px;color:var(--text-muted);"><?= $m['c'] ?> (<?= $pct ?>%)</span>
              </div>
              <div style="height:20px;background:var(--bg);border-radius:4px;overflow:hidden;">
                <div style="height:100%;background:var(--red);width:<?= $pct_width ?>%;"></div>
              </div>
            </div>
            <?php
            endforeach;
          endif;
        ?>

        <div style="margin-top: var(--space-6); padding-top: var(--space-4); border-top: 1px solid var(--border);">
          <h3 style="font-size:14px;margin-bottom:var(--space-3);">Últimos 20 Perdidos</h3>
          <div class="table-scroll">
            <table class="rel-table">
              <thead>
                <tr>
                  <th>Lead</th>
                  <th>Moto</th>
                  <th>Vendedor</th>
                  <th>Motivo</th>
                  <th>Data</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $sql = "SELECT l.id, l.nome, l.moto_id, m.titulo, u.nome as vendedor, l.motivo_perda, l.fechado_at FROM crm_leads l LEFT JOIN motos m ON l.moto_id=m.id LEFT JOIN users u ON l.vendedor_id=u.id WHERE l.etapa='perdido' AND l.fechado_at BETWEEN ? AND ?";
                  if ($where_vendor) $sql .= " AND l.$where_vendor";
                  $sql .= " ORDER BY l.fechado_at DESC LIMIT 20";
                  $stmt = $pdo->prepare($sql);
                  $stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));
                  $perdidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                  foreach ($perdidos as $p):
                ?>
                <tr>
                  <td><a href="<?= base_url('painel/crm_lead.php?id=' . (int)$p['id']) ?>" style="color:inherit;text-decoration:none;"><strong><?= htmlspecialchars($p['nome']) ?></strong></a></td>
                  <td><?= htmlspecialchars($p['titulo'] ?: '—') ?></td>
                  <td><?= htmlspecialchars($p['vendedor'] ?: '—') ?></td>
                  <td><?= htmlspecialchars($p['motivo_perda'] ?: '—') ?></td>
                  <td><?= date('d/m/Y', strtotime($p['fechado_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- 5. Origens e Campanhas -->
    <div class="rel-section">
      <h2>Origens e Campanhas</h2>
      <div class="rel-card" style="margin-bottom: var(--space-4);">
        <h3 style="font-size:14px;margin-bottom:var(--space-3);">Por Origem</h3>
        <div class="table-export">
          <button class="btn-export" onclick="exportarCSV('origens')">📥 Exportar</button>
        </div>
        <div class="table-scroll">
          <table class="rel-table">
            <thead>
              <tr>
                <th>Origem</th>
                <th style="text-align:right;">Leads</th>
                <th style="text-align:right;">Fechados</th>
                <th style="text-align:right;">Conversão %</th>
                <th style="text-align:right;">R$ Fechado</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $sql = "SELECT origem, COUNT(*) c, SUM(CASE WHEN etapa='fechado' THEN 1 ELSE 0 END) f, COALESCE(SUM(CASE WHEN etapa='fechado' THEN valor_negociado ELSE 0 END), 0) v FROM crm_leads WHERE created_at BETWEEN ? AND ?";
                if ($where_vendor) $sql .= " AND $where_vendor";
                $sql .= " GROUP BY origem ORDER BY c DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));
                $origens = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($origens as $o):
                  $conv = ($o['c'] > 0) ? round(($o['f'] / $o['c']) * 100, 1) : 0;
              ?>
              <tr>
                <td><?= htmlspecialchars($o['origem']) ?></td>
                <td style="text-align:right;"><?= $o['c'] ?></td>
                <td style="text-align:right;"><?= $o['f'] ?: 0 ?></td>
                <td style="text-align:right;"><?= $conv ?>%</td>
                <td style="text-align:right;">R$ <?= number_format($o['v'], 2, ',', '.') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="rel-card">
        <h3 style="font-size:14px;margin-bottom:var(--space-3);">Por Campanha (UTM)</h3>
        <div class="table-export">
          <button class="btn-export" onclick="exportarCSV('campanhas')">📥 Exportar</button>
        </div>
        <div class="table-scroll">
          <table class="rel-table">
            <thead>
              <tr>
                <th>Campanha</th>
                <th>Source / Medium</th>
                <th style="text-align:right;">Leads</th>
                <th style="text-align:right;">Fechados</th>
                <th style="text-align:right;">R$ Fechado</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $sql = "SELECT COALESCE(utm_campaign, '(sem campanha)') c, CONCAT(COALESCE(utm_source, '?'), ' / ', COALESCE(utm_medium, '?')) sm, COUNT(*) leads, SUM(CASE WHEN etapa='fechado' THEN 1 ELSE 0 END) f, COALESCE(SUM(CASE WHEN etapa='fechado' THEN valor_negociado ELSE 0 END), 0) v FROM crm_leads WHERE created_at BETWEEN ? AND ?";
                if ($where_vendor) $sql .= " AND $where_vendor";
                $sql .= " GROUP BY utm_campaign, utm_source, utm_medium ORDER BY v DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));
                $campanhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($campanhas as $ca):
              ?>
              <tr>
                <td><?= htmlspecialchars($ca['c']) ?></td>
                <td><?= htmlspecialchars($ca['sm']) ?></td>
                <td style="text-align:right;"><?= $ca['leads'] ?></td>
                <td style="text-align:right;"><?= $ca['f'] ?: 0 ?></td>
                <td style="text-align:right;">R$ <?= number_format($ca['v'], 2, ',', '.') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-top:var(--space-3);">💡 Leads sem UTM = tráfego direto/orgânico</p>
      </div>
    </div>

    <!-- 6. Estoque × Demanda -->
    <div class="rel-section">
      <h2>Estoque × Demanda</h2>
      <div class="rel-card">
        <div class="table-export">
          <button class="btn-export" onclick="exportarCSV('demanda')">📥 Exportar</button>
        </div>
        <div class="table-scroll">
          <table class="rel-table">
            <thead>
              <tr>
                <th>Modelo</th>
                <th style="text-align:right;">Pedidos (período)</th>
                <th style="text-align:right;">Em Estoque</th>
                <th style="text-align:right;">Diferença</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php
                $sql = "SELECT UPPER(COALESCE(NULLIF(modelo,''), 'Outro')) m, COUNT(*) pedidos FROM crm_interesses WHERE created_at BETWEEN ? AND ? GROUP BY modelo ORDER BY pedidos DESC LIMIT 10";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$deDT, $ateDT]);
                $demandas = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($demandas as $d):
                  // Estoque dessa marca
                  $stmt_est = $pdo->prepare("SELECT COUNT(*) FROM motos WHERE status='disponivel' AND modelo LIKE ? LIMIT 1");
                  $stmt_est->execute(['%' . $d['m'] . '%']);
                  $estoque = (int)$stmt_est->fetchColumn();
                  $diff = $d['pedidos'] - $estoque;
                  $classe = ($diff > 0) ? 'destaque-alto' : '';
              ?>
              <tr class="<?= $classe ?>">
                <td><strong><?= htmlspecialchars($d['m']) ?></strong></td>
                <td style="text-align:right;"><?= $d['pedidos'] ?></td>
                <td style="text-align:right;"><?= $estoque ?></td>
                <td style="text-align:right;"><?php
                  if ($diff > 0) {
                    echo '<span style="color:var(--red);font-weight:700;">+' . $diff . ' faltam</span>';
                  } elseif ($diff === 0) {
                    echo '<span style="color:var(--green-600);font-weight:700;">✓ OK</span>';
                  } else {
                    echo '<span style="color:var(--green-600);font-weight:700;">-' . abs($diff) . ' sobra</span>';
                  }
                ?></td>
                <td style="text-align:right;font-size:12px;color:var(--text-muted);">
                  <?php if ($diff > 0): ?>
                    ⚠️ Considere comprar
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</main>

<script>
function exportarCSV(tipo) {
  const params = new URLSearchParams({
    tipo: tipo,
    de: '<?= htmlspecialchars($de) ?>',
    ate: '<?= htmlspecialchars($ate) ?>'
    <?php if ($user['role'] !== 'vendedor' && !empty($_GET['vendedor_id'])): ?>
    , vendedor_id: <?= (int)$_GET['vendedor_id'] ?>
    <?php endif; ?>
  });
  window.location.href = '<?= base_url('painel/crm_export.php') ?>?' + params.toString();
}
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
