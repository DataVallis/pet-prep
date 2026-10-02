import { useEffect, useRef, useState } from 'react';
import {
  Animated,
  Image,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  Beef,
  Droplet,
  Footprints,
  Heart,
  LogOut,
  PawPrint,
  Sparkles,
} from 'lucide-react-native';
import { useVideoPlayer, VideoView } from 'expo-video';

import { useAppStore, type WebSocketStatus } from '@/store/appStore';
import { usePetWebSocket } from '@/hooks/usePetWebSocket';
import { clearAuthToken } from '@/api/client';
import ActionButton from '@/components/ActionButton';
import CleaningOverlay from '@/components/CleaningOverlay';
import LockedScreen from '@/screens/LockedScreen';
import MetricBar from '@/components/MetricBar';
import WalkTrackerOverlay from '@/modules/walk/WalkTrackerOverlay';
import { formatVirtualAge, isActionDisabled } from '@/utils/metrics';

/** Pulsing connection-status dot for the top status bar. */
function WsStatusDot({ status }: { status: WebSocketStatus }) {
  if (status === 'connected') {
    return (
      <View style={styles.wsRow}>
        <View style={[styles.wsDot, styles.wsConnected]} />
        <Text style={styles.wsText}>V ŽIVO</Text>
      </View>
    );
  }
  if (status === 'reconnecting' || status === 'connecting') {
    return (
      <View style={styles.wsRow}>
        <View style={[styles.wsDot, styles.wsConnecting]} />
        <Text style={styles.wsText}>POVEZAVA...</Text>
      </View>
    );
  }
  return (
    <View style={styles.wsRow}>
      <View style={[styles.wsDot, styles.wsDisconnected]} />
      <Text style={styles.wsText}>OFFLINE</Text>
    </View>
  );
}

/**
 * Main child video HUD — full-bleed pet viewport with glassmorphism
 * status bar, right-edge metric sliders, and a floating action dock.
 */
