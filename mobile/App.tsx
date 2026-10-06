import { useEffect } from 'react';
import { StatusBar } from 'expo-status-bar';
import * as NativeSplash from 'expo-splash-screen';
import { QueryClientProvider } from '@tanstack/react-query';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { queryClient } from './src/api/queryClient';
import AppNavigator from './src/navigation/AppNavigator';
import { useBrandFonts } from './src/theme/typography';

// Keep the native splash (CGP v2 face on fog) up until the brand fonts are ready, so the
// first frame is already in Instrument Sans / Bricolage. A failed load renders anyway
// with the system font.
NativeSplash.preventAutoHideAsync().catch(() => undefined);

export default function App() {
  const [fontsLoaded, fontError] = useBrandFonts();
  const ready = fontsLoaded || fontError !== null;

  useEffect(() => {
    if (ready) NativeSplash.hideAsync().catch(() => undefined);
  }, [ready]);

  if (!ready) return null;

  return (
    <QueryClientProvider client={queryClient}>
      <SafeAreaProvider>
        {/* Default for the light screens; the child simulator mounts its own "light" one later. */}
        <StatusBar style="dark" />
        <AppNavigator />
      </SafeAreaProvider>
    </QueryClientProvider>
  );
}
