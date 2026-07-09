<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';
require_login();

ensure_crm_schema($pdo);

$user = current_user();
$etapas = crm_etapas();

$filtros = [
  'busca' => $_GET['busca'] ?? '',
  'vendedor' => $_GET['vendedor'] ?? '',
  'temperatura' => $_GET['temperatura'] ?? '',
  'origem' => $_GET['origem'] ?? '',
];

$where = "WHERE 1=1";
$params = [];

if ($user['role'] === 'vendedor') {
  $where .= " AND (l.vendedor_id=? OR l.vendedor_id IS NULL)";
  $params[] = $user['id'];
}

if (!empty($filtros['busca'])) {
  $where .= " AND (l.nome LIKE ? OR l.telefone LIKE ?)";
  $params[] = '%' . $filtros['busca'] . '%';
  $params[] = '%' . $filtros['busca'] . '%';
}

if (!empty($filtros['vendedor']) && $user['role'] === 'gerente') {
  $where .= " AND l.vendedor_id=?";
  $params[] = (int)$filtros['vendedor'];
}

if (!empty($filtros['temperatura'])) {
  $where .= " AND l.temperatura=?";
  $params[] = $filtros['temperatura'];
}

if (!empty($filtros['origem'])) {
  $where .= " AND l.origem=?";
  $params[] = $filtros['origem'];
}

$query = "SELECT l.*, u.nome as vendedor_nome, m.titulo as moto_titulo, m.valor as moto_valor FROM crm_leads l LEFT JOIN users u ON l.vendedor_id=u.id LEFT JOIN motos m ON l.moto_id=m.id " . $where . " ORDER BY l.etapa_desde DESC, l.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$todos_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$leads_por_etapa = [];
foreach ($etapas as $etapa_key => $_) {
  $leads_por_etapa[$etapa_key] = array_filter($todos_leads, fn($l) => $l['etapa'] === $etapa_key);
}

$vendedores = $pdo->query("SELECT id, nome FROM users WHERE role='vendedor' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'CRM';
include __DIR__ . '/../inc/header.php';
?>

<script>
// Funções essenciais que precisam estar disponíveis para onclick
function abrirModalNovoLead() {
  const modal = document.getElementById('modal-novo-lead');
  if (modal) modal.style.display = 'flex';
}
function fecharModalNovoLead() {
  const modal = document.getElementById('modal-novo-lead');
  if (modal) modal.style.display = 'none';
  const form = document.getElementById('form-novo-lead');
  if (form) form.reset();
  const aviso = document.getElementById('dedup-aviso');
  if (aviso) aviso.style.display = 'none';
}
function fecharModalPerda() {
  const modal = document.getElementById('modal-confirma-perda');
  if (modal) modal.style.display = 'none';
}
function fecharModalFechado() {
  const modal = document.getElementById('modal-confirma-fechado');
  if (modal) modal.style.display = 'none';
}
function importarVendas() {
  if (!confirm('Importar compradores do histórico de vendas?')) return;
  alert('Importação em desenvolvimento...');
}
function limparFiltros() {
  window.location = '<?= base_url('painel/crm.php') ?>';
}

// CSRF helper
function addCsrfToken(fd) {
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (token) fd.append('_csrf', token);
  return fd;
}

// Salvar novo lead
function salvarNovoLead(e) {
  e.preventDefault();
  const form = document.getElementById('form-novo-lead');
  if (!form) return;

  const fd = new FormData(form);
  fd.append('acao', 'criar_lead');
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      fecharModalNovoLead();
      window.location.reload();
    } else {
      alert('Erro: ' + d.msg);
    }
  })
  .catch(err => {
    alert('Erro ao criar lead: ' + err.message);
  });
}

// Checar telefone (dedup)
function checarTelefone() {
  const tel = document.getElementById('telefone-modal')?.value;
  if (!tel) return;

  const fd = new FormData();
  fd.append('acao', 'checar_telefone');
  fd.append('telefone', tel);
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.existe) {
      const aviso = document.getElementById('dedup-aviso');
      const link = document.getElementById('dedup-link');
      if (aviso && link) {
        link.href = '<?= base_url('painel/crm_lead.php?id=') ?>' + d.lead_id;
        aviso.style.display = 'block';
      }
    } else {
      const aviso = document.getElementById('dedup-aviso');
      if (aviso) aviso.style.display = 'none';
    }
  })
  .catch(() => {});
}

