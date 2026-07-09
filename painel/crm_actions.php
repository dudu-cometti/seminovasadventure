<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

// Parse input (JSON ou POST)
$input = $_POST;
if (empty($input) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
  $input = json_decode(file_get_contents('php://input'), true) ?: [];
}

// CSRF validation (skip para algumas ações que não modificam)
$acao = $input['acao'] ?? '';
$skipCsrf = in_array($acao, ['buscar_lead']);
if (!$skipCsrf) {
  $csrf = $input['_csrf'] ?? '';
  if ($csrf !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'CSRF token inválido']);
    exit;
  }
}

ensure_crm_schema($pdo);

$resp = ['ok' => false, 'msg' => 'Ação inválida'];

try {
  switch ($acao) {
    case 'mover':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      $etapa = $_POST['etapa'] ?? '';
      if ($lead_id <= 0) throw new Exception('Lead inválido');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      if ($etapa === 'perdido') {
        $motivo = $_POST['motivo_perda'] ?? '';
        if (empty($motivo)) throw new Exception('Motivo é obrigatório');
        crm_lead_move($pdo, $lead_id, $etapa, current_user()['id'], ['motivo_perda' => $motivo, 'motivo_perda_obs' => $_POST['motivo_perda_obs'] ?? '']);
      } else {
        crm_lead_move($pdo, $lead_id, $etapa, current_user()['id']);
      }

      if ($etapa === 'fechado') {
        $valor = $_POST['valor_negociado'] ?? null;
        if ($valor !== null && $valor !== '') {
          $valor = (float)str_replace(['.', ','], ['', '.'], $valor);
          $upd = $pdo->prepare("UPDATE crm_leads SET valor_negociado=? WHERE id=?");
          $upd->execute([$valor, $lead_id]);
        }
      }

      $resp = ['ok' => true, 'msg' => 'Lead movido com sucesso'];
      break;

    case 'nova_interacao':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      $tipo = $_POST['tipo'] ?? 'nota';
      $texto = trim($_POST['texto'] ?? '');
      if ($lead_id <= 0 || empty($texto)) throw new Exception('Dados inválidos');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      crm_registrar_interacao($pdo, $lead_id, $tipo, $texto, current_user()['id']);
      $resp = ['ok' => true, 'msg' => 'Interação registrada'];
      break;

    case 'temperatura':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      $temp = $_POST['temperatura'] ?? 'morno';
      if ($lead_id <= 0) throw new Exception('Lead inválido');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      $upd = $pdo->prepare("UPDATE crm_leads SET temperatura=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$temp, $lead_id]);
      crm_registrar_interacao($pdo, $lead_id, 'sistema', 'Temperatura alterada para ' . $temp . ' por ' . current_user()['nome']);

      $resp = ['ok' => true, 'msg' => 'Temperatura atualizada'];
      break;

    case 'atribuir_vendedor':
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      $vendedor_id = (int)($_POST['vendedor_id'] ?? 0);
      if ($lead_id <= 0) throw new Exception('Lead inválido');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      $user = current_user();
      if ($user['role'] === 'vendedor' && $vendedor_id !== (int)$user['id']) {
        throw new Exception('Vendedor pode atribuir só para si mesmo');
      }

      $upd = $pdo->prepare("UPDATE crm_leads SET vendedor_id=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$vendedor_id ?: null, $lead_id]);

      $v_nome = '';
      if ($vendedor_id > 0) {
        $v_stmt = $pdo->prepare("SELECT nome FROM users WHERE id=?");
        $v_stmt->execute([$vendedor_id]);
        $v_nome = $v_stmt->fetchColumn() ?: '';
      }
      crm_registrar_interacao($pdo, $lead_id, 'sistema', 'Vendedor atribuído: ' . ($v_nome ?: 'Sem atribuição'));

      $resp = ['ok' => true, 'msg' => 'Vendedor atribuído'];
      break;

    case 'valor_negociado':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      $valor = (float)str_replace(['.', ','], ['', '.'], $_POST['valor'] ?? '0');
      if ($lead_id <= 0) throw new Exception('Lead inválido');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      $upd = $pdo->prepare("UPDATE crm_leads SET valor_negociado=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$valor ?: null, $lead_id]);

      $resp = ['ok' => true, 'msg' => 'Valor atualizado'];
      break;

    case 'trocar_moto':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      $moto_id = (int)($_POST['moto_id'] ?? 0);
      if ($lead_id <= 0) throw new Exception('Lead inválido');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      if ($moto_id > 0) {
        $m_stmt = $pdo->prepare("SELECT id, titulo FROM motos WHERE id=? AND status IN ('disponivel','reservada')");
        $m_stmt->execute([$moto_id]);
        if (!$m_stmt->fetch()) throw new Exception('Moto não disponível');
      }

      $upd = $pdo->prepare("UPDATE crm_leads SET moto_id=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$moto_id ?: null, $lead_id]);

      $resp = ['ok' => true, 'msg' => 'Moto alterada'];
      break;

    case 'salvar_interesse':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      $marca = trim($_POST['marca'] ?? '');
      $modelo = trim($_POST['modelo'] ?? '');
      if ($lead_id <= 0) throw new Exception('Lead inválido');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      $stmt = $pdo->prepare("INSERT INTO crm_interesses (lead_id, marca, modelo, ano_min, ano_max, valor_max, km_max, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([
        $lead_id,
        $marca ?: null,
        $modelo ?: null,
        (int)($_POST['ano_min'] ?? 0) ?: null,
        (int)($_POST['ano_max'] ?? 0) ?: null,
        (float)str_replace(['.', ','], ['', '.'], $_POST['valor_max'] ?? '0') ?: null,
        (int)($_POST['km_max'] ?? 0) ?: null,
        trim($_POST['observacao'] ?? '')
      ]);

      $resp = ['ok' => true, 'msg' => 'Interesse registrado', 'id' => $pdo->lastInsertId()];
      break;

    case 'excluir_interesse':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $interest_id = (int)($_POST['interest_id'] ?? 0);
      if ($interest_id <= 0) throw new Exception('Interesse inválido');

      $int_stmt = $pdo->prepare("SELECT lead_id FROM crm_interesses WHERE id=?");
      $int_stmt->execute([$interest_id]);
      $lead_id = $int_stmt->fetchColumn();

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      $del = $pdo->prepare("DELETE FROM crm_interesses WHERE id=?");
      $del->execute([$interest_id]);

      $resp = ['ok' => true, 'msg' => 'Interesse excluído'];
      break;

    case 'novo_agendamento':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      $tipo = $_POST['tipo'] ?? 'ligacao';
      $data_hora = $_POST['data_hora'] ?? '';
      if ($lead_id <= 0 || empty($data_hora)) throw new Exception('Dados inválidos');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      $stmt = $pdo->prepare("INSERT INTO crm_agendamentos (lead_id, vendedor_id, tipo, data_hora, observacao) VALUES (?, ?, ?, ?, ?)");
      $stmt->execute([
        $lead_id,
        current_user()['id'],
        $tipo,
        $data_hora,
        trim($_POST['observacao'] ?? '')
      ]);

      $resp = ['ok' => true, 'msg' => 'Agendamento criado', 'id' => $pdo->lastInsertId()];
      break;

    case 'status_agendamento':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $agenda_id = (int)($_POST['agenda_id'] ?? 0);
      $status = $_POST['status'] ?? 'pendente';
      if ($agenda_id <= 0) throw new Exception('Agendamento inválido');

      $ag_stmt = $pdo->prepare("SELECT lead_id FROM crm_agendamentos WHERE id=?");
      $ag_stmt->execute([$agenda_id]);
      $lead_id = $ag_stmt->fetchColumn();

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      $upd = $pdo->prepare("UPDATE crm_agendamentos SET status=? WHERE id=?");
      $upd->execute([$status, $agenda_id]);

      $resp = ['ok' => true, 'msg' => 'Agendamento atualizado'];
      break;

    case 'checar_telefone':
      $telefone = trim($_POST['telefone'] ?? '');
      if (empty($telefone)) {
        $resp = ['ok' => true, 'existe' => false];
        break;
      }

      $tel_norm = crm_normaliza_telefone($telefone);
      $stmt = $pdo->prepare("SELECT id FROM crm_leads WHERE telefone=? OR telefone LIKE ? LIMIT 1");
      $stmt->execute([$tel_norm, '%' . $tel_norm . '%']);
      $lead_existente = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($lead_existente) {
        $resp = ['ok' => true, 'existe' => true, 'lead_id' => (int)$lead_existente['id']];
      } else {
        $resp = ['ok' => true, 'existe' => false];
      }
      break;

    case 'importar_vendas':
      if ($GLOBALS['pdo']->query("SELECT user FROM information_schema.PROCESSLIST")->rowCount() > 0) {
        require_role('gerente');
      }
      $importados = crm_import_vendas($pdo, current_user()['id']);
      $resp = ['ok' => true, 'msg' => 'Importados ' . $importados . ' compradores', 'importados' => $importados];
      break;

    case 'criar_lead':
      $nome = trim($_POST['nome'] ?? '');
      $telefone = trim($_POST['telefone'] ?? '');
      if (empty($nome) || empty($telefone)) throw new Exception('Nome e telefone obrigatórios');

      $tel_norm = crm_normaliza_telefone($telefone);
      $stmt = $pdo->prepare("INSERT INTO crm_leads (nome, telefone, email, moto_id, vendedor_id, origem, temperatura, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([
        $nome,
        $tel_norm,
        $_POST['email'] ?? null,
        (int)($_POST['moto_id'] ?? 0) ?: null,
        (int)($_POST['vendedor_id'] ?? current_user()['id']),
        $_POST['origem'] ?? 'manual',
        $_POST['temperatura'] ?? 'morno',
        current_user()['id']
      ]);

      $lead_id = $pdo->lastInsertId();
      if (!empty($_POST['observacao'])) {
        crm_registrar_interacao($pdo, $lead_id, 'nota', $_POST['observacao'], current_user()['id']);
      }

      $resp = ['ok' => true, 'msg' => 'Lead criado', 'lead_id' => $lead_id];
      break;

    case 'editar_lead':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      if ($lead_id <= 0) throw new Exception('Lead inválido');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');
      if (!crm_pode_ver_lead(current_user(), $lead)) throw new Exception('Sem acesso a este lead');

      $stmt = $pdo->prepare("UPDATE crm_leads SET nome=?, email=?, updated_at=NOW() WHERE id=?");
      $stmt->execute([
        trim($_POST['nome'] ?? $lead['nome']),
        $_POST['email'] ?? $lead['email'],
        $lead_id
      ]);

      $resp = ['ok' => true, 'msg' => 'Lead atualizado'];
      break;

    case 'excluir_lead':
      require_role('gerente');
      $lead_id = (int)($_POST['lead_id'] ?? 0);
      if ($lead_id <= 0) throw new Exception('Lead inválido');
      if (!user_can('delete')) throw new Exception('Sem permissão');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');

      $del = $pdo->prepare("DELETE FROM crm_leads WHERE id=?");
      $del->execute([$lead_id]);

      $resp = ['ok' => true, 'msg' => 'Lead excluído'];
      break;

    case 'buscar_lead':
      if (!user_can('view')) throw new Exception('Sem permissão');
      $q = trim($input['q'] ?? '');
      if (strlen($q) < 2) throw new Exception('Mínimo 2 caracteres');

      $user = current_user();
      $where = "nome LIKE ? OR telefone LIKE ?";
      $params = ['%' . $q . '%', '%' . preg_replace('/\D/', '', $q) . '%'];

      if ($user['role'] !== 'gerente') {
        $where .= " AND vendedor_id = ?";
        $params[] = $user['id'];
      }

      $stmt = $pdo->prepare("SELECT id, nome, telefone FROM crm_leads WHERE {$where} LIMIT 10");
      $stmt->execute($params);
      $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $resp = ['ok' => true, 'leads' => $leads];
      break;

    case 'criar_agendamento':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $lead_id = (int)($input['lead_id'] ?? 0);
      $tipo = $input['tipo'] ?? '';
      $data_hora = $input['data_hora'] ?? '';
      $observacao = $input['observacao'] ?? '';
      $vendedor_id = !empty($input['vendedor_id']) ? (int)$input['vendedor_id'] : null;

      if ($lead_id <= 0) throw new Exception('Lead inválido');
      if (empty($tipo) || !in_array($tipo, ['ligacao','visita','test_ride','entrega','outro'])) throw new Exception('Tipo inválido');
      if (empty($data_hora)) throw new Exception('Data e hora obrigatórias');

      $lead = crm_lead_get($pdo, $lead_id);
      if (!$lead) throw new Exception('Lead não encontrado');

      $user = current_user();
      if (!crm_pode_ver_lead($user, $lead)) throw new Exception('Sem acesso a este lead');
      if ($user['role'] !== 'gerente' && $vendedor_id && $vendedor_id !== $user['id']) {
        throw new Exception('Só o gerente pode atribuir agendamentos a outros vendedores');
      }

      if (!$vendedor_id && $user['role'] === 'vendedor') {
        $vendedor_id = $user['id'];
      }

      $stmt = $pdo->prepare("INSERT INTO crm_agendamentos (lead_id, tipo, data_hora, observacao, vendedor_id, status, created_at)
                             VALUES (?, ?, ?, ?, ?, 'pendente', NOW())");
      $stmt->execute([$lead_id, $tipo, $data_hora, $observacao, $vendedor_id]);

      $resp = ['ok' => true, 'msg' => 'Agendamento criado'];
      break;

    case 'status_agendamento':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $ag_id = (int)($input['agendamento_id'] ?? 0);
      $status = $input['status'] ?? '';
      $obs_realizado = trim($input['observacao_realizado'] ?? '');

      if ($ag_id <= 0) throw new Exception('Agendamento inválido');
      if (!in_array($status, ['realizado','cancelado'])) throw new Exception('Status inválido');

      $stmt = $pdo->prepare("SELECT ca.*, cl.id AS lead_id FROM crm_agendamentos ca
                             JOIN crm_leads cl ON ca.lead_id = cl.id WHERE ca.id=?");
      $stmt->execute([$ag_id]);
      $ag = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$ag) throw new Exception('Agendamento não encontrado');

      $user = current_user();
      if ($user['role'] !== 'gerente' && $ag['vendedor_id'] != $user['id']) {
        throw new Exception('Sem acesso a este agendamento');
      }

      $pdo->prepare("UPDATE crm_agendamentos SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $ag_id]);

      if ($status === 'realizado' && !empty($obs_realizado)) {
        $tipos_validos = ['ligacao', 'visita', 'test_ride', 'entrega'];
        $tipo_interacao = in_array($ag['tipo'], $tipos_validos) ? $ag['tipo'] : 'interacao';
        crm_registrar_interacao($pdo, $ag['lead_id'], $tipo_interacao, $obs_realizado);
      }

      $resp = ['ok' => true, 'msg' => 'Agendamento atualizado'];
      break;

    case 'reagendar_agendamento':
      if (!user_can('edit')) throw new Exception('Sem permissão');
      $ag_id = (int)($input['agendamento_id'] ?? 0);
      $data_hora = $input['data_hora'] ?? '';

      if ($ag_id <= 0) throw new Exception('Agendamento inválido');
      if (empty($data_hora)) throw new Exception('Data e hora obrigatórias');

      $stmt = $pdo->prepare("SELECT vendedor_id FROM crm_agendamentos WHERE id=?");
      $stmt->execute([$ag_id]);
      $ag = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$ag) throw new Exception('Agendamento não encontrado');

      $user = current_user();
      if ($user['role'] !== 'gerente' && $ag['vendedor_id'] != $user['id']) {
        throw new Exception('Sem acesso a este agendamento');
      }

      $pdo->prepare("UPDATE crm_agendamentos SET data_hora=?, updated_at=NOW() WHERE id=?")->execute([$data_hora, $ag_id]);

      $resp = ['ok' => true, 'msg' => 'Agendamento reagendado'];
      break;
  }
} catch (Exception $e) {
  $resp = ['ok' => false, 'msg' => $e->getMessage()];
}

echo json_encode($resp, JSON_UNESCAPED_UNICODE);
exit;
