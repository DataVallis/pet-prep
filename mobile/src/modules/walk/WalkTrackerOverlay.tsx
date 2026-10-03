import { useEffect, useRef, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { Check, Cloud, Footprints, Play, X } from 'lucide-react-native';
import { Pedometer } from 'expo-sensors';

import { useAppStore } from '@/store/appStore';
import type { BreedType } from '@/types';
import { formatStepCount } from '@/utils/metrics';

type StepSubscription = ReturnType<typeof Pedometer.watchStepCount>;

/** Daily step goals per breed — mirrors backend breed_configs seed data. */
const BREED_DAILY_STEPS: Record<BreedType, number> = {
  mutt: 4000,
  border_collie: 10000,
};
const DEFAULT_DAILY_STEPS = 4000;
/** Anti-cheat: ignore updates implying more than this many steps per minute. */
const MAX_STEPS_PER_MIN = 200;

export default function WalkTrackerOverlay() {
  const pet = useAppStore((s) => s.pet);
  const setWalkModalVisible = useAppStore((s) => s.setWalkModalVisible);
  const [isAvailable, setIsAvailable] = useState<boolean | null>(null);
  const [isTracking, setIsTracking] = useState(false);
  const [sessionSteps, setSessionSteps] = useState(0);
  const [synced, setSynced] = useState(false);
  const subscriptionRef = useRef<StepSubscription | null>(null);
  const lastCountRef = useRef(0);
  const lastTimeRef = useRef(0);

  const dailyGoal = pet
    ? (BREED_DAILY_STEPS[pet.breed_type] ?? DEFAULT_DAILY_STEPS)
    : DEFAULT_DAILY_STEPS;
  const currentSteps = (pet?.daily_step_count ?? 0) + sessionSteps;
  const progressPct = Math.min(100, Math.round((currentSteps / dailyGoal) * 100));

  // Check availability on mount.
  useEffect(() => {
    Pedometer.isAvailableAsync().then(setIsAvailable).catch(() => setIsAvailable(false));
    return () => {
      stopTracking();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const stopTracking = () => {
    if (subscriptionRef.current) {
      subscriptionRef.current.remove();
      subscriptionRef.current = null;
    }
    setIsTracking(false);
  };

  const handleStartTracking = async () => {
    if (isAvailable === false) return;

    try {
      const granted = await Pedometer.requestPermissionsAsync();
      if (granted.status !== 'granted') return;

      const start = new Date();
      start.setHours(0, 0, 0, 0);
      const end = new Date();
      const initial = await Pedometer.getStepCountAsync(start, end);
      lastCountRef.current = initial.steps;
      lastTimeRef.current = Date.now();
      setSessionSteps(0);
      setSynced(false);
      setIsTracking(true);

      subscriptionRef.current = Pedometer.watchStepCount((result) => {
        const now = Date.now();
        const delta = result.steps - lastCountRef.current;
        const elapsedMin = (now - lastTimeRef.current) / 60000;
        // Anti-cheat: ignore spikes exceeding the rate limit.
        if (elapsedMin > 0 && delta / elapsedMin > MAX_STEPS_PER_MIN) return;
        if (delta > 0) {
          lastCountRef.current = result.steps;
          lastTimeRef.current = now;
          setSessionSteps((prev) => prev + delta);
        }
      });
    } catch {
      // Pedometer read failed — tracking cannot start.
    }
  };

  const handleSync = () => {
    console.log('[WalkTracker] Sync to backend:', {
      pet_id: pet?.id ?? null,
      steps: currentSteps,
      daily_goal: dailyGoal,
      session_steps: sessionSteps,
    });
    setSynced(true);
  };

  const handleClose = () => {
    stopTracking();
    setWalkModalVisible(false);
  };

  return (
    <View className="absolute inset-0 z-30 items-center justify-center bg-slate-900/80">
      <View className="w-80 rounded-3xl border border-white/20 bg-slate-800/90 p-6">
        {/* Header with title and close button */}
        <View className="flex-row items-center justify-between">
          <View className="flex-row items-center gap-2">
            <Footprints color="#ffffff" size={24} />
            <Text className="text-lg font-bold text-white">Daily Walk</Text>
          </View>
          <Pressable onPress={handleClose} className="active:scale-90">
            <X color="#ffffff" size={24} />
          </Pressable>
        </View>

        {isAvailable === false ? (
          <Text className="py-8 text-center text-base text-slate-300">
            Step tracking not available on this device
          </Text>
        ) : (
          <>
            {/* Step count display */}
            <View className="mt-6 items-center">
              <Text className="text-4xl font-bold text-white">
                {formatStepCount(currentSteps)}
              </Text>
              <Text className="mt-1 text-sm text-slate-300">
                / {formatStepCount(dailyGoal)} daily steps
              </Text>
            </View>

            {/* Progress bar */}
            <View className="mt-4 h-3 w-full overflow-hidden rounded-full bg-slate-700">
              <View
                className="h-full rounded-full bg-emerald-400"
                style={{ width: `${progressPct}%` }}
              />
            </View>
            <Text className="mt-2 text-center text-xs text-slate-400">
              {progressPct}% complete
            </Text>

            {/* Action buttons */}
            <View className="mt-6 gap-3">
              <Pressable
                onPress={isTracking ? stopTracking : handleStartTracking}
                disabled={isAvailable === null}
                className="flex-row items-center justify-center gap-2 rounded-xl bg-emerald-500 px-6 py-3 active:scale-95 disabled:opacity-40"
              >
                <Play color="#ffffff" size={18} />
                <Text className="text-sm font-semibold text-white">
                  {isTracking ? 'Stop Tracking' : 'Start Tracking'}
                </Text>
              </Pressable>

              <Pressable
                onPress={handleSync}
                disabled={sessionSteps === 0 || synced}
                className="flex-row items-center justify-center gap-2 rounded-xl bg-white/20 px-6 py-3 active:scale-95 disabled:opacity-40"
              >
                {synced ? (
                  <Check color="#10B981" size={18} />
                ) : (
                  <Cloud color="#ffffff" size={18} />
                )}
                <Text className="text-sm font-semibold text-white">
                  {synced ? 'Synced!' : 'Sync to Backend'}
                </Text>
              </Pressable>
            </View>
          </>
        )}
      </View>
    </View>
  );
}
