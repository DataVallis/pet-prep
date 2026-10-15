/**
 * Which social sign-in buttons this device shows (M2-10c). Google is known synchronously
 * (client IDs + native module); Apple asks the OS once (`isAvailableAsync`, iOS only) — the
 * button appears when that resolves true and stays hidden on failure.
 */

import { useEffect, useState } from 'react';

import { isAppleSignInAvailable, isGoogleSignInAvailable } from './socialSdk';

export interface SocialProviders {
  google: boolean;
  apple: boolean;
}

export function useSocialProviders(): SocialProviders {
  const [google] = useState(() => isGoogleSignInAvailable());
  const [apple, setApple] = useState(false);

  useEffect(() => {
    let active = true;
    // One bounded native call per mount; never throws (false on any failure).
    void isAppleSignInAvailable().then((available) => {
      if (active) setApple(available);
    });
    return () => {
      active = false;
    };
  }, []);

  return { google, apple };
}
