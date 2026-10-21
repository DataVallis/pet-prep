/**
 * M5-T11 child settings: »Prikaz: Video / Slika« (`PATCH /api/child/settings`).
 *
 * Optimistic: the HUD switches at once (the cached child state's `settings.media_display`);
 * a failure puts the previous value back. Afterwards the child state is refetched (the
 * server value is the truth — a parent may have changed it at the same time).
 */

import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api, type UpdateChildDisplayResponse } from '@/api/client';
import { childPetKey } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import type { MediaDisplay } from '@/modules/display/display';

/** The cached view with another display (unchanged object when it already has it). */
export function withMediaDisplay(view: ChildPetView, display: MediaDisplay): ChildPetView {
  if (view.settings.media_display === display) return view;
  return { ...view, settings: { ...view.settings, media_display: display } };
}

export function useSetMediaDisplay() {
  const client = useQueryClient();
  return useMutation<UpdateChildDisplayResponse, unknown, MediaDisplay, { previous: MediaDisplay | null }>({
    mutationFn: (display) => api.updateChildDisplay({ media_display: display }),
    retry: false,
    onMutate: async (display) => {
      await client.cancelQueries({ queryKey: childPetKey });
      const view = client.getQueryData<ChildPetView>(childPetKey);
      if (view) client.setQueryData<ChildPetView>(childPetKey, withMediaDisplay(view, display));
      return { previous: view?.settings.media_display ?? null };
    },
    onError: (_error, _display, context) => {
      const view = client.getQueryData<ChildPetView>(childPetKey);
      if (view && context?.previous) client.setQueryData<ChildPetView>(childPetKey, withMediaDisplay(view, context.previous));
    },
    onSettled: () => client.invalidateQueries({ queryKey: childPetKey }),
  });
}
