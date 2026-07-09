<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';

require_login();
$page_title = 'Agenda de Agendamentos';

$user = current_user();
$role = $user['role'] ?? '';
$isGerente = ($role === 'gerente');
$userId = (int)($user['id'] ?? 0);

ensure_crm_schema($pdo);

// Visão: dia|semana
$view = $_GET['v'] ?? 'dia';
if (!in_array($view, ['dia', 'semana'])) $view = 'dia';

// Data base (GET date ou hoje)
$dateParam = $_GET['d'] ?? date('Y-m-d');
try {
  $date = new DateTime($dateParam);
} catch (Exception $e) {
  $date = new DateTime('now');
}
$dateStr = $date->format('Y-m-d');

// Para visão semana: segunda-feira da semana
if ($view === 'semana') {
  $dow = (int)$date->format('N'); // 1=seg, 7=dom
  if ($dow > 1) {
    $date->modify('-' . ($dow - 1) . ' days');
  }
  $dateStr = $date->format('Y-m-d');
}

// Monta query base com visibilidade
$where = "1=1";
$params = [];
if (!$isGerente) {
  $where .= " AND (crm_agendamentos.vendedor_id = ? OR crm_agendamentos.vendedor_id IS NULL)";
  $params[] = $userId;
}

// Query agendamentos
if ($view === 'dia') {
  // Dia: agendamentos deste dia + atrasados de antes
  $sql = "SELECT ca.*, cl.nome AS lead_nome, cl.id AS lead_id, cl.moto_id, m.titulo AS moto_titulo, u.nome AS vendedor_nome
          FROM crm_agendamentos ca
          LEFT JOIN crm_leads cl ON ca.lead_id = cl.id
          LEFT JOIN motos m ON cl.moto_id = m.id
          LEFT JOIN users u ON ca.vendedor_id = u.id
          WHERE {$where} AND (DATE(ca.data_hora) <= ? AND ca.status = 'pendente')
          ORDER BY (CASE WHEN DATE(ca.data_hora) < ? THEN 0 ELSE 1 END), ca.data_hora ASC";

  $queryParams = array_merge($params, [$dateStr, $dateStr]);
  $stmt = $pdo->prepare($sql);
  $stmt->execute($queryParams);
  $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Separa atrasados vs hoje
  $atrasados = [];
  $hoje = [];
  $now = new DateTime();
  foreach ($agendamentos as $ag) {
    $agDateTime = new DateTime($ag['data_hora']);
    if ($agDateTime < $now) {
      $atrasados[] = $ag;
    } else if (substr($ag['data_hora'], 0, 10) === $dateStr) {
      $hoje[] = $ag;
    }
  }
} else {
  // Semana: agendamentos de 7 dias + atrasados antes da seg
  $endDate = clone $date;
  $endDate->modify('+6 days');
  $endDateStr = $endDate->format('Y-m-d');

  $sql = "SELECT ca.*, cl.nome AS lead_nome, cl.id AS lead_id, cl.moto_id, m.titulo AS moto_titulo, u.nome AS vendedor_nome
          FROM crm_agendamentos ca
          LEFT JOIN crm_leads cl ON ca.lead_id = cl.id
          LEFT JOIN motos m ON cl.moto_id = m.id
          LEFT JOIN users u ON ca.vendedor_id = u.id
          WHERE {$where} AND (DATE(ca.data_hora) <= ? AND ca.status = 'pendente')
          ORDER BY ca.data_hora ASC";

  $queryParams = array_merge($params, [$endDateStr]);
  $stmt = $pdo->prepare($sql);
  $stmt->execute($queryParams);
  $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Agrupa por dia para semana
  $semanaData = [];
  for ($i = 0; $i < 7; $i++) {
    $d = clone $date;
    $d->modify("+{$i} days");
    $semanaData[$d->format('Y-m-d')] = [];
  }

  $atrasados = [];
  $now = new DateTime();
  foreach ($agendamentos as $ag) {
    $agDateTime = new DateTime($ag['data_hora']);
    $agDate = substr($ag['data_hora'], 0, 10);

    if ($agDateTime < $now) {
      $atrasados[] = $ag;
    } elseif (isset($semanaData[$agDate])) {
      $semanaData[$agDate][] = $ag;
    }
  }
}
?>
<?php include __DIR__ . '/../inc/header.php'; ?>

