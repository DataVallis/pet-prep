/**
 * ParentDashboardScreen — main parent dashboard with a LIGHT theme.
 *
 * Shows a traffic-light status banner, a 2×2 real-time metric grid
 * (updated via WebSocket), an activity timeline, a weekly performance
 * chart, and a bottom navigation bar for Dashboard / Controls / Breeds.
 */

import { useState, type ReactNode } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import {
  AlertCircle,
  AlertTriangle,
  Beef,
  CheckCircle,
  Droplet,
  Footprints,
  LayoutDashboard,
  PawPrint,
  Settings,
  Sparkles,
  X,
} from 'lucide-react-native';

import { useAppStore } from '@/store/appStore';
import { usePetWebSocket } from '@/hooks/usePetWebSocket';
import { interpolateColor } from '@/utils/metrics';
import type { ActivityType, Pet } from '@/types';
import ControlsScreen from '@/screens/parent/ControlsScreen';
import BreedPaywallScreen from '@/screens/parent/BreedPaywallScreen';

type TrafficLight = 'green' | 'amber' | 'red';
type Tab = 'dashboard' | 'controls' | 'breeds';

interface ActivityEntry {
  id: number;
  type: ActivityType;
  timestamp: string;
  description: string;
}

interface DayData {
  day: string;
  completed: number;
  missed: number;
}

const POSITIVE_ACTIVITIES: ActivityType[] = [
  'fed_pet',
  'watered_pet',
  'walked_pet',
  'cleaned_poop',
];

const ACTIVITY_DESCRIPTIONS: Record<ActivityType, string> = {
  fed_pet: 'Fed the pet',
  watered_pet: 'Provided fresh water',
  walked_pet: 'Took the pet for a walk',
  cleaned_poop: 'Cleaned up after the pet',
  ignored_warning: 'Ignored a warning',
};

function getTrafficLight(escalationLevel: number): TrafficLight {
  if (escalationLevel >= 3) return 'red';
  if (escalationLevel >= 1) return 'amber';
  return 'green';
}

// ── Traffic Light Status Banner ────────────────────────────────
function TrafficLightBanner({ pet }: { pet: Pet | null }) {
  const level = getTrafficLight(pet?.escalation_level ?? 0);

  const config = {
    green: {
      bg: 'bg-emerald-100 border-emerald-500',
      Icon: CheckCircle,
      iconColor: '#059669',
      text: 'Simulation Healthy',
    },
    amber: {
      bg: 'bg-amber-100 border-amber-500',
      Icon: AlertTriangle,
      iconColor: '#D97706',
      text: 'Routines missed today',
    },
    red: {
      bg: 'bg-rose-100 border-rose-500',
      Icon: AlertCircle,
      iconColor: '#E11D48',
      text: 'Critical Neglect',
    },
  } as const;

  const { bg, Icon, iconColor, text } = config[level];

  return (
    <View className={`flex-row items-center gap-3 rounded-3xl border p-6 shadow-sm ${bg}`}>
      <Icon color={iconColor} size={40} />
      <View className="flex-1">
        <Text className="text-xl font-bold text-slate-900">{text}</Text>
        <Text className="mt-1 text-sm text-slate-600">
          Escalation level: {pet?.escalation_level ?? 0}
        </Text>
      </View>
    </View>
  );
}

// ── Metric Card (horizontal bar, light theme) ──────────────────
interface MetricCardProps {
  icon: ReactNode;
  name: string;
  level: number;
  statusText: string;
}

function MetricCard({ icon, name, level, statusText }: MetricCardProps) {
  const clamped = Math.max(0, Math.min(100, level));
  const fillColor = interpolateColor(clamped);

  return (
    <View className="bg-white rounded-2xl p-4 shadow-sm shadow-slate-200/50">
      <View className="flex-row items-center gap-2">
        {icon}
        <Text className="text-sm font-semibold text-slate-900">{name}</Text>
      </View>
      <View className="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
        <View
          className="h-full rounded-full"
          style={{ width: `${clamped}%`, backgroundColor: fillColor }}
        />
      </View>
      <Text className="mt-2 text-xs text-slate-500">
        {Math.round(clamped)}% · {statusText}
      </Text>
    </View>
  );
}

