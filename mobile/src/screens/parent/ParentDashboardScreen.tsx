/**
 * ParentDashboardScreen — the parent app (M2-05, light theme per ADR-007).
 *
 * "Pregled": one card per child from `GET /api/parent/dashboard` → `family.children`
 * (traffic light + reasons, Care Score, 12-week progress, today's routines, last 7
 * days, pet mini status). Live: one private Reverb channel per pet patches / refetches
 * the cache; without a subscribed socket the query polls every 30 s. Tapping a child
 * opens the report (`ChildDetailScreen`). "Nadzor": children + devices, hard stop per
 * pet, quiet hours, parents + invite / join. M3-09: a banner (waiting for the purchase / game pauses soon)
 * opens the challenge paywall (`ChallengeScreen`); the simulated "Pasme" tab is gone.
 * M5-F01: "12-week challenge — buy" on the child card and detail, and the "Purchases /
 * challenge" row in Nadzor open the same paywall (it lists every dog that can be bought).
 */

import { useEffect, useState } from 'react';
import { Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { BrandMark } from '@/components/brand/BrandLogo';
import { fonts, radius, tightTracking } from '@/theme';
import { LayoutDashboard, LogOut, Settings } from 'lucide-react-native';

import { ApiError } from '@/api/client';
import { isNoChildPaired, useParentDashboard } from '@/hooks/queries/useParentDashboard';
import { familyFromDashboard, petOfChild, type FamilyChild } from '@/modules/family/family';
import { logout } from '@/modules/session/logout';
import { useAppStore } from '@/store/appStore';
import { usePushPromptOnFirstView } from '@/modules/push/usePushPromptOnFirstView';
import AddChildCard from '@/components/AddChildCard';
import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import JoinFamilyCard, { joinNoticeText, type JoinNotice } from '@/components/parent/JoinFamilyCard';
import ParentLiveChannels from '@/components/ParentLiveChannels';
import { ErrorBanner, LoadingBlock, NoticeBanner, PARENT_COLORS as C } from '@/components/parent/ParentUi';
import ControlsScreen from '@/screens/parent/ControlsScreen';
import ChallengeScreen from '@/screens/parent/ChallengeScreen';
import ChallengeBanner from '@/components/parent/ChallengeBanner';
import AddChildScreen from '@/screens/parent/AddChildScreen';
import ChildDetailScreen from '@/screens/parent/ChildDetailScreen';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** User-visible strings (`parent:dashboard`, M1-18). */
export const DASHBOARD_STRINGS = strings('parent', 'dashboard', {
  /** "2 otroka · 1 kuža" / "2 children · 1 dog". */
  familySubtitle: (children: number, pets: number) =>
    t('parent:dashboard.familySubtitle', {
      children: t('parent:dashboard.counts.children', { count: children }),
      pets: t('parent:dashboard.counts.dogs', { count: pets }),
    }),
});

type Tab = 'dashboard' | 'controls';

/** `child` set = PIN for an existing child (pet choice or re-login); unset = new child. */
type Overlay =
  | { kind: 'none' }
  | { kind: 'addChild'; child?: FamilyChild }
  | { kind: 'child'; childId: number }
  /** `backToChildId`: opened from a child's detail → "Back" returns there (M5-F01). */
  | { kind: 'challenge'; backToChildId?: number };

function BottomNavBar({ activeTab, onSelect }: { activeTab: Tab; onSelect: (tab: Tab) => void }) {
  const tabs: { id: Tab; label: string; Icon: typeof LayoutDashboard }[] = [
    { id: 'dashboard', label: DASHBOARD_STRINGS.tabs.dashboard, Icon: LayoutDashboard },
    { id: 'controls', label: DASHBOARD_STRINGS.tabs.controls, Icon: Settings },
  ];
  return (
    <View style={styles.navBar}>
      {tabs.map(({ id, label, Icon }) => {
        const active = activeTab === id;
        return (
          <Pressable
            key={id}
            onPress={() => onSelect(id)}
            style={[styles.navTab, active && styles.navTabActive]}
            accessibilityRole="tab"
            accessibilityState={{ selected: active }}
            testID={`tab-${id}`}
          >
            <Icon color={active ? C.accent : C.faint} size={22} />
            <Text style={[styles.navLabel, active && styles.navLabelActive]}>{label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export default function ParentDashboardScreen() {
  const [activeTab, setActiveTab] = useState<Tab>('dashboard');
  const [overlay, setOverlay] = useState<Overlay>({ kind: 'none' });
  // A code, not text: translated at render so it follows a language switch (M1-18 review).
  const [notice, setNotice] = useState<JoinNotice | null>(null);
  const dashboard = useParentDashboard();
  const noChild = isNoChildPaired(dashboard.data);
  const family = familyFromDashboard(dashboard.data);
  const children = family?.children ?? [];
  const livePetIds = (family?.pets ?? []).filter((p) => p.is_active && !p.is_game_over).map((p) => p.id);

  const openAddChild = () => setOverlay({ kind: 'addChild' });
  const openChildPin = (child: FamilyChild) => setOverlay({ kind: 'addChild', child });
  const openChild = (child: FamilyChild) => setOverlay({ kind: 'child', childId: child.id });
  const closeOverlay = () => setOverlay({ kind: 'none' });
  const openChallenge = (backToChildId?: number) => setOverlay({ kind: 'challenge', backToChildId });

  // The child shown in the detail left the family list (removed / other parent's change)
  // → back to the overview instead of a stale or empty screen.
  const detailMissing =
    overlay.kind === 'child' && dashboard.data !== undefined && !children.some((c) => c.id === overlay.childId);
  useEffect(() => {
    if (detailMissing) setOverlay({ kind: 'none' });
  }, [detailMissing]);

  // A tapped push (M3-02) → the detail of the (first) child caring for that pet. Waits
  // for the dashboard; a pet that is not (any more) in the family just clears the target.
  // PR #35: first view of the session → ask about alarms while undecided.
  usePushPromptOnFirstView('parent');
  const pushTarget = useAppStore((s) => s.pushTarget);
  const setPushTarget = useAppStore((s) => s.setPushTarget);
  useEffect(() => {
    if (pushTarget === null || dashboard.data === undefined) return;
    const child = children.find((c) => c.pet_id === pushTarget.petId);
    if (child) {
      setActiveTab('dashboard');
      setOverlay({ kind: 'child', childId: child.id });
    }
    setPushTarget(null);
  }, [pushTarget, dashboard.data, children, setPushTarget]);

  // One stable position for the live subscriptions, whatever tab / overlay is shown.
  return (
    <View style={styles.root}>
      <ParentLiveChannels petIds={livePetIds} />
      {renderContent()}
    </View>
  );

  function renderContent() {
    if (overlay.kind === 'addChild') {
      return (
        <AddChildScreen
          child={overlay.child}
          onBack={() => {
            closeOverlay();
            void dashboard.refetch();
          }}
        />
      );
    }

    // Before the tabs: the paywall also opens from the "Nadzor" tab (M5-F01) and returns there.
    if (overlay.kind === 'challenge') {
      const backTo = overlay.backToChildId;
      return (
        <ChallengeScreen
          family={family}
          onBack={() => {
            setOverlay(backTo !== undefined ? { kind: 'child', childId: backTo } : { kind: 'none' });
            void dashboard.refetch();
          }}
        />
      );
    }

    const detailChild = overlay.kind === 'child' ? children.find((c) => c.id === overlay.childId) : undefined;
    if (overlay.kind === 'child' && family && detailChild) {
      return (
        <ChildDetailScreen
          child={detailChild}
          family={family}
          onBack={closeOverlay}
          onOpenChallenge={() => openChallenge(detailChild.id)}
        />
      );
    }

    if (activeTab === 'controls') {
      return (
        <>
          <ControlsScreen
            onBack={() => setActiveTab('dashboard')}
            family={family}
            onAddChild={openAddChild}
            onChildPin={openChildPin}
            onOpenChallenge={() => openChallenge()}
            notice={notice}
            onNotice={setNotice}
          />
          <BottomNavBar activeTab={activeTab} onSelect={setActiveTab} />
        </>
      );
    }

    const offline = dashboard.isError && !(dashboard.error instanceof ApiError);
    const subtitle =
      family && children.length > 0
        ? DASHBOARD_STRINGS.familySubtitle(children.length, family.pets.length)
        : dashboard.data
          ? DASHBOARD_STRINGS.noChildSubtitle
          : '';

    return (
      <>
        <View style={styles.header}>
          <BrandMark size={36} tone="light" testID="dashboard-brand-mark" />
          <View style={styles.flex}>
            <Text style={styles.headerTitle}>{DASHBOARD_STRINGS.title}</Text>
            {subtitle !== '' && <Text style={styles.headerSubtitle}>{subtitle}</Text>}
          </View>
          <Pressable
            style={({ pressed }) => [styles.logoutButton, pressed && styles.pressed]}
            onPress={() => void logout()}
            accessibilityRole="button"
            accessibilityLabel={DASHBOARD_STRINGS.logout}
          >
            <LogOut color={C.muted} size={18} />
          </Pressable>
        </View>

        <ScrollView style={styles.flex} contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
          <ChallengeBanner family={family} onOpen={() => openChallenge()} />
          {notice && <NoticeBanner text={joinNoticeText(notice)} closeLabel={DASHBOARD_STRINGS.closeNotice} onClose={() => setNotice(null)} />}
          {dashboard.isError && (
            <ErrorBanner
              text={
                dashboard.data
                  ? offline
                    ? DASHBOARD_STRINGS.offline
                    : DASHBOARD_STRINGS.loadError
                  : DASHBOARD_STRINGS.firstLoadError
              }
              retryLabel={DASHBOARD_STRINGS.retry}
              onRetry={() => void dashboard.refetch()}
              offline={offline}
              testID="dashboard-error"
            />
          )}

          {dashboard.isPending ? (
            <LoadingBlock text={DASHBOARD_STRINGS.loading} testID="dashboard-loading" />
          ) : !dashboard.data ? null : children.length === 0 ? (
            <>
              <AddChildCard onPress={openAddChild} />
              {(noChild || family === null || family.pets.length === 0) && <JoinFamilyCard onJoined={setNotice} />}
            </>
          ) : (
            children.map((child) => (
              <ChildOverviewCard
                key={child.id}
                child={child}
                pet={family ? petOfChild(child, family) : null}
                timezone={family?.timezone ?? 'Europe/Ljubljana'}
                onOpen={openChild}
                onChildPin={openChildPin}
                onOpenChallenge={() => openChallenge()}
              />
            ))
          )}
        </ScrollView>

        <BottomNavBar activeTab={activeTab} onSelect={setActiveTab} />
      </>
    );
  }
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: C.bg },
  flex: { flex: 1 },
  header: {
    paddingTop: Platform.OS === 'ios' ? 56 : 40,
    paddingHorizontal: 20,
    paddingBottom: 14,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: C.card,
    borderBottomWidth: 1,
    borderBottomColor: C.border,
  },
  headerTitle: { fontFamily: fonts.display, fontSize: 22, letterSpacing: tightTracking(22), color: C.text },
  headerSubtitle: { fontSize: 13, color: C.muted, marginTop: 2 },
  logoutButton: {
    width: 44,
    height: 44,
    borderRadius: radius.button,
    backgroundColor: C.divider,
    alignItems: 'center',
    justifyContent: 'center',
  },
  content: { padding: 16, gap: 14, paddingBottom: 30 },
  navBar: {
    flexDirection: 'row',
    borderTopWidth: 1,
    borderTopColor: C.border,
    backgroundColor: C.card,
    paddingBottom: Platform.OS === 'ios' ? 24 : 8,
  },
  navTab: { flex: 1, alignItems: 'center', paddingVertical: 10 },
  navTabActive: { borderTopWidth: 2, borderTopColor: C.accent },
  navLabel: { marginTop: 3, fontSize: 11, fontWeight: '600', color: C.faint },
  navLabelActive: { color: C.accent },
  pressed: { opacity: 0.7 },
});
