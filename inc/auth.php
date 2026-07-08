<?php
require_once __DIR__ . '/../config.php';

function is_logged_in(): bool {
    return isset($_SESSION['user']);
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . base_url('login.php'));
        exit;
    }
}

function require_role(string $role): void {
    require_login();
    $user = current_user();
    if (!$user || $user['role'] !== $role) {
        http_response_code(403);
        echo 'Acesso negado';
        exit;
    }
}

function user_can(string $perm): bool {
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'gerente') return true; // gerente pode tudo
    if ($perm === 'create') return !empty($user['can_create']);
    if ($perm === 'edit')   return !empty($user['can_edit']);
    if ($perm === 'delete') return !empty($user['can_delete']);
    return false;
}
?>