export default function ChildHudScreen() {
  const pet = useAppStore((s) => s.pet);
  const setPet = useAppStore((s) => s.setPet);
  const resetStore = useAppStore((s) => s.reset);
  const wsStatus = useAppStore((s) => s.wsStatus);
  const lockState = useAppStore((s) => s.lockState);
  const isWalkModalVisible = useAppStore((s) => s.isWalkModalVisible);
  const isCleaningOverlayVisible = useAppStore((s) => s.isCleaningOverlayVisible);
  const setWalkModalVisible = useAppStore((s) => s.setWalkModalVisible);
  const setCleaningOverlayVisible = useAppStore((s) => s.setCleaningOverlayVisible);

  const [feedbackMessage, setFeedbackMessage] = useState<string | null>(null);

  // Breathing animation for avatar fallback
  const bounceAnim = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    Animated.loop(
      Animated.sequence([
        Animated.timing(bounceAnim, {
          toValue: -8,
          duration: 1500,
          useNativeDriver: true,
        }),
        Animated.timing(bounceAnim, {
          toValue: 0,
          duration: 1500,
          useNativeDriver: true,
        }),
      ]),
    ).start();
  }, [bounceAnim]);

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

  const showFeedback = (msg: string) => {
    setFeedbackMessage(msg);
    setTimeout(() => setFeedbackMessage(null), 2000);
  };

  const handleFeed = () => {
    if (!pet || feedDisabled) return;
    const newHunger = Math.min(100, (pet.hunger_level ?? 0) + 20);
    setPet({ ...pet, hunger_level: newHunger });
    showFeedback('🍖 +20% Hrana!');
  };

  const handleWater = () => {
    if (!pet || waterDisabled) return;
    const newThirst = Math.min(100, (pet.thirst_level ?? 0) + 20);
    setPet({ ...pet, thirst_level: newThirst });
    showFeedback('💧 +20% Voda!');
  };

  const handleWalk = () => setWalkModalVisible(true);
  const handleClean = () => setCleaningOverlayVisible(true);

  const handleLogout = async () => {
    await clearAuthToken();
    resetStore();
  };

  return (
    <View style={styles.container}>
      {/* Full-bleed background viewport */}
      {pet?.current_video_url ? (
        <VideoView
          player={player}
          contentFit="cover"
          nativeControls={false}
          style={StyleSheet.absoluteFill}
        />
      ) : pet?.pet_dna?.reference_image_url ? (
        <Image
          source={{ uri: pet.pet_dna.reference_image_url }}
          resizeMode="cover"
          style={StyleSheet.absoluteFill}
        />
      ) : (
        <View style={styles.fallbackViewport}>
          {/* Ambient gradient glows */}
          <View style={[styles.glowOrb, styles.glowIndigo]} />
          <View style={[styles.glowOrb, styles.glowEmerald]} />

          {/* Animated Pet Representation */}
          <Animated.View
            style={[
              styles.petAvatarWrapper,
              { transform: [{ translateY: bounceAnim }] },
            ]}
          >
            <View style={styles.petAvatarCircle}>
              <Text style={styles.petEmoji}>🐕</Text>
              <View style={styles.petHeartBadge}>
                <Heart color="#ef4444" fill="#ef4444" size={16} />
              </View>
            </View>

            <View style={styles.petStatusPill}>
              <Sparkles color="#818cf8" size={14} />
              <Text style={styles.petStatusPillText}>
                {pet?.pet_state === 'hungry'
                  ? 'Lačen kužek'
                  : pet?.pet_state === 'sleeping'
                  ? 'Počiva'
                  : pet?.pet_state === 'sick'
                  ? 'Bolran'
                  : 'Srečen in igriv'}
              </Text>
            </View>
          </Animated.View>
        </View>
      )}

      {/* Dark overlay for readability when video/image is present */}
      {(pet?.current_video_url || pet?.pet_dna?.reference_image_url) && (
        <View style={styles.mediaOverlay} />
      )}

      {/* Glassmorphism top status bar */}
      <View style={styles.topBar}>
        <View style={styles.petInfoLeft}>
          <View style={styles.petIconBox}>
            <PawPrint color="#a5b4fc" size={20} />
          </View>
          <View>
            <Text style={styles.petBreedName}>
              {pet?.breed_type?.replace('_', ' ') ?? 'Mutt kuža'}
            </Text>
            <Text style={styles.petAgeText}>
              {formatVirtualAge(pet?.born_at ?? null)}
            </Text>
          </View>
        </View>

        <View style={styles.topBarRight}>
          <WsStatusDot status={wsStatus} />
          <Pressable
            style={({ pressed }) => [styles.logoutButton, pressed && styles.pressed]}
            onPress={handleLogout}
          >
            <LogOut color="#94a3b8" size={16} />
          </Pressable>
        </View>
      </View>

      {/* Feedback Toast Notification */}
      {feedbackMessage && (
        <View style={styles.feedbackToast}>
          <Text style={styles.feedbackText}>{feedbackMessage}</Text>
        </View>
      )}

      {/* Right-edge vertical progress sliders */}
      <View style={styles.metricsColumn}>
        <MetricBar
          level={pet?.hunger_level ?? 0}
          label="Hrana"
          icon={<Beef color="#ffffff" size={16} />}
        />
        <MetricBar
          level={pet?.thirst_level ?? 0}
          label="Voda"
          icon={<Droplet color="#ffffff" size={16} />}
        />
        <MetricBar
          level={pet?.energy_level ?? 0}
          label="Energija"
          icon={<Footprints color="#ffffff" size={16} />}
        />
        <MetricBar
          level={pet?.hygiene_level ?? 0}
          label="Čistoča"
          icon={<Sparkles color="#ffffff" size={16} />}
        />
      </View>

      {/* Bottom floating control dock */}
      <View style={styles.bottomDock}>
        <ActionButton
          icon={<Beef color="#ffffff" size={24} />}
          label="Hrani"
          onPress={handleFeed}
          disabled={feedDisabled}
        />
        <ActionButton
          icon={<Droplet color="#ffffff" size={24} />}
          label="Voda"
          onPress={handleWater}
          disabled={waterDisabled}
        />
        <ActionButton
          icon={<Footprints color="#ffffff" size={24} />}
          label="Sprehod"
          onPress={handleWalk}
          disabled={walkDisabled}
        />
        <ActionButton
          icon={<Sparkles color="#ffffff" size={24} />}
          label="Očisti"
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

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#020617',
  },
  mediaOverlay: {
    ...StyleSheet.absoluteFill,
    backgroundColor: 'rgba(0, 0, 0, 0.25)',
  },
  fallbackViewport: {
    ...StyleSheet.absoluteFill,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#020617',
  },
  glowOrb: {
    position: 'absolute',
    borderRadius: 9999,
  },
  glowIndigo: {
    width: 320,
    height: 320,
    top: 100,
    left: -100,
    backgroundColor: 'rgba(79, 70, 229, 0.18)',
  },
  glowEmerald: {
    width: 260,
    height: 260,
    bottom: 140,
    right: -80,
    backgroundColor: 'rgba(16, 185, 129, 0.14)',
  },
  petAvatarWrapper: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: 16,
  },
  petAvatarCircle: {
    width: 150,
    height: 150,
    borderRadius: 75,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.2)',
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#4f46e5',
    shadowOffset: { width: 0, height: 10 },
    shadowOpacity: 0.45,
    shadowRadius: 20,
    elevation: 10,
  },
  petEmoji: {
    fontSize: 72,
  },
  petHeartBadge: {
    position: 'absolute',
    top: 4,
    right: 4,
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: 'rgba(15, 23, 42, 0.9)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.2)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  petStatusPill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 20,
    backgroundColor: 'rgba(15, 23, 42, 0.8)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
  },
  petStatusPillText: {
    fontSize: 13,
    fontWeight: '700',
    color: '#ffffff',
  },
  topBar: {
    position: 'absolute',
    top: Platform.OS === 'ios' ? 56 : 36,
    left: 16,
    right: 16,
    zIndex: 20,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: 'rgba(15, 23, 42, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    borderRadius: 20,
    paddingHorizontal: 16,
    paddingVertical: 12,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.4,
    shadowRadius: 12,
  },
  petInfoLeft: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    flex: 1,
  },
  petIconBox: {
    width: 38,
    height: 38,
    borderRadius: 12,
    backgroundColor: 'rgba(255, 255, 255, 0.1)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  petBreedName: {
    fontSize: 16,
    fontWeight: '800',
    textTransform: 'capitalize',
    color: '#ffffff',
  },
  petAgeText: {
    fontFamily: Platform.OS === 'ios' ? 'Courier New' : 'monospace',
    fontSize: 10,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.8,
    color: 'rgba(255, 255, 255, 0.55)',
  },
  topBarRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  wsRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 8,
    backgroundColor: 'rgba(255, 255, 255, 0.06)',
  },
  wsDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  wsConnected: {
    backgroundColor: '#10b981',
  },
  wsConnecting: {
    backgroundColor: '#f59e0b',
  },
  wsDisconnected: {
    backgroundColor: '#ef4444',
  },
  wsText: {
    fontFamily: Platform.OS === 'ios' ? 'Courier New' : 'monospace',
    fontSize: 9,
    fontWeight: '700',
    color: '#94a3b8',
  },
  logoutButton: {
    width: 34,
    height: 34,
    borderRadius: 10,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  feedbackToast: {
    position: 'absolute',
    top: 130,
    alignSelf: 'center',
    zIndex: 30,
    backgroundColor: '#4f46e5',
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 20,
    shadowColor: '#4f46e5',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.6,
    shadowRadius: 10,
  },
  feedbackText: {
    color: '#ffffff',
    fontWeight: '700',
    fontSize: 14,
  },
  metricsColumn: {
    position: 'absolute',
    right: 16,
    top: 130,
    zIndex: 20,
    gap: 12,
  },
  bottomDock: {
    position: 'absolute',
    bottom: Platform.OS === 'ios' ? 36 : 24,
    left: 20,
    right: 20,
    zIndex: 20,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-around',
    backgroundColor: 'rgba(15, 23, 42, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    borderRadius: 30,
    paddingVertical: 14,
    paddingHorizontal: 12,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 8 },
    shadowOpacity: 0.5,
    shadowRadius: 16,
  },
  pressed: {
    opacity: 0.7,
  },
});
