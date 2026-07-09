<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';
require_login();

// Helper function for settings
function setting_get_any($pdo, $keys, $default = '') {
  if (!is_array($keys)) $keys = [$keys];
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

ensure_crm_schema($pdo);

$user = current_user();
$lead_id = (int)($_GET['id'] ?? 0);
if ($lead_id <= 0) die('ID inválido');

$lead = crm_lead_get($pdo, $lead_id);
if (!$lead) die('Lead não encontrado');
if (!crm_pode_ver_lead($user, $lead)) die('Sem acesso a este lead');

$etapas = crm_etapas();
$interacoes = $pdo->prepare("SELECT * FROM crm_interacoes WHERE lead_id=? ORDER BY created_at DESC");
$interacoes->execute([$lead_id]);
$interacoes = $interacoes->fetchAll(PDO::FETCH_ASSOC);

$interesses = $pdo->prepare("SELECT * FROM crm_interesses WHERE lead_id=? ORDER BY created_at DESC");
$interesses->execute([$lead_id]);
$interesses = $interesses->fetchAll(PDO::FETCH_ASSOC);

$agendamentos = $pdo->prepare("SELECT * FROM crm_agendamentos WHERE lead_id=? ORDER BY data_hora DESC");
$agendamentos->execute([$lead_id]);
$agendamentos = $agendamentos->fetchAll(PDO::FETCH_ASSOC);

$vendedores = $pdo->query("SELECT id, nome FROM users WHERE role='vendedor' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

$motivos_perda_json = setting_get_any($pdo, 'crm_motivos_perda', '["Preço","Outro"]');
$motivos_perda = json_decode($motivos_perda_json, true) ?: [];

$dias_etapa = 0;
if ($lead['etapa_desde']) {
  $dias_etapa = (new DateTime('now'))->diff(new DateTime($lead['etapa_desde']))->days;
}

$page_title = 'Lead: ' . $lead['nome'];
include __DIR__ . '/../inc/header.php';
?>

<main class="container" style="padding: var(--space-4) 0;">
  <div class="page">

    <div style="display: flex; gap: var(--space-4); align-items: center; justify-content: space-between; margin-bottom: var(--space-6);">
      <a href="<?= base_url('painel/crm.php') ?>" class="btn btn-ghost">← Voltar ao Pipeline</a>
      <button class="btn btn-primary" onclick="abrirWhatsApp('<?= htmlspecialchars(crm_formata_telefone($lead['telefone'])) ?>')">
        📱 Chamar no WhatsApp
      </button>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 320px; gap: var(--space-6); margin-bottom: var(--space-6);">

      <!-- Coluna Principal -->
      <div>

        <!-- Header do Lead -->
        <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-4); margin-bottom: var(--space-4);">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-2);">
            <h1 style="margin: 0; font-size: 28px; font-family: 'Big Shoulders Display', sans-serif;">
              <?= htmlspecialchars($lead['nome']) ?>
            </h1>
            <button class="btn btn-ghost" style="font-size: 12px; padding: 6px 12px;" onclick="abrirModalEditarLead()">
              ✏️ Editar
            </button>
          </div>
          <div style="font-family: 'JetBrains Mono', monospace; font-size: 14px; color: var(--muted); margin-bottom: var(--space-2);">
            📱 <?= htmlspecialchars(crm_formata_telefone($lead['telefone'])) ?>
            <?php if ($lead['email']): ?>
              | ✉️ <?= htmlspecialchars($lead['email']) ?>
            <?php endif; ?>
          </div>
          <div style="display: flex; gap: 8px; margin-bottom: var(--space-2);">
            <span style="background: var(--bg); padding: 4px 8px; border-radius: 4px; font-size: 12px;">
              Origem: <?= htmlspecialchars($lead['origem']) ?>
            </span>
            <span style="background: var(--bg); padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer;" onclick="ciclarTemperatura(<?= (int)$lead['id'] ?>)">
              <?php
                $temp_emoji = ['frio' => '❄️ Frio', 'morno' => '🌡️ Morno', 'quente' => '🔥 Quente'][$lead['temperatura']] ?? '◯ Morno';
              ?>
              Temperatura: <?= $temp_emoji ?>
            </span>
          </div>
        </div>

        <!-- Stepper de Etapas -->
        <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-4); margin-bottom: var(--space-4);">
          <h3 style="margin: 0 0 var(--space-3) 0; font-size: 14px; font-weight: 700;">Etapa do funil</h3>
          <div style="display: flex; gap: 12px; overflow-x: auto;">
            <?php foreach ($etapas as $e_key => $e_info): ?>
              <button class="btn" style="
                flex: 0 0 auto;
                background: <?= $lead['etapa'] === $e_key ? $e_info['cor'] : 'var(--bg)' ?>;
                color: <?= $lead['etapa'] === $e_key ? 'white' : 'var(--ink)' ?>;
                border: 1px solid <?= $lead['etapa'] === $e_key ? $e_info['cor'] : 'var(--line)' ?>;
                padding: 8px 16px;
                font-size: 12px;
                cursor: pointer;
                font-weight: 600;
              " onclick="mudarEtapa('<?= $e_key ?>')">
                <?= htmlspecialchars($e_info['rótulo']) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Moto de Interesse -->
        <?php if ($lead['moto_id']): ?>
          <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-4); margin-bottom: var(--space-4);">
            <h3 style="margin: 0 0 var(--space-3) 0; font-size: 14px; font-weight: 700;">Moto de interesse</h3>
            <div style="display: flex; gap: var(--space-4); margin-bottom: var(--space-4);">
              <?php if ($lead['moto_foto']): ?>
                <img src="<?= base_url($lead['moto_foto']) ?>" style="width: 120px; height: 90px; border-radius: 6px; object-fit: cover;" alt="Moto">
              <?php else: ?>
                <svg width="120" height="90" style="border-radius: 6px; background: var(--surface-2);" viewBox="0 0 120 90">
                  <rect width="120" height="90" fill="var(--surface-2)"/>
                  <g fill="var(--muted)">
                    <circle cx="40" cy="35" r="12"/>
                    <path d="M 15 70 L 45 40 L 75 60 L 105 30 L 105 85 L 15 85 Z" fill="var(--line)"/>
                  </g>
                </svg>
              <?php endif; ?>
              <div>
                <div style="font-size: 16px; font-weight: 600; margin-bottom: 4px;">
                  <a href="<?= base_url('moto.php?id=' . (int)$lead['moto_id']) ?>" target="_blank" style="color: var(--ink); text-decoration: none;">
                    <?= htmlspecialchars($lead['moto_titulo']) ?>
                  </a>
                </div>
                <div style="font-size: 13px; color: var(--muted); margin-bottom: 2px;">
                  <?= $lead['moto_km'] ? number_format($lead['moto_km'], 0, ',', '.') . ' km' : '—' ?>
                </div>
                <div style="font-family: 'JetBrains Mono', monospace; font-size: 14px; font-weight: 600; color: var(--red); margin-bottom: var(--space-2);">
                  R$ <?= number_format($lead['moto_valor'], 2, ',', '.') ?>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                  <span style="background: <?= $lead['moto_status'] === 'disponivel' ? 'var(--ok)' : ($lead['moto_status'] === 'vendida' ? '#999' : '#ff9800') ?>; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                    <?= htmlspecialchars(ucfirst($lead['moto_status'])) ?>
                  </span>
                  <?php if ($lead['moto_status'] === 'vendida'): ?>
                    <button class="btn btn-ghost" style="padding: 4px 8px; font-size: 11px;" onclick="trocarMotoForm()">
                      Trocar moto
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php if ($lead['moto_status'] === 'vendida' && $lead['etapa'] !== 'fechado' && $lead['etapa'] !== 'perdido'): ?>
              <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: var(--space-3); color: #856404; font-size: 13px;">
                ⚠️ A moto de interesse foi vendida. Considere trocar para outra ou fechar este lead.
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Interesses Genéricos -->
        <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-4); margin-bottom: var(--space-4);">
          <h3 style="margin: 0 0 var(--space-3) 0; font-size: 14px; font-weight: 700;">Perfil de interesse</h3>
          <p style="margin: 0 0 var(--space-3) 0; font-size: 12px; color: var(--muted);">Usado pela IA de oportunidades nas próximas fases</p>

          <?php if (!empty($interesses)): ?>
            <?php foreach ($interesses as $int): ?>
              <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: var(--space-3); margin-bottom: var(--space-2); display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="font-size: 13px;">
                  <?php if ($int['marca']): ?>
                    <div><strong><?= htmlspecialchars($int['marca']) ?></strong> <?= htmlspecialchars($int['modelo'] ?? '') ?></div>
                  <?php endif; ?>
                  <?php if ($int['ano_min'] || $int['ano_max']): ?>
                    <div style="color: var(--muted); font-size: 12px;">Ano: <?= (int)$int['ano_min'] ?> - <?= (int)$int['ano_max'] ?></div>
                  <?php endif; ?>
                  <?php if ($int['valor_max'] || $int['km_max']): ?>
                    <div style="color: var(--muted); font-size: 12px;">
                      <?php if ($int['valor_max']): ?>
                        Até R$ <?= number_format($int['valor_max'], 2, ',', '.') ?>
                      <?php endif; ?>
                      <?php if ($int['km_max']): ?>
                        | Até <?= number_format($int['km_max'], 0, ',', '.') ?> km
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($int['observacao']): ?>
                    <div style="color: var(--muted); font-size: 12px; margin-top: 4px;">📝 <?= htmlspecialchars($int['observacao']) ?></div>
                  <?php endif; ?>
                </div>
                <button class="btn btn-ghost" style="padding: 2px 6px; font-size: 11px;" onclick="excluirInteresse(<?= (int)$int['id'] ?>)">✕</button>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <form id="form-novo-interesse" onsubmit="salvarInteresse(event)" style="background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: var(--space-3);">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-2); margin-bottom: var(--space-2);">
              <input type="text" name="marca" placeholder="Marca (Honda, Yamaha...)" style="padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px;">
              <input type="text" name="modelo" placeholder="Modelo (CG, XRE...)" style="padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-2); margin-bottom: var(--space-2);">
              <input type="number" name="ano_min" placeholder="Ano mín" style="padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px;">
              <input type="number" name="ano_max" placeholder="Ano máx" style="padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-2); margin-bottom: var(--space-2);">
              <input type="text" name="valor_max" placeholder="Valor máx (R$)" style="padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px;">
              <input type="number" name="km_max" placeholder="KM máx" style="padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px;">
            </div>
            <textarea name="observacao" placeholder="Observação" rows="2" style="padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px; width: 100%; margin-bottom: var(--space-2); font-family: Inter, sans-serif;"></textarea>
            <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 12px;">+ Adicionar interesse</button>
          </form>
        </div>

        <!-- Timeline de Interações -->
        <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-4);">
          <h3 style="margin: 0 0 var(--space-3) 0; font-size: 14px; font-weight: 700;">Timeline</h3>

          <form id="form-nova-interacao" onsubmit="salvarInteracao(event)" style="background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: var(--space-3); margin-bottom: var(--space-4);">
            <select name="tipo" style="width: 100%; padding: 8px 12px; border: 1px solid var(--line); border-radius: 4px; margin-bottom: var(--space-2); font-size: 13px;">
              <option value="nota">📝 Nota</option>
              <option value="ligacao">☎️ Ligação</option>
              <option value="whatsapp">📱 WhatsApp</option>
              <option value="visita">🏍️ Visita</option>
              <option value="proposta">📄 Proposta</option>
              <option value="email">✉️ E-mail</option>
            </select>
            <textarea name="texto" placeholder="Descrever interação..." rows="3" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--line); border-radius: 4px; font-size: 13px; font-family: Inter, sans-serif; margin-bottom: var(--space-2); box-sizing: border-box;"></textarea>
            <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 13px;">Registrar interação</button>
          </form>

          <div style="max-height: 600px; overflow-y: auto;">
            <?php foreach ($interacoes as $int): ?>
              <div style="padding: var(--space-3); border-left: 2px solid <?= $int['tipo'] === 'sistema' ? '#ccc' : 'var(--red)' ?>; margin-bottom: var(--space-2); background: <?= $int['tipo'] === 'sistema' ? '#f9f9f9' : 'transparent' ?>;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px;">
                  <div style="font-weight: 600; font-size: 12px; color: <?= $int['tipo'] === 'sistema' ? 'var(--muted)' : 'var(--ink)' ?>;">
                    <?= htmlspecialchars($int['tipo']) ?>
                  </div>
                  <div style="font-size: 11px; color: var(--muted);">
                    <?= htmlspecialchars(date_format(new DateTime($int['created_at']), 'd/m/Y H:i')) ?>
                  </div>
                </div>
                <div style="font-size: 13px; color: var(--ink); margin-bottom: 4px; <?= $int['tipo'] === 'sistema' ? 'font-style: italic; color: var(--muted);' : '' ?>">
                  <?= htmlspecialchars($int['texto']) ?>
                </div>
                <?php if ($int['user_id']): ?>
                  <div style="font-size: 11px; color: var(--muted);">
                    por <?php $u_stmt = $pdo->prepare("SELECT nome FROM users WHERE id=?"); $u_stmt->execute([$int['user_id']]); echo htmlspecialchars($u_stmt->fetchColumn() ?: 'Usuário'); ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- Sidebar -->
      <div>

        <!-- Vendedor Responsável -->
        <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-3); margin-bottom: var(--space-4);">
          <h4 style="margin: 0 0 var(--space-2) 0; font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--muted);">Vendedor</h4>
          <select id="vendedor-select" style="width: 100%; padding: 8px; border: 1px solid var(--line); border-radius: 4px; font-size: 13px;" onchange="atribuirVendedor(this.value)">
            <option value="">Sem atribuição</option>
            <?php foreach ($vendedores as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= (int)$lead['vendedor_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($v['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Valor Negociado -->
        <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-3); margin-bottom: var(--space-4);">
          <h4 style="margin: 0 0 var(--space-2) 0; font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--muted);">Valor negociado</h4>
          <div style="display: flex; gap: 4px;">
            <span style="padding: 8px; background: var(--bg); border: 1px solid var(--line); border-radius: 4px; line-height: 36px;">R$</span>
            <input type="text" id="valor-input" placeholder="0,00" value="<?= $lead['valor_negociado'] ? number_format($lead['valor_negociado'], 2, ',', '.') : '' ?>" style="flex: 1; padding: 8px; border: 1px solid var(--line); border-radius: 4px; font-family: 'JetBrains Mono', monospace; font-size: 13px;" onblur="atualizarValor(this.value)">
          </div>
        </div>

        <!-- Agendamentos -->
        <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-3); margin-bottom: var(--space-4);">
          <h4 style="margin: 0 0 var(--space-2) 0; font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--muted);">Agendamentos</h4>

          <form id="form-novo-agd" onsubmit="salvarAgendamento(event)" style="margin-bottom: var(--space-3); padding-bottom: var(--space-3); border-bottom: 1px solid var(--line);">
            <select name="tipo" style="width: 100%; padding: 6px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px; margin-bottom: 6px;">
              <option value="ligacao">☎️ Ligação</option>
              <option value="visita">🏍️ Visita</option>
              <option value="test_ride">🏍️ Test ride</option>
              <option value="entrega">📦 Entrega</option>
              <option value="outro">Outro</option>
            </select>
            <input type="datetime-local" name="data_hora" required style="width: 100%; padding: 6px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px; margin-bottom: 6px; box-sizing: border-box;">
            <input type="text" name="observacao" placeholder="Obs..." style="width: 100%; padding: 6px; border: 1px solid var(--line); border-radius: 4px; font-size: 12px; margin-bottom: 6px; box-sizing: border-box;">
            <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 12px;">+ Agendar</button>
          </form>

          <div style="max-height: 300px; overflow-y: auto;">
            <?php foreach ($agendamentos as $agd): ?>
              <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 4px; padding: 8px; margin-bottom: 6px; font-size: 12px;">
                <div style="font-weight: 600; margin-bottom: 2px;">
                  <?= htmlspecialchars($agd['tipo']) ?>
                  <span style="background: <?= $agd['status'] === 'pendente' && strtotime($agd['data_hora']) < time() ? 'var(--red)' : ($agd['status'] === 'realizado' ? 'var(--ok)' : 'var(--muted)') ?>; color: white; padding: 1px 4px; border-radius: 2px; font-size: 10px;">
                    <?= $agd['status'] === 'pendente' && strtotime($agd['data_hora']) < time() ? 'Atrasado' : htmlspecialchars($agd['status']) ?>
                  </span>
                </div>
                <div style="color: var(--muted); font-size: 11px; margin-bottom: 4px;">
                  <?= htmlspecialchars(date_format(new DateTime($agd['data_hora']), 'd/m/Y H:i')) ?>
                </div>
                <?php if ($agd['observacao']): ?>
                  <div style="color: var(--muted); font-size: 11px; margin-bottom: 4px;"><?= htmlspecialchars($agd['observacao']) ?></div>
                <?php endif; ?>
                <div style="display: flex; gap: 4px;">
                  <?php if ($agd['status'] === 'pendente'): ?>
                    <button class="btn btn-ghost" style="padding: 2px 6px; font-size: 11px;" onclick="atualizarAgendamento(<?= (int)$agd['id'] ?>, 'realizado')">✓ Realizado</button>
                  <?php endif; ?>
                  <button class="btn btn-ghost" style="padding: 2px 6px; font-size: 11px;" onclick="atualizarAgendamento(<?= (int)$agd['id'] ?>, 'cancelado')">✕ Cancelar</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Dados -->
        <div style="background: white; border: 1px solid var(--line); border-radius: 8px; padding: var(--space-3); margin-bottom: var(--space-4);">
          <h4 style="margin: 0 0 var(--space-2) 0; font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--muted);">Informações</h4>
          <div style="font-size: 12px; line-height: 1.6; color: var(--muted);">
            <div>Criado em: <strong><?= htmlspecialchars(date_format(new DateTime($lead['created_at']), 'd/m/Y H:i')) ?></strong></div>
            <div>Dias na etapa: <strong><?= $dias_etapa ?></strong></div>
            <?php if ($lead['venda_id']): ?>
              <div>
                Venda: <a href="<?= base_url('painel/moto_mark_sold.php?id=' . htmlspecialchars($lead['moto_id']) ?? '#') ?>" target="_blank" style="color: var(--red);">
                  #<?= (int)$lead['venda_id'] ?>
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Zona de Perigo -->
        <?php if (user_can('delete')): ?>
          <div style="background: white; border: 1px solid var(--red); border-radius: 8px; padding: var(--space-3);">
            <h4 style="margin: 0 0 var(--space-2) 0; font-size: 12px; font-weight: 700; color: var(--red); text-transform: uppercase;">Zona de Perigo</h4>
            <button class="btn" style="width: 100%; background: var(--red); color: white; font-size: 12px;" onclick="excluirLead()">
              🗑️ Excluir lead
            </button>
          </div>
        <?php endif; ?>

      </div>

    </div>

  </div>