// Mover lead (drag & drop)
function moverLead(leadId, etapa) {
  const fd = new FormData();
  fd.append('acao', 'mover');
  fd.append('lead_id', leadId);
  fd.append('etapa', etapa);
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      window.location.reload();
    } else {
      alert('Erro: ' + d.msg);
    }
  })
  .catch(err => {
    alert('Erro ao mover: ' + err.message);
  });
}
</script>

<main class="container" style="padding: var(--space-4) 0;">
  <div class="page">

    <div class="crm-header" style="display: flex; gap: var(--space-4); align-items: center; justify-content: space-between; margin-bottom: var(--space-6); flex-wrap: wrap;">
      <h1 class="page-title">Pipeline de Vendas</h1>
      <div style="display: flex; gap: var(--space-2); flex-wrap: wrap;">
        <button class="btn btn-primary" onclick="abrirModalNovoLead()">+ Novo lead</button>
        <?php if ($user['role'] === 'gerente'): ?>
          <button class="btn btn-ghost" onclick="importarVendas()">📥 Importar compradores</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Filtros -->
    <div class="crm-filters" style="background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: var(--space-3); margin-bottom: var(--space-6); display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center;">
      <input type="text" id="filtro-busca" placeholder="Buscar por nome/telefone" value="<?= htmlspecialchars($filtros['busca']) ?>" style="padding: 8px 12px; border: 1px solid var(--line); border-radius: 4px; font-family: Inter, sans-serif;">

      <select id="filtro-vendedor" style="padding: 8px 12px; border: 1px solid var(--line); border-radius: 4px;">
        <option value="">Todos vendedores</option>
        <?php foreach ($vendedores as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= $filtros['vendedor'] === (string)$v['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($v['nome']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select id="filtro-temp" style="padding: 8px 12px; border: 1px solid var(--line); border-radius: 4px;">
        <option value="">Todas temperaturas</option>
        <option value="frio" <?= $filtros['temperatura'] === 'frio' ? 'selected' : '' ?>>❄️ Frio</option>
        <option value="morno" <?= $filtros['temperatura'] === 'morno' ? 'selected' : '' ?>>🌡️ Morno</option>
        <option value="quente" <?= $filtros['temperatura'] === 'quente' ? 'selected' : '' ?>>🔥 Quente</option>
      </select>

      <button class="btn btn-ghost" onclick="limparFiltros()">Limpar</button>
    </div>

    <!-- Kanban Board -->
    <div class="crm-board" style="display: flex; gap: var(--space-4); overflow-x: auto; padding-bottom: var(--space-4);">
      <?php foreach ($etapas as $etapa_key => $etapa_info): ?>
        <div class="crm-coluna" data-etapa="<?= $etapa_key ?>" style="flex: 0 0 360px; background: #f9f8f5; border: 1px solid var(--line); border-radius: 8px; display: flex; flex-direction: column; overflow: hidden;">

          <div class="crm-coluna-header" style="padding: var(--space-3); border-bottom: 2px solid <?= $etapa_info['cor'] ?>; background: white;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
              <h2 style="font-size: 15px; font-weight: 700; color: var(--ink); margin: 0;">
                <?= htmlspecialchars($etapa_info['rótulo']) ?>
              </h2>
              <span class="badge" style="background: <?= $etapa_info['cor'] ?>; color: white; font-size: 11px; padding: 4px 8px; border-radius: 12px;">
                <?= count($leads_por_etapa[$etapa_key]) ?>
              </span>
            </div>
            <?php
              $total_valor = 0;
              foreach ($leads_por_etapa[$etapa_key] as $l) {
                if ($l['valor_negociado'] && $l['valor_negociado'] > 0) {
                  $total_valor += $l['valor_negociado'];
                }
              }
              if ($total_valor > 0):
            ?>
              <div style="font-size: 12px; color: var(--muted); font-family: 'JetBrains Mono', monospace; font-weight: 500;">
                R$ <?= number_format($total_valor, 2, ',', '.') ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="crm-coluna-corpo" data-etapa="<?= $etapa_key ?>" style="flex: 1; overflow-y: auto; padding: var(--space-3); display: flex; flex-direction: column; gap: var(--space-2);">
            <?php if (empty($leads_por_etapa[$etapa_key])): ?>
              <div style="text-align: center; color: var(--muted); padding: var(--space-4); font-size: 13px;">
                <div style="margin-bottom: var(--space-2); font-size: 24px; opacity: 0.4;">↳</div>
                Nenhum lead aqui ainda
              </div>
            <?php else: ?>
              <?php foreach ($leads_por_etapa[$etapa_key] as $lead): ?>
                <div class="crm-card" draggable="true" data-lead-id="<?= (int)$lead['id'] ?>" data-etapa="<?= htmlspecialchars($lead['etapa']) ?>"
                  style="background: white; border: 1px solid var(--line); border-radius: 6px; padding: var(--space-3); cursor: grab; transition: all 0.2s ease; border-left: 3px solid <?= $etapa_info['cor'] ?>;">

                  <div style="display: flex; gap: var(--space-2); margin-bottom: var(--space-2);">
                    <?php if ($lead['moto_id']): ?>
                      <img src="<?= base_url($lead['moto_foto'] ?? 'assets/placeholder.jpg') ?>" style="width: 48px; height: 36px; border-radius: 4px; object-fit: cover;">
                    <?php else: ?>
                      <div style="width: 48px; height: 36px; border-radius: 4px; background: var(--line); display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: 12px;">sem foto</div>
                    <?php endif; ?>
                    <div style="flex: 1; min-width: 0;">
                      <div style="font-family: Inter, sans-serif; font-size: 13px; font-weight: 600; color: var(--ink); margin-bottom: 2px;">
                        <?= htmlspecialchars(mb_substr($lead['nome'], 0, 20)) ?>
                      </div>
                      <div style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--muted);">
                        <?= htmlspecialchars(crm_formata_telefone($lead['telefone'])) ?>
                      </div>
                    </div>
                  </div>

                  <?php if ($lead['moto_titulo']): ?>
                    <div style="font-size: 11px; color: var(--muted); margin-bottom: var(--space-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                      <?= htmlspecialchars($lead['moto_titulo']) ?>
                    </div>
                  <?php endif; ?>

                  <div style="display: flex; gap: var(--space-2); align-items: center; justify-content: space-between; margin-bottom: var(--space-2);">
                    <div style="display: flex; gap: 4px; align-items: center;">
                      <?php
                        $temp_emoji = ['frio' => '❄️', 'morno' => '🌡️', 'quente' => '🔥'][$lead['temperatura']] ?? '◯';
                      ?>
                      <span style="font-size: 12px; cursor: pointer;" onclick="ciclarTemperatura(<?= (int)$lead['id'] ?>)">
                        <?= $temp_emoji ?>
                      </span>
                      <?php if ($lead['vendedor_id']): ?>
                        <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--red); color: white; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;">
                          <?= htmlspecialchars(mb_substr($lead['vendedor_nome'], 0, 1)) ?>
                        </div>
                      <?php else: ?>
                        <div style="font-size: 12px; color: var(--muted);">⊘</div>
                      <?php endif; ?>
                    </div>
                    <a href="<?= base_url('painel/crm_lead.php?id=' . (int)$lead['id']) ?>" class="btn" style="padding: 4px 8px; font-size: 11px; white-space: nowrap;">
                      Ver →
                    </a>
                  </div>

                  <?php if ($lead['valor_negociado'] && $lead['valor_negociado'] > 0): ?>
                    <div style="font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 600; color: var(--red); margin-bottom: var(--space-2);">
                      R$ <?= number_format($lead['valor_negociado'], 2, ',', '.') ?>
                    </div>
                  <?php endif; ?>

                  <button class="btn btn-ghost" style="width: 100%; padding: 6px; font-size: 11px;" onclick="abrirWhatsApp(<?= (int)$lead['id'] ?>, '<?= htmlspecialchars(crm_formata_telefone($lead['telefone'])) ?>')">
                    📱 Chamar no WhatsApp
                  </button>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</main>