// ── Activity Timeline ──────────────────────────────────────────
function ActivityTimeline({ activities }: { activities: ActivityEntry[] }) {
  if (activities.length === 0) {
    return (
      <View className="bg-white rounded-2xl p-4 shadow-sm shadow-slate-200/50">
        <Text className="text-sm text-slate-500">No recent activity yet.</Text>
      </View>
    );
  }

  return (
    <View className="bg-white rounded-2xl p-4 shadow-sm shadow-slate-200/50">
      <Text className="mb-3 text-base font-bold text-slate-900">Recent Activity</Text>
      <View className="flex-col gap-3">
        {activities.map((entry) => {
          const isPositive = POSITIVE_ACTIVITIES.includes(entry.type);
          const BadgeIcon = isPositive ? CheckCircle : X;
          const badgeBg = isPositive ? 'bg-emerald-500' : 'bg-rose-500';

          return (
            <View key={entry.id} className="flex-row items-center gap-3">
              <View
                className={`h-8 w-8 items-center justify-center rounded-full ${badgeBg}`}
              >
                <BadgeIcon color="#ffffff" size={16} />
              </View>
              <View className="flex-1">
                <Text className="text-sm font-medium text-slate-900">
                  {ACTIVITY_DESCRIPTIONS[entry.type]}
                </Text>
                <Text className="text-xs text-slate-500">
                  {new Date(entry.timestamp).toLocaleString()}
                </Text>
              </View>
            </View>
          );
        })}
      </View>
    </View>
  );
}

// ── Weekly Performance Chart ───────────────────────────────────
function WeeklyChart({ data }: { data: DayData[] }) {
  const maxCount = Math.max(
    1,
    ...data.map((d) => Math.max(d.completed, d.missed)),
  );

  return (
    <View className="bg-white rounded-2xl p-4 shadow-sm shadow-slate-200/50">
      <Text className="mb-3 text-base font-bold text-slate-900">This Week</Text>
      <View className="flex-row items-end justify-between gap-2">
        {data.map((d) => (
          <View key={d.day} className="flex-1 items-center gap-1">
            <View className="h-28 w-full flex-col-reverse items-center justify-start overflow-hidden rounded-lg bg-slate-100">
              {/* Completed (green) segment */}
              <View
                className="w-full bg-emerald-500"
                style={{ height: `${(d.completed / maxCount) * 100}%` }}
              />
              {/* Missed (red) segment */}
              <View
                className="w-full bg-rose-500"
                style={{ height: `${(d.missed / maxCount) * 100}%` }}
              />
            </View>
            <Text className="text-xs text-slate-600">{d.day}</Text>
          </View>
        ))}
      </View>
      <View className="mt-3 flex-row items-center gap-4">
        <View className="flex-row items-center gap-1">
          <View className="h-3 w-3 rounded bg-emerald-500" />
          <Text className="text-xs text-slate-600">Completed</Text>
        </View>
        <View className="flex-row items-center gap-1">
          <View className="h-3 w-3 rounded bg-rose-500" />
          <Text className="text-xs text-slate-600">Missed</Text>
        </View>
      </View>
    </View>
  );
}

// ── Mock data (placeholder until API returns activities) ───────
const MOCK_ACTIVITIES: ActivityEntry[] = [
  { id: 1, type: 'fed_pet', timestamp: new Date(Date.now() - 7_200_000).toISOString(), description: 'Fed the pet' },
  { id: 2, type: 'watered_pet', timestamp: new Date(Date.now() - 10_800_000).toISOString(), description: 'Provided fresh water' },
  { id: 3, type: 'ignored_warning', timestamp: new Date(Date.now() - 18_000_000).toISOString(), description: 'Ignored a hunger warning' },
  { id: 4, type: 'walked_pet', timestamp: new Date(Date.now() - 86_400_000).toISOString(), description: 'Took the pet for a walk' },
];

const MOCK_WEEKLY: DayData[] = [
  { day: 'Mon', completed: 4, missed: 1 },
  { day: 'Tue', completed: 3, missed: 2 },
  { day: 'Wed', completed: 4, missed: 0 },
  { day: 'Thu', completed: 2, missed: 3 },
  { day: 'Fri', completed: 4, missed: 1 },
  { day: 'Sat', completed: 3, missed: 1 },
  { day: 'Sun', completed: 2, missed: 2 },
];