</main>

<!-- Modal Trocar Moto -->
<div id="modal-trocar-moto" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 12px; padding: var(--space-6); width: 90%; max-width: 400px;">
    <h2 style="margin-top: 0; margin-bottom: var(--space-4);">Trocar moto de interesse</h2>
    <form id="form-trocar-moto" onsubmit="confirmarTrocarMoto(event)">
      <select id="select-moto" name="moto_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--line); border-radius: 4px; margin-bottom: var(--space-4);">
        <option value="">Selecione uma moto...</option>
        <?php
          $motos = $pdo->query("SELECT id, titulo, ano_modelo FROM motos WHERE status IN ('disponivel','reservada') ORDER BY titulo ASC")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($motos as $m):
        ?>
          <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['titulo'] . ' ' . $m['ano_modelo']) ?></option>
        <?php endforeach; ?>
      </select>
      <div style="display: flex; gap: var(--space-2); justify-content: flex-end;">
        <button type="button" class="btn btn-ghost" onclick="fecharModalTrocarMoto()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Trocar moto</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar Lead -->
<div id="modal-editar-lead" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 12px; padding: var(--space-6); width: 90%; max-width: 400px;">
    <h2 style="margin-top: 0; margin-bottom: var(--space-4);">Editar lead</h2>
    <form id="form-editar-lead" onsubmit="salvarEdicaoLead(event)">
      <div class="field mb-4">
        <label>Nome</label>
        <input type="text" id="editar-nome" name="nome" required placeholder="Nome do lead">
      </div>
      <div class="field mb-4">
        <label>E-mail</label>
        <input type="email" id="editar-email" name="email" placeholder="email@exemplo.com">
      </div>
      <div style="display: flex; gap: var(--space-2); justify-content: flex-end;">
        <button type="button" class="btn btn-ghost" onclick="fecharModalEditarLead()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
