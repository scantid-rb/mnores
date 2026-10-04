import type { ComponentProps } from "react";
import { ScrollView } from "react-native";

type Props = ComponentProps<typeof ScrollView> & {
  bottomOffset?: number;
};

export function KeyboardAwareScrollViewCompat({ bottomOffset: _bottomOffset, ...props }: Props) {
  return <ScrollView {...props} />;
}
