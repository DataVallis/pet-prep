/**
 * WishesScreen — the parent »Želje« list (M6-W05, SHOP_SPEC §3, WISHES_API §4).
 *
 * - **Prošnje:** open wishes (item, pet, child, when) with »Kupi za <store price>« and »Ne zdaj«;
 *   when the server set `bundle_product_id` also »Vse tri za <price>« (the welcome bundle).
 * - **Kupi brez prošnje:** a surprise — the parent picks the pet first, then the item.
 * - **Kupljeno, še ni dodeljeno:** `unassigned_credits` → »Komu?« (pick the pet, redeem only).
 * Prices only from the store (RevenueCat product `priceString`, localized) — a product the
 * store doesn't have yet → »Nakup še ni na voljo« and the button is off. Purchases run only in
 * the parent app (password-protected = parental protection, PAYMENTS_SPEC); never in a child
 * session (`modules/purchases` checks the role). Outcomes are codes translated at render.
 * M6-W03 designs (David 2026-11-03): a wish shows the requested design's picture + name and is
 * bought as exactly that design; a surprise purchase, the welcome bundle (one design per item)
 * and »Komu?« ask for the design(s) first (`DesignChoice`). A server without designs → as before.
 */

import { petZoneOf } from '@/modules/familyZone/familyZone';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, BackHandler, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { ChevronLeft } from 'lucide-react-native';
import type { PurchasesStoreProduct } from 'react-native-purchases';

