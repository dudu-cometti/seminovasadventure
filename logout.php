<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// limpa sessão
$_SESSION = [];

// remove cookie de sessão
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"] ?? '/',
    $params["domain"] ?? '',
    $params["secure"] ?? false,
    $params["httponly"] ?? true
  );
}

session_destroy();

// volta pro marketplace
header("Location: " . base_url("index.php"));
exit;