// CSRF token helper
function addCsrfToken(fd) {
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (token) fd.append('_csrf', token);
  return fd;
}

function mudarEtapa(etapa) {
  if (etapa === 'perdido') {
    alert('Para mover para Perdido, use o painel Kanban');
    return;
  }
  const fd = new FormData();
  fd.append('acao', 'mover');
  fd.append('lead_id', <?= (int)$lead_id ?>);
  fd.append('etapa', etapa);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) location.reload();
    else alert('Erro: ' + d.msg);
  });
}

function ciclarTemperatura(leadId) {
  const temps = ['frio', 'morno', 'quente'];
  const atual = '<?= $lead['temperatura'] ?>';
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
    if (d.ok) location.reload();
  });
}

function atribuirVendedor(vendedor_id) {
  const fd = new FormData();
  fd.append('acao', 'atribuir_vendedor');
  fd.append('lead_id', <?= (int)$lead_id ?>);
  fd.append('vendedor_id', vendedor_id);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) alert('Erro: ' + d.msg);
  });
}

function atualizarValor(valor) {
  if (!valor) return;
  const valor_num = parseFloat(valor.replace(/\D/g, '')) / 100;

  const fd = new FormData();
  fd.append('acao', 'valor_negociado');
  fd.append('lead_id', <?= (int)$lead_id ?>);
  fd.append('valor', valor_num);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) alert('Erro: ' + d.msg);
  });
}

