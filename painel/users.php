<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
require_role('gerente');

$page_title = 'Usuários e permissões';

$flash = '';
$erroNovo = '';

// ===== Criar novo usuário =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['novo_usuario'])) {
    $nome  = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $role  = ($_POST['role'] ?? 'vendedor') === 'gerente' ? 'gerente' : 'vendedor';
    $can_create = isset($_POST['can_create']) ? 1 : 0;
    $can_edit   = isset($_POST['can_edit']) ? 1 : 0;
    $can_delete = isset($_POST['can_delete']) ? 1 : 0;
    // Gerente sempre tem acesso total
    if ($role === 'gerente') { $can_create = $can_edit = $can_delete = 1; }

    if (!$nome || !$email || !$senha) {
        $erroNovo = 'Preencha nome, e-mail e senha.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erroNovo = 'E-mail inválido.';
    } elseif (strlen($senha) < 6) {
        $erroNovo = 'A senha deve ter no mínimo 6 caracteres.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (nome, email, senha_hash, role, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $role, $can_create, $can_edit, $can_delete]);
            $flash = 'Usuário "' . $nome . '" criado com sucesso.';
        } catch (PDOException $e) {
            $erroNovo = ($e->getCode() === '23000')
                ? 'Já existe uma conta com este e-mail.'
                : 'Erro ao criar usuário: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $uid = (int)$_POST['user_id'];
    if ($uid > 0) {
        $can_create = isset($_POST['can_create']) ? 1 : 0;
        $can_edit   = isset($_POST['can_edit']) ? 1 : 0;
        $can_delete = isset($_POST['can_delete']) ? 1 : 0;
        $role       = $_POST['role'] === 'gerente' ? 'gerente' : 'vendedor';

        if ($role === 'vendedor') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'gerente'");
            $total_gerentes = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $old = $stmt->fetchColumn();
            if ($old === 'gerente' && $total_gerentes <= 1) {
                $role = 'gerente';
                $flash = 'Não é possível remover o último gerente. Função mantida.';
            }
        }

        $stmt = $pdo->prepare("UPDATE users SET role = ?, can_create = ?, can_edit = ?, can_delete = ? WHERE id = ?");
        $stmt->execute([$role, $can_create, $can_edit, $can_delete, $uid]);
        if (!$flash) $flash = 'Permissões atualizadas com sucesso.';
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY created_at ASC")->fetchAll();
include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">

    <div class="page-header">
      <div>
        <h1 class="page-title">Usuários e permissões</h1>
        <p class="page-subtitle">Defina quem pode cadastrar, editar e excluir motos.</p>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-success"><span>✓</span> <?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($erroNovo): ?>
      <div class="alert alert-error"><span>⚠</span> <?= htmlspecialchars($erroNovo) ?></div>
    <?php endif; ?>

    <!-- ===== Novo usuário ===== -->
    <div class="card card-pad mb-4">
      <h2 style="font-size:16px;font-weight:800;margin-bottom:4px;">Cadastrar novo usuário</h2>
      <p class="text-sm text-muted mb-3">Crie um acesso para um consultor ou gerente da equipe.</p>
      <form method="post">
        <input type="hidden" name="novo_usuario" value="1">
        <div class="form-grid form-grid-2">
          <div class="field">
            <label>Nome *</label>
            <input type="text" name="nome" required placeholder="Nome completo">
          </div>
          <div class="field">
            <label>E-mail *</label>
            <input type="email" name="email" required placeholder="email@exemplo.com" autocomplete="off">
          </div>
        </div>
        <div class="form-grid form-grid-2">
          <div class="field">
            <label>Senha *</label>
            <input type="password" name="senha" required placeholder="mínimo 6 caracteres" autocomplete="new-password">
          </div>
          <div class="field">
            <label>Função</label>
            <select name="role" id="novoRole">
              <option value="vendedor">Vendedor</option>
              <option value="gerente">Gerente</option>
            </select>
          </div>
        </div>
        <div class="form-grid" id="novoPerms" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); align-items:end;">
          <label class="check"><input type="checkbox" name="can_create"><span>Cadastrar</span></label>
          <label class="check"><input type="checkbox" name="can_edit"><span>Editar</span></label>
          <label class="check"><input type="checkbox" name="can_delete"><span>Excluir</span></label>
          <button class="btn btn-primary" type="submit">Criar usuário</button>
        </div>
        <small class="text-muted">Gerente já tem acesso total automaticamente. Você pode ajustar as permissões depois, na lista abaixo.</small>
      </form>
    </div>

    <div class="stack">
      <?php foreach ($users as $u):
        $letra = mb_strtoupper(mb_substr($u['nome'], 0, 1, 'UTF-8'), 'UTF-8');
      ?>
        <form method="post" class="card card-pad">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

          <div class="row-between" style="gap:var(--space-4);">
            <div class="row" style="gap:12px;">
              <div style="width:48px;height:48px;border-radius:999px;background:var(--brand-50);color:var(--brand-700);display:grid;place-items:center;font-weight:900;font-size:18px;">
                <?= htmlspecialchars($letra) ?>
              </div>
              <div>
                <div style="font-weight:800;font-size:15px;"><?= htmlspecialchars($u['nome']) ?></div>
                <div class="text-sm text-muted"><?= htmlspecialchars($u['email']) ?></div>
                <div class="text-xs text-muted mt-1">Cadastrado em <?= date('d/m/Y', strtotime($u['created_at'])) ?></div>
              </div>
            </div>

            <?php if ($u['role'] === 'gerente'): ?>
              <span class="badge badge-danger">Gerente</span>
            <?php else: ?>
              <span class="badge badge-info">Vendedor</span>
            <?php endif; ?>
          </div>

          <div class="divider"></div>

          <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); align-items:end;">
            <label class="check">
              <input type="checkbox" name="can_create" <?= $u['can_create'] ? 'checked' : '' ?>>
              <span>Cadastrar</span>
            </label>
            <label class="check">
              <input type="checkbox" name="can_edit" <?= $u['can_edit'] ? 'checked' : '' ?>>
              <span>Editar</span>
            </label>
            <label class="check">
              <input type="checkbox" name="can_delete" <?= $u['can_delete'] ? 'checked' : '' ?>>
              <span>Excluir</span>
            </label>

            <div class="field">
              <label>Função</label>
              <select name="role">
                <option value="vendedor" <?= $u['role'] === 'vendedor' ? 'selected' : '' ?>>Vendedor</option>
                <option value="gerente"  <?= $u['role'] === 'gerente' ? 'selected' : '' ?>>Gerente</option>
              </select>
            </div>

            <button class="btn btn-primary" type="submit">Salvar</button>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</main>

<script>
  (function(){
    const role = document.getElementById('novoRole');
    const perms = document.getElementById('novoPerms');
    if (!role || !perms) return;
    const checks = perms.querySelectorAll('input[type="checkbox"]');
    function sync(){
      const ger = role.value === 'gerente';
      checks.forEach(c => { if (ger) c.checked = true; c.disabled = ger; });
      perms.style.opacity = ger ? '.5' : '';
    }
    role.addEventListener('change', sync);
    sync();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
