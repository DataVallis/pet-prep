/**
 * Small React hooks for pet media (M4-03 app side).
 */

import { useCallback, useEffect, useState } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { mediaKey } from '@/modules/petMedia/petMedia';

/** true while the app is in the foreground (`AppState` "active"). */
export function useAppActive(): boolean {
  const [active, setActive] = useState<boolean>(AppState.currentState !== 'background' && AppState.currentState !== 'inactive');
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state: AppStateStatus) => {
      setActive(state === 'active');
    });
    return () => sub.remove();
  }, []);
  return active;
}

export interface StableUrl {
  /** URL to render (null: the image is unusable → placeholder). */
  uri: string | null;
  /** Hand to `<Image onError>`: switch to the newest URL, or give up on this media. */
  onError: () => void;
}

/**
 * Keep rendering the first URL of a media while only its signature changes (the
 * server re-signs every ~30 min) — an `<Image>` with a new `uri` reloads and flickers.
 * A different file (`v` / path) switches at once. On a load error the newest URL is
 * tried; if that is the one that failed, the image is given up (→ placeholder) until
 * a new URL (re-signed or a different file) arrives.
 */
export function useStableUrl(latest: string | null): StableUrl {
  const [held, setHeld] = useState<string | null>(latest);
  const [failedUrl, setFailedUrl] = useState<string | null>(null);

  // Adjust state while rendering (React's derived-state pattern): a new file replaces
  // the held URL; a re-signed URL of the same file only replaces it once the held one
  // failed (expired).
  if (latest === null) {
    if (held !== null) setHeld(null);
  } else if (held === null || mediaKey(held) !== mediaKey(latest) || (held === failedUrl && latest !== held)) {
    setHeld(latest);
  }

  const onError = useCallback(() => {
    if (held === null) return;
    setFailedUrl(held);
    if (latest !== null && latest !== held && mediaKey(latest) === mediaKey(held)) setHeld(latest);
  }, [held, latest]);

  return { uri: held !== null && held === failedUrl ? null : held, onError };
}
