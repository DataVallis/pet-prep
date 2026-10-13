/**
 * M5-R09c »Moja pasma« (DID_YOU_KNOW_SPEC §3.4) — the child's breed guide, dark theme, a
 * full-screen layer over the HUD (like the album; the HUD video pauses). Header with the round
 * pet picture, eyebrow and breed name; search (diacritic-insensitive: »znacaj« finds »značaj«);
 * category chips (»Vse« + the categories present); »Prebrano: X od Y« with a thin mint bar; the
 * list with »Novo« on unread facts. Every fact is readable at once (no locks, spec §0.2); a tap
 * opens the same card as the automatic one. Android back closes it.
 *
 * Not built: the three quick-fact tiles (»2+ h gibanja«, »13 let«, »53 cm«) — the approved
 * facts carry no structured numbers with a source, and inventing them is not allowed (spec §5);
 * see DECISIONS 2026-10-13 (M5-R09c).
 */

import { useContext, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, BackHandler, FlatList, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { ChevronLeft, Search, WifiOff, X } from 'lucide-react-native';

import type { BreedFact } from '@/api/client';
import { Text, TextInput } from '@/components/ui/Text';
import { t } from '@/i18n';
import { filterFacts, presentCategories, type FactCategory } from '@/modules/breedFacts/breedFacts';
import { PetAvatar, categoryLabel } from '@/modules/breedFacts/FactSheet';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';

export interface BreedGuideScreenProps {
  breedLabel: string;
  imageUrl: string | null;
  facts: readonly BreedFact[];
  readCount: number;
  totalCount: number;
  isLoading: boolean;
  isError: boolean;
  onRetry: () => void;
  onOpenFact: (fact: BreedFact) => void;
  onClose: () => void;
  testID?: string;
}

export default function BreedGuideScreen({
  breedLabel,
  imageUrl,
  facts,
  readCount,
  totalCount,
  isLoading,
  isError,
  onRetry,
  onOpenFact,
  onClose,
  testID = 'breed-guide',
}: BreedGuideScreenProps) {
  const insets = useContext(SafeAreaInsetsContext) ?? { top: 0, bottom: 0 };
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState<FactCategory | null>(null);
  const categories = useMemo(() => presentCategories(facts), [facts]);
  const shown = useMemo(() => filterFacts(facts, query, category), [facts, query, category]);
  const progress = totalCount > 0 ? Math.min(1, readCount / totalCount) : 0;

  const closeRef = useRef(onClose);
  closeRef.current = onClose;
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      closeRef.current();
      return true;
    });
    return () => sub.remove();
  }, []);

  const header = (
    <View style={styles.headerBlock}>
      <View style={styles.hero}>
        <PetAvatar url={imageUrl} size={72} testID={`${testID}-pet`} />
        <View style={styles.heroText}>
          <Text style={styles.eyebrow}>{t('facts:guide.eyebrow')}</Text>
          <Text style={styles.breed} accessibilityRole="header" testID={`${testID}-breed`}>
            {breedLabel}
          </Text>
        </View>
      </View>

      <View style={styles.searchBox}>
        <Search color={palette.n400} size={18} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder={t('facts:guide.search')}
          placeholderTextColor={palette.n500}
          accessibilityLabel={t('facts:guide.searchA11y')}
          style={styles.searchInput}
          autoCorrect={false}
          autoCapitalize="none"
          returnKeyType="search"
          testID={`${testID}-search`}
        />
        {query.length > 0 && (
          <Pressable
            onPress={() => setQuery('')}
            accessibilityRole="button"
            accessibilityLabel={t('facts:guide.clearSearch')}
            hitSlop={8}
            testID={`${testID}-search-clear`}
          >
            <X color={palette.n400} size={18} />
          </Pressable>
        )}
      </View>

      {categories.length > 1 && (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
          {[null, ...categories].map((c) => {
            const selected = c === category;
            return (
              <Pressable
                key={c ?? 'all'}
                onPress={() => setCategory(c)}
                accessibilityRole="button"
                accessibilityState={{ selected }}
                style={({ pressed }) => [styles.chip, selected && styles.chipSelected, pressed && styles.pressed]}
                testID={`${testID}-category-${c ?? 'all'}`}
              >
                <Text style={[styles.chipText, selected && styles.chipTextSelected]}>
                  {c === null ? t('facts:guide.all') : categoryLabel(c)}
                </Text>
              </Pressable>
            );
          })}
        </ScrollView>
      )}

      {totalCount > 0 && (
        <View style={styles.progress} accessible testID={`${testID}-progress`}>
          <Text style={styles.progressText}>{t('facts:guide.progress', { read: readCount, total: totalCount })}</Text>
          <View style={styles.track}>
            <View style={[styles.fill, { width: `${Math.round(progress * 100)}%` }]} />
          </View>
        </View>
      )}
    </View>
  );

  return (
    <View style={[styles.container, { paddingTop: insets.top + 8 }]} testID={testID} accessibilityViewIsModal>
      <View style={styles.topRow}>
        <Pressable
          onPress={onClose}
          accessibilityRole="button"
          accessibilityLabel={t('facts:guide.back')}
          style={({ pressed }) => [styles.back, pressed && styles.pressed]}
          testID={`${testID}-close`}
        >
          <ChevronLeft color={palette.white} size={22} />
        </Pressable>
        <Text style={styles.topTitle}>{t('facts:guide.title')}</Text>
      </View>

      {isLoading && facts.length === 0 ? (
        <View style={styles.center} testID={`${testID}-loading`}>
          <ActivityIndicator color={palette.mint} />
          <Text style={styles.centerText}>{t('facts:guide.loading')}</Text>
        </View>
      ) : isError && facts.length === 0 ? (
        <View style={styles.center} testID={`${testID}-error`}>
          <WifiOff color={palette.n400} size={28} />
          <Text style={styles.centerText}>{t('facts:guide.loadFailed')}</Text>
          <Pressable accessibilityRole="button" onPress={onRetry} style={({ pressed }) => [styles.retry, pressed && styles.pressed]}>
            <Text style={styles.retryText}>{t('facts:guide.retry')}</Text>
          </Pressable>
        </View>
      ) : (
        <FlatList
          data={shown}
          keyExtractor={(f) => String(f.id)}
          ListHeaderComponent={header}
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={[styles.listContent, { paddingBottom: insets.bottom + 24 }]}
          ListEmptyComponent={
            <Text style={styles.empty} testID={`${testID}-empty`}>
              {t('facts:guide.noResults')}
            </Text>
          }
          renderItem={({ item }) => (
            <Pressable
              onPress={() => onOpenFact(item)}
              accessibilityRole="button"
              accessibilityLabel={item.read ? item.title : `${t('facts:guide.newA11y')}, ${item.title}`}
              accessibilityHint={t('facts:guide.openFactHint')}
              style={({ pressed }) => [styles.card, pressed && styles.pressed]}
              testID={`${testID}-fact-${item.id}`}
            >
              <View style={styles.cardTop}>
                <Text style={styles.cardCategory}>{categoryLabel(item.category)}</Text>
                {!item.read && (
                  <View style={styles.newBadge} testID={`${testID}-new-${item.id}`}>
                    <Text style={styles.newText}>{t('facts:guide.new')}</Text>
                  </View>
                )}
              </View>
              <Text style={styles.cardTitle}>{item.title}</Text>
            </Pressable>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { ...StyleSheet.absoluteFill, zIndex: 60, backgroundColor: dark.bg },
  topRow: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 12, paddingBottom: 4 },
  back: {
    width: MIN_TOUCH,
    height: MIN_TOUCH,
    borderRadius: MIN_TOUCH / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.08),
  },
  topTitle: { color: palette.n300, fontSize: 15, fontWeight: '700' },
  listContent: { paddingHorizontal: 16, gap: 10 },
  headerBlock: { gap: 14, paddingTop: 8, paddingBottom: 6 },
  hero: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  heroText: { flex: 1, minWidth: 0, gap: 2 },
  eyebrow: { color: palette.mint, fontSize: 12, fontWeight: '700', letterSpacing: 1.4 },
  breed: { color: palette.white, fontSize: 28, lineHeight: 34, fontFamily: fonts.display, letterSpacing: tightTracking(28) },
  searchBox: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    minHeight: MIN_TOUCH + 4,
    paddingHorizontal: 14,
    borderRadius: radius.input,
    backgroundColor: dark.surface,
    borderWidth: 1,
    borderColor: dark.glassBorder,
  },
  searchInput: { flex: 1, color: palette.white, fontSize: 16, paddingVertical: 10 },
  chips: { gap: 8, paddingRight: 8 },
  chip: {
    minHeight: 36,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.06),
    borderWidth: 1,
    borderColor: dark.glassBorder,
  },
  chipSelected: { backgroundColor: palette.mint, borderColor: palette.mint },
  chipText: { color: palette.n300, fontSize: 14, fontWeight: '700' },
  chipTextSelected: { color: palette.graphite },
  progress: { gap: 6 },
  progressText: { color: palette.n300, fontSize: 14, fontWeight: '700' },
  track: { height: 4, borderRadius: 2, backgroundColor: alpha(palette.white, 0.12), overflow: 'hidden' },
  fill: { height: 4, borderRadius: 2, backgroundColor: palette.mint },
  card: {
    gap: 6,
    padding: 16,
    borderRadius: radius.card,
    backgroundColor: dark.surface,
    borderWidth: 1,
    borderColor: dark.glassBorder,
  },
  cardTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  cardCategory: { color: palette.n400, fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.6 },
  newBadge: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: radius.pill, backgroundColor: alpha(palette.mint, 0.18) },
  newText: { color: palette.mint, fontSize: 12, fontWeight: '800' },
  cardTitle: { color: palette.white, fontSize: 17, lineHeight: 23, fontWeight: '700' },
  empty: { color: palette.n400, fontSize: 15, textAlign: 'center', paddingVertical: 24 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, paddingHorizontal: 32 },
  centerText: { color: palette.n300, fontSize: 15, fontWeight: '600', textAlign: 'center' },
  retry: { paddingHorizontal: 24, paddingVertical: 12, borderRadius: radius.button, backgroundColor: palette.mint },
  retryText: { color: palette.graphite, fontWeight: '700', fontSize: 15 },
  pressed: { opacity: 0.8 },
});