<!-- Modal Novo Lead -->
<div id="modal-novo-lead" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 12px; padding: var(--space-6); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
    <h2 style="margin-top: 0; margin-bottom: var(--space-4);">Novo lead</h2>
    <form id="form-novo-lead" onsubmit="salvarNovoLead(event)">
      <div class="field mb-4">
        <label>Nome *</label>
        <input type="text" name="nome" required placeholder="Nome completo" autofocus>
      </div>
      <div class="field mb-4">
        <label>Telefone *</label>
        <input type="text" name="telefone" id="telefone-modal" required placeholder="(XX) 99999-9999" inputmode="numeric" onblur="checarTelefone()">
        <div id="dedup-aviso" style="display: none; color: var(--red); font-size: 12px; margin-top: var(--space-2);">
          ⚠️ Telefone já existe: <a href="#" id="dedup-link" target="_blank" style="color: var(--red); text-decoration: underline;">Ver lead existente</a>
        </div>
      </div>
      <div class="field mb-4">
        <label>E-mail <span style="color: var(--muted); font-weight: 400;">(opcional)</span></label>
        <input type="email" name="email" placeholder="email@exemplo.com">
      </div>
      <div class="field mb-4">
        <label>Moto de interesse <span style="color: var(--muted); font-weight: 400;">(opcional)</span></label>
        <select name="moto_id">
          <option value="">Nenhuma</option>
          <?php
            $motos = $pdo->query("SELECT id, titulo, ano_modelo FROM motos WHERE status IN ('disponivel','reservada') ORDER BY titulo ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($motos as $m):
          ?>
            <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['titulo'] . ' ' . $m['ano_modelo']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field mb-4">
        <label>Origem</label>
        <select name="origem">
          <option value="manual">Manual</option>
          <option value="site">Site</option>
          <option value="whatsapp">WhatsApp</option>
          <option value="telefone">Telefone</option>
          <option value="loja">Loja</option>
          <option value="indicacao">Indicação</option>
        </select>
      </div>
      <div class="field mb-4">
        <label>Temperatura</label>
        <select name="temperatura">
          <option value="frio">❄️ Frio</option>
          <option value="morno" selected>🌡️ Morno</option>
          <option value="quente">🔥 Quente</option>
        </select>
      </div>
      <div class="field mb-4">
        <label>Observação <span style="color: var(--muted); font-weight: 400;">(opcional)</span></label>
        <textarea name="observacao" rows="2" placeholder="Notas iniciais..."></textarea>
      </div>
      <div style="display: flex; gap: var(--space-2); justify-content: flex-end;">
        <button type="button" class="btn btn-ghost" onclick="fecharModalNovoLead()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Criar lead</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Confirmação Perda -->
<div id="modal-confirma-perda" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 12px; padding: var(--space-6); width: 90%; max-width: 400px;">
    <h2 style="margin-top: 0; margin-bottom: var(--space-4);">Registrar perda</h2>
    <form id="form-confirma-perda" onsubmit="confirmarPerda(event)">
      <input type="hidden" id="lead-id-perda">
      <div class="field mb-4">
        <label>Motivo da perda *</label>
        <select id="motivo-perda" name="motivo_perda" required>
          <option value="">Selecione...</option>
        </select>
      </div>
      <div class="field mb-4">
        <label>Detalhes <span style="color: var(--muted); font-weight: 400;">(opcional)</span></label>
        <textarea id="motivo-obs" name="motivo_perda_obs" rows="2" placeholder="Observações adicionais..."></textarea>
      </div>
      <div style="display: flex; gap: var(--space-2); justify-content: flex-end;">
        <button type="button" class="btn btn-ghost" onclick="fecharModalPerda()">Cancelar</button>
        <button type="submit" class="btn" style="background: var(--red); color: white;">Confirmar perda</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Confirmação Fechado -->
<div id="modal-confirma-fechado" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 12px; padding: var(--space-6); width: 90%; max-width: 400px;">
    <h2 style="margin-top: 0; margin-bottom: var(--space-4);">Confirmar fechamento</h2>
    <form id="form-confirma-fechado" onsubmit="confirmarFechado(event)">
      <input type="hidden" id="lead-id-fechado">
      <div class="field mb-4">
        <label>Valor negociado <span style="color: var(--muted); font-weight: 400;">(opcional)</span></label>
        <div style="display: flex; gap: 4px;">
          <span style="padding: 8px 12px; background: var(--bg); border: 1px solid var(--line); border-radius: 4px; line-height: 36px;">R$</span>
          <input type="text" id="valor-fechado" name="valor_negociado" placeholder="0,00" style="flex: 1;">
        </div>
      </div>
      <div id="aviso-moto-venda" style="display: none; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: var(--space-3); margin-bottom: var(--space-4); color: #856404; font-size: 13px;">
        ℹ️ A moto ainda está disponível. <a href="#" id="link-vender" style="color: var(--red); font-weight: 600;">Registrar venda agora</a>
      </div>
      <div style="display: flex; gap: var(--space-2); justify-content: flex-end;">
        <button type="button" class="btn btn-ghost" onclick="fecharModalFechado()">Cancelar</button>
        <button type="submit" class="btn" style="background: var(--ok); color: white;">Confirmar fechamento</button>
      </div>
    </form>
  </div>
</div>

<style>
.crm-board::-webkit-scrollbar { height: 6px; }
.crm-board::-webkit-scrollbar-track { background: transparent; }
.crm-board::-webkit-scrollbar-thumb { background: var(--line); border-radius: 3px; }
.crm-board::-webkit-scrollbar-thumb:hover { background: var(--muted); }

.crm-coluna-corpo::-webkit-scrollbar { width: 4px; }
.crm-coluna-corpo::-webkit-scrollbar-track { background: transparent; }
.crm-coluna-corpo::-webkit-scrollbar-thumb { background: var(--line); border-radius: 2px; }

.crm-card:active { cursor: grabbing; }

.badge { display: inline-block; }

@media (max-width: 768px) {
  .crm-board { flex-direction: column; overflow-x: visible; }
  .crm-coluna { flex: 1; }
  .crm-header { flex-direction: column; align-items: flex-start; }
}
</style>

<script>
// CSRF token helper
function addCsrfToken(fd) {
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (token) fd.append('_csrf', token);
  return fd;
}

function abrirModalNovoLead() {
  document.getElementById('modal-novo-lead').style.display = 'flex';
}

function fecharModalNovoLead() {
  document.getElementById('modal-novo-lead').style.display = 'none';
  document.getElementById('form-novo-lead').reset();
  document.getElementById('dedup-aviso').style.display = 'none';
}

function fecharModalPerda() {
  document.getElementById('modal-confirma-perda').style.display = 'none';
}

function fecharModalFechado() {
  document.getElementById('modal-confirma-fechado').style.display = 'none';
}

function checarTelefone() {
  const tel = document.getElementById('telefone-modal').value;
  if (!tel) return;

  const fd = new FormData();
  fd.append('acao', 'checar_telefone');
  fd.append('telefone', tel);
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.existe) {
      const aviso = document.getElementById('dedup-aviso');
      const link = document.getElementById('dedup-link');
      link.href = '<?= base_url('painel/crm_lead.php?id=') ?>' + d.lead_id;
      aviso.style.display = 'block';
    } else {
      document.getElementById('dedup-aviso').style.display = 'none';
    }
  });
}

