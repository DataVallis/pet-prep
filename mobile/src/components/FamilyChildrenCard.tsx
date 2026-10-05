/**
 * The family's children for the parent (M2-01a slice, M2-02): nickname, pet,
 * signed-in devices, and per child "Nova koda za prijavo" / "Odjavi vse naprave" /
 * "Izbriši profil" (M2-08: consequences, password + "IZBRIŠI"). Signing out and
 * deleting ask for confirmation inline (no native alert). "Dodaj otroka" is
 * always offered (several children per family; backend limit 10).
 * Light parent theme (ADR-007).
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { KeyRound, LogOut, Smartphone, Trash2, UserPlus, Users } from 'lucide-react-native';

import DeletionConfirmForm from '@/components/parent/DeletionConfirmForm';
import { useDeleteChild, useRevokeChildDevices } from '@/hooks/queries/useFamilyMutations';
import { childDeletionImpact, classifyDeletionError, type DeletionErrorKind } from '@/modules/account/account';
import {
  breedLabel,
  classifyRevokeError,
  devicesLabel,
  type FamilyChild,
  type FamilyOverview,
  type RevokeErrorKind,
} from '@/modules/family/family';

/** All user-visible strings (extract to i18n with M1-18). */
export const FAMILY_STRINGS = {
  title: 'Otroci',
  addChild: 'Dodaj otroka',
  noPet: 'Še brez psa',
  awaitingContract: 'čaka na podpis pogodbe',
  gameOver: 'igra končana',
  legacyEmail: 'stari račun z e-pošto',
  newLoginPin: 'Nova koda za prijavo',
  firstPin: 'Ustvari kodo',
  revokeAll: 'Odjavi vse naprave',
  confirmRevoke: (name: string) => `${name}: odjava z vseh naprav? Za ponovno prijavo bo potrebna nova koda.`,
  cancel: 'Prekliči',
  confirm: 'Odjavi',
  revoked: (devices: string) => `Odjavljeno (${devices}).`,
  errors: {
    not_found: 'Tega otroka ni več v vaši družini.',
    offline: 'Ni povezave s strežnikom. Poskusite znova.',
    server: 'Odjava ni uspela. Poskusite znova.',
  } satisfies Record<RevokeErrorKind, string>,
  deleteProfile: 'Izbriši profil',
  deleteSubmit: 'Izbriši profil za vedno',
  deleteConsequences: (name: string, deletedPets: number, keptPets: number): string[] => [
    `Izbriše se profil »${name}«: vse prijave na napravah, kode in otrokova pogodba (podpis).`,
    ...(deletedPets > 0
      ? [`${deletedPets === 1 ? 'Pes, za katerega skrbi sam, se izbriše' : `Psi, za katere skrbi sam (${deletedPets}), se izbrišejo`} — z dnevnikom, ocenami, slikami in videi.`]
      : []),
    ...(keptPets > 0
      ? [`${keptPets === 1 ? 'Skupni pes ostane' : `Skupni psi (${keptPets}) ostanejo`} drugim otrokom; otrokova pretekla skrb ostane v dnevniku brez imena.`]
      : []),
  ],
  deleted: (name: string) => `Profil »${name}« je izbrisan.`,
  deleteErrors: {
    invalid_password: 'Geslo ni pravilno.',
    protected: 'Tega profila ni mogoče izbrisati.',
    not_found: 'Tega otroka ni več v vaši družini.',
    throttled: 'Preveč poskusov. Poskusite znova čez 15 minut.',
    invalid: 'Vpišite geslo in potrdite brisanje.',
    offline: 'Ni povezave s strežnikom. Nič ni bilo izbrisano.',
    server: 'Brisanje ni uspelo. Nič ni bilo izbrisano — poskusite znova.',
  } satisfies Record<DeletionErrorKind, string>,
} as const;

const S = FAMILY_STRINGS;

interface FamilyChildrenCardProps {
  family: FamilyOverview;
  onAddChild: () => void;
  /** Open the PIN flow for this child (pet choice if unpaired, re-login PIN if paired). */
  onChildPin: (child: FamilyChild) => void;
}

