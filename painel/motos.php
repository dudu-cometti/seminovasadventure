<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/moto_fields.php';
require_login();
ensure_moto_schema($pdo);

$user = current_user();
$page_title = 'Motos cadastradas';

$ano      = trim($_GET['ano'] ?? '');
$status   = trim($_GET['status'] ?? '');
$dias     = trim($_GET['dias'] ?? '');
$q        = trim($_GET['q'] ?? '');
$condicao = trim($_GET['condicao'] ?? '');
$precoMin = trim($_GET['preco_min'] ?? '');
$precoMax = trim($_GET['preco_max'] ?? '');

// "8.000" / "10.500,50" -> float
function parse_preco($v) {
  $v = trim((string)$v);
  if ($v === '') return null;
  $v = str_replace('.', '', $v);
  $v = str_replace(',', '.', $v);
  return is_numeric($v) ? (float)$v : null;
}
$pMin = parse_preco($precoMin);
$pMax = parse_preco($precoMax);

$where  = [];
$params = [];

if ($q !== '') {
  $where[] = "(m.titulo LIKE ? OR m.modelo LIKE ? OR m.cor LIKE ?)";
  $like = "%$q%";
  array_push($params, $like, $like, $like);
}
if ($ano !== '')    { $where[] = "m.ano_modelo LIKE ?";              $params[] = "%$ano%"; }
if ($status !== '') { $where[] = "m.status = ?";                     $params[] = $status; }
if ($dias !== '')   { $where[] = "DATEDIFF(NOW(), m.created_at) >= ?"; $params[] = (int)$dias; }
if ($condicao !== '' && in_array($condicao, ['nova','seminova'], true)) { $where[] = "m.condicao = ?"; $params[] = $condicao; }
if ($pMin !== null)  { $where[] = "m.valor >= ?"; $params[] = $pMin; }
if ($pMax !== null)  { $where[] = "m.valor <= ?"; $params[] = $pMax; }

$where_sql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "
  SELECT m.*, u.nome AS nome_criador,
         (SELECT caminho FROM moto_fotos WHERE moto_id=m.id ORDER BY ordem ASC, id ASC LIMIT 1) AS capa
  FROM motos m
  JOIN users u ON u.id = m.created_by
  $where_sql
  ORDER BY m.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$motos = $stmt->fetchAll();

