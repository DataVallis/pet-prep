/**
 * Puppy socialisation (M5-R17): `useSocialise()` records the week's "new experience"
 * (`POST /api/child/pet/socialisation {experience}`).
 *
 * Optimistic: the card shows the week as done at once (`optimisticSocialisation`); then the
 * cache is ALWAYS replaced by the server's `state` (200, 422 refusal, 423 lock). Without a
 * server answer (offline, 5xx) only the optimistic `socialisation` is undone and the state
 * refetched. No retries: one tap = one request (a repeat is refused as `week_done` anyway).
 * Answers that arrive after the session changed are ignored.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api, type ChildActionResponse } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import { currentUserId, sameSession, type SessionContext } from '@/hooks/queries/useChildActions';
import { classifyActionError } from '@/modules/childPet/actionMessages';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { optimisticSocialisation, type SocialisationExperience } from '@/modules/socialisation/socialisation';

interface SocialiseContext extends SessionContext {
  previous: ChildPetView | undefined;
}

export function useSocialise() {
  const client = useQueryClient();

  /** No server state to show: undo only the optimistic `socialisation`, then refetch. */
  const revert = (context: SocialiseContext | undefined) => {
    const current = client.getQueryData<ChildPetView>(childPetKey);
    const previous = context?.previous;
    // A broadcast since then already carries the server's `socialisation`.
    if (current && previous && current.lastEmittedMs <= previous.lastEmittedMs) {
      client.setQueryData<ChildPetView>(childPetKey, { ...current, socialisation: previous.socialisation });
    }
    void client.invalidateQueries({ queryKey: childPetKey });
  };

  return useMutation<ChildActionResponse, unknown, SocialisationExperience, SocialiseContext>({
    mutationKey: ['child', 'pet', 'socialisation'],
    networkMode: 'always',
    retry: false,
    onMutate: async (experience) => {
      await client.cancelQueries({ queryKey: childPetKey });
      const previous = client.getQueryData<ChildPetView>(childPetKey);
      if (previous?.socialisation) {
        client.setQueryData<ChildPetView>(childPetKey, { ...previous, socialisation: optimisticSocialisation(previous.socialisation, experience) });
      }
      return { previous, userId: currentUserId() };
    },
    mutationFn: (experience) => api.socialisePet(experience),
    onSuccess: (response, _experience, context) => {
      if (sameSession(context)) writeChildState(client, response.state);
    },
    onError: (error, _experience, context) => {
      if (!sameSession(context)) return;
      const failure = classifyActionError(error);
      if ((failure.kind === 'refused' || failure.kind === 'locked') && failure.state) {
        writeChildState(client, failure.state);
        return;
      }
      revert(context);
    },
  });
}