function salvarInteracao(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('form-nova-interacao'));
  fd.append('acao', 'nova_interacao');
  fd.append('lead_id', <?= (int)$lead_id ?>);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) location.reload();
    else alert('Erro: ' + d.msg);
  });
}

function salvarInteresse(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('form-novo-interesse'));
  fd.append('acao', 'salvar_interesse');
  fd.append('lead_id', <?= (int)$lead_id ?>);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      document.getElementById('form-novo-interesse').reset();
      location.reload();
    }
    else alert('Erro: ' + d.msg);
  });
}

function excluirInteresse(interesse_id) {
  if (!confirm('Excluir este interesse?')) return;

  const fd = new FormData();
  fd.append('acao', 'excluir_interesse');
  fd.append('interest_id', interesse_id);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) location.reload();
    else alert('Erro: ' + d.msg);
  });
}

function salvarAgendamento(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('form-novo-agd'));
  fd.append('acao', 'novo_agendamento');
  fd.append('lead_id', <?= (int)$lead_id ?>);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      document.getElementById('form-novo-agd').reset();
      location.reload();
    }
    else alert('Erro: ' + d.msg);
  });
}

function atualizarAgendamento(agenda_id, status) {
  const fd = new FormData();
  fd.append('acao', 'status_agendamento');
  fd.append('agenda_id', agenda_id);
  fd.append('status', status);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) location.reload();
  });
}

