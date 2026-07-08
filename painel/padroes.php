<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/moto_fields.php';
require_login();
require_role('gerente');

$erro = '';
$ok   = '';

// ---- Excluir ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
  $pid = (int)($_POST['id'] ?? 0);
  if ($pid > 0) {
    $pdo->prepare("DELETE FROM motos_padroes WHERE id=?")->execute([$pid]);
    $ok = 'Padrão excluído.';
  }
}

// ---- Criar / Editar ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {
  try {
    $nome = trim($_POST['nome'] ?? '');
    if ($nome === '') throw new Exception('Dê um nome ao padrão (ex: "Moto excelente").');

    $dados = moto_ficha_collect($_POST);
    $json  = json_encode($dados, JSON_UNESCAPED_UNICODE);
    $pid   = (int)($_POST['id'] ?? 0);

    if ($pid > 0) {
      $pdo->prepare("UPDATE motos_padroes SET nome=?, dados=? WHERE id=?")->execute([$nome, $json, $pid]);
      $ok = 'Padrão atualizado com sucesso.';
    } else {
      $pdo->prepare("INSERT INTO motos_padroes (nome, dados) VALUES (?, ?)")->execute([$nome, $json]);
      $ok = 'Padrão criado com sucesso.';
    }

    // Diferenciais digitados aqui também viram opções reutilizáveis
    foreach (moto_diferencial_tags($_POST) as $t) {
      try { $pdo->prepare("INSERT IGNORE INTO opcoes_moto (categoria, valor) VALUES ('diferencial', ?)")->execute([$t]); } catch (Throwable $e) {}
    }
  } catch (Exception $e) {
    $erro = $e->getMessage();
  }
}

// ---- Carrega padrão para edição ----
$idEdit = 0;
$nomeEdit = '';
$vals = moto_ficha_defaults();
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM motos_padroes WHERE id=?");
  $stmt->execute([$editId]);
  $edit = $stmt->fetch();
  if ($edit) {
    $idEdit   = (int)$edit['id'];
    $nomeEdit = $edit['nome'];
    $d = json_decode($edit['dados'], true);
    if (is_array($d)) $vals = array_merge($vals, $d);
  }
}

// ---- Lista ----
$lista = [];
try { $lista = $pdo->query("SELECT id, nome FROM motos_padroes ORDER BY nome ASC")->fetchAll(); } catch (Throwable $e) {}