function salvarNovoLead(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('form-novo-lead'));
  fd.append('acao', 'criar_lead');
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      fecharModalNovoLead();
      window.location.reload();
    } else {
      alert('Erro: ' + d.msg);
    }
  });
}

// Drag & Drop (após DOM estar pronto)
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.crm-card').forEach(card => {
  card.addEventListener('dragstart', e => {
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('lead-id', card.dataset.leadId);
    card.style.opacity = '0.5';
  });

  card.addEventListener('dragend', e => {
    card.style.opacity = '1';
  });
});

document.querySelectorAll('.crm-coluna-corpo').forEach(coluna => {
  coluna.addEventListener('dragover', e => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    coluna.style.background = 'rgba(200, 41, 31, 0.05)';
  });

  coluna.addEventListener('dragleave', e => {
    coluna.style.background = '';
  });

  coluna.addEventListener('drop', e => {
    e.preventDefault();
    coluna.style.background = '';
    const leadId = e.dataTransfer.getData('lead-id');
    const etapa = coluna.dataset.etapa;

    if (etapa === 'perdido') {
      document.getElementById('lead-id-perda').value = leadId;
      document.getElementById('modal-confirma-perda').style.display = 'flex';
    } else if (etapa === 'fechado') {
      document.getElementById('lead-id-fechado').value = leadId;
      document.getElementById('modal-confirma-fechado').style.display = 'flex';
    } else {
      moverLead(leadId, etapa);
    }
  });
  });
});