function trocarMotoForm() {
  document.getElementById('modal-trocar-moto').style.display = 'flex';
}

function fecharModalTrocarMoto() {
  document.getElementById('modal-trocar-moto').style.display = 'none';
}

function confirmarTrocarMoto(e) {
  e.preventDefault();
  const moto_id = document.getElementById('select-moto').value;
  const fd = new FormData();
  fd.append('acao', 'trocar_moto');
  fd.append('lead_id', <?= (int)$lead_id ?>);
  fd.append('moto_id', moto_id);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) location.reload();
    else alert('Erro: ' + d.msg);
  });
}

function abrirWhatsApp(tel) {
  const url = crm_whatsapp_link(tel);
  const fd = new FormData();
  fd.append('acao', 'nova_interacao');
  fd.append('lead_id', <?= (int)$lead_id ?>);
  fd.append('tipo', 'whatsapp');
  fd.append('texto', 'Clicou para chamar no WhatsApp');

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  });

  window.open(url, '_blank');
}

function crm_whatsapp_link(tel) {
  return 'https://wa.me/55' + tel.replace(/\D/g, '') + '?text=Oi!%20Tenho%20interesse%20em%20saber%20mais...';
}

function excluirLead() {
  if (!confirm('Tem certeza que deseja excluir este lead? Essa ação não pode ser desfeita.')) return;
  if (!confirm('Excluir permanentemente?')) return;

  const fd = new FormData();
  fd.append('acao', 'excluir_lead');
  fd.append('lead_id', <?= (int)$lead_id ?>);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) window.location = '<?= base_url('painel/crm.php') ?>';
    else alert('Erro: ' + d.msg);
  });
}

