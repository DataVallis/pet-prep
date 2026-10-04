/**
 * Invisible: the parent's live connection (M2-05) — one Echo with one private
 * Reverb channel per family pet (`usePetChannels`, status per pet). Events patch /
 * refetch the cached dashboard (`createParentBroadcastHandler`, decay ticks
 * throttled), never the session store. Unless every channel is subscribed,
 * `wsStatus` makes the dashboard poll every 30 s.
 */

import { useMemo } from 'react';
import { useQueryClient } from '@tanstack/react-query';

import { usePetChannels } from '@/hooks/usePetChannels';
import { createParentBroadcastHandler } from '@/modules/family/live';

export default function ParentLiveChannels({ petIds }: { petIds: number[] }) {
  const queryClient = useQueryClient();
  const onBroadcast = useMemo(() => createParentBroadcastHandler(queryClient), [queryClient]);
  usePetChannels(petIds, onBroadcast);
  return null;
}
