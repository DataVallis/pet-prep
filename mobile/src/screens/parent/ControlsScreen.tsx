/**
 * ControlsScreen — parent controls: Quiet Hours manager and
 * Emergency Hard Stop toggle. Light theme consistent with the
 * parent dashboard.
 */

import { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  ScrollView,
  Switch,
  Text,
  TextInput,
  View,
} from 'react-native';
import { ChevronLeft, ShieldAlert } from 'lucide-react-native';

import { api } from '@/api/client';
import AddChildCard from '@/components/AddChildCard';
import FamilyChildrenCard from '@/components/FamilyChildrenCard';
import type { FamilyChild, FamilyOverview } from '@/modules/family/family';
import type { QuietHours } from '@/types';

interface ControlsScreenProps {
  /** Navigate back to the dashboard. */
  onBack: () => void;
  /** The dashboard's family section (null while loading / no family yet). */
  family: FamilyOverview | null;
  /** Open "Dodaj otroka" — always offered (several children per family, M2-02). */
  onAddChild: () => void;
  /** PIN for an existing child (pet choice or re-login on a new device). */
  onChildPin: (child: FamilyChild) => void;
}

const EMPTY_QUIET_HOURS: QuietHours = {
  id: 0,
  school_start: '08:00',
  school_end: '13:00',
  bedtime_start: '21:00',
  bedtime_end: '07:00',
  is_active: true,
};

/** Validate HH:MM format. */
function isValidTime(value: string): boolean {
  return /^([01]\d|2[0-3]):[0-5]\d$/.test(value);
}

