/**
 * Small React hooks for pet media (M4-03 app side).
 */

import { useCallback, useEffect, useState } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { forgetLocalMedia, isLocalMediaUri, localMediaUri, type MediaKind } from '@/modules/petMedia/mediaFileCache';
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

export interface LocalMediaUri {
  /** The phone's copy of the file when there is one, else the remote URL (null stays null). */
  uri: string | null;
  /**
   * Call first in an error handler: true when the failing source was the local copy —
   * it is deleted and `uri` switches to the remote URL (handled, don't escalate).
   */
  onLocalError: () => boolean;
}

/**
 * Prefer the local copy of a media file (`mediaFileCache`, David 2026-10-20). Decided
 * once per file: a download that finishes while this is shown doesn't swap the source
 * (no reload / flicker) — the next mount uses the copy.
 */
export function useLocalMediaUri(remote: string | null, kind: MediaKind): LocalMediaUri {
  const key = remote === null ? null : mediaKey(remote);
  const [state, setState] = useState<{ key: string | null; uri: string | null; failed: boolean }>(() => ({
    key,
    uri: localMediaUri(remote, kind),
    failed: false,
  }));

  let current = state;
  if (state.key !== key) {
    current = { key, uri: localMediaUri(remote, kind), failed: false };
    setState(current);
  }

  const uri = current.failed || !isLocalMediaUri(current.uri) ? remote : current.uri;

  const onLocalError = useCallback((): boolean => {
    if (!isLocalMediaUri(uri)) return false;
    forgetLocalMedia(remote, kind);
    setState((s) => ({ ...s, failed: true }));
    return true;
  }, [uri, remote, kind]);

  return { uri, onLocalError };
}
