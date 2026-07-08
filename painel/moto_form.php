<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

if (!function_exists('user_can')) { function user_can($p) { return true; } }
if (!user_can('create') && !user_can('edit')) {
  http_response_code(403); exit('Acesso negado');
}

$user = function_exists('current_user') ? current_user() : ['id' => 0];
$id = (int)($_GET['id'] ?? 0);
$editando = $id > 0;

$erro = '';
$ok = '';

function money_to_float($val) {
  $val = trim((string)$val);
  if ($val === '') return 0.0;
  $val = str_replace('.', '', $val);
  $val = str_replace(',', '.', $val);
  return (float)$val;
}
function moto_tem_capa($pdo, $moto_id) {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM moto_fotos WHERE moto_id=? AND is_cover=1");
    $stmt->execute([$moto_id]);
    return ((int)($stmt->fetch()['c'] ?? 0)) > 0;
  } catch (Throwable $e) { return false; }
}

// Helpers compartilhados dos campos da ficha (yn, opt_val, field_yn, etc.)
require_once __DIR__ . '/../inc/moto_fields.php';
ensure_moto_schema($pdo); // garante colunas ordem / valor_a_combinar

if ($editando) {
  $stmt = $pdo->prepare("SELECT * FROM motos WHERE id=?");
  $stmt->execute([$id]);
  $moto = $stmt->fetch();
  if (!$moto) exit('Moto não encontrada');
} else {
  $moto = array_merge([
    'titulo' => '', 'modelo' => '', 'ano_modelo' => '',
    'quilometragem' => 0, 'cor' => '', 'valor' => 0, 'valor_a_combinar' => 0, 'valor_fipe' => 0,
    'descricao' => '', 'status' => 'disponivel',
  ], moto_ficha_defaults());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $marca      = trim($_POST['modelo'] ?? '');
    $ano_modelo = trim($_POST['ano_modelo'] ?? '');
    $cor        = trim($_POST['cor'] ?? '');
    $aCombinar  = !empty($_POST['valor_a_combinar']) ? 1 : 0;
    $valor      = $aCombinar ? 0.0 : money_to_float($_POST['valor'] ?? '0');
    $status     = $editando ? ($_POST['status'] ?? 'disponivel') : 'disponivel';

    if ($marca === '' || $ano_modelo === '' || $cor === '') {
      throw new Exception('Preencha marca, ano/modelo e cor.');
    }
    if (!$aCombinar && $valor <= 0) {
      throw new Exception('Informe o valor de venda ou marque "Valor a combinar".');
    }

    // Diferencial: tags novas pra cadastrar depois
    $difTagsUnicas = moto_diferencial_tags($_POST);

    // Base + ficha (procedência, conservação, comercial, condição...)
    $dados = array_merge([
      'titulo'        => trim($_POST['titulo'] ?? ''),
      'modelo'        => $marca,
      'ano_modelo'    => $ano_modelo,
      'quilometragem' => (int)str_replace(['.', ','], '', $_POST['quilometragem'] ?? '0'),
      'cor'           => $cor,
      'valor'         => $valor,
      'valor_a_combinar' => $aCombinar,
      'valor_fipe'    => money_to_float($_POST['valor_fipe'] ?? '0'),
      'descricao'     => trim($_POST['descricao'] ?? ''),
    ], moto_ficha_collect($_POST));

    if ($editando) {
      if (!user_can('edit')) throw new Exception('Sem permissão para editar.');
      $dados['status'] = $status;
      $sets = [];
      foreach ($dados as $col => $_) $sets[] = "`$col`=?";
      $sql = "UPDATE motos SET " . implode(', ', $sets) . ", updated_at=NOW() WHERE id=?";
      $vals = array_values($dados);
      $vals[] = $id;
      $pdo->prepare($sql)->execute($vals);
    } else {
      if (!user_can('create')) throw new Exception('Sem permissão para cadastrar.');
      $dados['status']     = 'disponivel';
      $dados['created_by'] = (int)($user['id'] ?? 0);
      $cols = array_keys($dados);
      $colSql = '`' . implode('`,`', $cols) . '`';
      $ph     = implode(',', array_fill(0, count($cols), '?'));
      $sql = "INSERT INTO motos ($colSql, created_at) VALUES ($ph, NOW())";
      $pdo->prepare($sql)->execute(array_values($dados));
      $id = (int)$pdo->lastInsertId();
      $editando = true;
    }

    // Cadastra diferenciais novos pra aparecerem na próxima moto
    if ($difTagsUnicas) {
      $insOpt = $pdo->prepare("INSERT IGNORE INTO opcoes_moto (categoria, valor) VALUES ('diferencial', ?)");
      foreach ($difTagsUnicas as $t) {
        try { $insOpt->execute([$t]); } catch (Throwable $e) { /* ignora duplicados */ }
      }
    }

    if (!empty($_FILES['fotos']['name'][0])) {
      $uploadDir = __DIR__ . '/../uploads/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

      $total = min(count($_FILES['fotos']['name']), 5);

      // Novas fotos entram no fim da sequência (ordem alta) e depois reindexamos.
      for ($i = 0; $i < $total; $i++) {
        $name = $_FILES['fotos']['name'][$i] ?? '';
        $tmp  = $_FILES['fotos']['tmp_name'][$i] ?? '';
        if (!$tmp) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;
        $newName = 'moto_' . $id . '_' . time() . '_' . $i . '.' . $ext;
        if (move_uploaded_file($tmp, $uploadDir . $newName)) {
          $stmt = $pdo->prepare("INSERT INTO moto_fotos (moto_id, caminho, is_cover, ordem) VALUES (?,?,0,?)");
          $stmt->execute([$id, $newName, 1000000 + $i]);
        }
      }
      // Renumera 0,1,2... e fixa a primeira como capa (mantém a capa existente se já havia fotos)
      moto_fotos_reindex($pdo, $id);
    }

    $stmt = $pdo->prepare("SELECT * FROM motos WHERE id=?");
    $stmt->execute([$id]);
    $moto = $stmt->fetch();
    $ok = 'Salvo com sucesso!';
  } catch (Exception $e) {
    $erro = $e->getMessage();
  }
}

