<?php
/**
 * Helpers compartilhados dos campos da ficha da moto.
 * Usado por painel/moto_form.php e painel/padroes.php.
 * (function_exists evita erro caso algo já tenha definido.)
 */

if (!function_exists('yn')) {
  function yn($v) {
    $v = trim((string)$v);
    return ($v === 'sim' || $v === 'nao') ? $v : '';
  }
}
if (!function_exists('opt_val')) {
  function opt_val($v, array $allowed) {
    $v = trim((string)$v);
    return in_array($v, $allowed, true) ? $v : '';
  }
}
if (!function_exists('pct_val')) {
  function pct_val($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $n = (int)$v;
    if ($n < 0)   $n = 0;
    if ($n > 100) $n = 100;
    return $n;
  }
}
// Renderiza um select Sim/Não
if (!function_exists('field_yn')) {
  function field_yn($label, $name, $cur) {
    $cur = (string)$cur;
    return '<div class="field"><label>' . htmlspecialchars($label) . '</label>'
         . '<select name="' . $name . '">'
         . '<option value="">— não informado —</option>'
         . '<option value="sim" ' . ($cur === 'sim' ? 'selected' : '') . '>Sim</option>'
         . '<option value="nao" ' . ($cur === 'nao' ? 'selected' : '') . '>Não</option>'
         . '</select></div>';
  }
}
// Renderiza um select de opções ($opts = ['valor' => 'Texto'])
if (!function_exists('field_opt')) {
  function field_opt($label, $name, $cur, array $opts) {
    $cur = (string)$cur;
    $h = '<div class="field"><label>' . htmlspecialchars($label) . '</label>'
       . '<select name="' . $name . '"><option value="">— não informado —</option>';
    foreach ($opts as $val => $txt) {
      $h .= '<option value="' . htmlspecialchars($val) . '" '
          . ($cur === (string)$val ? 'selected' : '') . '>' . htmlspecialchars($txt) . '</option>';
    }
    return $h . '</select></div>';
  }
}

/**
 * Tags do Diferencial vindas do POST (string separada por vírgula) -> array único.
 */
if (!function_exists('moto_diferencial_tags')) {
  function moto_diferencial_tags($post) {
    $raw = $post['diferencial'] ?? '';
    $tags = array_filter(array_map('trim', explode(',', (string)$raw)), function ($v) { return $v !== ''; });
    $out = [];
    foreach ($tags as $t) {
      $t = mb_substr($t, 0, 120);
      if (!in_array($t, $out, true)) $out[] = $t;
    }
    return $out;
  }
}

/**
 * Coleta e sanitiza TODOS os campos da ficha a partir do $_POST.
 * Retorna um array coluna => valor (serve pro INSERT da moto e pro JSON do padrão).
 */
if (!function_exists('moto_ficha_collect')) {
  function moto_ficha_collect($post) {
    return [
      'condicao'            => opt_val($post['condicao'] ?? '', ['nova','seminova']),
      // procedencia
      'unico_dono'          => yn($post['unico_dono'] ?? ''),
      'tem_manual'          => yn($post['tem_manual'] ?? ''),
      'revisada_autorizada' => yn($post['revisada_autorizada'] ?? ''),
      'garantia_fabrica'    => yn($post['garantia_fabrica'] ?? ''),
      'chave_reserva'       => yn($post['chave_reserva'] ?? ''),
      'revisoes_regulares'  => yn($post['revisoes_regulares'] ?? ''),
      'historico_negativo'  => yn($post['historico_negativo'] ?? ''),
      'laudo_cautelar'      => yn($post['laudo_cautelar'] ?? ''),
      // conservacao / estetica
      'conservacao'         => opt_val($post['conservacao'] ?? '', ['impecavel','excelente','muito_boa','boa']),
      'detalhe_estetico'    => mb_substr(trim($post['detalhe_estetico'] ?? ''), 0, 255),
      // pneus
      'pneu_dianteiro'      => pct_val($post['pneu_dianteiro'] ?? ''),
      'pneu_traseiro'       => pct_val($post['pneu_traseiro'] ?? ''),
      // mecanica
      'relacao'             => opt_val($post['relacao'] ?? '', ['nova','boa','regular']),
      'freios'              => opt_val($post['freios'] ?? '', ['novos','bons','regular']),
      // diferencial (string)
      'diferencial'         => mb_substr(implode(', ', moto_diferencial_tags($post)), 0, 500),
      // comercial
      'aceita_troca'        => yn($post['aceita_troca'] ?? ''),
      'aceita_carta'        => yn($post['aceita_carta'] ?? ''),
      'financiamento'       => yn($post['financiamento'] ?? ''),
      'garantia_loja'       => yn($post['garantia_loja'] ?? ''),
    ];
  }
}

/** Valores-padrão (todos vazios) dos campos da ficha. */
if (!function_exists('moto_ficha_defaults')) {
  function moto_ficha_defaults() {
    return [
      'condicao' => '',
      'unico_dono' => '', 'tem_manual' => '', 'revisada_autorizada' => '', 'garantia_fabrica' => '',
      'chave_reserva' => '', 'revisoes_regulares' => '', 'historico_negativo' => '', 'laudo_cautelar' => '',
      'conservacao' => '', 'detalhe_estetico' => '',
      'pneu_dianteiro' => '', 'pneu_traseiro' => '',
      'relacao' => '', 'freios' => '',
      'diferencial' => '',
      'aceita_troca' => '', 'aceita_carta' => '', 'financiamento' => '', 'garantia_loja' => '',
    ];
  }
}
