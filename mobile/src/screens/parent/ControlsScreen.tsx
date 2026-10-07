/**
 * ControlsScreen — "Nadzor" tab of the parent app (light theme, ADR-007; M2-05):
 * children and their devices, controls per pet (caretakers, hard stop with in-app
 * confirmation), family quiet hours, the family's parents with the second-parent
 * invite, and — while the family is empty — joining another family by code; push
 * "Obvestila" status (M3-02); last the "Račun" section (M2-08): data export and account
 * deletion. M5-F01: a "Purchases / challenge" row (while the family has a challenge dog)
 * opens the paywall.
 */

import { Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { fonts, tightTracking } from '@/theme';
import { ChevronLeft } from 'lucide-react-native';

import AddChildCard from '@/components/AddChildCard';
import FamilyChildrenCard from '@/components/FamilyChildrenCard';
import AccountCard from '@/components/parent/AccountCard';
import FamilyParentsCard from '@/components/parent/FamilyParentsCard';
import JoinFamilyCard, { joinNoticeText, type JoinNotice } from '@/components/parent/JoinFamilyCard';
import LanguageCard from '@/components/parent/LanguageCard';
import NotificationsCard from '@/components/parent/NotificationsCard';
import PetControlsCard from '@/components/parent/PetControlsCard';
import QuietHoursCard from '@/components/parent/QuietHoursCard';
import PurchasesRow from '@/components/parent/PurchasesRow';
import { showPurchasesRow } from '@/modules/plan/purchaseEntry';
import { NoticeBanner, PARENT_COLORS as C } from '@/components/parent/ParentUi';
import BuildLabel from '@/components/BuildLabel';
import type { FamilyChild, FamilyOverview } from '@/modules/family/family';
import { strings } from '@/i18n/strings';


/** All user-visible strings of this screen (`parent:controls`, M1-18). */
export const CONTROLS_STRINGS = strings('parent', 'controls');

interface ControlsScreenProps {
  /** Navigate back to the dashboard. */
  onBack: () => void;
  /** The dashboard's family section (null while loading / no family yet). */
  family: FamilyOverview | null;
  /** Open "Dodaj otroka" — always offered (several children per family, M2-02). */
  onAddChild: () => void;
  /** PIN for an existing child (pet choice or re-login on a new device). */
  onChildPin: (child: FamilyChild) => void;
  /** M5-F01: open the challenge paywall ("Purchases / challenge" row). */
  onOpenChallenge?: () => void;
  /** Confirmation owned by the parent screen (survives the join card disappearing). */
  /** Kept as data, translated at render (follows a language switch). */
  notice?: JoinNotice | null;
  onNotice?: (notice: JoinNotice | null) => void;
}

export default function ControlsScreen({
  onBack,
  family,
  onAddChild,
  onChildPin,
  onOpenChallenge,
  notice = null,
  onNotice,
}: ControlsScreenProps) {
  const isEmpty = !family || (family.children.length === 0 && family.pets.length === 0);

  return (
    <View style={styles.root}>
      <View style={styles.header}>
        <Pressable onPress={onBack} hitSlop={8} accessibilityRole="button" accessibilityLabel={CONTROLS_STRINGS.back}>
          <ChevronLeft color={C.accent} size={26} />
        </Pressable>
        <Text style={styles.title}>{CONTROLS_STRINGS.title}</Text>
      </View>

      <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
        {notice && <NoticeBanner text={joinNoticeText(notice)} closeLabel={CONTROLS_STRINGS.closeNotice} onClose={() => onNotice?.(null)} />}
        {family && family.children.length > 0 ? (
          <FamilyChildrenCard family={family} onAddChild={onAddChild} onChildPin={onChildPin} />
        ) : (
          <AddChildCard onPress={onAddChild} compact />
        )}

        {family && family.pets.length > 0 && (
          <>
            <Text style={styles.group}>{CONTROLS_STRINGS.pets}</Text>
            {family.pets.map((pet) => (
              <PetControlsCard key={pet.id} pet={pet} family={family} />
            ))}
          </>
        )}

        {family && onOpenChallenge && showPurchasesRow(family) && <PurchasesRow family={family} onPress={onOpenChallenge} />}

        <QuietHoursCard />
        <NotificationsCard />

        {family && <FamilyParentsCard family={family} />}
        {isEmpty && <JoinFamilyCard onJoined={(joined) => onNotice?.(joined)} />}

        <LanguageCard />
        <AccountCard family={family} />
        {/* Build identity (which code David / testers run). */}
        <View style={styles.about} testID="controls-about">
          <Text style={styles.group}>{CONTROLS_STRINGS.about}</Text>
          <BuildLabel tone="light" style={styles.aboutLabel} />
        </View>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: C.bg },
  flex: { flex: 1 },
  header: {
    paddingTop: Platform.OS === 'ios' ? 56 : 40,
    paddingHorizontal: 16,
    paddingBottom: 12,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: C.card,
    borderBottomWidth: 1,
    borderBottomColor: C.border,
  },
  title: { fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display, color: C.text },
  content: { padding: 16, gap: 14, paddingBottom: 32 },
  group: { fontSize: 13, fontWeight: '700', color: C.muted, textTransform: 'uppercase', letterSpacing: 0.4, marginTop: 4 },
  about: { gap: 6, alignItems: 'center', paddingTop: 8 },
  aboutLabel: { textAlign: 'center' },
});