export default function ControlsScreen({ onBack, family, onAddChild, onChildPin }: ControlsScreenProps) {
  const [quietHours, setQuietHours] = useState<QuietHours>(EMPTY_QUIET_HOURS);
  const [isSaving, setIsSaving] = useState(false);
  const [hardStopActive, setHardStopActive] = useState(false);
  const [hardStopLoading, setHardStopLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let mounted = true;
    api
      .getQuietHours()
      .then((res) => {
        if (mounted && res.quiet_hours) {
          setQuietHours(res.quiet_hours);
        }
      })
      .catch(() => {
        // Use defaults if endpoint is not yet available
      });
    return () => {
      mounted = false;
    };
  }, []);

  const updateField = (
    field: keyof QuietHours,
    value: string | boolean,
  ): void => {
    setQuietHours((prev) => ({ ...prev, [field]: value }));
  };

  const handleSave = async () => {
    const times = [
      quietHours.school_start,
      quietHours.school_end,
      quietHours.bedtime_start,
      quietHours.bedtime_end,
    ];
    if (times.some((t) => t !== null && !isValidTime(t ?? ''))) {
      setError('Times must be in HH:MM format (e.g. 08:00).');
      return;
    }

    setIsSaving(true);
    setError(null);
    try {
      await api.updateQuietHours({
        school_start: quietHours.school_start,
        school_end: quietHours.school_end,
        bedtime_start: quietHours.bedtime_start,
        bedtime_end: quietHours.bedtime_end,
        is_active: quietHours.is_active,
      });
      Alert.alert('Saved', 'Quiet hours updated successfully.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save quiet hours.');
    } finally {
      setIsSaving(false);
    }
  };

  const handleHardStop = () => {
    const newActive = !hardStopActive;
    const message = newActive
      ? 'Activate emergency hard stop? This will immediately lock the child device.'
      : 'Deactivate the hard stop? The child will regain access to the app.';

    Alert.alert(
      newActive ? 'Emergency Hard Stop' : 'Deactivate Hard Stop',
      message,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Confirm',
          style: newActive ? 'destructive' : 'default',
          onPress: async () => {
            setHardStopLoading(true);
            try {
              await api.toggleHardStop(newActive);
              setHardStopActive(newActive);
            } catch (err) {
              Alert.alert(
                'Error',
                err instanceof Error ? err.message : 'Failed to toggle hard stop.',
              );
            } finally {
              setHardStopLoading(false);
            }
          },
        },
      ],
    );
  };

  return (
    <View className="flex-1 bg-slate-950">
      {/* Header */}
      <View className="flex-row items-center gap-3 bg-slate-900 px-4 py-4 border-b border-slate-800">
        <Pressable onPress={onBack} hitSlop={8}>
          <ChevronLeft color="#818cf8" size={28} />
        </Pressable>
        <Text className="text-xl font-bold text-slate-100">Controls</Text>
      </View>

      <ScrollView className="flex-1" contentContainerClassName="gap-4 p-4 pb-6">
        {family && family.children.length > 0 ? (
          <FamilyChildrenCard family={family} onAddChild={onAddChild} onChildPin={onChildPin} />
        ) : (
          <AddChildCard onPress={onAddChild} compact />
        )}

        {/* Quiet Hours Manager */}
        <View className="bg-slate-900 rounded-2xl p-5 border border-slate-800">
          <View className="flex-row items-center justify-between">
            <Text className="text-lg font-bold text-slate-100">Quiet Hours</Text>
            <View className="flex-row items-center gap-2">
              <Text className="text-sm text-slate-500">Active</Text>
              <Switch
                value={quietHours.is_active}
                onValueChange={(v) => updateField('is_active', v)}
                trackColor={{ false: '#cbd5e1', true: '#6366f1' }}
              />
            </View>
          </View>

          {/* School Hours */}
          <Text className="mt-4 text-sm font-semibold text-slate-300">
            School Hours
          </Text>
          <View className="mt-2 flex-row gap-3">
            <View className="flex-1">
              <Text className="mb-1 text-xs text-slate-500">Start</Text>
              <TextInput
                className="h-12 rounded-xl border border-slate-700 bg-slate-800 px-3 text-base text-slate-100"
                value={quietHours.school_start ?? ''}
                onChangeText={(v) => updateField('school_start', v)}
                placeholder="08:00"
                placeholderTextColor="#64748b"
                keyboardType="numbers-and-punctuation"
                maxLength={5}
              />
            </View>
            <View className="flex-1">
              <Text className="mb-1 text-xs text-slate-500">End</Text>
              <TextInput
                className="h-12 rounded-xl border border-slate-700 bg-slate-800 px-3 text-base text-slate-100"
                value={quietHours.school_end ?? ''}
                onChangeText={(v) => updateField('school_end', v)}
                placeholder="13:00"
                placeholderTextColor="#64748b"
                keyboardType="numbers-and-punctuation"
                maxLength={5}
              />
            </View>
          </View>

          {/* Bedtime */}
          <Text className="mt-4 text-sm font-semibold text-slate-300">Bedtime</Text>
          <View className="mt-2 flex-row gap-3">
            <View className="flex-1">
              <Text className="mb-1 text-xs text-slate-500">Start</Text>
              <TextInput
                className="h-12 rounded-xl border border-slate-700 bg-slate-800 px-3 text-base text-slate-100"
                value={quietHours.bedtime_start ?? ''}
                onChangeText={(v) => updateField('bedtime_start', v)}
                placeholder="21:00"
                placeholderTextColor="#64748b"
                keyboardType="numbers-and-punctuation"
                maxLength={5}
              />
            </View>
            <View className="flex-1">
              <Text className="mb-1 text-xs text-slate-500">End</Text>
              <TextInput
                className="h-12 rounded-xl border border-slate-700 bg-slate-800 px-3 text-base text-slate-100"
                value={quietHours.bedtime_end ?? ''}
                onChangeText={(v) => updateField('bedtime_end', v)}
                placeholder="07:00"
                placeholderTextColor="#64748b"
                keyboardType="numbers-and-punctuation"
                maxLength={5}
              />
            </View>
          </View>

          {error && <Text className="mt-3 text-sm text-rose-600">{error}</Text>}

          <Pressable
            className="mt-4 items-center rounded-xl bg-indigo-600 py-3.5 disabled:opacity-50"
            onPress={handleSave}
            disabled={isSaving}
          >
            {isSaving ? (
              <ActivityIndicator color="#ffffff" />
            ) : (
              <Text className="text-base font-semibold text-white">Save</Text>
            )}
          </Pressable>
        </View>

        {/* Emergency Hard Stop */}
        <View className="bg-slate-900 rounded-2xl p-5 border border-slate-800">
          <Text className="text-lg font-bold text-slate-100">Emergency Controls</Text>
          <Text className="mt-1 text-sm text-slate-500">
            Immediately lock the child's device to prevent all interaction.
          </Text>

          <Pressable
            className={`mt-4 items-center rounded-xl py-4 shadow-lg active:scale-98 ${
              hardStopActive ? 'bg-slate-700' : 'bg-rose-600'
            }`}
            onPress={handleHardStop}
            disabled={hardStopLoading}
          >
            {hardStopLoading ? (
              <ActivityIndicator color="#ffffff" />
            ) : (
              <View className="flex-row items-center gap-2">
                <ShieldAlert color="#ffffff" size={24} />
                <Text className="text-base font-bold text-white">
                  {hardStopActive ? 'DEACTIVATE HARD STOP' : 'EMERGENCY HARD STOP'}
                </Text>
              </View>
            )}
          </Pressable>

          {hardStopActive && (
            <Text className="mt-2 text-center text-sm font-medium text-rose-600">
              Hard stop is currently active — the child device is locked.
            </Text>
          )}
        </View>
      </ScrollView>
    </View>
  );
}
