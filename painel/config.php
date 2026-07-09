<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/crm.php';
require_login();
require_role('gerente');

function ensure_settings_table($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
      `key` VARCHAR(60) PRIMARY KEY,
      `value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}
function setting_get($pdo, $key, $default = '') {
  try {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if ($row && $row['value'] !== null) return $row['value'];
  } catch (Exception $e) {}
  return $default;
}
function setting_set($pdo, $key, $value) {
  $stmt = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)
                         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  $stmt->execute([$key, $value]);
}

ensure_settings_table($pdo);

$erro = '';
$ok = '';

$whatsapp        = setting_get($pdo, 'whatsapp_number', '');
$logo_path       = setting_get($pdo, 'logo_path', '');
$banner_ativo    = setting_get($pdo, 'banner_ativo', '0');
$banner_desktop  = setting_get($pdo, 'banner_desktop', '');
$banner_mobile   = setting_get($pdo, 'banner_mobile', '');
$faixa1          = setting_get($pdo, 'faixa_1', '★★★★★ Bem avaliada no Google');
$faixa2          = setting_get($pdo, 'faixa_2', 'Loja física em São Silvano - ES');
$faixa3          = setting_get($pdo, 'faixa_3', 'Financiamento e troca na hora');
$nomeLoja        = setting_get($pdo, 'marketplace_nome', 'Adventure Motos');
$cidadeLoja   = setting_get($pdo, 'marketplace_cidade', 'São Silvano - ES');
$placaToken   = setting_get($pdo, 'placa_api_token', '');
$crm_pixel_id = setting_get($pdo, 'crm_pixel_id', '');
$crm_capi_token = setting_get($pdo, 'crm_capi_token', '');
$crm_capi_test_code = setting_get($pdo, 'crm_capi_test_code', '');
$crm_anthropic_key = setting_get($pdo, 'crm_anthropic_key', '');
$crm_motivos_perda = setting_get($pdo, 'crm_motivos_perda', '["Preço","Comprou em outra loja","Sem crédito/financiamento","Desistiu","Sem retorno","Trocou de ideia","Outro"]');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (isset($_POST['marketplace_nome'])) {
      $nomeLoja = trim($_POST['marketplace_nome']);
      setting_set($pdo, 'marketplace_nome', $nomeLoja);
    }
    if (isset($_POST['marketplace_cidade'])) {
      $cidadeLoja = trim($_POST['marketplace_cidade']);
      setting_set($pdo, 'marketplace_cidade', $cidadeLoja);
    }

    $wh = preg_replace('/\D+/', '', $_POST['whatsapp_number'] ?? '');
    if ($wh) {
      setting_set($pdo, 'whatsapp_number', $wh);
      $whatsapp = $wh;
    }

    if (isset($_POST['placa_api_token'])) {
      $placaToken = trim($_POST['placa_api_token']);
      setting_set($pdo, 'placa_api_token', $placaToken);
    }

    foreach (['faixa_1','faixa_2','faixa_3'] as $fk) {
      if (isset($_POST[$fk])) setting_set($pdo, $fk, trim($_POST[$fk]));
    }
    $faixa1 = $_POST['faixa_1'] ?? $faixa1;
    $faixa2 = $_POST['faixa_2'] ?? $faixa2;
    $faixa3 = $_POST['faixa_3'] ?? $faixa3;

    if (!empty($_FILES['logo']['tmp_name'])) {
      $uploadDir = __DIR__ . '/../uploads/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

      $name = $_FILES['logo']['name'] ?? '';
      $tmp  = $_FILES['logo']['tmp_name'] ?? '';
      $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

      if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
        throw new Exception('Formato inválido. Use JPG, PNG ou WEBP.');
      }
      $newName = 'logo_' . time() . '.' . $ext;
      if (!move_uploaded_file($tmp, $uploadDir . $newName)) {
        throw new Exception('Falha ao enviar a logo.');
      }
      $logo_path = 'uploads/' . $newName;
      setting_set($pdo, 'logo_path', $logo_path);
    }

    // Banner do marketplace: liga/desliga + imagens de desktop e celular
    $banner_ativo = !empty($_POST['banner_ativo']) ? '1' : '0';
    setting_set($pdo, 'banner_ativo', $banner_ativo);

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    // helper local pra subir cada banner
    $subirBanner = function($campo, $prefixo) use ($uploadDir) {
      $name = $_FILES[$campo]['name'] ?? '';
      $tmp  = $_FILES[$campo]['tmp_name'] ?? '';
      $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
        throw new Exception('Formato de banner inválido. Use JPG, PNG ou WEBP.');
      }
      $newName = $prefixo . '_' . time() . '.' . $ext;
      if (!move_uploaded_file($tmp, $uploadDir . $newName)) {
        throw new Exception('Falha ao enviar o banner.');
      }
      return 'uploads/' . $newName;
    };

    if (!empty($_POST['banner_desktop_remover'])) {
      setting_set($pdo, 'banner_desktop', ''); $banner_desktop = '';
    } elseif (!empty($_FILES['banner_desktop']['tmp_name'])) {
      $banner_desktop = $subirBanner('banner_desktop', 'banner_desktop');
      setting_set($pdo, 'banner_desktop', $banner_desktop);
    }

    if (!empty($_POST['banner_mobile_remover'])) {
      setting_set($pdo, 'banner_mobile', ''); $banner_mobile = '';
    } elseif (!empty($_FILES['banner_mobile']['tmp_name'])) {
      $banner_mobile = $subirBanner('banner_mobile', 'banner_mobile');
      setting_set($pdo, 'banner_mobile', $banner_mobile);
    }

    // Configurações CRM
    ensure_crm_schema($pdo);
    if (isset($_POST['crm_pixel_id'])) {
      $crm_pixel_id = trim($_POST['crm_pixel_id']);
      setting_set($pdo, 'crm_pixel_id', $crm_pixel_id);
    }
    if (isset($_POST['crm_capi_token'])) {
      $crm_capi_token = trim($_POST['crm_capi_token']);
      setting_set($pdo, 'crm_capi_token', $crm_capi_token);
    }
    if (isset($_POST['crm_capi_test_code'])) {
      $crm_capi_test_code = trim($_POST['crm_capi_test_code']);
      setting_set($pdo, 'crm_capi_test_code', $crm_capi_test_code);
    }
    if (isset($_POST['crm_anthropic_key'])) {
      $crm_anthropic_key = trim($_POST['crm_anthropic_key']);
      setting_set($pdo, 'crm_anthropic_key', $crm_anthropic_key);
    }
    if (isset($_POST['crm_motivos_perda'])) {
      $motivos_arr = array_filter(array_map('trim', explode("\n", $_POST['crm_motivos_perda'])), fn($m) => !empty($m));
      $crm_motivos_perda = json_encode(array_values($motivos_arr));
      setting_set($pdo, 'crm_motivos_perda', $crm_motivos_perda);
    }

    $ok = 'Configurações salvas com sucesso.';
  } catch (Exception $e) {
    $erro = $e->getMessage();
  }
}

$page_title = 'Configurações';
include __DIR__ . '/../inc/header.php';
?>

<main class="container">
  <div class="page" style="padding-top: var(--space-6);">

    <div class="page-header">
      <div>
        <h1 class="page-title">Configurações</h1>
        <p class="page-subtitle">Informações que aparecem no marketplace.</p>
      </div>
    </div>

    <?php if ($erro): ?>
      <div class="alert alert-error"><span>⚠</span> <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
      <div class="alert alert-success"><span>✓</span> <?= htmlspecialchars($ok) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-card" style="max-width:720px;">
      <div class="form-grid">

        <div class="form-grid form-grid-2">
          <div class="field">
            <label>Nome da loja</label>
            <input type="text" name="marketplace_nome" value="<?= htmlspecialchars($nomeLoja) ?>" placeholder="Adventure Motos">
            <small>Aparece na topbar e no rodapé.</small>
          </div>
          <div class="field">
            <label>Cidade / UF</label>
            <input type="text" name="marketplace_cidade" value="<?= htmlspecialchars($cidadeLoja) ?>" placeholder="São Silvano - ES">
          </div>
        </div>

        <div class="field">
          <label>WhatsApp da loja</label>
          <input type="text" name="whatsapp_number" value="<?= htmlspecialchars($whatsapp) ?>" placeholder="5527999999999">
          <small>Com DDI + DDD, sem espaços. Ex: 5527999999999</small>
        </div>

        <div class="field">
          <label>Token da API de placas (wdapi2 / apiplacas)</label>
          <input type="text" name="placa_api_token" value="<?= htmlspecialchars($placaToken) ?>" placeholder="cole aqui seu token" autocomplete="off">
          <small>Usado no cadastro para preencher a moto pela placa. Pegue no Painel do Usuário do apiplacas. Deixe vazio para desativar.</small>
        </div>

        <div class="field">
          <label>Logo</label>
          <input type="file" name="logo" accept="image/*">
          <small>JPG, PNG ou WEBP. Aparece no topo do site.</small>
          <?php if ($logo_path): ?>
            <div class="mt-3" style="display:inline-block;padding:8px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-md);">
              <img src="<?= base_url($logo_path) ?>" alt="Logo atual" style="max-width:160px;max-height:80px;display:block;">
            </div>
          <?php endif; ?>
        </div>

        <div class="field" style="padding-top:12px;border-top:1px solid var(--border-soft);">
          <label style="font-size:15px;font-weight:800;">Faixa de confiança <span class="text-muted" style="font-weight:500;">(barra escura abaixo do topo)</span></label>
          <div class="form-grid" style="gap:10px;margin-top:8px;">
            <input type="text" name="faixa_1" value="<?= htmlspecialchars($faixa1) ?>" placeholder="Ex: ★ 4,9 no Google">
            <input type="text" name="faixa_2" value="<?= htmlspecialchars($faixa2) ?>" placeholder="Ex: 12 anos de loja">
            <input type="text" name="faixa_3" value="<?= htmlspecialchars($faixa3) ?>" placeholder="Ex: Financiamento e troca">
          </div>
          <small>Três selos curtos de confiança que aparecem na barra escura abaixo do topo.</small>
        </div>

        <div class="field" style="padding-top:12px;border-top:1px solid var(--border-soft);">
          <label style="font-size:15px;font-weight:800;">Banner da página inicial</label>
          <label class="check mt-2">
            <input type="checkbox" name="banner_ativo" id="bannerAtivo" value="1" <?= $banner_ativo === '1' ? 'checked' : '' ?>>
            <span>Usar banner na abertura <span class="text-muted" style="font-weight:500;">(desmarcado = mostra o texto padrão)</span></span>
          </label>
        </div>

        <div id="bannerCampos" class="form-grid form-grid-2" style="gap:16px;">
          <div class="field">
            <label>Banner — Desktop</label>
            <input type="file" name="banner_desktop" accept="image/*">
            <small><b>1200 × 400 px</b> (largo, proporção 3:1). Aparece no computador.</small>
            <?php if ($banner_desktop): ?>
              <div class="mt-2" style="padding:6px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-md);">
                <img src="<?= base_url($banner_desktop) ?>" alt="Banner desktop" style="width:100%;border-radius:var(--r-sm);display:block;">
              </div>
              <label class="check mt-2"><input type="checkbox" name="banner_desktop_remover" value="1"><span>Remover</span></label>
            <?php endif; ?>
          </div>
          <div class="field">
            <label>Banner — Celular</label>
            <input type="file" name="banner_mobile" accept="image/*">
            <small><b>1080 × 1080 px</b> (mais quadrado/vertical). Aparece no celular.</small>
            <?php if ($banner_mobile): ?>
              <div class="mt-2" style="padding:6px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-md);">
                <img src="<?= base_url($banner_mobile) ?>" alt="Banner celular" style="width:100%;max-width:220px;border-radius:var(--r-sm);display:block;">
              </div>
              <label class="check mt-2"><input type="checkbox" name="banner_mobile_remover" value="1"><span>Remover</span></label>
            <?php endif; ?>
          </div>
        </div>

        <div class="field" style="padding-top:12px;border-top:1px solid var(--border-soft);">
          <label style="font-size:15px;font-weight:800;">CRM / Integrações</label>
          <p class="text-muted" style="font-size:13px;margin-bottom:var(--space-4);">Configure integrações externas para o CRM.</p>

          <!-- Diagnóstico Pixel/CAPI -->
          <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:var(--space-4);font-size:13px;">
            <div style="margin-bottom:6px;"><strong>Status:</strong></div>
            <div style="margin-bottom:4px;">
              <?php
                $pixel_ok = !empty($crm_pixel_id);
                $capi_ok = !empty($crm_capi_token);
                $pixel_icon = $pixel_ok ? '✓' : '○';
                $capi_icon = $capi_ok ? '✓' : '○';
                echo "Pixel: <span style='color:var(--text-muted)'>{$pixel_icon}</span> " . ($pixel_ok ? 'configurado' : 'não configurado');
                echo " · CAPI: <span style='color:var(--text-muted)'>{$capi_icon}</span> " . ($capi_ok ? 'token ok' : 'ausente');
              ?>
            </div>
          </div>

          <div class="form-grid form-grid-1">
            <div class="field mb-4">
              <label>Meta Pixel ID <span class="text-muted" style="font-weight:500;">(opcional)</span></label>
              <input type="text" name="crm_pixel_id" placeholder="123456789" value="<?= htmlspecialchars($crm_pixel_id) ?>" autocomplete="off">
              <small>ID do Meta Pixel para rastreamento de conversões. Será implementado na Fase 2.</small>
            </div>

            <div class="field mb-4">
              <label>Conversions API Token <span class="text-muted" style="font-weight:500;">(opcional)</span></label>
              <input type="password" name="crm_capi_token" placeholder="••••••••" value="<?= htmlspecialchars($crm_capi_token) ?>" autocomplete="off">
              <small>Token da Meta Conversions API. Será implementado na Fase 2.</small>
            </div>

            <div class="field mb-4">
              <label>CAPI Test Event Code <span class="text-muted" style="font-weight:500;">(opcional)</span></label>
              <input type="text" name="crm_capi_test_code" placeholder="test_code..." value="<?= htmlspecialchars($crm_capi_test_code) ?>" autocomplete="off">
              <small>Código de teste para eventos (Gerenciador de Eventos da Meta). Deixe vazio em produção.</small>
            </div>

            <div class="field mb-4">
              <label>Chave Anthropic <span class="text-muted" style="font-weight:500;">(opcional)</span></label>
              <div style="display:flex;gap:8px;align-items:flex-start;">
                <input type="password" name="crm_anthropic_key" placeholder="sk-••••••••" value="<?= htmlspecialchars($crm_anthropic_key) ?>" autocomplete="off" style="flex:1;">
                <?php if (!empty($crm_anthropic_key)): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="testarConexaoIA()" style="white-space:nowrap;margin-top:0;">↻ Testar</button>
                <?php endif; ?>
              </div>
              <small>Chave de API da Anthropic para IA. <a href="https://console.anthropic.com/" target="_blank" rel="noopener">Gerar chave →</a></small>
              <?php if (!empty($crm_anthropic_key)): ?>
              <small style="display:block;margin-top:8px;color:var(--green-600);font-weight:600;">✓ IA Configurada</small>
              <?php endif; ?>
            </div>

            <div class="field mb-4">
              <label>Motivos de Perda <span class="text-muted" style="font-weight:500;">(um por linha)</span></label>
              <textarea name="crm_motivos_perda" rows="4" style="font-family:monospace;"><?php
                $motivos = json_decode($crm_motivos_perda, true) ?: [];
                echo htmlspecialchars(implode("\n", $motivos));
              ?></textarea>
              <small>Motivos disponíveis ao marcar um lead como perdido.</small>
            </div>
          </div>
        </div>

        <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
          <button class="btn btn-primary btn-lg" type="submit">
            <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
            Salvar configurações
          </button>
          <?php if ($isGerente && !empty($crm_capi_token)): ?>
          <button class="btn btn-outline btn-lg" type="button" id="btnTestePixel" onclick="testarPixelEvent()">
            Enviar evento de teste
          </button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</main>

<script>
  (function(){
    const chk = document.getElementById('bannerAtivo');
    const campos = document.getElementById('bannerCampos');
    if (!chk || !campos) return;
    function sync(){ campos.style.display = chk.checked ? '' : 'none'; }
    chk.addEventListener('change', sync);
    sync();
  })();

  window.testarPixelEvent = async function() {
    const btn = document.getElementById('btnTestePixel');
    if (!btn) return;
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = 'Enviando...';

    try {
      const resp = await fetch('<?= base_url('painel/api_test_pixel.php') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({})
      });
      const data = await resp.json();
      const msg = data.ok ? '✓ Evento enviado! Verifique o Gerenciador de Eventos.' : ('✗ ' + (data.msg || 'Erro ao enviar'));
      alert(msg);
    } catch(e) {
      alert('✗ Erro: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  };

  window.testarConexaoIA = async function() {
    const btn = event.target;
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = '↻ Testando...';

    try {
      const resp = await fetch('<?= base_url('painel/crm_actions.php') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({ acao: 'ia_testar_conexao' })
      });
      const data = await resp.json();
      const msg = data.ok ? '✓ IA conectada!' : ('✗ ' + (data.msg || 'Erro ao conectar'));
      alert(msg);
    } catch(e) {
      alert('✗ Erro: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  };
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
