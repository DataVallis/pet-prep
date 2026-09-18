import { Image, Text, View } from 'react-native';
import { Beef, Droplet, Footprints, PawPrint, Sparkles } from 'lucide-react-native';
import { useVideoPlayer, VideoView } from 'expo-video';

import { useAppStore, type WebSocketStatus } from '@/store/appStore';
import { usePetWebSocket } from '@/hooks/usePetWebSocket';
import ActionButton from '@/components/ActionButton';
import CleaningOverlay from '@/components/CleaningOverlay';
import LockedScreen from '@/screens/LockedScreen';
import MetricBar from '@/components/MetricBar';
import WalkTrackerOverlay from '@/modules/walk/WalkTrackerOverlay';
import { formatVirtualAge, isActionDisabled } from '@/utils/metrics';

/** Pulsing connection-status dot for the top status bar. */
function WsStatusDot({ status }: { status: WebSocketStatus }) {
  if (status === 'connected') {
    return <View className="h-3 w-3 animate-pulse rounded-full bg-emerald-400" />;
  }
  if (status === 'reconnecting' || status === 'connecting') {
    return <View className="h-3 w-3 rounded-full bg-amber-400" />;
  }
  return <View className="h-3 w-3 rounded-full bg-red-500" />;
}

/**
 * Main child video HUD — full-bleed pet viewport with glassmorphism
 * status bar, right-edge metric sliders, and a floating action dock.
 */
export default function ChildHudScreen() {
  const pet = useAppStore((s) => s.pet);
  const wsStatus = useAppStore((s) => s.wsStatus);
  const lockState = useAppStore((s) => s.lockState);
  const isWalkModalVisible = useAppStore((s) => s.isWalkModalVisible);
  const isCleaningOverlayVisible = useAppStore((s) => s.isCleaningOverlayVisible);
  const setWalkModalVisible = useAppStore((s) => s.setWalkModalVisible);
  const setCleaningOverlayVisible = useAppStore((s) => s.setCleaningOverlayVisible);

  usePetWebSocket(pet?.id ?? null);

  const player = useVideoPlayer(pet?.current_video_url ?? null, (p) => {
    p.loop = true;
    p.muted = true;
    p.play();
  });

  const isLocked = lockState !== 'none';
  const petState = pet?.pet_state ?? 'idle';
  const noPet = pet === null;

  const feedDisabled = noPet || isActionDisabled('feed', petState, isLocked);
  const waterDisabled = noPet || isActionDisabled('water', petState, isLocked);
  const walkDisabled = noPet || isActionDisabled('walk', petState, isLocked);
  const cleanDisabled = noPet || isActionDisabled('clean', petState, isLocked);

  // Feed/Water backend endpoints are pending; walk & clean open overlays.
  const handleFeed = () => {};
  const handleWater = () => {};
  const handleWalk = () => setWalkModalVisible(true);
  const handleClean = () => setCleaningOverlayVisible(true);

  return (
    <View className="flex-1 bg-slate-950">
      {/* Full-bleed background viewport */}
      {pet?.current_video_url ? (
        <VideoView
          player={player}
          contentFit="cover"
          nativeControls={false}
          className="absolute inset-0 z-0"
        />
      ) : pet?.pet_dna?.reference_image_url ? (
        <Image
          source={{ uri: pet.pet_dna.reference_image_url }}
          resizeMode="cover"
          className="absolute inset-0 z-0"
        />
      ) : (
        <View className="absolute inset-0 z-0 items-center justify-center bg-slate-950">
          {/* Ambient gradient glows as fallback background */}
          <View className="absolute left-[-100] top-[80] h-72 w-72 rounded-full bg-indigo-600/15" />
          <View className="absolute right-[-80] bottom-[120] h-56 w-56 rounded-full bg-emerald-500/10" />
          <View className="absolute left-[60] bottom-[60] h-40 w-40 rounded-full bg-violet-600/10" />
          <PawPrint color="#334155" size={80} />
        </View>
      )}

      {/* Dark overlay for readability when video/image is present */}
      {pet?.current_video_url || pet?.pet_dna?.reference_image_url ? (
        <View className="absolute inset-0 z-0 bg-black/20" />
      ) : null}

      {/* Glassmorphism top status bar */}
      <View className="absolute left-4 right-4 top-14 z-10 flex-row items-center justify-between rounded-2xl border border-white/10 bg-slate-900/50 px-5 py-3.5 backdrop-blur-md">
        <View className="flex-row items-center gap-2.5">
          <View className="h-9 w-9 items-center justify-center rounded-xl bg-white/10">
            <PawPrint color="#a5b4fc" size={18} />
          </View>
          <View className="flex-col">
            <Text className="text-base font-bold capitalize text-white">
              {pet?.breed_type?.replace('_', ' ') ?? 'Your Pet'}
            </Text>
            <Text className="font-mono text-[10px] uppercase tracking-widest text-white/50">
              {formatVirtualAge(pet?.born_at ?? null)}
            </Text>
          </View>
        </View>
        <WsStatusDot status={wsStatus} />
      </View>

      {/* Right-edge vertical progress sliders */}
      <View className="absolute right-4 top-32 z-10 flex-col gap-4">
        <MetricBar
          level={pet?.hunger_level ?? 0}
          label={`${Math.round(pet?.hunger_level ?? 0)}%`}
          icon={<Beef color="#ffffff" size={16} />}
        />
        <MetricBar
          level={pet?.thirst_level ?? 0}
          label={`${Math.round(pet?.thirst_level ?? 0)}%`}
          icon={<Droplet color="#ffffff" size={16} />}
        />
        <MetricBar
          level={pet?.energy_level ?? 0}
          label={`${Math.round(pet?.energy_level ?? 0)}%`}
          icon={<Footprints color="#ffffff" size={16} />}
        />
        <MetricBar
          level={pet?.hygiene_level ?? 0}
          label={`${Math.round(pet?.hygiene_level ?? 0)}%`}
          icon={<Sparkles color="#ffffff" size={16} />}
        />
      </View>

      {/* Bottom floating control dock */}
      <View className="absolute bottom-8 left-6 right-6 z-10 flex-row items-center justify-around">
        <ActionButton
          icon={<Beef color="#ffffff" size={24} />}
          label="Feed"
          onPress={handleFeed}
          disabled={feedDisabled}
        />
        <ActionButton
          icon={<Droplet color="#ffffff" size={24} />}
          label="Water"
          onPress={handleWater}
          disabled={waterDisabled}
        />
        <ActionButton
          icon={<Footprints color="#ffffff" size={24} />}
          label="Walk"
          onPress={handleWalk}
          disabled={walkDisabled}
        />
        <ActionButton
          icon={<Sparkles color="#ffffff" size={24} />}
          label="Clean"
          onPress={handleClean}
          disabled={cleanDisabled}
        />
      </View>

      {/* Conditional overlays */}
      {isWalkModalVisible && <WalkTrackerOverlay />}
      {(isCleaningOverlayVisible || pet?.hygiene_level === 0) && <CleaningOverlay />}
      {lockState !== 'none' && <LockedScreen />}
    </View>
  );
}