function format_money($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function status_badge($s){
  $map = [
    'disponivel' => ['badge-success', 'Disponível'],
    'reservada'  => ['badge-warning', 'Reservada'],
    'vendida'    => ['badge-neutral', 'Vendida'],
  ];
  $info = $map[$s] ?? ['badge-neutral', ucfirst($s)];
  return '<span class="badge ' . $info[0] . '">' . $info[1] . '</span>';
}

include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">

    <div class="page-header">
      <div>
        <h1 class="page-title">Motos cadastradas</h1>
        <p class="page-subtitle"><?= count($motos) ?> moto<?= count($motos) === 1 ? '' : 's' ?> no estoque</p>
      </div>
      <?php if (user_can('create')): ?>
        <a href="<?= base_url('painel/moto_form.php') ?>" class="btn btn-primary">
          <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" width="16" height="16"><path d="M12 5v14M5 12h14"/></svg>
          Nova moto
        </a>
      <?php endif; ?>
    </div>

    <div class="card card-pad mb-4">
      <form method="get" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); align-items:end;">
        <div class="field">
          <label>Buscar</label>
          <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Modelo, cor...">
        </div>
        <div class="field">
          <label>Ano/modelo</label>
          <input type="text" name="ano" value="<?= htmlspecialchars($ano) ?>" placeholder="ex: 2024">
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="">Todos</option>
            <option value="disponivel" <?= $status === 'disponivel' ? 'selected' : '' ?>>Disponível</option>
            <option value="reservada"  <?= $status === 'reservada'  ? 'selected' : '' ?>>Reservada</option>
            <option value="vendida"    <?= $status === 'vendida'    ? 'selected' : '' ?>>Vendida</option>
          </select>
        </div>
        <div class="field">
          <label>Condição</label>
          <select name="condicao">
            <option value="">Todas</option>
            <option value="nova"     <?= $condicao === 'nova'     ? 'selected' : '' ?>>Nova</option>
            <option value="seminova" <?= $condicao === 'seminova' ? 'selected' : '' ?>>Seminova</option>
          </select>
        </div>
        <div class="field">
          <label>Preço mín. (R$)</label>
          <input type="text" name="preco_min" value="<?= htmlspecialchars($precoMin) ?>" placeholder="ex: 8.000" inputmode="numeric">
        </div>
        <div class="field">
          <label>Preço máx. (R$)</label>
          <input type="text" name="preco_max" value="<?= htmlspecialchars($precoMax) ?>" placeholder="ex: 20.000" inputmode="numeric">
        </div>
        <div class="field">
          <label>Tempo no estoque (dias ≥)</label>
          <input type="number" name="dias" min="0" value="<?= htmlspecialchars($dias) ?>" placeholder="ex: 30">
        </div>
        <div class="row" style="gap:6px;">
          <button class="btn btn-primary" type="submit">Filtrar</button>
          <?php if ($q || $ano || $status || $dias || $condicao || $precoMin || $precoMax): ?>
            <a href="<?= base_url('painel/motos.php') ?>" class="btn btn-ghost">Limpar</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="table-wrap">
      <table class="table table-responsive">
        <thead>
          <tr>
            <th style="width:60px;">Foto</th>
            <th>Modelo</th>
            <th>Ano</th>
            <th>Km</th>
            <th>Valor</th>
            <th>Status</th>
            <th>Cadastrada</th>
            <th>Criador</th>
            <th style="text-align:right;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$motos): ?>
            <tr><td colspan="9" class="empty">Nenhuma moto encontrada.</td></tr>
          <?php else: ?>
            <?php foreach ($motos as $m): ?>
              <tr>
                <td data-label="Foto">
                  <?php if ($m['capa']): ?>
                    <img src="<?= base_url('uploads/' . htmlspecialchars($m['capa'])) ?>" alt="" style="width:50px;height:50px;border-radius:8px;object-fit:cover;">
                  <?php else: ?>
                    <div style="width:50px;height:50px;border-radius:8px;background:var(--surface-2);display:grid;place-items:center;color:var(--text-muted);font-size:20px;">🏍️</div>
                  <?php endif; ?>
                </td>
                <td data-label="Modelo">
                  <div style="font-weight:700;"><?= htmlspecialchars($m['titulo'] ?: $m['modelo']) ?></div>
                  <small class="text-muted"><?= htmlspecialchars($m['modelo']) ?> · <?= htmlspecialchars($m['cor']) ?></small>
                </td>
                <td data-label="Ano"><?= htmlspecialchars($m['ano_modelo']) ?></td>
                <td data-label="Km"><?= number_format((int)$m['quilometragem'], 0, ',', '.') ?></td>
                <td data-label="Valor" style="font-weight:800;"><?= format_money($m['valor']) ?></td>
                <td data-label="Status"><?= status_badge($m['status']) ?></td>
                <td data-label="Cadastrada"><?= date('d/m/Y', strtotime($m['created_at'])) ?></td>
                <td data-label="Criador"><?= htmlspecialchars($m['nome_criador']) ?></td>

                <td class="actions-cell" style="text-align:right;white-space:nowrap;">
                  <div class="btn-group" style="justify-content:flex-end;">
                    <a class="btn btn-sm btn-secondary" href="<?= base_url('moto.php?id=' . (int)$m['id']) ?>" target="_blank" title="Ver no marketplace">
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14" height="14"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                    <?php if (user_can('edit')): ?>
                      <a class="btn btn-sm btn-secondary" href="<?= base_url('painel/moto_form.php?id=' . (int)$m['id']) ?>">Editar</a>
                    <?php endif; ?>

                    <?php if (user_can('edit') && $m['status'] === 'disponivel'): ?>
                      <a class="btn btn-sm" style="background:var(--orange-500);color:#fff;"
                         href="<?= base_url('painel/moto_toggle_reserva.php?id=' . (int)$m['id'] . '&to=reservada') ?>"
                         onclick="return confirm('Marcar esta moto como RESERVADA?')">Reservar</a>
                    <?php elseif (user_can('edit') && $m['status'] === 'reservada'): ?>
                      <a class="btn btn-sm btn-ghost"
                         href="<?= base_url('painel/moto_toggle_reserva.php?id=' . (int)$m['id'] . '&to=disponivel') ?>"
                         onclick="return confirm('Tirar reserva?')">Desreservar</a>
                    <?php endif; ?>

                    <?php if ($m['status'] === 'disponivel' && user_can('edit')): ?>
                      <a class="btn btn-sm btn-success"
                         href="<?= base_url('painel/moto_mark_sold.php?id=' . (int)$m['id']) ?>">Vender</a>
                    <?php endif; ?>

                    <?php if (user_can('delete')): ?>
                      <a class="btn btn-sm btn-danger"
                         href="<?= base_url('painel/moto_delete.php?id=' . (int)$m['id']) ?>"
                         onclick="return confirm('Excluir esta moto? Esta ação não pode ser desfeita.')">Excluir</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../inc/footer.php'; ?>
