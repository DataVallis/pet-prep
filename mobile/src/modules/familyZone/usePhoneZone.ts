import { useEffect, useState } from 'react';
import { AppState } from 'react-native';

import { currentPhoneZone } from './familyZone';

/** The phone zone, refreshed whenever the app comes back to the foreground. */
export function usePhoneZone(): string | null {
  const [zone, setZone] = useState<string | null>(() => currentPhoneZone());
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') setZone(currentPhoneZone());
    });
    return () => sub.remove();
  }, []);
  return zone;
}
