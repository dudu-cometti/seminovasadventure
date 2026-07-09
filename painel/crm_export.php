<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';
require_login();

ensure_crm_schema($pdo);

$user = current_user();
$tipo = trim($_GET['tipo'] ?? 'leads');
$de = trim($_GET['de'] ?? date('Y-m-01'));
$ate = trim($_GET['ate'] ?? date('Y-m-t'));

if ($de > $ate) { $tmp = $de; $de = $ate; $ate = $tmp; }
$deDT = $de . ' 00:00:00';
$ateDT = $ate . ' 23:59:59';

// Filtro de vendedor
$where_vendor = '';
$params_vendor = [];
if ($user['role'] === 'vendedor') {
  $where_vendor = 'l.vendedor_id = ?';
  $params_vendor = [$user['id']];
} elseif (!empty($_GET['vendedor_id'])) {
  $where_vendor = 'l.vendedor_id = ?';
  $params_vendor = [(int)$_GET['vendedor_id']];
}

// Header CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="crm_' . $tipo . '_' . date('d-m-Y') . '.csv"');

// BOM UTF-8
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

$success = false;

switch ($tipo) {
  case 'leads':
    // Exportar leads completo
    fputcsv($output, [
      'ID', 'Nome', 'Telefone', 'Email', 'Moto Interesse', 'Vendedor',
      'Etapa', 'Temperatura', 'Origem', 'Valor Negociado', 'Total Interações',
      'Última Interação', 'Criado em', 'Fechado em'
    ], ';');

    $sql = "SELECT l.id, l.nome, l.telefone, l.email, m.titulo as moto_titulo, u.nome as vendedor_nome, l.etapa, l.temperatura, l.origem, l.valor_negociado, l.created_at, l.fechado_at FROM crm_leads l LEFT JOIN motos m ON l.moto_id=m.id LEFT JOIN users u ON l.vendedor_id=u.id WHERE l.created_at BETWEEN ? AND ?";
    if ($where_vendor) $sql .= " AND $where_vendor";
    $sql .= " ORDER BY l.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      // Conta interações
      $stmt_inter = $pdo->prepare("SELECT COUNT(*) FROM crm_interacoes WHERE lead_id=?");
      $stmt_inter->execute([$row['id']]);
      $interacoes = (int)$stmt_inter->fetchColumn();

      // Última interação
      $stmt_inter = $pdo->prepare("SELECT created_at FROM crm_interacoes WHERE lead_id=? ORDER BY created_at DESC LIMIT 1");
      $stmt_inter->execute([$row['id']]);
      $ultima_inter = $stmt_inter->fetchColumn() ?: '';

      fputcsv($output, [
        $row['id'],
        $row['nome'],
        $row['telefone'],
        $row['email'] ?: '',
        $row['moto_titulo'] ?: '',
        $row['vendedor_nome'] ?: '',
        $row['etapa'],
        $row['temperatura'],
        $row['origem'],
        number_format($row['valor_negociado'] ?: 0, 2, ',', ''),
        $interacoes,
        $ultima_inter ? date('d/m/Y H:i', strtotime($ultima_inter)) : '',
        date('d/m/Y H:i', strtotime($row['created_at'])),
        $row['fechado_at'] ? date('d/m/Y H:i', strtotime($row['fechado_at'])) : ''
      ], ';');
    }
    $success = true;
    break;

  case 'funil':
    fputcsv($output, ['Etapa', 'Qtde', '% Passagem'], ';');

    $etapas_ordem = ['novo', 'contato', 'negociacao', 'proposta', 'fechado'];
    $etapas_labels = [
      'novo' => 'Novo',
      'contato' => 'Contato',
      'negociacao' => 'Negociação',
      'proposta' => 'Proposta',
      'fechado' => 'Fechado'
    ];

    $etapas_dados = [];
    foreach ($etapas_ordem as $etapa) {
      $sql = "SELECT COUNT(*) FROM crm_leads WHERE etapa=? AND created_at BETWEEN ? AND ?";
      if ($where_vendor) $sql .= " AND $where_vendor";
      $stmt = $pdo->prepare($sql);
      $stmt->execute(array_merge([$etapa, $deDT, $ateDT], $params_vendor));
      $etapas_dados[$etapa] = (int)$stmt->fetchColumn();
    }

    foreach ($etapas_ordem as $etapa) {
      $qtd = $etapas_dados[$etapa];
      $pct_passagem = ($etapa !== 'fechado' && isset($etapas_ordem[array_search($etapa, $etapas_ordem) + 1]))
        ? round(($etapas_dados[$etapas_ordem[array_search($etapa, $etapas_ordem) + 1]] / max($qtd, 1)) * 100, 0)
        : 0;

      fputcsv($output, [
        $etapas_labels[$etapa],
        $qtd,
        ($etapa !== 'fechado' && $pct_passagem > 0) ? $pct_passagem . '%' : ''
      ], ';');
    }
    $success = true;
    break;

  case 'vendedores':
    if ($user['role'] !== 'gerente') {
      http_response_code(403);
      exit('Sem permissão');
    }

    fputcsv($output, ['Vendedor', 'Leads', 'Em Aberto', 'Fechados', 'R$ Fechado', 'Conversão %'], ';');

    $vendedores = $pdo->query("SELECT id, nome FROM users WHERE role='vendedor' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($vendedores as $v) {
      $v_id = (int)$v['id'];

      $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE vendedor_id=? AND created_at BETWEEN ? AND ?");
      $stmt->execute([$v_id, $deDT, $ateDT]);
      $v_leads = (int)$stmt->fetchColumn();

      $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE vendedor_id=? AND etapa IN ('novo','contato','negociacao','proposta') AND created_at BETWEEN ? AND ?");
      $stmt->execute([$v_id, $deDT, $ateDT]);
      $v_abertos = (int)$stmt->fetchColumn();

      $stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(valor_negociado), 0) v FROM crm_leads WHERE vendedor_id=? AND etapa='fechado' AND fechado_at BETWEEN ? AND ?");
      $stmt->execute([$v_id, $deDT, $ateDT]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $v_fechados = (int)$row['c'];
      $v_valor = (float)$row['v'];

      $v_conversao = ($v_leads > 0) ? round(($v_fechados / $v_leads) * 100, 1) : 0;

      fputcsv($output, [
        $v['nome'],
        $v_leads,
        $v_abertos,
        $v_fechados,
        number_format($v_valor, 2, ',', ''),
        $v_conversao . '%'
      ], ';');
    }
    $success = true;
    break;

  case 'perdas':
    fputcsv($output, ['Lead', 'Moto', 'Vendedor', 'Motivo', 'Data'], ';');

    $sql = "SELECT l.id, l.nome, l.moto_id, m.titulo, u.nome as vendedor, l.motivo_perda, l.fechado_at FROM crm_leads l LEFT JOIN motos m ON l.moto_id=m.id LEFT JOIN users u ON l.vendedor_id=u.id WHERE l.etapa='perdido' AND l.fechado_at BETWEEN ? AND ?";
    if ($where_vendor) $sql .= " AND l.$where_vendor";
    $sql .= " ORDER BY l.fechado_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      fputcsv($output, [
        $row['nome'],
        $row['titulo'] ?: '',
        $row['vendedor'] ?: '',
        $row['motivo_perda'] ?: '',
        date('d/m/Y', strtotime($row['fechado_at']))
      ], ';');
    }
    $success = true;
    break;

  case 'origens':
    fputcsv($output, ['Origem', 'Leads', 'Fechados', 'Conversão %', 'R$ Fechado'], ';');

    $sql = "SELECT origem, COUNT(*) c, SUM(CASE WHEN etapa='fechado' THEN 1 ELSE 0 END) f, COALESCE(SUM(CASE WHEN etapa='fechado' THEN valor_negociado ELSE 0 END), 0) v FROM crm_leads WHERE created_at BETWEEN ? AND ?";
    if ($where_vendor) $sql .= " AND $where_vendor";
    $sql .= " GROUP BY origem ORDER BY c DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $conv = ($row['c'] > 0) ? round(($row['f'] / $row['c']) * 100, 1) : 0;
      fputcsv($output, [
        $row['origem'],
        $row['c'],
        $row['f'] ?: 0,
        $conv . '%',
        number_format($row['v'], 2, ',', '')
      ], ';');
    }
    $success = true;
    break;

  case 'campanhas':
    fputcsv($output, ['Campanha', 'Source / Medium', 'Leads', 'Fechados', 'R$ Fechado'], ';');

    $sql = "SELECT COALESCE(utm_campaign, '(sem campanha)') c, CONCAT(COALESCE(utm_source, '?'), ' / ', COALESCE(utm_medium, '?')) sm, COUNT(*) leads, SUM(CASE WHEN etapa='fechado' THEN 1 ELSE 0 END) f, COALESCE(SUM(CASE WHEN etapa='fechado' THEN valor_negociado ELSE 0 END), 0) v FROM crm_leads WHERE created_at BETWEEN ? AND ?";
    if ($where_vendor) $sql .= " AND $where_vendor";
    $sql .= " GROUP BY utm_campaign, utm_source, utm_medium ORDER BY v DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$deDT, $ateDT], $params_vendor));

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      fputcsv($output, [
        $row['c'],
        $row['sm'],
        $row['leads'],
        $row['f'] ?: 0,
        number_format($row['v'], 2, ',', '')
      ], ';');
    }
    $success = true;
    break;

  case 'demanda':
    fputcsv($output, ['Modelo', 'Pedidos (período)', 'Em Estoque', 'Diferença'], ';');

    $sql = "SELECT UPPER(COALESCE(NULLIF(modelo,''), 'Outro')) m, COUNT(*) pedidos FROM crm_interesses WHERE created_at BETWEEN ? AND ? GROUP BY modelo ORDER BY pedidos DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$deDT, $ateDT]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $stmt_est = $pdo->prepare("SELECT COUNT(*) FROM motos WHERE status='disponivel' AND modelo LIKE ? LIMIT 1");
      $stmt_est->execute(['%' . $row['m'] . '%']);
      $estoque = (int)$stmt_est->fetchColumn();
      $diff = $row['pedidos'] - $estoque;

      fputcsv($output, [
        $row['m'],
        $row['pedidos'],
        $estoque,
        ($diff > 0 ? '+' : '') . $diff
      ], ';');
    }
    $success = true;
    break;
}

fclose($output);
exit;
