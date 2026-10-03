/**
 * Runs the session restore once on mount and exposes a retry for the offline
 * state. Also wires the API client's 401 hook to a local logout, so a token
 * revoked while the app is open sends the user back to login.
 */

import { useCallback, useEffect } from 'react';

import { setUnauthorizedHandler } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import { logout } from './logout';
import { restoreSession } from './restoreSession';

export function useSessionBootstrap(): { retry: () => void } {
  const run = useCallback(async () => {
    const store = useAppStore.getState();
    store.setBootStatus('restoring');
    const result = await restoreSession();
    if (result.status === 'authenticated') {
      useAppStore.getState().signIn(result.session);
    } else if (result.status === 'offline') {
      useAppStore.getState().setBootStatus('offline');
    } else {
      useAppStore.getState().reset();
    }
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => {
      void logout({ revoke: false });
    });
    void run();
    return () => setUnauthorizedHandler(null);
  }, [run]);

  return {
    retry: () => {
      void run();
    },
  };
}
