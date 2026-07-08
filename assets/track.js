(function () {
  function getOrCreateSessionKey() {
    const keyName = "honca_session_key";
    let k = localStorage.getItem(keyName);
    if (!k) {
      k = "s_" + Math.random().toString(16).slice(2) + Date.now().toString(16);
      localStorage.setItem(keyName, k);
    }
    return k;
  }

  function getUTM() {
    const p = new URLSearchParams(window.location.search);
    return {
      utm_source: p.get("utm_source") || "",
      utm_medium: p.get("utm_medium") || "",
      utm_campaign: p.get("utm_campaign") || "",
      utm_content: p.get("utm_content") || "",
      utm_term: p.get("utm_term") || ""
    };
  }

  const session_key = getOrCreateSessionKey();
  const utm = getUTM();

  function send(event, extra) {
    const payload = Object.assign({
      session_key,
      event,
      page: location.pathname + location.search,
      referrer: document.referrer || ""
    }, utm, extra || {});

    // usa sendBeacon quando possível (mais confiável no mobile)
    try {
      const blob = new Blob([JSON.stringify(payload)], { type: "application/json" });
      if (navigator.sendBeacon) {
        navigator.sendBeacon("/track.php", blob);
        return;
      }
    } catch (e) {}

    fetch("/track.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      keepalive: true
    }).catch(() => {});
  }

  // evento de visita
  send("page_view");

  // ping "online agora" a cada 70s
  setInterval(() => send("ping"), 70000);

  // clique WhatsApp (qualquer botão com data-wa="1")
  document.addEventListener("click", function (e) {
    const a = e.target.closest('a[data-wa="1"]');
    if (!a) return;
    const motoId = a.getAttribute("data-moto-id") || "";
    send("click_whatsapp", { moto_id: motoId ? parseInt(motoId, 10) : null });
  });

  // quando abrir lightbox, registrar view_moto (o index vai chamar window.trackMotoView)
  window.trackMotoView = function (motoId) {
    if (!motoId) return;
    send("view_moto", { moto_id: parseInt(motoId, 10) });
  };
})();
