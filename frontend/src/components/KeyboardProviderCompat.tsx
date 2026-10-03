import type { PropsWithChildren } from "react";
import { KeyboardProvider } from "react-native-keyboard-controller";

export function KeyboardProviderCompat({ children }: PropsWithChildren) {
  return <KeyboardProvider>{children}</KeyboardProvider>;
}