// ── Bottom Navigation Bar ──────────────────────────────────────
interface NavBarProps {
  activeTab: Tab;
  onSelect: (tab: Tab) => void;
}

function BottomNavBar({ activeTab, onSelect }: NavBarProps) {
  const tabs: { id: Tab; label: string; Icon: typeof LayoutDashboard }[] = [
    { id: 'dashboard', label: 'Dashboard', Icon: LayoutDashboard },
    { id: 'controls', label: 'Controls', Icon: Settings },
    { id: 'breeds', label: 'Breeds', Icon: PawPrint },
  ];

  return (
    <View className="flex-row border-t border-slate-200 bg-white">
      {tabs.map(({ id, label, Icon }) => {
        const isActive = activeTab === id;
        return (
          <Pressable
            key={id}
            onPress={() => onSelect(id)}
            className={`flex-1 items-center py-3 ${isActive ? 'border-t-2 border-indigo-500' : ''}`}
          >
            <Icon
              color={isActive ? '#6366f1' : '#94a3b8'}
              size={24}
            />
            <Text
              className={`mt-1 text-xs font-medium ${isActive ? 'text-indigo-600' : 'text-slate-400'}`}
            >
              {label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

// ── Main Screen ────────────────────────────────────────────────
export default function ParentDashboardScreen() {
  const pet = useAppStore((s) => s.pet);
  const [activeTab, setActiveTab] = useState<Tab>('dashboard');

  // Subscribe to real-time pet updates via WebSocket
  usePetWebSocket(pet?.id ?? null);

  if (activeTab === 'controls') {
    return (
      <View className="flex-1 bg-slate-50">
        <ControlsScreen onBack={() => setActiveTab('dashboard')} />
        <BottomNavBar activeTab={activeTab} onSelect={setActiveTab} />
      </View>
    );
  }

  if (activeTab === 'breeds') {
    return (
      <View className="flex-1 bg-slate-50">
        <BreedPaywallScreen onBack={() => setActiveTab('dashboard')} />
        <BottomNavBar activeTab={activeTab} onSelect={setActiveTab} />
      </View>
    );
  }

  return (
    <View className="flex-1 bg-slate-50">
      <ScrollView className="flex-1" contentContainerClassName="gap-4 p-4 pb-6">
        {/* Header */}
        <View className="flex-row items-center justify-between">
          <Text className="text-2xl font-bold text-slate-900">Parent Dashboard</Text>
          <Text className="text-sm text-slate-500">
            {pet?.breed_type === 'border_collie' ? 'Border Collie' : 'Mutt'}
          </Text>
        </View>

        {/* Traffic Light Status Banner */}
        <TrafficLightBanner pet={pet} />

        {/* 2×2 Real-Time Metric Grid */}
        <View className="flex-row flex-wrap gap-3">
          <View className="w-[48%]">
            <MetricCard
              icon={<Beef color="#6366f1" size={20} />}
              name="Hunger"
              level={pet?.hunger_level ?? 0}
              statusText="Fed 2h ago"
            />
          </View>
          <View className="w-[48%]">
            <MetricCard
              icon={<Droplet color="#6366f1" size={20} />}
              name="Thirst"
              level={pet?.thirst_level ?? 0}
              statusText="Watered 3h ago"
            />
          </View>
          <View className="w-[48%]">
            <MetricCard
              icon={<Footprints color="#6366f1" size={20} />}
              name="Movement"
              level={pet?.energy_level ?? 0}
              statusText={`${pet?.daily_step_count ?? 0} steps today`}
            />
          </View>
          <View className="w-[48%]">
            <MetricCard
              icon={<Sparkles color="#6366f1" size={20} />}
              name="Hygiene"
              level={pet?.hygiene_level ?? 0}
              statusText="Cleaned 5h ago"
            />
          </View>
        </View>

        {/* Activity Timeline */}
        <ActivityTimeline activities={MOCK_ACTIVITIES} />

        {/* Weekly Performance Chart */}
        <WeeklyChart data={MOCK_WEEKLY} />
      </ScrollView>

      <BottomNavBar activeTab={activeTab} onSelect={setActiveTab} />
    </View>
  );
}
