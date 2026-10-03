(() => {
  let deferredPrompt = null;
  const button = document.getElementById("pwa-install-button");
  if (!button) return;

  const standalone = window.matchMedia("(display-mode: standalone)").matches ||
    window.navigator.standalone === true;

  if (standalone) {
    button.hidden = true;
    return;
  }

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    deferredPrompt = event;
  });

  window.addEventListener("appinstalled", () => {
    deferredPrompt = null;
    button.hidden = true;
  });

  button.addEventListener("click", async () => {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      try {
        await deferredPrompt.userChoice;
      } finally {
        deferredPrompt = null;
      }
      return;
    }

    const ua = navigator.userAgent || "";
    const isiOS = /iPad|iPhone|iPod/.test(ua) ||
      (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);

    if (isiOS) {
      alert("Para instalar ShipInventory en iPhone/iPad: abre la PWA, pulsa Compartir y elige “Añadir a pantalla de inicio”.");
    }

    const target = button.getAttribute("data-pwa-url") || "/pwa/";
    window.location.href = target;
  });
})();