function abrirModalEditarLead() {
  const modal = document.getElementById('modal-editar-lead');
  const nomeInput = document.getElementById('editar-nome');
  const emailInput = document.getElementById('editar-email');

  nomeInput.value = '<?= htmlspecialchars($lead['nome'] ?? '', ENT_QUOTES) ?>';
  emailInput.value = '<?= htmlspecialchars($lead['email'] ?? '', ENT_QUOTES) ?>';

  modal.style.display = 'flex';
}

function fecharModalEditarLead() {
  const modal = document.getElementById('modal-editar-lead');
  modal.style.display = 'none';
}

function salvarEdicaoLead(event) {
  event.preventDefault();

  const nome = document.getElementById('editar-nome').value.trim();
  const email = document.getElementById('editar-email').value.trim();

  if (!nome) {
    alert('Nome é obrigatório');
    return;
  }

  const fd = new FormData();
  fd.append('acao', 'editar_lead');
  fd.append('lead_id', <?= (int)$lead_id ?>);
  fd.append('nome', nome);
  fd.append('email', email);

  addCsrfToken(fd);
  fetch('<?= base_url('painel/crm_actions.php') ?>', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      fecharModalEditarLead();
      location.reload();
    } else alert('Erro: ' + d.msg);
  });
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    fecharModalTrocarMoto();
    fecharModalEditarLead();
  }
});
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
