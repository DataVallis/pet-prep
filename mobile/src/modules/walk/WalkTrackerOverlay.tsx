import { Platform, Pressable, Text, View } from 'react-native';
import { Footprints, RefreshCw, X } from 'lucide-react-native';

import type { ChildPetView } from '@/modules/childPet/childPetView';
import { formatSteps } from '@/modules/steps/stepCounter';
import type { StepSync } from '@/modules/steps/useStepSync';

/** User-visible strings (i18n with M1-18). */
export const WALK_STRINGS = {
  title: 'Sprehod',
  steps: (steps: string, goal: string) => `${steps} / ${goal} korakov`,
  energy: (pct: number) => `Energija ${pct} %`,
  mine: (steps: string) => `Tvoji koraki danes: ${steps}`,
  goalReached: 'Bravo! Današnji sprehod je opravljen.',
  allow: 'Dovoli štetje korakov',
  allowHint: 'Telefon bo štel tvoje korake, da kuža dobi energijo.',
  denied: 'Štetje korakov ni dovoljeno. Prosi starša, da ga vklopi v nastavitvah telefona.',
  unavailable: 'Ta telefon ne zna šteti korakov.',
  refresh: 'Osveži korake',
  syncing: 'Shranjujem …',
  androidNote: 'Koraki se štejejo, ko je aplikacija odprta.',
  close: 'Zapri',
} as const;

export interface WalkTrackerOverlayProps {
  view: ChildPetView;
  stepSync: StepSync;
  onClose: () => void;
}

/**
 * Daily walk (M1-14): today's steps and energy from the server (`state.steps`); the
 * phone's count is synced by `useStepSync` (foreground + every 5 min, or "Osveži").
 */
export default function WalkTrackerOverlay({ view, stepSync, onClose }: WalkTrackerOverlayProps) {
  const { steps_today: steps, goal, energy_level: energy, my_steps_today: mine } = view.steps;
  const progressPct = goal > 0 ? Math.min(100, Math.round((steps / goal) * 100)) : 0;
  const shared = view.pet.caretakers_count > 1;
  const { permission } = stepSync;

  return (
    <View className="absolute inset-0 z-30 items-center justify-center bg-slate-900/80" testID="walk-overlay">
      <View className="w-80 rounded-3xl border border-white/20 bg-slate-800/90 p-6">
        <View className="flex-row items-center justify-between">
          <View className="flex-row items-center gap-2">
            <Footprints color="#ffffff" size={24} />
            <Text className="text-lg font-bold text-white">{WALK_STRINGS.title}</Text>
          </View>
          <Pressable onPress={onClose} accessibilityRole="button" accessibilityLabel={WALK_STRINGS.close} className="active:scale-90">
            <X color="#ffffff" size={24} />
          </Pressable>
        </View>

        <View className="mt-6 items-center">
          <Text className="text-3xl font-bold text-white">
            {WALK_STRINGS.steps(formatSteps(steps), formatSteps(goal))}
          </Text>
          <Text className="mt-1 text-sm text-emerald-300">{WALK_STRINGS.energy(energy)}</Text>
          {shared && <Text className="mt-1 text-xs text-slate-300">{WALK_STRINGS.mine(formatSteps(mine))}</Text>}
        </View>

        <View className="mt-4 h-3 w-full overflow-hidden rounded-full bg-slate-700">
          <View className="h-full rounded-full bg-emerald-400" style={{ width: `${progressPct}%` }} />
        </View>
        {progressPct >= 100 && (
          <Text className="mt-2 text-center text-xs text-emerald-300">{WALK_STRINGS.goalReached}</Text>
        )}

        <View className="mt-6 gap-3">
          {permission === 'unavailable' ? (
            <Text className="text-center text-sm text-slate-300">{WALK_STRINGS.unavailable}</Text>
          ) : permission === 'denied' ? (
            <Text className="text-center text-sm text-slate-300">{WALK_STRINGS.denied}</Text>
          ) : permission === 'undetermined' ? (
            <>
              <Text className="text-center text-xs text-slate-300">{WALK_STRINGS.allowHint}</Text>
              <Pressable
                onPress={() => {
                  void stepSync.requestPermission();
                }}
                accessibilityRole="button"
                className="flex-row items-center justify-center gap-2 rounded-xl bg-emerald-500 px-6 py-3 active:scale-95"
              >
                <Footprints color="#ffffff" size={18} />
                <Text className="text-sm font-semibold text-white">{WALK_STRINGS.allow}</Text>
              </Pressable>
            </>
          ) : permission === 'granted' ? (
            <>
              <Pressable
                onPress={() => {
                  void stepSync.syncNow();
                }}
                disabled={stepSync.isSyncing}
                accessibilityRole="button"
                accessibilityState={{ disabled: stepSync.isSyncing }}
                className="flex-row items-center justify-center gap-2 rounded-xl bg-white/20 px-6 py-3 active:scale-95 disabled:opacity-40"
              >
                <RefreshCw color="#ffffff" size={18} />
                <Text className="text-sm font-semibold text-white">
                  {stepSync.isSyncing ? WALK_STRINGS.syncing : WALK_STRINGS.refresh}
                </Text>
              </Pressable>
              {Platform.OS === 'android' && (
                <Text className="text-center text-xs text-slate-400">{WALK_STRINGS.androidNote}</Text>
              )}
            </>
          ) : null}
        </View>
      </View>
    </View>
  );
}
