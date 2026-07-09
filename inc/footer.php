<footer class="site-footer">
  <div class="footer-brand">
    <?= htmlspecialchars($nomeLoja ?? 'Adventure Motos') ?>
  </div>
  <p>
    <?= htmlspecialchars($cidade ?? 'São Silvano - ES') ?> &middot; Atendimento via WhatsApp.
    As imagens, informações e valores são ilustrativos. Reservamo-nos o direito de
    corrigir eventuais erros sem aviso prévio.
  </p>
  <div class="footer-mini">
    © <?= date('Y') ?> <?= htmlspecialchars($nomeLoja ?? 'Adventure Motos') ?>. Todos os direitos reservados.
    <?php if (empty($isLogged)): ?>
      &middot; <a class="footer-admin-link" href="<?= base_url('login.php') ?>">Acesso restrito</a>
    <?php endif; ?>
  </div>
</footer>

<!-- Modal de Captura de Lead (FASE 2) — Apenas páginas públicas -->
<!-- Debug: isPanel=<?= $isPanel ? 'true' : 'false' ?> URI=<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?> -->
<?php if (empty($isPanel)): ?>
<div id="crm-cap-modal" class="crm-cap-overlay" style="display: none;">
  <div class="crm-cap-modal">
    <button class="crm-cap-close" onclick="crmCapFecharModal()" aria-label="Fechar">✕</button>
    <h2 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 800;">Fale com a gente no WhatsApp</h2>
    <p id="crm-cap-subtitulo" style="margin: 0 0 var(--space-4) 0; font-size: 13px; color: var(--muted);"></p>

    <form id="crm-cap-form" onsubmit="crmCapEnviar(event)">
      <div class="field mb-3">
        <label for="crm-cap-nome">Nome *</label>
        <input type="text" id="crm-cap-nome" name="nome" required placeholder="Seu nome completo" minlength="2" maxlength="120" autofocus>
      </div>

      <div class="field mb-3">
        <label for="crm-cap-tel">WhatsApp *</label>
        <input type="text" id="crm-cap-tel" name="telefone" required placeholder="(XX) 99999-9999" inputmode="tel">
      </div>

      <!-- Honeypot invisível -->
      <input type="text" name="site" style="display: none !important;" tabindex="-1" autocomplete="off">

      <input type="hidden" name="moto_id" id="crm-cap-moto-id" value="">

      <button type="submit" class="btn btn-primary" style="width: 100%;">Continuar para o WhatsApp</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// ===== CRM Capture Modal (FASE 2) =====
const CRM_CAP_KEY = 'am_lead_capture';
const CRM_CAP_TIMEOUT = 3000; // 3s

function crmCapMaskTel(input) {
  let v = input.value.replace(/\D/g, '');
  if (v.length > 11) v = v.slice(0, 11);
  if (v.length === 0) { input.value = ''; return; }
  if (v.length <= 2) {
    input.value = v.length === 2 ? '(' + v : '(' + v;
  } else if (v.length <= 7) {
    input.value = '(' + v.slice(0, 2) + ') ' + v.slice(2);
  } else {
    input.value = '(' + v.slice(0, 2) + ') ' + v.slice(2, 7) + '-' + v.slice(7);
  }
}

function crmCapFecharModal() {
  const modal = document.getElementById('crm-cap-modal');
  if (modal) modal.style.display = 'none';
}

function crmCapAbrirModal(motoId, motoTitulo, waHref) {
  const modal = document.getElementById('crm-cap-modal');
  if (!modal) return;

  const stored = localStorage.getItem(CRM_CAP_KEY);
  const now = Date.now();

  // Se já converteu nas últimas 24h, pula modal e envia em background
  if (stored) {
    const data = JSON.parse(stored);
    if (data.expires > now) {
      crmCapEnviarBackground(motoId, data.nome, data.telefone);
      window.location.href = waHref;
      return;
    }
  }

  // Montar modal
  document.getElementById('crm-cap-moto-id').value = motoId || '';
  const subtitulo = document.getElementById('crm-cap-subtitulo');
  if (motoTitulo) {
    subtitulo.textContent = 'Dúvidas sobre ' + motoTitulo + '?';
  } else {
    subtitulo.textContent = 'Tire suas dúvidas com a gente!';
  }

  // Pré-preencher se existir
  if (stored) {
    const data = JSON.parse(stored);
    document.getElementById('crm-cap-nome').value = data.nome || '';
    document.getElementById('crm-cap-tel').value = data.telefone || '';
  }

  modal.style.display = 'flex';
  document.getElementById('crm-cap-nome').focus();

  // Guardar dados do wa para redirecionar após submit
  modal.dataset.waHref = waHref;
}

function crmCapEnviar(e) {
  e.preventDefault();
  const form = document.getElementById('crm-cap-form');
  const modal = document.getElementById('crm-cap-modal');
  const waHref = modal.dataset.waHref || '';

  const fd = new FormData(form);
  const payload = Object.fromEntries(fd);

  // Anexar dados de rastreio (UTM) salvos pelo track.js
  try {
    const track = JSON.parse(localStorage.getItem('am_track') || 'null');
    if (track) payload.track = track;
  } catch (err) {}

  // Salvar para próximo clique
  localStorage.setItem(CRM_CAP_KEY, JSON.stringify({
    nome: payload.nome,
    telefone: payload.telefone,
    expires: Date.now() + (24 * 3600000) // 24h
  }));

  // Redireciona pro WhatsApp (ou fecha o modal se não houver link)
  const irParaWhats = () => {
    crmCapFecharModal();
    if (waHref) window.location.href = waHref;
  };

  // Enviar para API
  const timeout = new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), CRM_CAP_TIMEOUT));
  Promise.race([
    fetch('/api/lead_capture.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(r => r.json()),
    timeout
  ])
  .then(data => {
    // Disparar fbq Lead com event_id se recebido
    if (data && data.event_id && window.fbqLead) {
      window.fbqLead(data.event_id);
    }
  })
  .catch(() => {}) // Falha silenciosa
  .finally(irParaWhats);
}

function crmCapEnviarBackground(motoId, nome, telefone) {
  const payload = { nome, telefone, moto_id: motoId || '' };
  fetch('/api/lead_capture.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    keepalive: true
  }).catch(() => {});
}

// Interceptar cliques em data-wa (apenas se modal existe)
if (document.getElementById('crm-cap-modal')) {
  document.addEventListener('click', e => {
    const btn = e.target.closest('a[data-wa]');
    if (!btn) return;

    e.preventDefault();
    const motoId = btn.getAttribute('data-moto-id') || '';
    const motoTitulo = btn.getAttribute('data-moto-titulo') || '';
    const waHref = btn.href;

    crmCapAbrirModal(motoId, motoTitulo, waHref);
  });
} else {
  console.warn('CRM Modal ausente - interceptador desabilitado');
}

// Fechar modal com ESC ou overlay
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') crmCapFecharModal();
});

document.getElementById('crm-cap-modal')?.addEventListener('click', e => {
  if (e.target.id === 'crm-cap-modal') crmCapFecharModal();
});

// Máscara de telefone
document.getElementById('crm-cap-tel')?.addEventListener('input', function() {
  crmCapMaskTel(this);
});
</script>

</body>
</html>