$fotos = [];
if ($editando) {
  $stmt = $pdo->prepare("SELECT * FROM moto_fotos WHERE moto_id=? ORDER BY ordem ASC, id ASC");
  $stmt->execute([$id]);
  $fotos = $stmt->fetchAll();
}

// Opções reutilizáveis (tags) do campo Diferencial
$opcoesDiferencial = [];
try {
  $stmt = $pdo->query("SELECT valor FROM opcoes_moto WHERE categoria='diferencial' ORDER BY valor ASC");
  $opcoesDiferencial = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $opcoesDiferencial = []; }

// Padrões de moto (presets) para preenchimento rápido
$padroesMap = [];
try {
  foreach ($pdo->query("SELECT id, nome, dados FROM motos_padroes ORDER BY nome ASC") as $row) {
    $d = json_decode($row['dados'], true);
    if (is_array($d)) $padroesMap[(int)$row['id']] = ['nome' => $row['nome'], 'dados' => $d];
  }
} catch (Throwable $e) { $padroesMap = []; }

$page_title = $editando ? 'Editar moto' : 'Nova moto';
include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">
    <div class="page-header">
      <div>
        <h1 class="page-title"><?= $editando ? 'Editar moto' : 'Nova moto' ?></h1>
        <p class="page-subtitle"><?= $editando ? 'Atualize as informações da moto e fotos.' : 'Cadastre uma nova moto no marketplace.' ?></p>
      </div>
      <a href="<?= base_url('painel/motos.php') ?>" class="btn btn-ghost">← Voltar</a>
    </div>

    <?php if ($erro): ?>
      <div class="alert alert-error"><span>⚠</span> <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
      <div class="alert alert-success"><span>✓</span> <?= htmlspecialchars($ok) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-card" style="max-width:760px;">
      <div class="form-grid">

        <div class="field" style="background:var(--surface-2);border:1px dashed var(--border);border-radius:var(--r-md);padding:14px;">
          <label>🔎 Buscar pela placa <span class="text-muted" style="font-weight:500;">(preenche automático)</span></label>
          <div class="row" style="gap:8px;align-items:stretch;">
            <input type="text" id="placaInput" placeholder="AAA0A00" maxlength="8" style="text-transform:uppercase;flex:1;">
            <button type="button" class="btn btn-secondary" id="btnPlaca">Buscar</button>
          </div>
          <small id="placaMsg" class="text-muted">Digite a placa e clique em Buscar. Marca, modelo, ano e cor são preenchidos sozinhos — depois é só conferir.</small>
        </div>

        <?php if ($padroesMap): ?>
        <div class="field" style="background:#eef6ff;border:1px dashed #9cc3f0;border-radius:var(--r-md);padding:14px;">
          <label>⚡ Aplicar um padrão <span class="text-muted" style="font-weight:500;">(preenche a ficha de uma vez)</span></label>
          <select id="padraoSelect">
            <option value="">Selecione um padrão...</option>
            <?php foreach ($padroesMap as $pid => $p): ?>
              <option value="<?= (int)$pid ?>"><?= htmlspecialchars($p['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <small id="padraoMsg" class="text-muted">Preenche procedência, conservação, condições etc. de uma vez. Você ainda pode ajustar tudo depois.</small>
        </div>
        <?php endif; ?>

        <div class="field">
          <label>Título / Modelo</label>
          <input type="text" name="titulo" value="<?= htmlspecialchars($moto['titulo']) ?>" placeholder="Ex: CG 160 Fan 2024 — revisada, único dono">
          <small>Nome completo que aparece em destaque no card.</small>
        </div>

        <div class="form-grid form-grid-2">
          <div class="field">
            <label>Marca *</label>
            <select name="modelo" required>
              <option value="">Selecione...</option>
              <?php
                $marcas = ['Honda','Yamaha','Kawasaki','Suzuki','BMW','Dafra','Haojue','Shineray','Royal Enfield','Triumph','KTM','Outra'];
                foreach ($marcas as $marca) {
                  $sel = ($moto['modelo'] === $marca) ? 'selected' : '';
                  echo "<option value=\"".htmlspecialchars($marca)."\" $sel>".htmlspecialchars($marca)."</option>";
                }
              ?>
            </select>
          </div>
          <div class="field">
            <label>Ano/Modelo *</label>
            <input type="text" name="ano_modelo" value="<?= htmlspecialchars($moto['ano_modelo']) ?>" placeholder="Ex: 2023/2024" required>
          </div>
        </div>

        <div class="form-grid form-grid-2">
          <div class="field">
            <label>Quilometragem (km) *</label>
            <input type="text" name="quilometragem" id="kmInput" value="<?= number_format((int)$moto['quilometragem'], 0, ',', '.') ?>" placeholder="Ex: 12.500" required inputmode="numeric">
          </div>
          <div class="field">
            <label>Cor *</label>
            <input type="text" name="cor" value="<?= htmlspecialchars($moto['cor']) ?>" placeholder="Ex: Prata" required>
          </div>
        </div>

        <div class="form-grid form-grid-2">
          <?= field_opt('Condição', 'condicao', $moto['condicao'] ?? '', ['nova'=>'Nova (0 km)','seminova'=>'Seminova']) ?>
          <div class="field field-prefix">
            <label>Valor FIPE <span class="text-muted" style="font-weight:500;">(referência)</span></label>
            <span>R$</span>
            <input type="text" name="valor_fipe" id="valorFipeInput" value="<?= number_format((float)($moto['valor_fipe'] ?? 0), 2, ',', '.') ?>" placeholder="0,00" inputmode="decimal">
          </div>
        </div>

        <div class="field field-prefix" id="valorWrap">
          <label>Valor de venda</label>
          <span>R$</span>
          <input type="text" name="valor" id="valorInput" value="<?= number_format((float)$moto['valor'], 2, ',', '.') ?>" placeholder="0,00" inputmode="decimal">
        </div>
        <label class="check-line">
          <input type="checkbox" name="valor_a_combinar" id="valorCombinarChk" value="1" <?= !empty($moto['valor_a_combinar']) ? 'checked' : '' ?>>
          <span>Valor a combinar com o consultor <span class="text-muted" style="font-weight:500;">(o preço não aparece no site — mostra "Sob consulta")</span></span>
        </label>

        <div class="field">
          <label>Descrição</label>
          <textarea name="descricao" rows="4" placeholder="Detalhes: revisões, pneus, acessórios..."><?= htmlspecialchars($moto['descricao']) ?></textarea>
        </div>

        <!-- ===== PROCEDÊNCIA ===== -->
        <h2 style="font-size:15px;font-weight:800;margin:8px 0 -4px;padding-top:12px;border-top:1px solid var(--border-soft);">Procedência</h2>
        <small class="text-muted" style="margin-top:-8px;">Deixe em "não informado" o que não se aplica.</small>
        <div class="form-grid form-grid-2">
          <?= field_yn('É único dono?', 'unico_dono', $moto['unico_dono']) ?>
          <?= field_yn('Possui manual do proprietário?', 'tem_manual', $moto['tem_manual']) ?>
          <?= field_yn('Revisada em concessionária autorizada?', 'revisada_autorizada', $moto['revisada_autorizada']) ?>
          <?= field_yn('Em garantia de fábrica?', 'garantia_fabrica', $moto['garantia_fabrica']) ?>
          <?= field_yn('Possui chave reserva?', 'chave_reserva', $moto['chave_reserva']) ?>
          <?= field_yn('Revisões feitas regularmente?', 'revisoes_regulares', $moto['revisoes_regulares']) ?>
          <?= field_yn('Histórico de leilão, sinistro ou recuperação?', 'historico_negativo', $moto['historico_negativo']) ?>
          <?= field_yn('Possui laudo cautelar aprovado?', 'laudo_cautelar', $moto['laudo_cautelar']) ?>
        </div>

        <!-- ===== CONSERVAÇÃO / FICHA ===== -->
        <h2 style="font-size:15px;font-weight:800;margin:8px 0 -4px;padding-top:12px;border-top:1px solid var(--border-soft);">Conservação e ficha técnica</h2>
        <div class="form-grid form-grid-2">
          <?= field_opt('Conservação', 'conservacao', $moto['conservacao'], ['impecavel'=>'Impecável','excelente'=>'Excelente','muito_boa'=>'Muito boa','boa'=>'Boa']) ?>
          <div class="field">
            <label>Detalhe estético relevante</label>
            <input type="text" name="detalhe_estetico" value="<?= htmlspecialchars($moto['detalhe_estetico']) ?>" placeholder="Ex: pequeno risco no paralama">
          </div>
        </div>
        <div class="form-grid form-grid-2">
          <div class="field">
            <label>Pneu dianteiro (% de vida útil)</label>
            <input type="number" name="pneu_dianteiro" min="0" max="100" value="<?= htmlspecialchars((string)($moto['pneu_dianteiro'] ?? '')) ?>" placeholder="Ex: 80">
          </div>
          <div class="field">
            <label>Pneu traseiro (% de vida útil)</label>
            <input type="number" name="pneu_traseiro" min="0" max="100" value="<?= htmlspecialchars((string)($moto['pneu_traseiro'] ?? '')) ?>" placeholder="Ex: 70">
          </div>
        </div>
        <div class="form-grid form-grid-2">
          <?= field_opt('Relação (corrente/coroa/pinhão)', 'relacao', $moto['relacao'], ['nova'=>'Nova','boa'=>'Boa','regular'=>'Regular']) ?>
          <?= field_opt('Freios', 'freios', $moto['freios'], ['novos'=>'Novos','bons'=>'Bons','regular'=>'Regular']) ?>
        </div>

        <!-- ===== DIFERENCIAL (tags) ===== -->
        <div class="field">
          <label>Diferencial — o que mais chama atenção nessa moto?</label>
          <div id="tagBox" class="tag-box">
            <div id="tagChips" class="tag-chips"></div>
            <input type="text" id="tagInput" class="tag-input" placeholder="Escolha abaixo ou digite e tecle Enter…" autocomplete="off">
            <div id="tagSuggest" class="tag-suggest"></div>
          </div>
          <input type="hidden" name="diferencial" id="diferencialHidden" value="<?= htmlspecialchars($moto['diferencial']) ?>">
          <small class="text-muted">Selecione um ou mais. O que você digitar de novo fica salvo para as próximas motos.</small>
        </div>

        <!-- ===== CONDIÇÕES COMERCIAIS ===== -->
        <h2 style="font-size:15px;font-weight:800;margin:8px 0 -4px;padding-top:12px;border-top:1px solid var(--border-soft);">Condições comerciais</h2>
        <div class="form-grid form-grid-2">
          <?= field_yn('Aceita troca?', 'aceita_troca', $moto['aceita_troca']) ?>
          <?= field_yn('Aceita carta de crédito?', 'aceita_carta', $moto['aceita_carta']) ?>
          <?= field_yn('Financiamento disponível?', 'financiamento', $moto['financiamento']) ?>
          <?= field_yn('Possui garantia (loja)?', 'garantia_loja', $moto['garantia_loja']) ?>
        </div>

        <?php if ($editando): ?>
          <div class="field">
            <label>Status</label>
            <select name="status">
              <option value="disponivel" <?= $moto['status']==='disponivel' ? 'selected' : '' ?>>Disponível</option>
              <option value="reservada"  <?= $moto['status']==='reservada'  ? 'selected' : '' ?>>Reservada</option>
              <option value="vendida"    <?= $moto['status']==='vendida'    ? 'selected' : '' ?>>Vendida</option>
            </select>
            <small>"Vendida" não aparece no marketplace.</small>
          </div>
        <?php endif; ?>

        <div class="field">
          <label>Fotos (até 5)</label>
          <input type="file" name="fotos[]" id="fotos" multiple accept="image/*">
          <small>JPG, PNG ou WEBP.</small>
          <div class="foto-dica">
            ✂️ Ao escolher as fotos, abre um <b>editor de recorte</b>: arraste e dê zoom pra enquadrar cada uma no formato <b>4:3</b> (o que aparece no site). Se preferir, use "Usar sem cortar".
            A <b>1ª foto</b> vira a capa — você pode reordenar depois de salvar.
          </div>
          <div id="preview" class="photo-grid mt-2"></div>
        </div>

        <div class="row" style="gap:8px;">
          <button class="btn btn-primary btn-lg" type="submit">
            <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
            Salvar
          </button>
          <a class="btn btn-secondary btn-lg" href="<?= base_url('painel/motos.php') ?>">Cancelar</a>
        </div>
      </div>
    </form>

    <?php if ($editando): ?>
      <div class="form-card mt-6" style="max-width:760px;">
        <h2 style="font-size:18px;margin-bottom:8px;">Fotos atuais</h2>
        <p class="text-sm text-muted mb-3">Use as setas ‹ › para ordenar. A <b>1ª foto (Capa)</b> é a que aparece primeiro no site.</p>

        <?php if (!$fotos): ?>
          <p class="text-muted">Nenhuma foto cadastrada ainda.</p>
        <?php else: ?>
          <div class="photo-grid">
            <?php $totFotos = count($fotos); foreach ($fotos as $pos => $f): ?>
              <div class="photo-thumb">
                <img src="<?= base_url('uploads/' . htmlspecialchars($f['caminho'])) ?>" alt="">
                <div class="photo-pos"><?= $pos + 1 ?></div>
                <?php if ($pos === 0): ?>
                  <div class="photo-cover-tag">Capa</div>
                <?php endif; ?>
                <a class="photo-x"
                   href="<?= base_url('painel/moto_foto_delete.php?moto_id='.(int)$id.'&foto_id='.(int)$f['id']) ?>"
                   onclick="return confirm('Apagar esta foto?')" title="Apagar">×</a>
                <div class="photo-actions">
                  <?php if ($pos > 0): ?>
                    <a href="<?= base_url('painel/moto_foto_reorder.php?moto_id='.(int)$id.'&foto_id='.(int)$f['id'].'&dir=antes') ?>" title="Mover para trás">‹</a>
                  <?php else: ?>
                    <span class="disabled">‹</span>
                  <?php endif; ?>
                  <?php if ($pos > 0): ?>
                    <a href="<?= base_url('painel/moto_set_cover.php?moto_id='.(int)$id.'&foto_id='.(int)$f['id']) ?>"
                       title="Tornar capa (mover para o início)">Capa</a>
                  <?php else: ?>
                    <span class="is-cover">Capa</span>
                  <?php endif; ?>
                  <?php if ($pos < $totFotos - 1): ?>
                    <a href="<?= base_url('painel/moto_foto_reorder.php?moto_id='.(int)$id.'&foto_id='.(int)$f['id'].'&dir=depois') ?>" title="Mover para frente">›</a>
                  <?php else: ?>
                    <span class="disabled">›</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Modal de recorte de foto (4:3) -->
<div id="cropModal" class="crop-modal">
  <div class="crop-box">
    <div class="crop-head">
      <strong>Enquadrar foto</strong>
      <span id="cropCount" class="text-muted"></span>
    </div>
    <p class="text-sm text-muted" style="margin:-2px 0 8px;">Arraste a foto e use o zoom para enquadrar. A área dentro do quadro é a que vai aparecer.</p>
    <div class="crop-stage" id="cropStage">
      <img id="cropImg" alt="">
    </div>
    <div class="crop-zoom">
      <span>–</span>
      <input type="range" id="cropZoom" min="1" max="3" step="0.01" value="1">
      <span>+</span>
    </div>
    <div class="crop-actions">
      <button type="button" class="btn btn-ghost" id="cropCancel">Cancelar</button>
      <button type="button" class="btn btn-secondary" id="cropSkip">Usar sem cortar</button>
      <button type="button" class="btn btn-primary" id="cropOk">Cortar e usar</button>
    </div>
  </div>
</div>

<style>
  .crop-modal{ display:none; position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.6); padding:16px; overflow-y:auto; }
  .crop-modal.open{ display:flex; align-items:flex-start; justify-content:center; }
  .crop-box{ background:var(--surface); border-radius:var(--r-lg,14px); box-shadow:var(--shadow-lg,0 20px 50px rgba(0,0,0,.3)); width:100%; max-width:520px; margin:24px auto; padding:16px; }
  .crop-head{ display:flex; align-items:baseline; justify-content:space-between; }
  .crop-head strong{ font-size:17px; }
  .crop-stage{ position:relative; width:100%; aspect-ratio:4/3; background:#111; border-radius:var(--r-md); overflow:hidden; cursor:grab; touch-action:none; }
  .crop-stage:active{ cursor:grabbing; }
  .crop-stage img{ position:absolute; top:0; left:0; max-width:none; user-select:none; -webkit-user-drag:none; pointer-events:none; }
  .crop-zoom{ display:flex; align-items:center; gap:10px; margin:12px 2px 4px; color:var(--text-muted); font-weight:800; }
  .crop-zoom input{ flex:1; }
  .crop-actions{ display:flex; gap:8px; justify-content:flex-end; margin-top:12px; flex-wrap:wrap; }
</style>

<style>
  .tag-box{ position:relative; border:1px solid var(--border); border-radius:var(--r-md); padding:8px; background:var(--surface); }
  .tag-chips{ display:flex; flex-wrap:wrap; gap:6px; }
  .tag-chip{ display:inline-flex; align-items:center; gap:6px; background:var(--brand-50,#fff5f5); color:var(--brand-700,#b00000); border:1px solid var(--brand-100,#ffe0e0); border-radius:999px; padding:4px 10px; font-size:13px; font-weight:600; }
  .tag-chip button{ border:none; background:none; cursor:pointer; color:inherit; font-weight:900; line-height:1; font-size:15px; padding:0; }
  .tag-input{ border:none; outline:none; width:100%; padding:6px 4px; background:transparent; font-size:14px; }
  .tag-chips:empty + .tag-input{ margin-top:0; }
  .tag-suggest{ display:none; position:absolute; left:0; right:0; top:100%; z-index:30; background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md); margin-top:4px; max-height:220px; overflow:auto; box-shadow:var(--shadow-md); }
  .tag-suggest.open{ display:block; }
  .tag-suggest-item{ padding:9px 12px; cursor:pointer; font-size:14px; }
  .tag-suggest-item:hover{ background:var(--surface-2); }
  .tag-suggest-new{ color:var(--brand-700,#b00000); font-weight:700; }
  .check-line{ display:flex; align-items:flex-start; gap:8px; cursor:pointer; font-size:14px; font-weight:600; padding:4px 2px; }
  .check-line input{ width:18px; height:18px; margin-top:1px; flex-shrink:0; cursor:pointer; }
  .photo-pos{ position:absolute; top:6px; left:6px; background:rgba(0,0,0,.7); color:#fff; width:22px; height:22px; border-radius:999px; display:grid; place-items:center; font-size:12px; font-weight:800; z-index:2; }
  .photo-actions span{ flex:1; text-align:center; padding:6px 8px; border-radius:var(--r-sm); background:rgba(255,255,255,.92); color:var(--text); font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
  .photo-actions span.is-cover{ background:var(--green-600,#16a34a); color:#fff; }
  .photo-actions span.disabled{ opacity:.35; }
  .foto-dica{ margin-top:8px; padding:10px 12px; background:#eef6ff; border:1px dashed #9cc3f0; border-radius:var(--r-md); font-size:12.5px; line-height:1.5; color:var(--text-soft); }
</style>
<script>
// Busca pela placa (preenche o formulário)
const btnPlaca   = document.getElementById('btnPlaca');
const placaInput = document.getElementById('placaInput');
const placaMsg   = document.getElementById('placaMsg');

async function buscarPlaca() {
  const placa = (placaInput.value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  if (placa.length !== 7) {
    placaMsg.textContent = '⚠ Placa inválida. Use AAA0A00 ou AAA9999.';
    placaMsg.style.color = '#b00';
    return;
  }
  btnPlaca.disabled = true;
  placaMsg.style.color = '';
  placaMsg.textContent = 'Consultando...';
  try {
    const resp = await fetch('<?= base_url('painel/placa_lookup.php') ?>?placa=' + encodeURIComponent(placa));
    const j = await resp.json();
    if (!j.ok) {
      placaMsg.textContent = '⚠ ' + (j.error || 'Não encontrado.');
      placaMsg.style.color = '#b00';
      return;
    }
    const form = document.querySelector('form.form-card');
    const marcaSel = form.querySelector('select[name="modelo"]');
    if (marcaSel && j.marca_select) marcaSel.value = j.marca_select;

    const titulo = form.querySelector('input[name="titulo"]');
    if (titulo && !titulo.value && j.modelo) titulo.value = j.modelo;

    const ano = form.querySelector('input[name="ano_modelo"]');
    if (ano && j.ano) ano.value = j.ano;

    const cor = form.querySelector('input[name="cor"]');
    if (cor && j.cor) cor.value = j.cor;

    // FIPE (ex: "R$ 28.799,00") -> campo Valor FIPE
    const fipeEl = form.querySelector('input[name="valor_fipe"]');
    if (fipeEl && j.fipe_texto) {
      const num = (j.fipe_texto.match(/[\d.,]+/) || [''])[0].replace(/\./g, '').replace(',', '.');
      if (num) fipeEl.value = Number(num).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    let msg = '✓ Preenchido' + (j.marca_api ? ' (' + j.marca_api + (j.modelo ? ' ' + j.modelo : '') + ')' : '') + '. Confira e ajuste.';
    if (j.fipe_texto) msg += ' · FIPE ref.: ' + j.fipe_texto;
    placaMsg.textContent = msg;
    placaMsg.style.color = '#16a34a';
  } catch (e) {
    placaMsg.textContent = '⚠ Erro ao consultar. Tente novamente.';
    placaMsg.style.color = '#b00';
  } finally {
    btnPlaca.disabled = false;
  }
}
if (btnPlaca) {
  btnPlaca.addEventListener('click', buscarPlaca);
  placaInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); buscarPlaca(); }
  });
}

// ===== Upload com recorte 4:3 =====
const input = document.getElementById('fotos');
const preview = document.getElementById('preview');
const OUT_W = 1200, OUT_H = 900; // saída 4:3

// Cropper
const cm      = document.getElementById('cropModal');
const stage   = document.getElementById('cropStage');
const cImg     = document.getElementById('cropImg');
const zoomEl  = document.getElementById('cropZoom');
const btnOk   = document.getElementById('cropOk');
const btnSkip = document.getElementById('cropSkip');
const btnCancel = document.getElementById('cropCancel');
const cCount  = document.getElementById('cropCount');

let fila = [], resultados = [], atual = 0, natW = 0, natH = 0;
let base = 1, zoom = 1, ox = 0, oy = 0, sw = 0, sh = 0;

function stageSize(){ sw = stage.clientWidth; sh = stage.clientHeight; }

function aplicarTransform(){
  const dw = natW * base * zoom, dh = natH * base * zoom;
  // limita para a imagem sempre cobrir o quadro
  ox = Math.min(0, Math.max(sw - dw, ox));
  oy = Math.min(0, Math.max(sh - dh, oy));
  cImg.style.width = dw + 'px';
  cImg.style.height = dh + 'px';
  cImg.style.transform = 'translate(' + ox + 'px,' + oy + 'px)';
}

function abrirCropper(file){
  const url = URL.createObjectURL(file);
  cImg.onload = () => {
    natW = cImg.naturalWidth; natH = cImg.naturalHeight;
    stageSize();
    base = Math.max(sw / natW, sh / natH); // cobre o quadro
    zoom = 1; zoomEl.value = '1';
    const dw = natW * base, dh = natH * base;
    ox = (sw - dw) / 2; oy = (sh - dh) / 2;
    aplicarTransform();
    cCount.textContent = (atual + 1) + ' / ' + fila.length;
    cm.classList.add('open');
    document.body.style.overflow = 'hidden';
  };
  cImg.src = url;
}

function fecharCropper(){ cm.classList.remove('open'); document.body.style.overflow = ''; }

function proxima(){
  atual++;
  if (atual < fila.length) abrirCropper(fila[atual]);
  else finalizar();
}

function gerarRecorte(cb){
  const esc = base * zoom;
  const srcX = (-ox) / esc, srcY = (-oy) / esc;
  const srcW = sw / esc, srcH = sh / esc;
  const cv = document.createElement('canvas');
  cv.width = OUT_W; cv.height = OUT_H;
  const ctx = cv.getContext('2d');
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(cImg, srcX, srcY, srcW, srcH, 0, 0, OUT_W, OUT_H);
  cv.toBlob(blob => {
    const nome = (fila[atual].name || ('foto' + atual)).replace(/\.[^.]+$/, '') + '.jpg';
    cb(new File([blob], nome, { type: 'image/jpeg' }));
  }, 'image/jpeg', 0.9);
}

function finalizar(){
  fecharCropper();
  // aplica os arquivos recortados no input
  const dt = new DataTransfer();
  resultados.forEach(f => dt.items.add(f));
  input.files = dt.files;
  // preview
  preview.innerHTML = '';
  resultados.forEach(f => {
    const box = document.createElement('div'); box.className = 'photo-thumb';
    const im = document.createElement('img'); im.src = URL.createObjectURL(f);
    box.appendChild(im); preview.appendChild(box);
  });
}

if (input) {
  input.addEventListener('change', () => {
    const files = Array.from(input.files || []).filter(f => f.type.startsWith('image/')).slice(0, 5);
    if (!files.length) return;
    fila = files; resultados = []; atual = 0;
    abrirCropper(fila[0]);
  });

  // Zoom
  zoomEl.addEventListener('input', () => {
    // mantém o centro do quadro ao dar zoom
    const cx = (sw/2 - ox), cy = (sh/2 - oy);
    const antigo = base * zoom;
    zoom = parseFloat(zoomEl.value);
    const novo = base * zoom;
    ox = sw/2 - cx * (novo/antigo);
    oy = sh/2 - cy * (novo/antigo);
    aplicarTransform();
  });

  // Arrastar (mouse + toque)
  let dragging = false, px = 0, py = 0;
  function down(x, y){ dragging = true; px = x; py = y; }
  function move(x, y){ if(!dragging) return; ox += x - px; oy += y - py; px = x; py = y; aplicarTransform(); }
  function up(){ dragging = false; }
  stage.addEventListener('mousedown', e => down(e.clientX, e.clientY));
  window.addEventListener('mousemove', e => move(e.clientX, e.clientY));
  window.addEventListener('mouseup', up);
  stage.addEventListener('touchstart', e => { if(e.touches[0]) down(e.touches[0].clientX, e.touches[0].clientY); }, {passive:true});
  stage.addEventListener('touchmove', e => { if(e.touches[0]){ e.preventDefault(); move(e.touches[0].clientX, e.touches[0].clientY); } }, {passive:false});
  stage.addEventListener('touchend', up);

  btnOk.addEventListener('click', () => { gerarRecorte(f => { resultados.push(f); proxima(); }); });
  // pular recorte: usa o arquivo original
  btnSkip.addEventListener('click', () => { resultados.push(fila[atual]); proxima(); });
  btnCancel.addEventListener('click', () => { fila = []; resultados = []; input.value = ''; fecharCropper(); });
}

// Máscara de km (com pontos)
const kmEl = document.getElementById('kmInput');
if (kmEl) {
  kmEl.addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g, '');
    e.target.value = v ? Number(v).toLocaleString('pt-BR') : '';
  });
}

// Máscara de valor (R$ 0,00) — aplica nos dois campos de dinheiro
function aplicarMascaraMoeda(el){
  if (!el) return;
  el.addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g, '');
    v = (v / 100).toFixed(2);
    e.target.value = Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  });
}
aplicarMascaraMoeda(document.getElementById('valorInput'));
aplicarMascaraMoeda(document.getElementById('valorFipeInput'));

// "Valor a combinar" desabilita o campo de preço
const valorChk = document.getElementById('valorCombinarChk');
const valorInput = document.getElementById('valorInput');
const valorWrap = document.getElementById('valorWrap');
function syncValorCombinar(){
  if (!valorChk || !valorInput) return;
  const on = valorChk.checked;
  valorInput.disabled = on;
  if (on) valorInput.value = '';
  if (valorWrap) valorWrap.style.opacity = on ? '.45' : '';
}
if (valorChk){ valorChk.addEventListener('change', syncValorCombinar); syncValorCombinar(); }

// ===== Campo de TAGS do Diferencial =====
const DIF_OPCOES = <?= json_encode(array_values($opcoesDiferencial), JSON_UNESCAPED_UNICODE) ?>;
const DIF_ATUAIS = <?= json_encode(array_values(array_filter(array_map('trim', explode(',', (string)($moto['diferencial'] ?? ''))), function($v){ return $v !== ''; })), JSON_UNESCAPED_UNICODE) ?>;

window.difTagField = (function(){
  const box = document.getElementById('tagBox');
  if (!box) return null;
  const chipsEl = document.getElementById('tagChips');
  const inputEl = document.getElementById('tagInput');
  const sugEl   = document.getElementById('tagSuggest');
  const hidden  = document.getElementById('diferencialHidden');
  let chips = Array.isArray(DIF_ATUAIS) ? DIF_ATUAIS.slice() : [];

  function sync(){ hidden.value = chips.join(', '); }
  function temChip(v){ return chips.some(c => c.toLowerCase() === v.toLowerCase()); }

  function renderChips(){
    chipsEl.innerHTML = '';
    chips.forEach((t, i) => {
      const c = document.createElement('span');
      c.className = 'tag-chip';
      c.appendChild(document.createTextNode(t));
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = '×';
      b.addEventListener('click', () => { chips.splice(i, 1); renderChips(); renderSuggest(); });
      c.appendChild(b);
      chipsEl.appendChild(c);
    });
    sync();
  }
  function addTag(val){
    val = (val || '').trim();
    if (!val || temChip(val)) return;
    chips.push(val);
    renderChips();
  }
  function renderSuggest(){
    const q = inputEl.value.trim().toLowerCase();
    const disponiveis = DIF_OPCOES.filter(o => !temChip(o)).filter(o => o.toLowerCase().includes(q));
    sugEl.innerHTML = '';
    disponiveis.slice(0, 25).forEach(o => {
      const d = document.createElement('div');
      d.className = 'tag-suggest-item';
      d.textContent = o;
      d.addEventListener('mousedown', e => { e.preventDefault(); addTag(o); inputEl.value = ''; renderSuggest(); });
      sugEl.appendChild(d);
    });
    const txt = inputEl.value.trim();
    if (txt && !DIF_OPCOES.some(o => o.toLowerCase() === txt.toLowerCase()) && !temChip(txt)) {
      const d = document.createElement('div');
      d.className = 'tag-suggest-item tag-suggest-new';
      d.textContent = '+ Adicionar "' + txt + '"';
      d.addEventListener('mousedown', e => { e.preventDefault(); addTag(txt); inputEl.value = ''; renderSuggest(); });
      sugEl.appendChild(d);
    }
    sugEl.classList.toggle('open', sugEl.children.length > 0);
  }

  inputEl.addEventListener('focus', renderSuggest);
  inputEl.addEventListener('input', renderSuggest);
  inputEl.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addTag(inputEl.value); inputEl.value = ''; renderSuggest(); }
    else if (e.key === 'Backspace' && inputEl.value === '' && chips.length) { chips.pop(); renderChips(); renderSuggest(); }
  });
  document.addEventListener('click', e => { if (!box.contains(e.target)) sugEl.classList.remove('open'); });

  renderChips();
  return {
    set: function(arr){ chips = Array.isArray(arr) ? arr.slice() : []; renderChips(); }
  };
})();

// ===== Aplicar PADRÃO (preenche a ficha de uma vez) =====
const PADROES = <?= json_encode($padroesMap, JSON_UNESCAPED_UNICODE) ?>;
const padraoSel = document.getElementById('padraoSelect');
const padraoMsg = document.getElementById('padraoMsg');
if (padraoSel) {
  padraoSel.addEventListener('change', () => {
    const item = PADROES[padraoSel.value];
    if (!item || !item.dados) return;
    const p = item.dados;
    const form = document.querySelector('form.form-card');
    Object.keys(p).forEach(campo => {
      if (campo === 'diferencial') return; // tratado pelo widget de tags
      if (p[campo] === null || p[campo] === '') return; // só preenche o que o padrão define
      const el = form.querySelector('[name="' + campo + '"]');
      if (el) el.value = p[campo];
    });
    if (window.difTagField && typeof p.diferencial === 'string' && p.diferencial.trim() !== '') {
      window.difTagField.set(p.diferencial.split(',').map(s => s.trim()).filter(Boolean));
    }
    if (padraoMsg) {
      padraoMsg.textContent = '✓ Padrão "' + item.nome + '" aplicado. Confira e ajuste o que precisar.';
      padraoMsg.style.color = '#16a34a';
    }
  });
}
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