import { ApiError } from '@/api/client';
import { Text } from '@/components/ui/Text';
import { Card, PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { currentLanguageTag, t } from '@/i18n';
import { useDeclineWish, useParentWishes, useWishProducts } from '@/hooks/queries/useWishes';
import type { FamilyOverview } from '@/modules/family/family';
import { petLabel } from '@/modules/petName/petName';
import { speciesName } from '@/modules/species/species';
import { fonts, palette, radius, tightTracking } from '@/theme';
import WishIcon from '@/modules/wishes/WishIcon';
import DesignCard, { DesignThumb, designLabel } from '@/modules/wishes/DesignCard';
import { isGoodResult, useWishPurchase, wishResultText, type WishPurchaseResult } from '@/modules/wishes/useWishPurchase';
import { useAppStore } from '@/store/appStore';
import { retryPendingPurchases } from '@/modules/wishes/redeem';
import {
  defaultDesigns,
  designChoiceFor,
  designKeysFor,
  designOf,
  errorReason,
  isFeatureMissing,
  parentItemState,
  productIdsOf,
  showBundleOffer,
  variantFor,
  wishDesign,
  type ParentWish,
  type ParentWishPet,
  type WishItemKey,
} from '@/modules/wishes/wishes';

/**
 * M6-W03: a purchase / assignment waiting for the parent's design pick(s). `fixed` = picks that
 * are not the parent's to change (the design the child asked for); `picks` = the current choice.
 */
export interface PendingDesignChoice {
  /** Where the chooser shows (a wish / surprise row / credit) — one at a time. */
  anchor: string;
  action: 'buy' | 'assign';
  productId: string;
  petId: number;
  wishId: number | null;
  species: 'dog' | 'cat';
  keys: WishItemKey[];
  fixed: Partial<Record<WishItemKey, string>>;
  picks: Partial<Record<WishItemKey, string>>;
}

/** The chooser for a product on a pet, or null when nothing is left to choose (buy at once). */
export function designChoiceOf(
  pet: ParentWishPet,
  opts: { anchor: string; action: 'buy' | 'assign'; productId: string; wishId: number | null; fixed?: Partial<Record<WishItemKey, string>> },
): PendingDesignChoice | null {
  const fixed = opts.fixed ?? {};
  const keys = designKeysFor(opts.productId, pet.items);
  const open = keys.filter((k) => fixed[k] === undefined);
  const picks = { ...defaultDesigns(keys, pet.items), ...fixed };
  if (open.length === 0) return null;
  return { anchor: opts.anchor, action: opts.action, productId: opts.productId, petId: pet.pet_id, wishId: opts.wishId, species: pet.species, keys: open, fixed, picks };
}

type Message =
  | { kind: 'purchase'; result: WishPurchaseResult }
  | { kind: 'declined' }
  /** QA #416 m5: another parent bought / declined it first (404 / 422 `wish_not_open`). */
  | { kind: 'handledElsewhere' }
  | { kind: 'declineFailed' };

function messageText(m: Message): string {
  if (m.kind === 'purchase') return wishResultText(m.result);
  if (m.kind === 'handledElsewhere') return t('wishes:parent.result.handledElsewhere');
  return m.kind === 'declined' ? t('wishes:parent.result.declined') : t('wishes:parent.result.declineFailed');
}

/** QA #416 m5: a decline that lost the race with another parent (the wish is no longer open). */
export function wishHandledElsewhere(error: unknown): boolean {
  if (!(error instanceof ApiError)) return false;
  const reason = errorReason(error);
  return error.status === 404 || (error.status === 422 && (reason === 'wish_not_open' || reason === null));
}

/** "pon., 27. okt., 9:00" in the family's zone; '' when the date is unusable. */
export function formatWishTime(iso: string | null, timeZone: string): string {
  if (iso === null) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  try {
    return new Intl.DateTimeFormat(currentLanguageTag(), { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', timeZone }).format(date);
  } catch {
    return date.toISOString();
  }
}

interface WishesScreenProps {
  family: FamilyOverview | null;
  onBack: () => void;
}

export default function WishesScreen({ family, onBack }: WishesScreenProps) {
  useTranslation();
  const backRef = useRef(onBack);
  backRef.current = onBack;
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      backRef.current();
      return true;
    });
    return () => sub.remove();
  }, []);

  const wishes = useParentWishes();
  // QA #416 M2: purchases charged earlier but not yet on a pet (late webhook, app closed,
  // offline) are redeemed again for their own pet / wish whenever this screen opens.
  const parentId = useAppStore((s) => (s.user?.role === 'parent' ? s.user.id : null));
  const refetchWishes = wishes.refetch;
  useEffect(() => {
    if (parentId === null) return;
    let alive = true;
    void retryPendingPurchases(parentId)
      .then((settled) => {
        if (alive && settled > 0) void refetchWishes();
      })
      .catch(() => undefined);
    return () => {
      alive = false;
    };
  }, [parentId, refetchWishes]);
  const data = wishes.data ?? null;
  const productIds = useMemo(() => productIdsOf(data), [data]);
  const products = useWishProducts(productIds);
  const purchase = useWishPurchase();
  const decline = useDeclineWish();
  const [message, setMessage] = useState<Message | null>(null);
  const [surprisePet, setSurprisePet] = useState<number | null>(null);
  const [assignFor, setAssignFor] = useState<string | null>(null);
  // M6-W03: the design pick(s) a purchase / assignment waits for.
  const [choice, setChoice] = useState<PendingDesignChoice | null>(null);
  const busy = purchase.isPending || decline.isPending;
  const timezone = family?.timezone ?? 'Europe/Ljubljana';

  const productOf = (id: string): PurchasesStoreProduct | null => products.data?.find((p) => p.identifier === id) ?? null;
  const storeHas = (id: string) => productOf(id) !== null;

  const petName = (petId: number, name: string | null, species: 'dog' | 'cat'): string => {
    const familyPet = family?.pets.find((p) => p.id === petId);
    if (familyPet) return petLabel(familyPet);
    return name ?? speciesName(species);
  };

  const petOf = (petId: number): ParentWishPet | null => data?.pets.find((p) => p.pet_id === petId) ?? null;

  const buyNow = (productId: string, petId: number, wishId: number | null, species: 'dog' | 'cat', picks: Partial<Record<WishItemKey, string>>) => {
    const product = productOf(productId);
    if (!product || busy) return;
    setMessage(null);
    purchase.mutate(
      { product, productId, petId, wishId, species, choice: designChoiceFor(productId, picks) },
      {
        onSuccess: (result) => {
          setMessage({ kind: 'purchase', result });
          setChoice(null);
        },
      },
    );
  };

  /** Buy — first the design(s) when the parent still has to choose (surprise, bundle). */
  const buy = (anchor: string, productId: string, petId: number, wishId: number | null, species: 'dog' | 'cat', fixed: Partial<Record<WishItemKey, string>> = {}) => {
    if (busy || !productOf(productId)) return;
    const pet = petOf(petId);
    const pending = pet === null ? null : designChoiceOf(pet, { anchor, action: 'buy', productId, wishId, fixed });
    if (pending !== null) {
      setMessage(null);
      setChoice(pending);
      return;
    }
    buyNow(productId, petId, wishId, species, fixed);
  };

  const assignNow = (productId: string, petId: number, species: 'dog' | 'cat', picks: Partial<Record<WishItemKey, string>>) => {
    if (busy) return;
    setMessage(null);
    purchase.mutate(
      { product: null, productId, petId, wishId: null, species, choice: designChoiceFor(productId, picks) },
      {
        onSuccess: (result) => {
          setMessage({ kind: 'purchase', result });
          setAssignFor(null);
          setChoice(null);
        },
      },
    );
  };

  const assign = (productId: string, pet: ParentWishPet) => {
    if (busy) return;
    const pending = designChoiceOf(pet, { anchor: `credit-${productId}`, action: 'assign', productId, wishId: null });
    if (pending !== null) {
      setMessage(null);
      setChoice(pending);
      return;
    }
    assignNow(productId, pet.pet_id, pet.species, {});
  };

  /** The design pick(s) of a pending purchase / assignment, shown at its anchor. */
  const renderChoice = (anchor: string) => {
    if (choice === null || choice.anchor !== anchor) return null;
    const pet = petOf(choice.petId);
    if (pet === null) return null;
    const petText = petName(pet.pet_id, pet.pet_name, pet.species);
    const confirm = () => (choice.action === 'buy' ? buyNow(choice.productId, choice.petId, choice.wishId, choice.species, choice.picks) : assignNow(choice.productId, choice.petId, choice.species, choice.picks));
    return (
      <View style={styles.choice} testID={`design-choice-${anchor}`}>
        {choice.keys.map((key) => {
          const item = pet.items.find((i) => i.key === key);
          if (!item) return null;
          return (
            <View key={key} style={styles.choiceGroup}>
              <Text style={[styles.body, styles.bold]}>{t('wishes:designs.parentPick', { item: t(`wishes:items.${item.variant}`) })}</Text>
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.choiceRow}>
                {item.designs.map((d) => (
                  <DesignCard
                    key={d.key}
                    design={d}
                    variant={item.variant}
                    tone="light"
                    size={104}
                    selected={choice.picks[key] === d.key}
                    onPress={() => setChoice({ ...choice, picks: { ...choice.picks, [key]: d.key } })}
                    testID={`design-choice-${anchor}-${key}-${d.key}`}
                  />
                ))}
              </ScrollView>
            </View>
          );
        })}
        {choice.action === 'buy' ? (
          priceButton({
            productId: choice.productId,
            label: (price) => t('wishes:parent.buy', { price }),
            a11y: (price) => t('wishes:designs.buyA11y', { pet: petText, price }),
            onPress: confirm,
            testID: `design-choice-confirm-${anchor}`,
          })
        ) : (
          <Pressable
            style={({ pressed }) => [styles.primaryButton, busy && styles.disabled, pressed && styles.pressed]}
            disabled={busy}
            onPress={confirm}
            accessibilityRole="button"
            testID={`design-choice-confirm-${anchor}`}
          >
            <Text style={styles.primaryText}>{t('wishes:parent.assignTo', { pet: petText })}</Text>
          </Pressable>
        )}
        <Pressable style={({ pressed }) => [styles.textButton, pressed && styles.pressed]} onPress={() => setChoice(null)} accessibilityRole="button" testID={`design-choice-cancel-${anchor}`}>
          <Text style={styles.textButtonText}>{t('wishes:designs.cancel')}</Text>
        </Pressable>
      </View>
    );
  };

  const declineWish = (wish: ParentWish) => {
    if (busy) return;
    setMessage(null);
    decline.mutate(wish.id, {
      onSuccess: () => setMessage({ kind: 'declined' }),
      onError: (error) => {
        if (wishHandledElsewhere(error)) {
          setMessage({ kind: 'handledElsewhere' });
          void wishes.refetch();
        } else setMessage({ kind: 'declineFailed' });
      },
    });
  };

  const priceButton = (opts: { productId: string; label: (price: string) => string; a11y: (price: string) => string; onPress: () => void; testID: string; secondary?: boolean }) => {
    const product = productOf(opts.productId);
    const loading = products.isPending && products.fetchStatus !== 'idle';
    const disabled = busy || product === null;
    const text = product ? opts.label(product.priceString) : loading ? t('wishes:parent.priceLoading') : t('wishes:parent.notAvailable');
    return (
      <Pressable
        style={({ pressed }) => [opts.secondary ? styles.secondaryButton : styles.primaryButton, disabled && styles.disabled, pressed && styles.pressed]}
        disabled={disabled}
        onPress={opts.onPress}
        accessibilityRole="button"
        accessibilityLabel={product ? opts.a11y(product.priceString) : text}
        accessibilityState={{ disabled }}
        testID={opts.testID}
      >
        <Text style={opts.secondary ? styles.secondaryText : styles.primaryText}>{purchase.isPending ? t('wishes:parent.busy') : text}</Text>
      </Pressable>
    );
  };

  const surprise = data?.pets.find((p) => p.pet_id === surprisePet) ?? null;

  return (
    <View style={styles.root} testID="wishes-screen">
      <View style={styles.header}>
        <Pressable onPress={onBack} hitSlop={8} accessibilityRole="button" accessibilityLabel={t('wishes:parent.back')} testID="wishes-back">
          <ChevronLeft color={C.accent} size={26} />
        </Pressable>
        <Text style={styles.title} accessibilityRole="header">
          {t('wishes:parent.title')}
        </Text>
      </View>

      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.intro}>{t('wishes:parent.intro')}</Text>

        {message && (
          <View
            style={[styles.message, message.kind === 'purchase' && isGoodResult(message.result) ? styles.messageOk : message.kind === 'declined' ? styles.messageOk : styles.messageWarn]}
            testID="wishes-message"
            accessibilityLiveRegion="polite"
          >
            <Text style={styles.body}>{messageText(message)}</Text>
          </View>
        )}

        {wishes.isPending ? (
          <View style={styles.center}>
            <ActivityIndicator color={C.accent} />
            <Text style={styles.muted}>{t('wishes:parent.loading')}</Text>
          </View>
        ) : wishes.isError || data === null ? (
          <Card testID="wishes-load-error">
            <Text style={styles.body}>
              {isFeatureMissing(wishes.error) ? t('wishes:parent.unavailable') : wishes.error instanceof ApiError ? t('wishes:parent.loadError') : t('wishes:parent.offline')}
            </Text>
            {!isFeatureMissing(wishes.error) && (
              <Pressable style={styles.secondaryButton} onPress={() => void wishes.refetch()} accessibilityRole="button">
                <Text style={styles.secondaryText}>{t('wishes:parent.retry')}</Text>
              </Pressable>
            )}
          </Card>
        ) : (
          <>
            {data.unassigned_credits.length > 0 && (
              <>
                <Text style={styles.sectionTitle}>{t('wishes:parent.creditsTitle')}</Text>
                {data.unassigned_credits.map((credit) => {
                  const key = credit.item_key;
                  const variant = key === null || key === 'welcome_bundle' ? 'welcome_bundle' : variantFor(key, null, 'dog');
                  const name = key === null ? t('wishes:parent.unknownItem') : t(`wishes:items.${variant}`);
                  return (
                    <Card key={credit.product_id} testID={`wishes-credit-${credit.product_id}`}>
                      <View style={styles.itemRow}>
                        <WishIcon variant={variant} tone="light" size={44} />
                        <Text style={[styles.body, styles.bold]}>{t('wishes:parent.creditLine', { item: name, count: credit.count })}</Text>
                      </View>
                      {renderChoice(`credit-${credit.product_id}`)}
                      {choice?.anchor === `credit-${credit.product_id}` ? null : assignFor === credit.product_id ? (
                        data.pets.map((pet) => (
                          <Pressable
                            key={pet.pet_id}
                            style={({ pressed }) => [styles.secondaryButton, busy && styles.disabled, pressed && styles.pressed]}
                            disabled={busy}
                            onPress={() => assign(credit.product_id, pet)}
                            accessibilityRole="button"
                            testID={`wishes-assign-${credit.product_id}-${pet.pet_id}`}
                          >
                            <Text style={styles.secondaryText}>{t('wishes:parent.assignTo', { pet: petName(pet.pet_id, pet.pet_name, pet.species) })}</Text>
                          </Pressable>
                        ))
                      ) : (
                        <Pressable
                          style={({ pressed }) => [styles.primaryButton, busy && styles.disabled, pressed && styles.pressed]}
                          disabled={busy}
                          onPress={() => setAssignFor(credit.product_id)}
                          accessibilityRole="button"
                          accessibilityLabel={t('wishes:parent.assignA11y', { item: name })}
                          testID={`wishes-assign-${credit.product_id}`}
                        >
                          <Text style={styles.primaryText}>{t('wishes:parent.assign')}</Text>
                        </Pressable>
                      )}
                    </Card>
                  );
                })}
              </>
            )}

            <Text style={styles.sectionTitle}>{t('wishes:parent.openTitle')}</Text>
            {data.wishes.length === 0 ? (
              <Card testID="wishes-none">
                <Text style={styles.body}>{t('wishes:parent.noOpen')}</Text>
              </Card>
            ) : (
              data.wishes.map((wish) => {
                const name = t(`wishes:items.${wish.variant}`);
                const pet = petName(wish.pet_id, wish.pet_name, wish.species);
                // M6-W03: the design the child asked for — bought as exactly that one.
                const design = wishDesign(wish, data.pets);
                const fixed: Partial<Record<WishItemKey, string>> = wish.design !== null ? { [wish.item_key]: wish.design } : {};
                return (
                  <Card key={wish.id} testID={`wish-${wish.id}`}>
                    <View style={styles.itemRow}>
                      <DesignThumb design={design} variant={wish.variant} tone="light" size={design !== null ? 72 : 48} testID={`wish-design-image-${wish.id}`} />
                      <View style={styles.flex}>
                        <Text style={[styles.body, styles.bold]}>{name}</Text>
                        {design !== null && (
                          <Text style={styles.body} testID={`wish-design-name-${wish.id}`}>
                            {t('wishes:designs.requested', { design: designLabel(design, wish.variant) })}
                          </Text>
                        )}
                        <Text style={styles.muted}>{t('wishes:parent.wishMeta', { child: wish.child_name, pet, when: formatWishTime(wish.created_at, petZoneOf(family?.pets.find((fp) => fp.id === wish.pet_id), timezone) ?? timezone) })}</Text>
                      </View>
                    </View>
                    {priceButton({
                      productId: wish.product_id,
                      label: (price) => t('wishes:parent.buy', { price }),
                      a11y: (price) => t('wishes:parent.buyA11y', { item: name, pet, price }),
                      onPress: () => buy(`wish-${wish.id}`, wish.product_id, wish.pet_id, wish.id, wish.species, fixed),
                      testID: `wish-buy-${wish.id}`,
                    })}
                    {showBundleOffer(wish, storeHas) &&
                      wish.bundle_product_id !== null &&
                      priceButton({
                        productId: wish.bundle_product_id,
                        label: (price) => t('wishes:parent.bundle', { price }),
                        a11y: (price) => t('wishes:parent.bundleA11y', { pet, price }),
                        onPress: () => buy(`wish-${wish.id}`, wish.bundle_product_id ?? '', wish.pet_id, wish.id, wish.species, fixed),
                        testID: `wish-bundle-${wish.id}`,
                        secondary: true,
                      })}
                    {renderChoice(`wish-${wish.id}`)}
                    <Pressable
                      style={({ pressed }) => [styles.textButton, busy && styles.disabled, pressed && styles.pressed]}
                      disabled={busy}
                      onPress={() => declineWish(wish)}
                      accessibilityRole="button"
                      accessibilityLabel={t('wishes:parent.declineA11y', { item: name, pet })}
                      testID={`wish-decline-${wish.id}`}
                    >
                      <Text style={styles.textButtonText}>{t('wishes:parent.decline')}</Text>
                    </Pressable>
                  </Card>
                );
              })
            )}

            {data.pets.length > 0 && (
              <>
                <Text style={styles.sectionTitle}>{t('wishes:parent.surpriseTitle')}</Text>
                <Text style={styles.muted}>{t('wishes:parent.surpriseIntro')}</Text>
                <View style={styles.petPicker}>
                  {data.pets.map((pet) => {
                    const label = petName(pet.pet_id, pet.pet_name, pet.species);
                    const selected = surprisePet === pet.pet_id;
                    return (
                      <Pressable
                        key={pet.pet_id}
                        style={({ pressed }) => [styles.petChip, selected && styles.petChipSelected, pressed && styles.pressed]}
                        onPress={() => setSurprisePet(selected ? null : pet.pet_id)}
                        accessibilityRole="button"
                        accessibilityLabel={t('wishes:parent.pickPetA11y', { pet: label })}
                        accessibilityState={{ selected }}
                        testID={`surprise-pet-${pet.pet_id}`}
                      >
                        <Text style={styles.petChipText}>{label}</Text>
                      </Pressable>
                    );
                  })}
                </View>
                {surprise && (
                  <Card testID={`surprise-items-${surprise.pet_id}`}>
                    {surprise.bundle_eligible && surprise.bundle_product_id !== null && (
                      <View style={styles.surpriseRow}>
                        <View style={styles.itemRow}>
                          <WishIcon variant="welcome_bundle" tone="light" size={44} />
                          <Text style={[styles.body, styles.bold]}>{t('wishes:items.welcome_bundle')}</Text>
                        </View>
                        {priceButton({
                          productId: surprise.bundle_product_id,
                          label: (price) => t('wishes:parent.bundle', { price }),
                          a11y: (price) => t('wishes:parent.bundleA11y', { pet: petName(surprise.pet_id, surprise.pet_name, surprise.species), price }),
                          onPress: () => buy(`surprise-${surprise.pet_id}-welcome_bundle`, surprise.bundle_product_id ?? '', surprise.pet_id, null, surprise.species),
                          testID: `surprise-buy-${surprise.pet_id}-welcome_bundle`,
                          secondary: true,
                        })}
                        {renderChoice(`surprise-${surprise.pet_id}-welcome_bundle`)}
                      </View>
                    )}
                    {surprise.items.map((item) => {
                      const name = t(`wishes:items.${item.variant}`);
                      const state = parentItemState(item);
                      const pet = petName(surprise.pet_id, surprise.pet_name, surprise.species);
                      // M6-W03: an owned item shows its design.
                      const owned = item.owned ? designOf(item.designs, item.design) : null;
                      return (
                        <View key={item.key} style={styles.surpriseRow}>
                          <View style={styles.itemRow}>
                            <DesignThumb design={owned} variant={item.variant} tone="light" size={44} />
                            <View style={styles.flex}>
                              <Text style={[styles.body, styles.bold]}>{owned !== null ? designLabel(owned, item.variant) : name}</Text>
                              {item.treats_left !== null && <Text style={styles.muted}>{t('wishes:parent.treatsLeft', { count: item.treats_left })}</Text>}
                            </View>
                          </View>
                          {state === 'buy' ? (
                            priceButton({
                              productId: item.product_id,
                              label: (price) => t('wishes:parent.buy', { price }),
                              a11y: (price) => t('wishes:parent.buyA11y', { item: name, pet, price }),
                              onPress: () => buy(`surprise-${surprise.pet_id}-${item.key}`, item.product_id, surprise.pet_id, null, surprise.species),
                              testID: `surprise-buy-${surprise.pet_id}-${item.key}`,
                              secondary: true,
                            })
                          ) : (
                            <Text style={styles.muted} testID={`surprise-state-${surprise.pet_id}-${item.key}`}>
                              {state === 'owned' ? t('wishes:parent.owned') : t('wishes:parent.onTheWay')}
                            </Text>
                          )}
                          {renderChoice(`surprise-${surprise.pet_id}-${item.key}`)}
                        </View>
                      );
                    })}
                  </Card>
                )}
              </>
            )}
            <Text style={styles.muted}>{t('wishes:parent.honest')}</Text>
          </>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: C.bg },
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
  content: { padding: 16, gap: 12, paddingBottom: 40 },
  intro: { fontSize: 15, lineHeight: 21, color: C.text },
  sectionTitle: { fontSize: 13, fontWeight: '700', color: C.muted, textTransform: 'uppercase', letterSpacing: 0.4, marginTop: 6 },
  body: { fontSize: 14, lineHeight: 20, color: C.text },
  bold: { fontWeight: '700' },
  muted: { fontSize: 12, lineHeight: 17, color: C.muted },
  flex: { flex: 1, gap: 2 },
  itemRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  surpriseRow: { gap: 8, paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: C.divider },
  message: { padding: 12, borderRadius: 12, borderWidth: 1 },
  messageOk: { backgroundColor: palette.okSoft, borderColor: palette.okBorder },
  messageWarn: { backgroundColor: palette.warnSoft, borderColor: palette.warnBorder },
  center: { alignItems: 'center', gap: 8, paddingVertical: 12 },
  primaryButton: { marginTop: 12, minHeight: 48, borderRadius: 14, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12 },
  primaryText: { fontSize: 15, fontWeight: '700', color: palette.white },
  secondaryButton: { marginTop: 8, minHeight: 46, borderRadius: 14, borderWidth: 1, borderColor: C.border, backgroundColor: C.card, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12 },
  secondaryText: { fontSize: 15, fontWeight: '700', color: C.accent },
  textButton: { marginTop: 4, minHeight: 44, alignItems: 'center', justifyContent: 'center' },
  textButtonText: { fontSize: 14, fontWeight: '600', color: C.muted },
  petPicker: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  petChip: { minHeight: 44, paddingHorizontal: 14, borderRadius: radius.pill, borderWidth: 1, borderColor: C.border, backgroundColor: C.card, justifyContent: 'center' },
  petChipSelected: { backgroundColor: C.accentSoft, borderColor: C.accentBorder },
  petChipText: { fontSize: 14, fontWeight: '600', color: C.text },
  choice: { marginTop: 10, gap: 10, paddingTop: 10, borderTopWidth: 1, borderTopColor: C.divider },
  choiceGroup: { gap: 6 },
  choiceRow: { gap: 10, paddingVertical: 2 },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