<style>
  .agenda-header {
    display: flex; gap: var(--space-4); align-items: center; justify-content: space-between;
    margin-bottom: var(--space-6);
  }
  .agenda-tabs {
    display: flex; gap: 0; border-bottom: 1px solid var(--border);
  }
  .agenda-tab {
    padding: var(--space-3) var(--space-4); cursor: pointer; border: none; background: none;
    font-weight: 600; font-size: 14px; color: var(--text-muted);
    border-bottom: 3px solid transparent; transition: all var(--t-fast);
  }
  .agenda-tab.active {
    color: var(--text); border-bottom-color: var(--brand);
  }

  .agenda-nav {
    display: flex; gap: 8px; align-items: center;
  }
  .agenda-nav input[type="date"] {
    padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px;
    font-size: 14px; cursor: pointer;
  }
  .agenda-nav button {
    width: 36px; height: 36px; border: 1px solid var(--border); border-radius: 6px;
    background: var(--surface); cursor: pointer; font-weight: 700; font-size: 16px;
  }
  .agenda-nav button:hover { background: var(--surface-hover); }

  .agenda-title {
    font-size: 18px; font-weight: 700; color: var(--text);
  }
  .agenda-subtitle {
    font-size: 13px; color: var(--text-muted);
  }

  .agenda-item {
    padding: var(--space-3); border: 1px solid var(--border); border-radius: 8px;
    background: var(--surface); margin-bottom: var(--space-2);
    display: grid; grid-template-columns: 80px 1fr auto; gap: var(--space-3); align-items: start;
  }
  .agenda-hora {
    font-family: var(--font-mono); font-size: 18px; font-weight: 700; color: var(--brand);
  }
  .agenda-info {
    display: flex; flex-direction: column; gap: 6px;
  }
  .agenda-tipo {
    display: inline-flex; align-items: center; gap: 4px; width: fit-content;
    padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;
    background: var(--bg-secondary); color: var(--text);
  }
  .agenda-lead {
    font-weight: 600; color: var(--brand);
  }
  .agenda-moto {
    font-size: 13px; color: var(--text-muted); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .agenda-obs {
    font-size: 13px; color: var(--text-muted); max-width: 400px;
  }
  .agenda-vendedor {
    font-size: 11px; background: var(--bg-tertiary); color: var(--text-muted);
    padding: 2px 6px; border-radius: 3px; width: fit-content;
  }
  .agenda-actions {
    display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end;
  }
  .agenda-actions button {
    padding: 6px 12px; border-radius: 4px; border: none; font-size: 12px; cursor: pointer;
    font-weight: 600; transition: all var(--t-fast);
  }
  .agenda-btn-ok { background: #10b981; color: white; }
  .agenda-btn-ok:hover { background: #059669; }
  .agenda-btn-cancel { background: #ef4444; color: white; }
  .agenda-btn-cancel:hover { background: #dc2626; }
  .agenda-btn-reagendar { background: var(--bg-secondary); color: var(--text); }
  .agenda-btn-reagendar:hover { background: var(--bg-tertiary); }

  .agenda-atrasados {
    background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;
    padding: var(--space-4); margin-bottom: var(--space-6);
  }
  .agenda-atrasados-title {
    font-weight: 700; color: #dc2626; margin-bottom: var(--space-3);
    display: flex; align-items: center; gap: 6px;
  }

  .agenda-semana {
    display: grid; grid-template-columns: repeat(7, 1fr); gap: var(--space-3);
  }
  .agenda-col {
    border: 1px solid var(--border); border-radius: 8px; background: var(--surface);
    padding: var(--space-3); min-height: 200px;
  }
  .agenda-col.hoje {
    border-color: var(--brand); background: var(--bg-secondary);
  }
  .agenda-col-header {
    font-weight: 700; font-size: 14px; margin-bottom: var(--space-2);
  }
  .agenda-col-dia {
    font-size: 12px; color: var(--text-muted);
  }
  .agenda-col-count {
    background: var(--brand); color: white; padding: 2px 6px;
    border-radius: 12px; font-size: 10px; font-weight: 700;
  }
  .agenda-col-item {
    font-size: 12px; padding: 6px; background: white; border-radius: 4px;
    margin: 4px 0; cursor: pointer; border: 1px solid var(--border);
  }
  .agenda-col-item:hover { border-color: var(--brand); background: var(--bg-secondary); }

  @media (max-width: 920px) {
    .agenda-semana { grid-template-columns: 1fr; }
    .agenda-item { grid-template-columns: 1fr; }
  }
</style>

<main class="container crm-main" style="max-width: 1200px;">
  <div class="crm-header">
    <h1>Agenda de Agendamentos</h1>
  </div>

  <!-- Tabs -->
  <div class="agenda-tabs">
    <button class="agenda-tab <?= ($view === 'dia') ? 'active' : '' ?>" onclick="location.href='<?= base_url('painel/crm_agenda.php?v=dia&d=' . $dateStr) ?>'">
      📅 Por Dia
    </button>
    <button class="agenda-tab <?= ($view === 'semana') ? 'active' : '' ?>" onclick="location.href='<?= base_url('painel/crm_agenda.php?v=semana&d=' . $dateStr) ?>'">
      📊 Por Semana
    </button>
  </div>

  <!-- Navegação e controles -->
  <div class="agenda-header" style="margin-top: var(--space-4);">
    <div>
      <div class="agenda-title">
        <?php if ($view === 'dia'): ?>
          <?= $date->format('d \d\e F \d\e Y') ?>
        <?php else: ?>
          Semana de <?= $date->format('d \d\e F') ?> a <?php $endDate = clone $date; $endDate->modify('+6 days'); echo $endDate->format('d \d\e F \d\e Y'); ?>
        <?php endif; ?>
      </div>
      <div class="agenda-subtitle">
        <?php if ($view === 'dia'): ?>
          <?= $date->format('l') ?>
        <?php else: ?>
          7 dias
        <?php endif; ?>
      </div>
    </div>
    <div class="agenda-nav">
      <button onclick="navData(-1)" title="Anterior">&larr;</button>
      <input type="date" id="inputData" value="<?= $dateStr ?>" onchange="navDataDirecto(this.value)">
      <button onclick="navData(1)" title="Próximo">&rarr;</button>
      <button onclick="navHoje()" style="margin-left:var(--space-2);" title="Hoje">Hoje</button>
    </div>
  </div>

  <button class="btn btn-primary" style="margin-bottom: var(--space-6);" onclick="abrirModalNovoAgendamento()">
    + Agendamento
  </button>

  <?php if ($view === 'dia'): ?>
    <!-- VISÃO DIA -->

    <?php if (!empty($atrasados)): ?>
    <div class="agenda-atrasados">
      <div class="agenda-atrasados-title">
        ⚠️ <?= count($atrasados) ?> Atrasado(s)
      </div>
      <?php foreach ($atrasados as $ag): ?>
        <div class="agenda-item">
          <div class="agenda-hora"><?= substr($ag['data_hora'], 11, 5) ?></div>
          <div class="agenda-info">
            <div>
              <span class="agenda-tipo"><?= getAgendamentoIcon($ag['tipo']) ?> <?= htmlspecialchars($ag['tipo']) ?></span>
              <div style="margin-top:4px;">
                <a href="<?= base_url('painel/crm_lead.php?id=' . $ag['lead_id']) ?>" class="agenda-lead"><?= htmlspecialchars($ag['lead_nome']) ?></a>
                <?php if (!empty($ag['moto_titulo'])): ?>
                <div class="agenda-moto"><?= htmlspecialchars($ag['moto_titulo']) ?></div>
                <?php endif; ?>
              </div>
              <?php if (!empty($ag['observacao'])): ?>
              <div class="agenda-obs"><?= htmlspecialchars(substr($ag['observacao'], 0, 80)) ?></div>
              <?php endif; ?>
              <div class="agenda-vendedor"><?= htmlspecialchars($ag['vendedor_nome'] ?? 'S/ vendedor') ?></div>
            </div>
          </div>
          <div class="agenda-actions">
            <button class="agenda-btn-ok" onclick="marcarRealizado(<?= $ag['id'] ?>)">✓ Realizado</button>
            <button class="agenda-btn-cancel" onclick="cancelarAgendamento(<?= $ag['id'] ?>)">✕ Cancelar</button>
            <button class="agenda-btn-reagendar" onclick="abrirReagendarAgendamento(<?= $ag['id'] ?>, '<?= htmlspecialchars($ag['data_hora']) ?>')">↻ Reagendar</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($hoje as $ag): ?>
    <div class="agenda-item">
      <div class="agenda-hora"><?= substr($ag['data_hora'], 11, 5) ?></div>
      <div class="agenda-info">
        <div>
          <span class="agenda-tipo"><?= getAgendamentoIcon($ag['tipo']) ?> <?= htmlspecialchars($ag['tipo']) ?></span>
          <div style="margin-top:4px;">
            <a href="<?= base_url('painel/crm_lead.php?id=' . $ag['lead_id']) ?>" class="agenda-lead"><?= htmlspecialchars($ag['lead_nome']) ?></a>
            <?php if (!empty($ag['moto_titulo'])): ?>
            <div class="agenda-moto"><?= htmlspecialchars($ag['moto_titulo']) ?></div>
            <?php endif; ?>
          </div>
          <?php if (!empty($ag['observacao'])): ?>
          <div class="agenda-obs"><?= htmlspecialchars(substr($ag['observacao'], 0, 80)) ?></div>
          <?php endif; ?>
          <div class="agenda-vendedor"><?= htmlspecialchars($ag['vendedor_nome'] ?? 'S/ vendedor') ?></div>
        </div>
      </div>
      <div class="agenda-actions">
        <button class="agenda-btn-ok" onclick="marcarRealizado(<?= $ag['id'] ?>)">✓ Realizado</button>
        <button class="agenda-btn-cancel" onclick="cancelarAgendamento(<?= $ag['id'] ?>)">✕ Cancelar</button>
        <button class="agenda-btn-reagendar" onclick="abrirReagendarAgendamento(<?= $ag['id'] ?>, '<?= htmlspecialchars($ag['data_hora']) ?>')">↻ Reagendar</button>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($atrasados) && empty($hoje)): ?>
    <div style="text-align:center;padding:var(--space-6);color:var(--text-muted);">
      <p>Nenhum agendamento para hoje.</p>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- VISÃO SEMANA -->
    <div class="agenda-semana">
      <?php
      $daysOfWeek = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
      for ($i = 0; $i < 7; $i++):
        $d = clone $date;
        $d->modify("+{$i} days");
        $dStr = $d->format('Y-m-d');
        $items = $semanaData[$dStr] ?? [];
        $isToday = ($dStr === date('Y-m-d'));
        $dayNum = $d->format('d');
      ?>
      <div class="agenda-col <?= $isToday ? 'hoje' : '' ?>">
        <div class="agenda-col-header">
          <div><?= ucfirst($daysOfWeek[$i]) ?></div>
          <div class="agenda-col-dia"><?= $dayNum ?></div>
          <?php if (!empty($items)): ?>
          <span class="agenda-col-count"><?= count($items) ?></span>
          <?php endif; ?>
        </div>
        <?php foreach ($items as $ag): ?>
        <div class="agenda-col-item" onclick="location.href='<?= base_url('painel/crm_agenda.php?v=dia&d=' . $dStr) ?>'">
          <strong><?= substr($ag['data_hora'], 11, 5) ?></strong> · <?= getAgendamentoIcon($ag['tipo']) ?>
          <br><?= htmlspecialchars(substr($ag['lead_nome'], 0, 20)) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</main>

<!-- Modal Novo Agendamento -->
<div id="modalNovoAgendamento" class="crm-modal" style="display:none;">
  <div class="crm-modal-inner">
    <div class="crm-modal-header">
      <h2>Novo Agendamento</h2>
      <button class="crm-modal-close" onclick="fecharModalNovoAgendamento()">✕</button>
    </div>
    <div class="crm-modal-body">
      <form id="formNovoAgendamento" onsubmit="salvarNovoAgendamento(event)">
        <div class="field mb-3">
          <label for="agend_lead">Lead *</label>
          <input type="text" id="agend_lead" placeholder="Buscar por nome ou telefone" required>
          <input type="hidden" id="agend_lead_id" name="lead_id">
          <div id="agend_lead_lista" style="display:none;position:absolute;background:white;border:1px solid var(--border);border-radius:6px;max-height:200px;overflow-y:auto;z-index:10;margin-top:4px;width:100%;">
          </div>
        </div>

        <div class="field mb-3">
          <label for="agend_tipo">Tipo *</label>
          <select id="agend_tipo" name="tipo" required>
            <option value="">Selecionar...</option>
            <option value="ligacao">📞 Ligação</option>
            <option value="visita">🏬 Visita</option>
            <option value="test_ride">🏍 Test Ride</option>
            <option value="entrega">🔑 Entrega</option>
            <option value="outro">❓ Outro</option>
          </select>
        </div>

        <div class="field mb-3">
          <label for="agend_data_hora">Data e Hora *</label>
          <input type="datetime-local" id="agend_data_hora" name="data_hora" required value="<?= date('Y-m-d\TH:i') ?>">
        </div>

        <div class="field mb-3">
          <label for="agend_obs">Observação</label>
          <textarea id="agend_obs" name="observacao" rows="3" placeholder="Anotações..."></textarea>
        </div>

        <?php if ($isGerente): ?>
        <div class="field mb-3">
          <label for="agend_vendedor">Vendedor</label>
          <select id="agend_vendedor" name="vendedor_id">
            <option value="">Sem vendedor</option>
            <?php
            $stmt = $pdo->prepare("SELECT id, nome FROM users WHERE role='vendedor' ORDER BY nome");
            $stmt->execute();
            foreach ($stmt as $v):
            ?>
            <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Criar Agendamento</button>
      </form>
    </div>
  </div>
</div>

<script>
const dateView = '<?= $view ?>';
const currentDate = new Date('<?= $dateStr ?>T00:00:00');

function navData(dias) {
  const d = new Date(currentDate);
  d.setDate(d.getDate() + dias);
  location.href = '<?= base_url('painel/crm_agenda.php') ?>?v=' + dateView + '&d=' + formatDate(d);
}

function navDataDirecto(val) {
  location.href = '<?= base_url('painel/crm_agenda.php') ?>?v=' + dateView + '&d=' + val;
}

function navHoje() {
  location.href = '<?= base_url('painel/crm_agenda.php') ?>?v=' + dateView;
}

function formatDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return y + '-' + m + '-' + day;
}

function abrirModalNovoAgendamento() {
  document.getElementById('modalNovoAgendamento').style.display = 'flex';
  document.getElementById('agend_lead').focus();
}

function fecharModalNovoAgendamento() {
  document.getElementById('modalNovoAgendamento').style.display = 'none';
  document.getElementById('formNovoAgendamento').reset();
  document.getElementById('agend_lead_id').value = '';
}

// Busca leads via AJAX
document.getElementById('agend_lead')?.addEventListener('input', async function(e) {
  const q = e.target.value.trim();
  if (q.length < 2) {
    document.getElementById('agend_lead_lista').style.display = 'none';
    return;
  }

  const resp = await fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ acao: 'buscar_lead', q })
  });
  const data = await resp.json();

  const lista = document.getElementById('agend_lead_lista');
  lista.innerHTML = '';
  if (data.leads && data.leads.length > 0) {
    data.leads.forEach(lead => {
      const div = document.createElement('div');
      div.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border);';
      div.textContent = lead.nome + ' (' + lead.telefone + ')';
      div.onclick = function() {
        document.getElementById('agend_lead').value = lead.nome + ' (' + lead.telefone + ')';
        document.getElementById('agend_lead_id').value = lead.id;
        lista.style.display = 'none';
      };
      lista.appendChild(div);
    });
    lista.style.display = 'block';
  }
});

