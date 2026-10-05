/**
 * PR #35: ask about notifications on the first parent dashboard / child HUD view of a
 * session (login or restored session) — only while the user hasn't decided, and never
 * within 3 days of "Ne zdaj" (`maybeAskForPush` keeps those rules). Once per session
 * token, so tab switches or remounts don't ask again.
 */

import { useEffect } from 'react';

import type { PushAudience } from '@/modules/push/pushConfig';
import { maybeAskForPush } from '@/modules/push/pushPrompt';
import { useAppStore } from '@/store/appStore';

let promptedForToken: string | null = null;

export function usePushPromptOnFirstView(audience: PushAudience): void {
  const token = useAppStore((s) => s.authToken);

  useEffect(() => {
    if (token === null || promptedForToken === token) return;
    promptedForToken = token;
    void maybeAskForPush(audience, Date.now(), { onlyIfUndetermined: true });
  }, [token, audience]);
}

/** Test hook. */
export function resetFirstViewPromptForTests(): void {
  promptedForToken = null;
}
