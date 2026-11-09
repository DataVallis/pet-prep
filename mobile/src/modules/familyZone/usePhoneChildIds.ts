import { useEffect, useState } from 'react';
import { AppState } from 'react-native';

import { loadChildSessions } from '@/modules/session/profiles';
import { useAppStore } from '@/store/appStore';

/**
 * The child profiles THIS parent signed in on THIS phone (M2-11 shared-phone sessions —
 * the children's profiles and the parent's own caretaker profile): the source of »which pets
 * does this phone care for« for the per-pet time zone banner (David 2026-11-09). Device-local,
 * read from SecureStore; re-read when the app comes back to the foreground. Unreadable = none.
 */
export function usePhoneChildIds(): number[] {
  const parentId = useAppStore((s) => s.user?.id ?? null);
  const [ids, setIds] = useState<number[]>([]);

  useEffect(() => {
    if (parentId === null) {
      setIds([]);
      return;
    }
    let alive = true;
    const read = () => {
      loadChildSessions()
        .then((sessions) => {
          if (!alive) return;
          setIds(
            Object.entries(sessions)
              .filter(([, s]) => s.parentUserId === parentId)
              .map(([id]) => Number(id)),
          );
        })
        .catch(() => {
          if (alive) setIds([]);
        });
    };
    read();
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') read();
    });
    return () => {
      alive = false;
      sub.remove();
    };
  }, [parentId]);

  return ids;
}
