<?php
/**
 * Consulta de veículo por placa (wdapi2 / apiplacas).
 * Proxy server-side: o token NUNCA vai pro navegador e só roda logado,
 * pra não vazar a chave nem estourar a cota com chamadas públicas.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function placa_out($arr) { echo json_encode($arr); exit; }

// ---- Token salvo nas Configurações ----
$token = '';
try {
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key`='placa_api_token' LIMIT 1");
  $stmt->execute();
  $token = trim((string)$stmt->fetchColumn());
} catch (Throwable $e) { $token = ''; }

if ($token === '') {
  placa_out(['ok' => false, 'error' => 'Token da API de placas não configurado. Vá em Configurações e cole seu token.']);
}

// ---- Valida a placa ----
$placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['placa'] ?? ''));
if (strlen($placa) !== 7) {
  placa_out(['ok' => false, 'error' => 'Placa inválida. Use o formato AAA0A00 ou AAA9999.']);
}

// ---- Chamada à API ----
$url = 'https://wdapi2.com.br/consulta/' . rawurlencode($placa) . '/' . rawurlencode($token);
$body = false;
$httpcode = 0;

if (function_exists('curl_init')) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'SeminovasHonca/1.0',
  ]);
  $body = curl_exec($ch);
  $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($body === false) {
    placa_out(['ok' => false, 'error' => 'Falha de conexão com a API: ' . $err]);
  }
} else {
  $ctx = stream_context_create(['http' => ['timeout' => 20], 'https' => ['timeout' => 20]]);
  $body = @file_get_contents($url, false, $ctx);
  if ($body === false) {
    placa_out(['ok' => false, 'error' => 'Falha de conexão com a API.']);
  }
  if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
      if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) { $httpcode = (int)$m[1]; break; }
    }
  } else {
    $httpcode = 200;
  }
}

$data = json_decode($body, true);
if (!is_array($data)) {
  placa_out(['ok' => false, 'error' => 'Resposta inválida da API.']);
}

// ---- Erros conhecidos (400/401/402/406/429) ----
if ($httpcode !== 200 || isset($data['message'])) {
  $msg = $data['message'] ?? ('Erro na consulta (HTTP ' . $httpcode . ').');
  if ($httpcode === 429) $msg = 'Limite diário de consultas atingido.';
  placa_out(['ok' => false, 'error' => $msg]);
}

// ---- Extrai os campos úteis ----
$marcaApi  = trim((string)($data['marca']  ?? $data['MARCA']  ?? ''));
$modeloApi = trim((string)($data['modelo'] ?? $data['MODELO'] ?? ''));
$cor       = trim((string)($data['cor'] ?? ''));

$extra       = is_array($data['extra'] ?? null) ? $data['extra'] : [];
$anoFab      = trim((string)($extra['ano_fabricacao'] ?? ''));
$anoMod      = trim((string)($extra['ano_modelo'] ?? $data['anoModelo'] ?? $data['ano'] ?? ''));
$cilindradas = trim((string)($extra['cilindradas'] ?? ''));
$combustivel = trim((string)($extra['combustivel'] ?? ''));

// Ano no formato "fab/modelo" (ex: 2023/2024)
if ($anoFab && $anoMod && $anoFab !== $anoMod) $ano = $anoFab . '/' . $anoMod;
else $ano = $anoMod ?: $anoFab;

// Mapeia a marca pro nosso <select>
$marcasSite = ['Honda','Yamaha','Kawasaki','Suzuki','BMW','Dafra','Haojue','Shineray','Royal Enfield','Triumph','KTM'];
$marcaSelect = 'Outra';
$mUp = strtoupper($marcaApi);
foreach ($marcasSite as $opt) {
  if ($mUp !== '' && (strtoupper($opt) === $mUp || strpos($mUp, strtoupper($opt)) !== false)) {
    $marcaSelect = $opt;
    break;
  }
}

// FIPE: escolhe o maior "score"
$fipeTexto = '';
$fipeModelo = '';
if (isset($data['fipe']['dados']) && is_array($data['fipe']['dados'])) {
  $best = null;
  foreach ($data['fipe']['dados'] as $d) {
    if (!is_array($d)) continue;
    if ($best === null || ((int)($d['score'] ?? 0) > (int)($best['score'] ?? 0))) $best = $d;
  }
  if ($best) {
    $fipeTexto  = trim((string)($best['texto_valor'] ?? ''));
    $fipeModelo = trim((string)($best['texto_modelo'] ?? ''));
  }
}

placa_out([
  'ok'           => true,
  'placa'        => $data['placa'] ?? $placa,
  'marca_api'    => $marcaApi,
  'marca_select' => $marcaSelect,
  'modelo'       => $modeloApi,
  'ano'          => $ano,
  'cor'          => $cor,
  'cilindradas'  => $cilindradas,
  'combustivel'  => $combustivel,
  'fipe_texto'   => $fipeTexto,
  'fipe_modelo'  => $fipeModelo,
]);