export default function FamilyChildrenCard({ family, onAddChild, onChildPin }: FamilyChildrenCardProps) {
  const revoke = useRevokeChildDevices();
  const removeChild = useDeleteChild();
  const [confirmingId, setConfirmingId] = useState<number | null>(null);
  const [result, setResult] = useState<{ childId: number; text: string; isError: boolean } | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deletedNotice, setDeletedNotice] = useState<string | null>(null);

  const openDelete = (child: FamilyChild) => {
    setResult(null);
    setConfirmingId(null);
    setDeleteError(null);
    setDeletedNotice(null);
    setDeletingId(child.id);
  };

  const confirmDelete = (child: FamilyChild, password: string) => {
    setDeleteError(null);
    removeChild.mutate(
      { childId: child.id, password },
      {
        onSuccess: () => {
          setDeletingId(null);
          setDeletedNotice(S.deleted(child.name));
        },
        onError: (err) => setDeleteError(S.deleteErrors[classifyDeletionError(err)]),
      },
    );
  };

  const petLine = (child: FamilyChild): string => {
    if (child.pet_id === null) return S.noPet;
    const pet = family.pets.find((p) => p.id === child.pet_id);
    if (!pet) return S.noPet;
    const label = breedLabel(pet.breed_type);
    if (pet.is_game_over) return `${label} · ${S.gameOver}`;
    if (!child.contract_signed) return `${label} · ${S.awaitingContract}`;
    return label;
  };

  const confirmRevoke = (child: FamilyChild) => {
    setResult(null);
    revoke.mutate(child.id, {
      onSuccess: (res) => {
        setConfirmingId(null);
        setResult({ childId: child.id, text: S.revoked(devicesLabel(res.revoked_tokens)), isError: false });
      },
      onError: (err) => {
        setResult({ childId: child.id, text: S.errors[classifyRevokeError(err)], isError: true });
      },
    });
  };

  return (
    <View style={styles.card} testID="family-children">
      <View style={styles.titleRow}>
        <Users color="#4f46e5" size={20} />
        <Text style={styles.title}>{S.title}</Text>
      </View>

      {deletedNotice && (
        <Text style={styles.result} testID="child-deleted-notice" accessibilityLiveRegion="polite">
          {deletedNotice}
        </Text>
      )}

      {family.children.map((child) => {
        const isConfirming = confirmingId === child.id;
        const isDeleting = deletingId === child.id;
        const impact = isDeleting ? childDeletionImpact(child, family) : null;
        const isRevoking = revoke.isPending && revoke.variables === child.id;
        return (
          <View key={child.id} style={styles.childRow} testID={`family-child-${child.id}`}>
            <View style={styles.childHeader}>
              <View style={styles.avatar}>
                <Text style={styles.avatarText}>{child.name.slice(0, 1).toUpperCase()}</Text>
              </View>
              <View style={styles.flex}>
                <Text style={styles.childName}>{child.name}</Text>
                <Text style={styles.childMeta}>{petLine(child)}</Text>
                <View style={styles.devicesRow}>
                  <Smartphone color="#64748b" size={13} />
                  <Text style={styles.childMeta} testID={`family-child-devices-${child.id}`}>
                    {devicesLabel(child.devices)}
                    {child.login === 'email' ? ` · ${S.legacyEmail}` : ''}
                  </Text>
                </View>
              </View>
            </View>

            {isDeleting && impact ? (
              <DeletionConfirmForm
                consequences={S.deleteConsequences(child.name, impact.deletedPets, impact.keptPets)}
                submitLabel={S.deleteSubmit}
                pending={removeChild.isPending}
                error={deleteError}
                onCancel={() => {
                  setDeletingId(null);
                  setDeleteError(null);
                }}
                onSubmit={(password) => confirmDelete(child, password)}
                testID={`child-delete-form-${child.id}`}
              />
            ) : isConfirming ? (
              <View style={styles.confirmBox} testID={`revoke-confirm-${child.id}`}>
                <Text style={styles.confirmText}>{S.confirmRevoke(child.name)}</Text>
                <View style={styles.actionsRow}>
                  <Pressable
                    style={({ pressed }) => [styles.actionButton, pressed && styles.pressed]}
                    onPress={() => setConfirmingId(null)}
                    disabled={isRevoking}
                    accessibilityRole="button"
                  >
                    <Text style={styles.actionText}>{S.cancel}</Text>
                  </Pressable>
                  <Pressable
                    style={({ pressed }) => [styles.actionButton, styles.dangerButton, pressed && styles.pressed]}
                    onPress={() => confirmRevoke(child)}
                    disabled={isRevoking}
                    accessibilityRole="button"
                    testID={`revoke-confirm-button-${child.id}`}
                  >
                    {isRevoking ? (
                      <ActivityIndicator color="#ffffff" />
                    ) : (
                      <Text style={[styles.actionText, styles.dangerText]}>{S.confirm}</Text>
                    )}
                  </Pressable>
                </View>
              </View>
            ) : (
              <View style={styles.actionsRow}>
                <Pressable
                  style={({ pressed }) => [styles.actionButton, pressed && styles.pressed]}
                  onPress={() => onChildPin(child)}
                  accessibilityRole="button"
                  testID={`child-pin-${child.id}`}
                >
                  <KeyRound color="#4f46e5" size={15} />
                  <Text style={styles.actionText}>{child.pet_id === null ? S.firstPin : S.newLoginPin}</Text>
                </Pressable>
                <Pressable
                  style={({ pressed }) => [styles.actionButton, child.devices === 0 && styles.disabled, pressed && styles.pressed]}
                  onPress={() => {
                    setResult(null);
                    setDeletingId(null);
                    setConfirmingId(child.id);
                  }}
                  disabled={child.devices === 0}
                  accessibilityRole="button"
                  accessibilityState={{ disabled: child.devices === 0 }}
                  testID={`child-revoke-${child.id}`}
                >
                  <LogOut color="#e11d48" size={15} />
                  <Text style={[styles.actionText, styles.revokeText]}>{S.revokeAll}</Text>
                </Pressable>
                <Pressable
                  style={({ pressed }) => [styles.actionButton, pressed && styles.pressed]}
                  onPress={() => openDelete(child)}
                  accessibilityRole="button"
                  testID={`child-delete-${child.id}`}
                >
                  <Trash2 color="#e11d48" size={15} />
                  <Text style={[styles.actionText, styles.revokeText]}>{S.deleteProfile}</Text>
                </Pressable>
              </View>
            )}

            {result?.childId === child.id && (
              <Text style={[styles.result, result.isError && styles.resultError]} testID={`revoke-result-${child.id}`}>
                {result.text}
              </Text>
            )}
          </View>
        );
      })}

      <Pressable
        style={({ pressed }) => [styles.addButton, pressed && styles.pressed]}
        onPress={onAddChild}
        accessibilityRole="button"
        accessibilityLabel={S.addChild}
        testID="family-add-child"
      >
        <UserPlus color="#4f46e5" size={18} />
        <Text style={styles.addText}>{S.addChild}</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 20,
    padding: 16,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    gap: 12,
  },
  flex: { flex: 1 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  title: { fontSize: 17, fontWeight: '800', color: '#0f172a' },
  childRow: { paddingTop: 12, borderTopWidth: 1, borderTopColor: '#f1f5f9', gap: 10 },
  childHeader: { flexDirection: 'row', gap: 12, alignItems: 'center' },
  avatar: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#eef2ff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { fontSize: 17, fontWeight: '800', color: '#4f46e5' },
  childName: { fontSize: 16, fontWeight: '700', color: '#0f172a' },
  childMeta: { fontSize: 13, color: '#64748b' },
  devicesRow: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 2 },
  actionsRow: { flexDirection: 'row', gap: 8, flexWrap: 'wrap' },
  actionButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    minHeight: 40,
    paddingHorizontal: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    backgroundColor: '#f8fafc',
  },
  actionText: { fontSize: 13, fontWeight: '700', color: '#4f46e5' },
  revokeText: { color: '#e11d48' },
  dangerButton: { backgroundColor: '#e11d48', borderColor: '#e11d48', minWidth: 88 },
  dangerText: { color: '#ffffff' },
  disabled: { opacity: 0.45 },
  confirmBox: {
    gap: 10,
    padding: 12,
    borderRadius: 14,
    backgroundColor: '#fff1f2',
    borderWidth: 1,
    borderColor: '#fecdd3',
  },
  confirmText: { fontSize: 13, lineHeight: 18, color: '#9f1239' },
  result: { fontSize: 13, color: '#047857' },
  resultError: { color: '#be123c' },
  addButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    height: 46,
    borderRadius: 14,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: '#a5b4fc',
    backgroundColor: '#eef2ff',
  },
  addText: { fontSize: 15, fontWeight: '700', color: '#4f46e5' },
  pressed: { opacity: 0.85 },
});
