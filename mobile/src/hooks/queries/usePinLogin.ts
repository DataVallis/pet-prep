/**
 * Child PIN login (M2-02) as a TanStack mutation: `POST /api/child/pin-login` →
 * token saved → session payload. The screen applies it with `appStore.signIn()`.
 */

import { useMutation } from '@tanstack/react-query';

import { deviceName } from '@/modules/pairing/deviceName';
import { performPinLogin } from '@/modules/pairing/pinLogin';
import type { SignInPayload } from '@/store/appStore';

export function usePinLogin() {
  return useMutation<SignInPayload, unknown, string>({
    mutationFn: (pin) => performPinLogin(pin, deviceName()),
    retry: false,
  });
}
