/**
 * Invisible: one private Reverb subscription per pet of the family (M2-05). Events
 * patch / refetch the cached dashboard (`applyParentBroadcast`), never the session
 * store. While a channel isn't subscribed `wsStatus` makes the dashboard poll.
 */

import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';

import { usePetWebSocket } from '@/hooks/usePetWebSocket';
import { applyParentBroadcast } from '@/modules/family/live';
import type { PetUpdatedBroadcast } from '@/types';

function PetChannel({ petId }: { petId: number }) {
  const queryClient = useQueryClient();
  const onBroadcast = useCallback(
    (event: PetUpdatedBroadcast) => applyParentBroadcast(queryClient, event),
    [queryClient],
  );
  usePetWebSocket(petId, onBroadcast);
  return null;
}

export default function ParentLiveChannels({ petIds }: { petIds: number[] }) {
  return (
    <>
      {petIds.map((id) => (
        <PetChannel key={id} petId={id} />
      ))}
    </>
  );
}
