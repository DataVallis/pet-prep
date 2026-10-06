/**
 * Light status-bar text while a dark (graphite) screen is mounted — the child simulator,
 * PIN login and contract (CGP v2). The app default is dark text on the light fog screens.
 * Imperative on purpose: several `<StatusBar>` elements mounting and unmounting in the
 * same commit (PIN login → HUD) made React Native's status-bar stack loop under fake timers.
 */

import { useEffect } from 'react';
import { setStatusBarStyle } from 'expo-status-bar';

export function useDarkStatusBar(): void {
  useEffect(() => {
    setStatusBarStyle('light');
    return () => setStatusBarStyle('dark');
  }, []);
}
