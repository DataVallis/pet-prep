/**
 * ParentDashboardScreen — main parent dashboard.
 *
 * Shows a traffic-light status banner, a 2×2 real-time metric grid
 * (updated via WebSocket), an activity timeline, a weekly performance
 * chart, and a bottom navigation bar for Dashboard / Controls / Breeds.
 */

import { useState, type ReactNode } from 'react';
import { ActivityIndicator, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import {
  AlertCircle,
  AlertTriangle,
  Beef,
  CheckCircle,
  Droplet,
  Footprints,
  LayoutDashboard,
  LogOut,
  PawPrint,
  Settings,
  Sparkles,
  X,
} from 'lucide-react-native';

import { useAppStore } from '@/store/appStore';
import { usePetWebSocket } from '@/hooks/usePetWebSocket';
import { isNoChildPaired, useParentDashboard } from '@/hooks/queries/useParentDashboard';
import { familyFromDashboard, type FamilyChild } from '@/modules/family/family';
import { logout } from '@/modules/session/logout';
import { interpolateColor } from '@/utils/metrics';
import type { ActivityType, Pet } from '@/types';
import AddChildCard from '@/components/AddChildCard';
import FamilyChildrenCard from '@/components/FamilyChildrenCard';
import ControlsScreen from '@/screens/parent/ControlsScreen';
import BreedPaywallScreen from '@/screens/parent/BreedPaywallScreen';
import AddChildScreen from '@/screens/parent/AddChildScreen';

/** Strings added with M1-12 / M2-02 (older strings are still inline — extract with M1-18). */
export const DASHBOARD_STRINGS = {
  noChildSubtitle: 'Še ni povezanega otroka',
  loading: 'Nalagam pregled …',
  loadError: 'Pregleda ni bilo mogoče osvežiti. Prikazani so zadnji znani podatki.',
  retry: 'Poskusi znova',
  noActivePet: 'Otrok je povezan, a nima aktivnega kužka.',
  logout: 'Odjava',
} as const;

type TrafficLight = 'green' | 'amber' | 'red';
type Tab = 'dashboard' | 'controls' | 'breeds';
/** `child` set = PIN for an existing child (pet choice or re-login); unset = new child. */
type Overlay = { kind: 'none' } | { kind: 'addChild'; child?: FamilyChild };

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
  fed_pet: 'Nahrani ljubljenčka',
  watered_pet: 'Nalil svežo vodo',
  walked_pet: 'Opravil sprehod',
  cleaned_poop: 'Počistil za psom',
  ignored_warning: 'Spregledal opozorilo',
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
      bg: 'rgba(2, 44, 34, 0.65)',
      border: 'rgba(16, 185, 129, 0.4)',
      Icon: CheckCircle,
      iconColor: '#10B981',
      text: 'Vse poteka brezhibno',
      subtext: 'Otrok redno opravlja vse naloge',
    },
    amber: {
      bg: 'rgba(69, 26, 3, 0.65)',
      border: 'rgba(245, 158, 11, 0.4)',
      Icon: AlertTriangle,
      iconColor: '#F59E0B',
      text: 'Zamujeni več kot 2 nalogi',
      subtext: 'Ljubljenček potrebuje pozornost',
    },
    red: {
      bg: 'rgba(76, 5, 25, 0.75)',
      border: 'rgba(239, 68, 68, 0.5)',
      Icon: AlertCircle,
      iconColor: '#EF4444',
      text: 'Kritično stanje – žival trpi',
      subtext: 'Takojšnje ukrepanje je potrebno!',
    },
  } as const;

  const { bg, border, Icon, iconColor, text, subtext } = config[level];

  return (
    <View style={[styles.bannerContainer, { backgroundColor: bg, borderColor: border }]}>
      <Icon color={iconColor} size={36} />
      <View style={styles.bannerTextCol}>
        <Text style={styles.bannerTitle}>{text}</Text>
        <Text style={styles.bannerSubtitle}>{subtext}</Text>
      </View>
    </View>
  );
}

// ── Metric Card ────────────────────────────────────────────────
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
    <View style={styles.metricCard}>
      <View style={styles.metricCardHeader}>
        {icon}
        <Text style={styles.metricCardTitle}>{name}</Text>
      </View>
      <View style={styles.metricProgressTrack}>
        <View
          style={[
            styles.metricProgressFill,
            { width: `${clamped}%`, backgroundColor: fillColor },
          ]}
        />
      </View>
      <Text style={styles.metricCardFooter}>
        {Math.round(clamped)}% · {statusText}
      </Text>
    </View>
  );
}

