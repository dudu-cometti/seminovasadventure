<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
require_role('gerente');

$page_title = 'Usuários e permissões';

$flash = '';
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

<?php include __DIR__ . '/../inc/footer.php'; ?>
