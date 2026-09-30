// Connectivity hook. Web uses the browser's native connectivity signal;
// native platforms keep using NetInfo.

import NetInfo from "@react-native-community/netinfo";
import { useEffect, useState } from "react";
import { Platform } from "react-native";

export interface ConnectivityState {
  online: boolean;
  isConnected: boolean;
  isInternetReachable: boolean;
}

export function useConnectivity(): ConnectivityState {
  const browserOnline =
    Platform.OS === "web" && typeof navigator !== "undefined" ? navigator.onLine : true;

  const [state, setState] = useState<{ isConnected: boolean; isInternetReachable: boolean }>({
    isConnected: browserOnline,
    isInternetReachable: browserOnline,
  });

  useEffect(() => {
    if (Platform.OS === "web" && typeof window !== "undefined" && typeof navigator !== "undefined") {
      const applyBrowserState = () => {
        const online = navigator.onLine;
        setState({ isConnected: online, isInternetReachable: online });
      };

      applyBrowserState();
      window.addEventListener("online", applyBrowserState);
      window.addEventListener("offline", applyBrowserState);
      return () => {
        window.removeEventListener("online", applyBrowserState);
        window.removeEventListener("offline", applyBrowserState);
      };
    }

    const apply = (isConnected: boolean, reachable: boolean | null) => {
      setState({
        isConnected: !!isConnected,
        // reachable === null means "unknown yet" -> treat as reachable.
        isInternetReachable: reachable !== false,
      });
    };

    void NetInfo.fetch().then((s) => apply(!!s.isConnected, s.isInternetReachable));
    const unsub = NetInfo.addEventListener((s) => apply(!!s.isConnected, s.isInternetReachable));
    return unsub;
  }, []);

  return {
    online: state.isConnected && state.isInternetReachable,
    isConnected: state.isConnected,
    isInternetReachable: state.isInternetReachable,
  };
}