// ── Activity Timeline ──────────────────────────────────────────
function ActivityTimeline({ activities }: { activities: ActivityEntry[] }) {
  return (
    <View style={styles.sectionCard}>
      <Text style={styles.sectionTitle}>Zadnje dejavnosti otroka</Text>
      <View style={styles.activityList}>
        {activities.map((entry) => {
          const isPositive = POSITIVE_ACTIVITIES.includes(entry.type);
          const BadgeIcon = isPositive ? CheckCircle : X;
          const badgeBg = isPositive ? '#10b981' : '#f43f5e';

          return (
            <View key={entry.id} style={styles.activityItem}>
              <View style={[styles.activityBadge, { backgroundColor: badgeBg }]}>
                <BadgeIcon color="#ffffff" size={14} />
              </View>
              <View style={styles.activityDetails}>
                <Text style={styles.activityName}>
                  {ACTIVITY_DESCRIPTIONS[entry.type] ?? entry.description}
                </Text>
                <Text style={styles.activityTime}>
                  {new Date(entry.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
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
  const maxCount = Math.max(1, ...data.map((d) => Math.max(d.completed, d.missed)));

  return (
    <View style={styles.sectionCard}>
      <Text style={styles.sectionTitle}>Tedenska statistika odgovornosti</Text>
      <View style={styles.chartBarsRow}>
        {data.map((d) => (
          <View key={d.day} style={styles.chartCol}>
            <View style={styles.chartTrack}>
              <View
                style={[
                  styles.chartBarGreen,
                  { height: `${(d.completed / maxCount) * 100}%` },
                ]}
              />
              <View
                style={[
                  styles.chartBarRed,
                  { height: `${(d.missed / maxCount) * 100}%` },
                ]}
              />
            </View>
            <Text style={styles.chartDayText}>{d.day}</Text>
          </View>
        ))}
      </View>
      <View style={styles.chartLegendRow}>
        <View style={styles.chartLegendItem}>
          <View style={[styles.legendDot, { backgroundColor: '#10b981' }]} />
          <Text style={styles.legendText}>Opravljeno</Text>
        </View>
        <View style={styles.chartLegendItem}>
          <View style={[styles.legendDot, { backgroundColor: '#ef4444' }]} />
          <Text style={styles.legendText}>Zamujeno</Text>
        </View>
      </View>
    </View>
  );
}

// ── Mock data ──────────────────────────────────────────────────
const MOCK_ACTIVITIES: ActivityEntry[] = [
  { id: 1, type: 'fed_pet', timestamp: new Date(Date.now() - 3_600_000).toISOString(), description: 'Hranjenje' },
  { id: 2, type: 'watered_pet', timestamp: new Date(Date.now() - 7_200_000).toISOString(), description: 'Voda' },
  { id: 3, type: 'walked_pet', timestamp: new Date(Date.now() - 14_400_000).toISOString(), description: 'Sprehod' },
  { id: 4, type: 'cleaned_poop', timestamp: new Date(Date.now() - 28_800_000).toISOString(), description: 'Čiščenje' },
];

const MOCK_WEEKLY: DayData[] = [
  { day: 'Pon', completed: 4, missed: 0 },
  { day: 'Tor', completed: 3, missed: 1 },
  { day: 'Sre', completed: 4, missed: 0 },
  { day: 'Čet', completed: 2, missed: 2 },
  { day: 'Pet', completed: 4, missed: 0 },
  { day: 'Sob', completed: 3, missed: 1 },
  { day: 'Ned', completed: 4, missed: 0 },
];

// ── Bottom Navigation Bar ──────────────────────────────────────
interface NavBarProps {
  activeTab: Tab;
  onSelect: (tab: Tab) => void;
}

function BottomNavBar({ activeTab, onSelect }: NavBarProps) {
  const tabs: { id: Tab; label: string; Icon: typeof LayoutDashboard }[] = [
    { id: 'dashboard', label: 'Pregled', Icon: LayoutDashboard },
    { id: 'controls', label: 'Nadzor & Ure', Icon: Settings },
    { id: 'breeds', label: 'Pasma & Trgovina', Icon: PawPrint },
  ];

  return (
    <View style={styles.navBar}>
      {tabs.map(({ id, label, Icon }) => {
        const isActive = activeTab === id;
        return (
          <Pressable
            key={id}
            onPress={() => onSelect(id)}
            style={[styles.navTab, isActive && styles.navTabActive]}
          >
            <Icon color={isActive ? '#818cf8' : '#64748b'} size={22} />
            <Text style={[styles.navLabel, isActive && styles.navLabelActive]}>
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
  const [overlay, setOverlay] = useState<Overlay>({ kind: 'none' });
  const dashboard = useParentDashboard();
  const noChild = isNoChildPaired(dashboard.data);
  const family = familyFromDashboard(dashboard.data);
  const hasChildren = family !== null && family.children.length > 0;

  usePetWebSocket(pet?.id ?? null);

  const handleLogout = () => {
    void logout();
  };

  const openAddChild = () => setOverlay({ kind: 'addChild' });
  const openChildPin = (child: FamilyChild) => setOverlay({ kind: 'addChild', child });

  if (overlay.kind === 'addChild') {
    return (
      <View style={styles.root}>
        <AddChildScreen
          child={overlay.child}
          onBack={() => {
            setOverlay({ kind: 'none' });
            void dashboard.refetch();
          }}
        />
      </View>
    );
  }

  if (activeTab === 'controls') {
    return (
      <View style={styles.root}>
        <ControlsScreen
          onBack={() => setActiveTab('dashboard')}
          family={family}
          onAddChild={openAddChild}
          onChildPin={openChildPin}
        />
        <BottomNavBar activeTab={activeTab} onSelect={setActiveTab} />
      </View>
    );
  }

  if (activeTab === 'breeds') {
    return (
      <View style={styles.root}>
        <BreedPaywallScreen onBack={() => setActiveTab('dashboard')} />
        <BottomNavBar activeTab={activeTab} onSelect={setActiveTab} />
      </View>
    );
  }

  return (
    <View style={styles.root}>
      {/* Top Header */}
      <View style={styles.header}>
        <View>
          <Text style={styles.headerTitle}>Nadzorna plošča za starše</Text>
          <Text style={styles.headerSubtitle}>
            {noChild
              ? DASHBOARD_STRINGS.noChildSubtitle
              : `Ljubljenček: ${pet?.breed_type === 'border_collie' ? 'Border Collie' : 'Mutt kuža'}`}
          </Text>
        </View>

        <Pressable
          style={({ pressed }) => [styles.logoutButton, pressed && styles.pressed]}
          onPress={handleLogout}
          accessibilityRole="button"
          accessibilityLabel={DASHBOARD_STRINGS.logout}
        >
          <LogOut color="#94a3b8" size={18} />
        </Pressable>
      </View>

      <ScrollView
        style={styles.scrollArea}
        contentContainerStyle={styles.scrollContent}
        showsVerticalScrollIndicator={false}
      >
        {dashboard.isError && (
          <View style={styles.inlineError} testID="dashboard-error">
            <Text style={styles.inlineErrorText}>{DASHBOARD_STRINGS.loadError}</Text>
            <Pressable onPress={() => void dashboard.refetch()} hitSlop={8}>
              <Text style={styles.inlineErrorRetry}>{DASHBOARD_STRINGS.retry}</Text>
            </Pressable>
          </View>
        )}

        {dashboard.isPending && !pet ? (
          <View style={styles.loadingBox} testID="dashboard-loading">
            <ActivityIndicator color="#818cf8" />
            <Text style={styles.legendText}>{DASHBOARD_STRINGS.loading}</Text>
          </View>
        ) : noChild && !hasChildren ? (
          <AddChildCard onPress={openAddChild} />
        ) : !pet && dashboard.data?.pet === null ? (
          <>
            {family && hasChildren && (
              <FamilyChildrenCard family={family} onAddChild={openAddChild} onChildPin={openChildPin} />
            )}
            <View style={styles.sectionCard}>
              <Text style={styles.legendText}>{DASHBOARD_STRINGS.noActivePet}</Text>
            </View>
          </>
        ) : (
          <>
            {family && hasChildren && (
              <FamilyChildrenCard family={family} onAddChild={openAddChild} onChildPin={openChildPin} />
            )}

            {/* Traffic Light Status Banner */}
            <TrafficLightBanner pet={pet} />

            {/* 2×2 Real-Time Metric Grid */}
            <View style={styles.metricGrid}>
              <View style={styles.gridCol}>
                <MetricCard
                  icon={<Beef color="#6366f1" size={18} />}
                  name="Hrana"
                  level={pet?.hunger_level ?? 80}
                  statusText="Nahranjen"
                />
              </View>
              <View style={styles.gridCol}>
                <MetricCard
                  icon={<Droplet color="#6366f1" size={18} />}
                  name="Voda"
                  level={pet?.thirst_level ?? 75}
                  statusText="Sveža voda"
                />
              </View>
              <View style={styles.gridCol}>
                <MetricCard
                  icon={<Footprints color="#6366f1" size={18} />}
                  name="Gibanje"
                  level={pet?.energy_level ?? 90}
                  statusText={`${pet?.daily_step_count ?? 4200} korakov`}
                />
              </View>
              <View style={styles.gridCol}>
                <MetricCard
                  icon={<Sparkles color="#6366f1" size={18} />}
                  name="Čistoča"
                  level={pet?.hygiene_level ?? 85}
                  statusText="Čisto"
                />
              </View>
            </View>

            {/* Activity Timeline */}
            <ActivityTimeline activities={MOCK_ACTIVITIES} />

            {/* Weekly Performance Chart */}
            <WeeklyChart data={MOCK_WEEKLY} />
          </>
        )}
      </ScrollView>

      <BottomNavBar activeTab={activeTab} onSelect={setActiveTab} />
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: '#020617',
  },
  header: {
    paddingTop: Platform.OS === 'ios' ? 56 : 40,
    paddingHorizontal: 20,
    paddingBottom: 16,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: '#0f172a',
    borderBottomWidth: 1,
    borderBottomColor: 'rgba(255, 255, 255, 0.08)',
  },
  headerTitle: {
    fontSize: 20,
    fontWeight: '800',
    color: '#ffffff',
  },
  headerSubtitle: {
    fontSize: 13,
    color: '#94a3b8',
    marginTop: 2,
  },
  logoutButton: {
    width: 38,
    height: 38,
    borderRadius: 12,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  scrollArea: {
    flex: 1,
  },
  scrollContent: {
    padding: 16,
    gap: 16,
    paddingBottom: 30,
  },
  bannerContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    borderRadius: 20,
    borderWidth: 1,
    padding: 16,
  },
  bannerTextCol: {
    flex: 1,
  },
  bannerTitle: {
    fontSize: 17,
    fontWeight: '800',
    color: '#ffffff',
  },
  bannerSubtitle: {
    fontSize: 13,
    color: '#94a3b8',
    marginTop: 2,
  },
  metricGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 12,
  },
  gridCol: {
    width: '48%',
  },
  metricCard: {
    backgroundColor: 'rgba(15, 23, 42, 0.8)',
    borderRadius: 18,
    padding: 14,
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.1)',
  },
  metricCardHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  metricCardTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: '#ffffff',
  },
  metricProgressTrack: {
    marginTop: 10,
    height: 8,
    width: '100%',
    borderRadius: 4,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    overflow: 'hidden',
  },
  metricProgressFill: {
    height: '100%',
    borderRadius: 4,
  },
  metricCardFooter: {
    marginTop: 8,
    fontSize: 11,
    fontWeight: '600',
    color: '#94a3b8',
  },
  sectionCard: {
    backgroundColor: 'rgba(15, 23, 42, 0.8)',
    borderRadius: 20,
    padding: 18,
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.1)',
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '800',
    color: '#ffffff',
    marginBottom: 14,
  },
  activityList: {
    gap: 12,
  },
  activityItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  activityBadge: {
    width: 30,
    height: 30,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
  },
  activityDetails: {
    flex: 1,
  },
  activityName: {
    fontSize: 14,
    fontWeight: '600',
    color: '#ffffff',
  },
  activityTime: {
    fontSize: 11,
    color: '#64748b',
    marginTop: 1,
  },
  chartBarsRow: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
    gap: 6,
    height: 120,
  },
  chartCol: {
    flex: 1,
    alignItems: 'center',
    gap: 6,
  },
  chartTrack: {
    height: 90,
    width: '100%',
    flexDirection: 'column-reverse',
    alignItems: 'center',
    borderRadius: 8,
    backgroundColor: 'rgba(255, 255, 255, 0.06)',
    overflow: 'hidden',
  },
  chartBarGreen: {
    width: '100%',
    backgroundColor: '#10b981',
  },
  chartBarRed: {
    width: '100%',
    backgroundColor: '#ef4444',
  },
  chartDayText: {
    fontSize: 11,
    fontWeight: '600',
    color: '#64748b',
  },
  chartLegendRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 16,
    marginTop: 12,
  },
  chartLegendItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  legendDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  legendText: {
    fontSize: 12,
    color: '#94a3b8',
  },
  navBar: {
    flexDirection: 'row',
    borderTopWidth: 1,
    borderTopColor: 'rgba(255, 255, 255, 0.08)',
    backgroundColor: '#0f172a',
    paddingBottom: Platform.OS === 'ios' ? 24 : 8,
  },
  navTab: {
    flex: 1,
    alignItems: 'center',
    paddingVertical: 10,
  },
  navTabActive: {
    borderTopWidth: 2,
    borderTopColor: '#6366f1',
  },
  navLabel: {
    marginTop: 3,
    fontSize: 11,
    fontWeight: '600',
    color: '#64748b',
  },
  navLabelActive: {
    color: '#818cf8',
  },
  pressed: {
    opacity: 0.7,
  },
  loadingBox: {
    alignItems: 'center',
    gap: 10,
    paddingVertical: 40,
  },
  inlineError: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    padding: 12,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: 'rgba(244, 63, 94, 0.35)',
    backgroundColor: 'rgba(244, 63, 94, 0.12)',
  },
  inlineErrorText: {
    flex: 1,
    fontSize: 13,
    color: '#fda4af',
  },
  inlineErrorRetry: {
    fontSize: 13,
    fontWeight: '700',
    color: '#ffffff',
  },
});