function salvarNovoAgendamento(e) {
  e.preventDefault();
  const form = document.getElementById('formNovoAgendamento');
  const leadId = document.getElementById('agend_lead_id').value;

  if (!leadId) {
    alert('Selecione um lead da lista');
    return;
  }

  const fd = new FormData(form);
  const data = {
    acao: 'criar_agendamento',
    lead_id: leadId,
    tipo: fd.get('tipo'),
    data_hora: fd.get('data_hora'),
    observacao: fd.get('observacao'),
    vendedor_id: fd.get('vendedor_id') || null
  };

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(r => r.json())
  .then(resp => {
    if (resp.ok) {
      location.reload();
    } else {
      alert('Erro: ' + (resp.msg || 'Desconhecido'));
    }
  });
}

function marcarRealizado(agId) {
  const obs = prompt('O que aconteceu? (opcional)');
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      acao: 'status_agendamento',
      agendamento_id: agId,
      status: 'realizado',
      observacao_realizado: obs || ''
    })
  })
  .then(r => r.json())
  .then(resp => {
    if (resp.ok) location.reload();
    else alert('Erro: ' + (resp.msg || ''));
  });
}

function cancelarAgendamento(agId) {
  if (!confirm('Tem certeza?')) return;
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      acao: 'status_agendamento',
      agendamento_id: agId,
      status: 'cancelado'
    })
  })
  .then(r => r.json())
  .then(resp => {
    if (resp.ok) location.reload();
    else alert('Erro: ' + (resp.msg || ''));
  });
}

function abrirReagendarAgendamento(agId, currentDH) {
  const newDH = prompt('Nova data e hora (YYYY-MM-DD HH:MM):', currentDH.replace('T', ' ').substring(0, 16));
  if (!newDH) return;

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      acao: 'reagendar_agendamento',
      agendamento_id: agId,
      data_hora: newDH.replace(' ', 'T')
    })
  })
  .then(r => r.json())
  .then(resp => {
    if (resp.ok) location.reload();
    else alert('Erro: ' + (resp.msg || ''));
  });
}

// Fecha modal ao clicar fora
document.getElementById('modalNovoAgendamento')?.addEventListener('click', function(e) {
  if (e.target === this) fecharModalNovoAgendamento();
});
</script>

<?php
function getAgendamentoIcon($tipo) {
  $icones = [
    'ligacao'   => '📞',
    'visita'    => '🏬',
    'test_ride' => '🏍',
    'entrega'   => '🔑',
  ];
  return $icones[$tipo] ?? '❓';
}
?>

<?php include __DIR__ . '/../inc/footer.php'; ?>
