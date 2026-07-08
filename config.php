<?php
// ==== CONFIGURAÇÃO BÁSICA DO SISTEMA ====

// Dados do banco vêm do arquivo privado config.local.php (fora do Git).
// Se ainda não existir, avisa de forma clara.
$__local = __DIR__ . '/config.local.php';
if (!file_exists($__local)) {
    die('Arquivo config.local.php não encontrado. Copie config.local.example.php para config.local.php e preencha as credenciais do banco.');
}
require $__local; // define $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS

// Valor padrão caso ainda não tenha nada na tabela config
$DEFAULT_WHATSAPP = '5527999999999'; // TROQUE SE QUISER UM PADRÃO INICIAL

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Erro ao conectar ao banco de dados: ' . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== Carrega configurações da tabela config =====
$APP_CONFIG = [];
try {
    $stmt = $pdo->query("SELECT nome, valor FROM config");
    foreach ($stmt as $row) {
        $APP_CONFIG[$row['nome']] = $row['valor'];
    }
} catch (PDOException $e) {
    // Se a tabela config ainda não existir, apenas segue usando o padrão
}

// Número do WhatsApp usado no marketplace (só dígitos)
$WHATSAPP_NUMBER = $APP_CONFIG['whatsapp_number'] ?? $DEFAULT_WHATSAPP;

// Caminho da logo (em uploads), se existir
$LOGO_PATH = $APP_CONFIG['logo_path'] ?? null;

/**
 * Gera URL relativa à raiz do projeto.
 * Funciona se o sistema estiver:
 *   - direto no domínio:     /painel/moto_form.php
 *   - dentro de uma pasta:   /ronca_seminovas/painel/moto_form.php
 */
function base_url(string $path = ''): string {
    $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // ex: /painel  ou  /ronca_seminovas/painel
    $root = str_replace('/painel', '', $dir);               // ex: ''      ou  /ronca_seminovas

    if ($root === '/' || $root === '\\') {
        $root = '';
    }

    return $root . '/' . ltrim($path, '/');
}
?>
