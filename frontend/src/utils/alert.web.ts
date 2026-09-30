type AlertButton = {
  text?: string;
  onPress?: () => void | Promise<void>;
  style?: "default" | "cancel" | "destructive";
};

type AlertOptions = Record<string, unknown>;

export const Alert = {
  alert(
    title: string,
    message?: string,
    buttons?: AlertButton[],
    _options?: AlertOptions,
  ): void {
    const text = message ? `${title}\n\n${message}` : title;
    const actions = buttons ?? [];

    if (actions.length === 0) {
      window.alert(text);
      return;
    }

    const cancel = actions.find((button) => button.style === "cancel");
    const confirmButton =
      actions.find((button) => button.style === "destructive") ??
      actions.find((button) => button !== cancel);

    if (!confirmButton) {
      window.alert(text);
      return;
    }

    const accepted = window.confirm(text);
    if (accepted) {
      void confirmButton.onPress?.();
    } else {
      void cancel?.onPress?.();
    }
  },
};
