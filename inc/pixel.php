<?php
// Meta Pixel + CAPI (Conversions API) integration

// Lê uma setting direto do banco (setting_get() não é global — só existe no config.php)
function pixel_setting_get($key) {
  global $pdo;
  if (!($pdo instanceof PDO)) return '';
  try {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v !== false && $v !== null) ? (string)$v : '';
  } catch (Throwable $e) {
    return '';
  }
}

function pixel_enabled() {
  return !empty(pixel_setting_get('crm_pixel_id'));
}

function pixel_event_id() {
  return bin2hex(random_bytes(8));
}

function pixel_head_snippet() {
  $pixel_id = pixel_setting_get('crm_pixel_id');
  if (empty($pixel_id)) {
    return '';
  }

  return <<<HTML
<!-- Meta Pixel -->
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '$pixel_id');
  fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id=$pixel_id&ev=PageView&noscript=1" /></noscript>
<!-- End Meta Pixel -->
HTML;
}

function capi_send($pdo, $eventName, array $userData, array $customData, $eventId, $sourceUrl) {
  try {
    $pixel_id = pixel_setting_get('crm_pixel_id');
    $token = pixel_setting_get('crm_capi_token');

    if (empty($pixel_id) || empty($token)) {
      return false;
    }

    $payload = [
      'data' => [
        [
          'event_name' => $eventName,
          'event_time' => time(),
          'event_id' => $eventId,
          'event_source_url' => $sourceUrl,
          'action_source' => 'website',
          'user_data' => $userData,
        ]
      ],
      'access_token' => $token
    ];

    // Adicionar test_event_code se configurada
    $test_code = pixel_setting_get('crm_capi_test_code');
    if (!empty($test_code)) {
      $payload['test_event_code'] = $test_code;
    }

    // Adicionar custom data se houver
    if (!empty($customData)) {
      $payload['data'][0]['custom_data'] = $customData;
    }

    $url = "https://graph.facebook.com/v21.0/{$pixel_id}/events";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($payload),
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 4,
      CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!empty($error)) {
      error_log("CAPI send error: {$error}");
      return false;
    }

    if ($http_code < 200 || $http_code >= 300) {
      error_log("CAPI HTTP {$http_code}: {$response}");
      return false;
    }

    return true;
  } catch (Throwable $e) {
    error_log("CAPI exception: " . $e->getMessage());
    return false;
  }
}

function normalize_phone_for_capi($tel) {
  $tel = preg_replace('/\D/', '', $tel);
  if (strlen($tel) === 11) {
    return '55' . $tel;
  }
  if (strlen($tel) === 10) {
    return '5585' . $tel;
  }
  return '55' . $tel;
}

function hash_pii($value) {
  if (empty($value)) {
    return '';
  }
  $clean = trim(strtolower($value));
  return hash('sha256', $clean);
}