function moverLead(leadId, etapa) {
  const fd = new FormData();
  fd.append('acao', 'mover');
  fd.append('lead_id', leadId);
  fd.append('etapa', etapa);
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      window.location.reload();
    } else {
      alert('Erro: ' + d.msg);
    }
  });
}

function confirmarPerda(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('form-confirma-perda'));
  fd.append('acao', 'mover');
  fd.append('lead_id', document.getElementById('lead-id-perda').value);
  fd.append('etapa', 'perdido');
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      window.location.reload();
    } else {
      alert('Erro: ' + d.msg);
    }
  });
}

function confirmarFechado(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('form-confirma-fechado'));
  fd.append('acao', 'mover');
  fd.append('lead_id', document.getElementById('lead-id-fechado').value);
  fd.append('etapa', 'fechado');
  fd.append('valor_negociado', document.getElementById('valor-fechado').value);
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      window.location.reload();
    } else {
      alert('Erro: ' + d.msg);
    }
  });
}

function ciclarTemperatura(leadId) {
  const temps = ['frio', 'morno', 'quente'];
  const card = document.querySelector(`[data-lead-id="${leadId}"]`);
  const etapa = card.dataset.etapa;
  const leads = <?= json_encode(array_map(fn($l) => $l['id'] . ':' . $l['temperatura'], $todos_leads)) ?>;
  const atual = '<?php echo isset($lead['temperatura']) ? $lead['temperatura'] : 'morno'; ?>';
  const idx = temps.indexOf(atual);
  const prox = temps[(idx + 1) % temps.length];

  const fd = new FormData();
  fd.append('acao', 'temperatura');
  fd.append('lead_id', leadId);
  fd.append('temperatura', prox);
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      window.location.reload();
    }
  });
}

