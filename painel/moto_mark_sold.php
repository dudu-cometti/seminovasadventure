<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/moto_fields.php';
require_once __DIR__ . '/../inc/crm.php';
require_login();

if (!user_can('edit')) {
    http_response_code(403);
    echo 'Acesso negado';
    exit;
}

// Garante tabela vendas + coluna sold_at (antes de qualquer transação)
ensure_vendas_schema($pdo);
ensure_crm_schema($pdo);

$user = current_user();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('ID inválido');

$stmt = $pdo->prepare("SELECT * FROM motos WHERE id = ?");
$stmt->execute([$id]);
$moto = $stmt->fetch();
if (!$moto) die('Moto não encontrada');

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valor_venda   = (float)str_replace(['.', ','], ['', '.'], $_POST['valor_venda'] ?? '0');
    $vendedorId    = (int)($_POST['vendedor_id'] ?? 0);
    // Nome do vendedor vem do usuário selecionado (garante consistência)
    $vendedorNome  = '';
    if ($vendedorId > 0) {
        $vst = $pdo->prepare("SELECT nome FROM users WHERE id = ?");
        $vst->execute([$vendedorId]);
        $vendedorNome = (string)($vst->fetchColumn() ?: '');
    }
    if ($vendedorId <= 0) { $vendedorId = (int)($user['id'] ?? 0); $vendedorNome = $user['nome'] ?? ''; }
    $clienteNome   = trim($_POST['cliente_nome'] ?? '');
    $clienteTel    = trim($_POST['cliente_telefone'] ?? '');
    $clienteEmail  = trim($_POST['cliente_email'] ?? '');
    $clienteDoc    = trim($_POST['cliente_doc'] ?? '');
    $observacao    = trim($_POST['observacao'] ?? '');
    $dataVenda     = trim($_POST['data_venda'] ?? '');
    // valida a data (YYYY-MM-DD); se vier vazia/errada, usa hoje
    $d = DateTime::createFromFormat('Y-m-d', $dataVenda);
    $dataVenda = ($d && $d->format('Y-m-d') === $dataVenda) ? $dataVenda : date('Y-m-d');

    if ($valor_venda <= 0) {
        $erro = 'Informe o valor da venda.';
    } elseif ($clienteNome === '') {
        $erro = 'Informe o nome do cliente.';
    } elseif ($clienteEmail !== '' && !filter_var($clienteEmail, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail do cliente inválido.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO vendas
                (moto_id, vendedor_id, vendedor_nome, cliente_nome, cliente_telefone, cliente_email, cliente_doc, valor_venda, data_venda, observacao)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $vendedorId, $vendedorNome, $clienteNome, $clienteTel, $clienteEmail, $clienteDoc, $valor_venda, $dataVenda, $observacao]);

            // sold_at recebe a data escolhida (meio-dia, evita fuso zerar pro dia anterior)
            $stmt = $pdo->prepare("UPDATE motos SET status = 'vendida', sold_at = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$dataVenda . ' 12:00:00', $id]);

            $venda_id = $pdo->lastInsertId();
            $pdo->commit();

            $sucesso = 'Venda registrada com sucesso!';

            // Integração CRM: fecha o lead correspondente (falha não quebra a venda)
            try {
              crm_on_venda_registrada($pdo, $venda_id);
            } catch (Throwable $crm_err) {}
        } catch (Exception $e) {
            $pdo->rollBack();
            $erro = 'Erro ao registrar venda: ' . $e->getMessage();
        }
    }
}

$vendedores = $pdo->query("SELECT id, nome, role FROM users ORDER BY nome ASC")->fetchAll();

