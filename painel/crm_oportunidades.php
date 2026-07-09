<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';
require_once __DIR__ . '/../inc/crm_match.php';
require_login();

ensure_crm_schema($pdo);

$user = current_user();
$view = $_GET['view'] ?? 'moto'; // 'moto' ou 'lead'
$moto_id_filter = (int)($_GET['moto_id'] ?? 0);

// Motos + leads compatíveis (respeitando visibilidade)
$oportunidades_por_moto = [];
if ($view === 'moto' || true) { // ambas views precisam dessa info
  $stmt_motos = $pdo->prepare("
    SELECT m.id, m.titulo, m.valor, m.ano_modelo, m.quilometragem as km, m.created_at,
           (SELECT caminho FROM moto_fotos WHERE moto_id=m.id ORDER BY ordem ASC LIMIT 1) as foto_capa
    FROM motos m
    WHERE m.status='disponivel'
    ORDER BY m.created_at DESC
  ");
  $stmt_motos->execute();
  $motos = $stmt_motos->fetchAll(PDO::FETCH_ASSOC);

  foreach ($motos as $moto) {
    // Aplica filtro se especificado
    if ($moto_id_filter > 0 && (int)$moto['id'] !== $moto_id_filter) {
      continue;
    }

    $leads = crm_match_leads_para_moto($pdo, (int)$moto['id'], 65, 100, $user);
    if (empty($leads)) {
      continue;
    }

    // Filtra dispensados
    $lead_ids = array_map(fn($l) => $l['lead_id'], $leads);
    $moto_id_safe = (int)$moto['id'];
    $dispensados = [];
    if (!empty($lead_ids)) {
      $place = implode(',', array_fill(0, count($lead_ids), '?'));
      $stmt_disp = $pdo->prepare("SELECT lead_id FROM crm_match_dispensados WHERE moto_id=? AND lead_id IN ($place)");
      $stmt_disp->execute(array_merge([$moto_id_safe], $lead_ids));
      $dispensados = array_column($stmt_disp->fetchAll(PDO::FETCH_ASSOC), 'lead_id');
    }

    $leads_ativos = array_filter($leads, fn($l) => !in_array((int)$l['lead_id'], $dispensados));
    if (empty($leads_ativos)) {
      continue;
    }

    $dias_estoque = ceil((time() - strtotime($moto['created_at'])) / 86400);
    $max_score = max(array_map(fn($l) => $l['score'], $leads_ativos));

    $oportunidades_por_moto[] = [
      'moto' => $moto,
      'dias_estoque' => $dias_estoque,
      'max_score' => $max_score,
      'leads' => array_values($leads_ativos)
    ];
  }

  // Ordena: max_score desc, depois dias_estoque desc
  usort($oportunidades_por_moto, function($a, $b) {
    if ($a['max_score'] !== $b['max_score']) {
      return $b['max_score'] <=> $a['max_score'];
    }
    return $b['dias_estoque'] <=> $a['dias_estoque'];
  });
}

// View por lead
$oportunidades_por_lead = [];
if ($view === 'lead' || true) {
  $where = "l.etapa IN ('novo', 'contato', 'negociacao', 'proposta')";
  $params = [];
  if ($user['role'] === 'vendedor') {
    $where .= " AND (l.vendedor_id=? OR l.vendedor_id IS NULL)";
    $params[] = $user['id'];
  }

  $stmt_leads = $pdo->prepare("
    SELECT l.id, l.nome, l.telefone, l.moto_id, l.temperatura, u.nome as vendedor_nome
    FROM crm_leads l
    LEFT JOIN users u ON l.vendedor_id=u.id
    WHERE $where
    ORDER BY l.id DESC
  ");
  $stmt_leads->execute($params);
  $leads = $stmt_leads->fetchAll(PDO::FETCH_ASSOC);

  foreach ($leads as $lead) {
    $motos = crm_match_motos_para_lead($pdo, (int)$lead['id'], 65, 100);
    if (empty($motos)) {
      continue;
    }

    // Filtra dispensados
    $moto_ids = array_map(fn($m) => $m['moto_id'], $motos);
    $lead_id_safe = (int)$lead['id'];
    $dispensados = [];
    if (!empty($moto_ids)) {
      $place = implode(',', array_fill(0, count($moto_ids), '?'));
      $stmt_disp = $pdo->prepare("SELECT moto_id FROM crm_match_dispensados WHERE lead_id=? AND moto_id IN ($place)");
      $stmt_disp->execute(array_merge([$lead_id_safe], $moto_ids));
      $dispensados = array_column($stmt_disp->fetchAll(PDO::FETCH_ASSOC), 'moto_id');
    }

    $motos_ativos = array_filter($motos, fn($m) => !in_array((int)$m['moto_id'], $dispensados));
    if (empty($motos_ativos)) {
      continue;
    }

    $oportunidades_por_lead[] = [
      'lead' => $lead,
      'motos' => array_values($motos_ativos)
    ];
  }
}

$page_title = '⚡ Oportunidades';
include __DIR__ . '/../inc/header.php';
?>

<style>
  .opp-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border); margin-bottom: var(--space-4); }
  .opp-tab { padding: var(--space-3) var(--space-4); font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; transition: all var(--t-fast); color: var(--text-muted); }
  .opp-tab.active { color: var(--text); border-bottom-color: var(--brand); }
  .opp-tab:hover { color: var(--text); }

  .opp-card { background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-4); margin-bottom: var(--space-4); }
  .opp-moto-header { display: flex; gap: var(--space-3); margin-bottom: var(--space-3); }
  .opp-moto-foto { width: 140px; height: 100px; border-radius: 6px; background: var(--bg); overflow: hidden; flex-shrink: 0; }
  .opp-moto-foto img { width: 100%; height: 100%; object-fit: cover; }
  .opp-moto-info { flex: 1; min-width: 0; }
  .opp-moto-title { font-weight: 700; font-size: 16px; margin-bottom: 4px; }
  .opp-moto-meta { font-size: 13px; color: var(--text-muted); margin-bottom: 4px; }
  .opp-moto-price { font-weight: 700; color: var(--brand); font-size: 16px; }

  .opp-leads-list { display: flex; flex-direction: column; gap: var(--space-2); }
  .opp-lead-item { background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: var(--space-3); display: flex; gap: var(--space-3); align-items: center; justify-content: space-between; flex-wrap: wrap; }
  .opp-lead-info { flex: 1; min-width: 200px; }
  .opp-lead-name { font-weight: 600; font-size: 14px; }
  .opp-lead-meta { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
  .opp-lead-score { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 700; margin-top: 4px; }
  .opp-lead-actions { display: flex; gap: 6px; flex-wrap: wrap; }
  .opp-btn { padding: 6px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; background: var(--bg-secondary); color: var(--text); font-weight: 600; transition: all var(--t-fast); }
  .opp-btn:hover { background: var(--bg-tertiary); }
  .opp-btn-danger { background: #fee2e2; color: #dc2626; }
  .opp-btn-danger:hover { background: #fecaca; }

  .opp-empty { text-align: center; padding: var(--space-6); color: var(--text-muted); font-size: 14px; }
</style>

<main class="container crm-main" style="max-width: 1200px;">
  <div class="crm-header">
    <h1>⚡ Oportunidades de venda</h1>
  </div>

  <div class="opp-tabs">
    <div class="opp-tab <?= ($view === 'moto' ? 'active' : '') ?>" onclick="location.href='<?= base_url('painel/crm_oportunidades.php?view=moto') ?>'">
      📦 Por moto (<?= count($oportunidades_por_moto) ?>)
    </div>
    <div class="opp-tab <?= ($view === 'lead' ? 'active' : '') ?>" onclick="location.href='<?= base_url('painel/crm_oportunidades.php?view=lead') ?>'">
      👤 Por lead (<?= count($oportunidades_por_lead) ?>)
    </div>
  </div>

  <?php if ($view === 'moto'): ?>
    <?php if (empty($oportunidades_por_moto)): ?>
      <div class="opp-empty">
        Nenhuma oportunidade no momento. Motos não conseguem virar compatíveis com leads só com a passagem do tempo — isso é coisa pra Fase 6 (IA).
      </div>
    <?php else: ?>
      <?php foreach ($oportunidades_por_moto as $opp): ?>
        <div class="opp-card">
          <div class="opp-moto-header">
            <?php if ($opp['moto']['foto_capa']): ?>
              <div class="opp-moto-foto">
                <img src="<?= base_url('uploads/' . $opp['moto']['foto_capa']) ?>" alt="">
              </div>
            <?php else: ?>
              <div class="opp-moto-foto" style="display: flex; align-items: center; justify-content: center; font-size: 40px;">🏍</div>
            <?php endif; ?>

            <div class="opp-moto-info">
              <div class="opp-moto-title"><?= htmlspecialchars($opp['moto']['titulo']) ?></div>
              <div class="opp-moto-meta">
                <?= $opp['moto']['ano_modelo'] ?> · <?= number_format($opp['moto']['km'], 0, ',', '.') ?> km · <?= $opp['dias_estoque'] ?> dias no estoque
              </div>
              <div class="opp-moto-price">R$ <?= number_format($opp['moto']['valor'], 0, ',', '.') ?></div>
            </div>
          </div>

          <div class="opp-leads-list">
            <?php foreach ($opp['leads'] as $lead): ?>
              <div class="opp-lead-item">
                <div class="opp-lead-info">
                  <div class="opp-lead-name"><?= htmlspecialchars($lead['nome']) ?></div>
                  <div class="opp-lead-meta">
                    📞 <?= htmlspecialchars($lead['telefone']) ?> · <?= htmlspecialchars($lead['vendedor_nome'] ?? 'Sem vendedor') ?>
                  </div>
                  <div style="margin-top: 6px;">
                    <span class="opp-lead-score" style="background: <?php
                      if ($lead['score'] >= 80) echo '#d1fae5';
                      elseif ($lead['score'] >= 65) echo '#fef3c7';
                      else echo '#e5e7eb';
                    ?>; color: <?php
                      if ($lead['score'] >= 80) echo '#047857';
                      elseif ($lead['score'] >= 65) echo '#92400e';
                      else echo '#6b7280';
                    ?>;">
                      <?php
                        if ($lead['score'] >= 80) echo '🟢';
                        elseif ($lead['score'] >= 65) echo '🟡';
                        else echo '⚪';
                      ?> Score <?= $lead['score'] ?>
                    </span>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                      <?= htmlspecialchars($lead['motivo']) ?>
                    </div>
                  </div>
                </div>
                <div class="opp-lead-actions">
                  <button class="opp-btn" onclick="enviarNoZapOportunidade(<?= (int)$opp['moto']['id'] ?>, '<?= htmlspecialchars(str_replace("'", "\\'", $opp['moto']['titulo'])) ?>', <?= $opp['moto']['ano_modelo'] ?>, <?= $opp['moto']['km'] ?>, '<?= number_format($opp['moto']['valor'], 2, '.', '') ?>', <?= (int)$lead['lead_id'] ?>)">
                    Enviar zap
                  </button>
                  <button class="opp-btn" onclick="definirMotoInteresse(<?= (int)$lead['lead_id'] ?>, <?= (int)$opp['moto']['id'] ?>, '<?= htmlspecialchars(str_replace("'", "\\'", $opp['moto']['titulo'])) ?>')">
                    Definir interesse
                  </button>
                  <button class="opp-btn opp-btn-danger" onclick="dispensarOportunidade(<?= (int)$lead['lead_id'] ?>, <?= (int)$opp['moto']['id'] ?>)">
                    Dispensar
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php else: ?>
    <!-- View por Lead -->
    <?php if (empty($oportunidades_por_lead)): ?>
      <div class="opp-empty">
        Nenhuma oportunidade. Seus leads não têm interesses compatíveis com motos disponíveis.
      </div>
    <?php else: ?>
      <?php foreach ($oportunidades_por_lead as $opp): ?>
        <div class="opp-card">
          <div style="margin-bottom: var(--space-3);">
            <div style="font-weight: 700; font-size: 16px;"><?= htmlspecialchars($opp['lead']['nome']) ?></div>
            <div style="font-size: 13px; color: var(--text-muted);">
              📞 <?= htmlspecialchars($opp['lead']['telefone']) ?> ·
              Temperatura: <?= ucfirst($opp['lead']['temperatura']) ?>
              <?php if ($opp['lead']['vendedor_nome']): ?>
                · <?= htmlspecialchars($opp['lead']['vendedor_nome']) ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="opp-leads-list">
            <?php foreach ($opp['motos'] as $moto): ?>
              <div class="opp-lead-item" style="flex-direction: column; align-items: flex-start;">
                <div style="width: 100%; margin-bottom: var(--space-2);">
                  <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px;"><?= htmlspecialchars($moto['titulo']) ?></div>
                  <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 6px;">
                    <?= $moto['ano_modelo'] ?> · <?= number_format($moto['km'], 0, ',', '.') ?> km · R$ <?= number_format($moto['valor'], 0, ',', '.') ?>
                  </div>
                  <span class="opp-lead-score" style="background: <?php
                    if ($moto['score'] >= 80) echo '#d1fae5';
                    elseif ($moto['score'] >= 65) echo '#fef3c7';
                    else echo '#e5e7eb';
                  ?>; color: <?php
                    if ($moto['score'] >= 80) echo '#047857';
                    elseif ($moto['score'] >= 65) echo '#92400e';
                    else echo '#6b7280';
                  ?>;">
                    <?php
                      if ($moto['score'] >= 80) echo '🟢';
                      elseif ($moto['score'] >= 65) echo '🟡';
                      else echo '⚪';
                    ?> Score <?= $moto['score'] ?>
                  </span>
                  <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                    <?= htmlspecialchars($moto['motivo']) ?>
                  </div>
                </div>

                <div class="opp-lead-actions" style="width: 100%;">
                  <button class="opp-btn" onclick="enviarNoZapOportunidade(<?= (int)$moto['moto_id'] ?>, '<?= htmlspecialchars(str_replace("'", "\\'", $moto['titulo'])) ?>', <?= $moto['ano_modelo'] ?>, <?= $moto['km'] ?>, '<?= number_format($moto['valor'], 2, '.', '') ?>', <?= (int)$opp['lead']['id'] ?>)">
                    Enviar zap
                  </button>
                  <button class="opp-btn" onclick="definirMotoInteresse(<?= (int)$opp['lead']['id'] ?>, <?= (int)$moto['moto_id'] ?>, '<?= htmlspecialchars(str_replace("'", "\\'", $moto['titulo'])) ?>')">
                    Definir interesse
                  </button>
                  <button class="opp-btn opp-btn-danger" onclick="dispensarOportunidade(<?= (int)$opp['lead']['id'] ?>, <?= (int)$moto['moto_id'] ?>)">
                    Dispensar
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

</main>

<script>
function definirMotoInteresse(leadId, motoId, motoTitulo) {
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    body: JSON.stringify({
      acao: 'definir_moto_interesse',
      lead_id: leadId,
      moto_id: motoId
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      alert('✓ Moto definida como interesse');
      location.reload();
    } else {
      alert('✗ Erro: ' + (data.msg || ''));
    }
  })
  .catch(e => alert('✗ Erro: ' + e.message));
}

function enviarNoZapOportunidade(motoId, motoTitulo, ano, km, valor, leadId) {
  const telefone = prompt('Telefone do lead (com DDD):');
  if (!telefone) return;

  const texto = `Oi! Chegou algo com a sua cara aqui: ${motoTitulo}, ${ano}, ${km} km, por R$ ${valor}. Quer que eu te mande as fotos?`;
  const linkZap = `https://wa.me/${telefone.replace(/\D/g, '')}?text=${encodeURIComponent(texto)}`;

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    body: JSON.stringify({
      acao: 'registrar_oportunidade_zap',
      lead_id: leadId,
      moto_id: motoId,
      texto: texto
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok || true) {
      window.open(linkZap, '_blank');
    }
  })
  .catch(e => console.error(e));
}

function dispensarOportunidade(leadId, motoId) {
  if (!confirm('Tem certeza que quer dispensar esta oportunidade?')) return;

  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    body: JSON.stringify({
      acao: 'dispensar_oportunidade',
      lead_id: leadId,
      moto_id: motoId
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      location.reload();
    } else {
      alert('✗ Erro: ' + (data.msg || ''));
    }
  })
  .catch(e => alert('✗ Erro: ' + e.message));
}
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