function abrirWhatsApp(leadId, tel) {
  const url = 'https://wa.me/55<?php echo setting_get_any($pdo, 'whatsapp_number', ''); ?>?text=Oi! Tenho%20interesse%20em%20saber%20mais...';
  const fd = new FormData();
  fd.append('acao', 'nova_interacao');
  fd.append('lead_id', leadId);
  fd.append('tipo', 'whatsapp');
  fd.append('texto', 'Clicou para chamar no WhatsApp');
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  });

  window.open(crm_whatsapp_link('<?= htmlspecialchars($tel) ?>'), '_blank');
}

function crm_whatsapp_link(tel) {
  return 'https://wa.me/55' + tel.replace(/\D/g, '') + '?text=Oi!%20Tenho%20interesse%20em%20saber%20mais...';
}

function importarVendas() {
  if (!confirm('Importar compradores do histórico de vendas?')) return;

  const fd = new FormData();
  fd.append('acao', 'importar_vendas');
  addCsrfToken(fd);

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    alert(d.msg);
    window.location.reload();
  });
}

function limparFiltros() {
  window.location = '<?= base_url('painel/crm.php') ?>';
}

// Carregar motivos de perda
fetch('<?= base_url('painel/crm_actions.php') ?>?acao=motivos')
.then(r => r.json())
.catch(() => {
  document.getElementById('motivo-perda').innerHTML += `
    <option value="Preço">Preço</option>
    <option value="Comprou em outra loja">Comprou em outra loja</option>
    <option value="Sem crédito/financiamento">Sem crédito/financiamento</option>
    <option value="Desistiu">Desistiu</option>
    <option value="Sem retorno">Sem retorno</option>
    <option value="Trocou de ideia">Trocou de ideia</option>
    <option value="Outro">Outro</option>
  `;
});

// Fechar modais com ESC
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    fecharModalNovoLead();
    fecharModalPerda();
    fecharModalFechado();
  }
});
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