$page_title = 'Padrões de moto';
include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">
    <div class="page-header">
      <div>
        <h1 class="page-title">Padrões de moto</h1>
        <p class="page-subtitle">Crie fichas prontas (ex: "Moto excelente") para preencher o cadastro com 1 clique.</p>
      </div>
    </div>

    <?php if ($erro): ?>
      <div class="alert alert-error"><span>⚠</span> <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
      <div class="alert alert-success"><span>✓</span> <?= htmlspecialchars($ok) ?></div>
    <?php endif; ?>

    <div class="form-grid form-grid-2" style="align-items:start;gap:var(--space-6);">

      <!-- ===== Lista de padrões ===== -->
      <div class="card">
        <div class="card-pad" style="border-bottom:1px solid var(--border-soft);">
          <h2 style="font-size:16px;">Padrões salvos</h2>
        </div>
        <div class="stack" style="padding:12px;">
          <?php if (!$lista): ?>
            <p class="text-muted" style="padding:8px;">Nenhum padrão ainda. Crie o primeiro ao lado →</p>
          <?php else: foreach ($lista as $p): ?>
            <div class="row-between card card-pad" style="gap:8px;">
              <strong><?= htmlspecialchars($p['nome']) ?></strong>
              <div class="row" style="gap:6px;">
                <a class="btn btn-sm btn-secondary" href="<?= base_url('painel/padroes.php?edit=' . (int)$p['id']) ?>">Editar</a>
                <form method="post" onsubmit="return confirm('Excluir este padrão?')" style="margin:0;">
                  <input type="hidden" name="acao" value="excluir">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Excluir</button>
                </form>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- ===== Form criar/editar ===== -->
      <form method="post" class="form-card">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int)$idEdit ?>">
        <div class="form-grid">

          <div class="field">
            <label><?= $idEdit ? 'Editar padrão' : 'Novo padrão' ?> — Nome *</label>
            <input type="text" name="nome" value="<?= htmlspecialchars($nomeEdit) ?>" placeholder="Ex: Moto excelente" required>
            <small>Campos deixados em "não informado" não alteram nada ao aplicar o padrão.</small>
          </div>

          <?= field_opt('Condição', 'condicao', $vals['condicao'], ['nova'=>'Nova (0 km)','seminova'=>'Seminova']) ?>

          <h2 style="font-size:15px;font-weight:800;margin:8px 0 -4px;padding-top:12px;border-top:1px solid var(--border-soft);">Procedência</h2>
          <div class="form-grid form-grid-2">
            <?= field_yn('É único dono?', 'unico_dono', $vals['unico_dono']) ?>
            <?= field_yn('Possui manual do proprietário?', 'tem_manual', $vals['tem_manual']) ?>
            <?= field_yn('Revisada em concessionária autorizada?', 'revisada_autorizada', $vals['revisada_autorizada']) ?>
            <?= field_yn('Em garantia de fábrica?', 'garantia_fabrica', $vals['garantia_fabrica']) ?>
            <?= field_yn('Possui chave reserva?', 'chave_reserva', $vals['chave_reserva']) ?>
            <?= field_yn('Revisões feitas regularmente?', 'revisoes_regulares', $vals['revisoes_regulares']) ?>
            <?= field_yn('Histórico de leilão, sinistro ou recuperação?', 'historico_negativo', $vals['historico_negativo']) ?>
            <?= field_yn('Possui laudo cautelar aprovado?', 'laudo_cautelar', $vals['laudo_cautelar']) ?>
          </div>

          <h2 style="font-size:15px;font-weight:800;margin:8px 0 -4px;padding-top:12px;border-top:1px solid var(--border-soft);">Conservação e mecânica</h2>
          <div class="form-grid form-grid-2">
            <?= field_opt('Conservação', 'conservacao', $vals['conservacao'], ['impecavel'=>'Impecável','excelente'=>'Excelente','muito_boa'=>'Muito boa','boa'=>'Boa']) ?>
            <?= field_opt('Relação (corrente/coroa/pinhão)', 'relacao', $vals['relacao'], ['nova'=>'Nova','boa'=>'Boa','regular'=>'Regular']) ?>
            <?= field_opt('Freios', 'freios', $vals['freios'], ['novos'=>'Novos','bons'=>'Bons','regular'=>'Regular']) ?>
          </div>

          <div class="field">
            <label>Diferencial (separe por vírgula)</label>
            <input type="text" name="diferencial" value="<?= htmlspecialchars($vals['diferencial']) ?>" placeholder="Ex: Toda original, Muito conservada, Revisões em concessionária">
            <small>No cadastro da moto isso vira etiquetas. O que você escrever aqui também fica salvo na lista.</small>
          </div>

          <h2 style="font-size:15px;font-weight:800;margin:8px 0 -4px;padding-top:12px;border-top:1px solid var(--border-soft);">Condições comerciais</h2>
          <div class="form-grid form-grid-2">
            <?= field_yn('Aceita troca?', 'aceita_troca', $vals['aceita_troca']) ?>
            <?= field_yn('Aceita carta de crédito?', 'aceita_carta', $vals['aceita_carta']) ?>
            <?= field_yn('Financiamento disponível?', 'financiamento', $vals['financiamento']) ?>
            <?= field_yn('Possui garantia (loja)?', 'garantia_loja', $vals['garantia_loja']) ?>
          </div>

          <div class="row" style="gap:8px;">
            <button class="btn btn-primary btn-lg" type="submit"><?= $idEdit ? 'Salvar alterações' : 'Criar padrão' ?></button>
            <?php if ($idEdit): ?>
              <a class="btn btn-secondary btn-lg" href="<?= base_url('painel/padroes.php') ?>">Cancelar</a>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../inc/footer.php'; ?>
