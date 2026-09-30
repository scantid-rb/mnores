import type { AlertButton } from "react-native";

// Match the native call sites using a real, accessible browser modal. Dialog
// keeps keyboard focus inside the confirmation and returns it on close.
export const Alert = {
  alert(title: string, message = "", buttons: AlertButton[] = [{ text: "Aceptar" }]): void {
    const dialog = document.createElement("dialog");
    const heading = document.createElement("h2");
    const body = document.createElement("p");
    const actions = document.createElement("div");
    const id = `confirmation-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    heading.id = id; heading.textContent = title;
    body.id = `${id}-body`; body.textContent = message;
    dialog.setAttribute("aria-labelledby", heading.id);
    dialog.setAttribute("aria-describedby", body.id);
    dialog.style.cssText = "max-width: min(30rem, 85vw); border: 1px solid #64748b; border-radius: 16px; padding: 24px; font: 16px system-ui; background: #0d1b2a; color: #fff";
    actions.style.cssText = "display:flex; flex-wrap:wrap; justify-content:flex-end; gap:12px; margin-top:24px";
    let settled = false;
    const close = (button?: AlertButton) => {
      if (settled) return;
      settled = true; dialog.close(); dialog.remove();
      void Promise.resolve().then(() => button?.onPress?.()).catch((error) => {
        Alert.alert("No se pudo completar la operación", error instanceof Error ? error.message : String(error));
      });
    };
    for (const button of buttons) {
      const element = document.createElement("button");
      element.textContent = button.text ?? "Aceptar";
      element.style.cssText = "min-height:44px; padding:10px 18px; border:0; border-radius:8px; font:inherit; cursor:pointer; background:#e2e8f0; color:#0d1b2a";
      if (button.style === "destructive") element.style.color = "#b91c1c";
      element.addEventListener("click", () => close(button));
      actions.append(element);
    }
    dialog.addEventListener("cancel", (event) => {
      event.preventDefault(); close(buttons.find((button) => button.style === "cancel"));
    });
    dialog.append(heading, body, actions); document.body.append(dialog); dialog.showModal();
  },
};