$page_title = 'Registrar venda';
include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">

    <div class="page-header">
      <div>
        <h1 class="page-title">Registrar venda</h1>
        <p class="page-subtitle">
          Moto: <strong><?= htmlspecialchars($moto['titulo'] ?: $moto['modelo']) ?></strong>
          · <?= htmlspecialchars($moto['ano_modelo']) ?>
          · <?= htmlspecialchars($moto['cor']) ?>
        </p>
      </div>
      <a href="<?= base_url('painel/motos.php') ?>" class="btn btn-ghost">← Voltar</a>
    </div>

    <?php if ($erro): ?>
      <div class="alert alert-error"><span>⚠</span> <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
      <div class="alert alert-success"><span>✓</span> <?= htmlspecialchars($sucesso) ?></div>
      <a href="<?= base_url('painel/motos.php') ?>" class="btn btn-primary mt-3">Voltar pra lista de motos</a>
    <?php else: ?>
      <form method="post" class="form-card form-card-narrow">
        <div class="card card-pad mb-4" style="background:var(--surface-2);box-shadow:none;">
          <div class="row-between">
            <div>
              <div class="text-xs text-muted" style="text-transform:uppercase;letter-spacing:.05em;font-weight:700;">Preço de tabela</div>
              <div style="font-size:22px;font-weight:900;margin-top:2px;">R$ <?= number_format((float)$moto['valor'], 2, ',', '.') ?></div>
            </div>
            <div style="font-size:30px;">💰</div>
          </div>
        </div>

        <div class="form-grid form-grid-2">
          <div class="field field-prefix mb-4">
            <label>Valor da venda *</label>
            <span>R$</span>
            <input type="text" name="valor_venda" id="valor_venda" required placeholder="0,00" inputmode="decimal" autofocus>
            <small>Pode ser diferente do preço de tabela (após negociação).</small>
          </div>
          <div class="field mb-4">
            <label>Data da venda *</label>
            <input type="date" name="data_venda" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
          </div>
        </div>

        <div class="field mb-4">
          <label>Vendedor *</label>
          <select name="vendedor_id" required>
            <?php foreach ($vendedores as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= ((int)$v['id'] === (int)($user['id'] ?? 0)) ? 'selected' : '' ?>>
                <?= htmlspecialchars($v['nome']) ?><?= $v['role'] === 'gerente' ? ' (gerente)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small>Consultor que realizou a venda (usuários cadastrados no sistema).</small>
        </div>

        <h2 style="font-size:15px;font-weight:800;margin:4px 0 8px;padding-top:8px;border-top:1px solid var(--border-soft);">Dados do cliente</h2>
        <div class="form-grid form-grid-2">
          <div class="field mb-4">
            <label>Nome do cliente *</label>
            <input type="text" name="cliente_nome" required placeholder="Nome de quem comprou">
          </div>
          <div class="field mb-4">
            <label>Telefone / WhatsApp</label>
            <input type="text" name="cliente_telefone" id="cliente_telefone" placeholder="(27) 99999-9999" inputmode="numeric" maxlength="16" autocomplete="off">
          </div>
        </div>
        <div class="form-grid form-grid-2">
          <div class="field mb-4">
            <label>E-mail <span class="text-muted" style="font-weight:500;">(opcional)</span></label>
            <input type="email" name="cliente_email" placeholder="email@exemplo.com" autocomplete="off">
          </div>
          <div class="field mb-4">
            <label>CPF <span class="text-muted" style="font-weight:500;">(opcional)</span></label>
            <input type="text" name="cliente_doc" id="cliente_doc" placeholder="000.000.000-00" inputmode="numeric" maxlength="14" autocomplete="off">
          </div>
        </div>
        <div class="field mb-4">
          <label>Observação <span class="text-muted" style="font-weight:500;">(opcional)</span></label>
          <textarea name="observacao" rows="3" placeholder="Ex: forma de pagamento, troca envolvida, garantia combinada..."></textarea>
        </div>

        <div class="row" style="gap:8px;">
          <button class="btn btn-success btn-lg" type="submit">
            <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
            Confirmar venda
          </button>
          <a href="<?= base_url('painel/motos.php') ?>" class="btn btn-ghost btn-lg">Cancelar</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</main>

<script>
const v = document.getElementById('valor_venda');
if (v) {
  v.addEventListener('input', e => {
    let val = e.target.value.replace(/\D/g, '');
    val = (val / 100).toFixed(2);
    e.target.value = Number(val).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  });
}

// Máscara de telefone: (XX) XXXXX-XXXX
const tel = document.getElementById('cliente_telefone');
if (tel) {
  tel.addEventListener('input', e => {
    let d = e.target.value.replace(/\D/g, '').slice(0, 11);
    let out = d;
    if (d.length > 6) {
      out = '(' + d.slice(0,2) + ') ' + (d.length > 10 ? d.slice(2,7) + '-' + d.slice(7) : d.slice(2,6) + '-' + d.slice(6));
    } else if (d.length > 2) {
      out = '(' + d.slice(0,2) + ') ' + d.slice(2);
    } else if (d.length > 0) {
      out = '(' + d;
    }
    e.target.value = out;
  });
}

// Máscara de CPF: 000.000.000-00
const cpf = document.getElementById('cliente_doc');
if (cpf) {
  cpf.addEventListener('input', e => {
    let d = e.target.value.replace(/\D/g, '').slice(0, 11);
    let out = d;
    if (d.length > 9)      out = d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6,9) + '-' + d.slice(9);
    else if (d.length > 6) out = d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6);
    else if (d.length > 3) out = d.slice(0,3) + '.' + d.slice(3);
    e.target.value = out;
  });
}
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
