/**
 * ContractScreen — "Pogodba o odgovornosti" (M1-07b). Shown to a signed-in child
 * whose pet waits for this child's contract: right after the PIN login (M2-02) and
 * after a session restore. The signature goes to `POST /api/child/contract`; the
 * server's state (pet born / unlocked) lands in the session and AppNavigator
 * switches to the HUD. Child UI = dark glass (ADR-007).
 */

import { useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Eraser, RotateCcw, ScrollText } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import type { ChildPetState } from '@/api/client';
import SignaturePad from '@/components/SignaturePad';
import { writeChildState } from '@/hooks/queries/useChildPet';
import { petFromChildState } from '@/modules/contract/petFromChildState';
import { hasSignature } from '@/modules/contract/signaturePath';
import { submitSignature } from '@/modules/contract/signContract';
import { logout } from '@/modules/session/logout';
import { lockStateFromPet, useAppStore } from '@/store/appStore';

const CONTRACT_TEXT = `Zavezujem se, da bom vsak dan odgovorno skrbel za svojega virtualnega ljubljenčka:

• Hranil ga bom, ko bo lačen.
• Vsak dan mu bom zagotovil svežo vodo.
• Vodil ga bom na sprehode za gibanje in zdravje.
• Redno bom čistil za njim in skrbel za higieno.
• Odzval se bom na opozorila, preden pride do bolezni ali virtualnega zavetišča.

Ali sprejemaš to odgovornost?`;

/** User-visible strings of the contract step (i18n with M1-18). */
export const CONTRACT_STRINGS = {
  title: 'Pogodba o odgovornosti',
  padHint: 'Podpiši se s prstom v okvir spodaj',
  padLabel: 'Polje za podpis',
  clear: 'Pobriši',
  accept: 'Sprejmem odgovornost',
  retry: 'Poskusi znova',
  logout: 'Odjava',
  invalid: 'Podpis ni veljaven, poskusi znova',
  network: 'Povezava s strežnikom ni uspela. Preveri internet in poskusi znova.',
  failed: 'Podpis ni uspel. Poskusi znova kasneje.',
  locked: {
    hard_stopped: 'Starš je igro začasno ustavil. Pogodbo lahko podpišeš, ko jo spet vklopi.',
    inactive: 'Ljubljenček trenutno ni aktiven. Prosi starša, da preveri nastavitve.',
    game_over: 'Igra je končana, pogodbe ni več mogoče podpisati.',
    ill: 'Ljubljenček je pri veterinarju. Poskusi znova kasneje.',
    default: 'Podpis trenutno ni mogoč. Poskusi znova kasneje.',
  },
} as const;

type LockedReasonKey = keyof typeof CONTRACT_STRINGS.locked;

function lockedMessage(reason: string | null): string {
  const key: LockedReasonKey =
    reason !== null && reason in CONTRACT_STRINGS.locked ? (reason as LockedReasonKey) : 'default';
  return CONTRACT_STRINGS.locked[key];
}

interface ContractError {
  message: string;
  /** Offline / 5xx: show the "Poskusi znova" button (re-sends the same signature). */
  retryable: boolean;
}

export default function ContractScreen() {
  const [signature, setSignature] = useState('');
  const [isSigning, setIsSigning] = useState(false);
  const [contractError, setContractError] = useState<ContractError | null>(null);

  const sessionUser = useAppStore((s) => s.user);
  const sessionPet = useAppStore((s) => s.pet);
  const setPet = useAppStore((s) => s.setPet);
  const setPairingStatus = useAppStore((s) => s.setPairingStatus);
  const setLockState = useAppStore((s) => s.setLockState);
  const queryClient = useQueryClient();

  /** Put the server's pet (born now / unlocked for this child) into the session; AppNavigator then shows the HUD. */
  const completeContract = (state: ChildPetState) => {
    // The HUD starts from this state instead of an extra GET (M1-13).
    writeChildState(queryClient, state);
    const pet = petFromChildState(state, {
      userId: sessionUser?.id ?? sessionPet?.user_id ?? 0,
      petDna: sessionPet?.pet_dna ?? null,
    });
    setPet(pet);
    setPairingStatus('paired');
    setLockState(lockStateFromPet(pet));
  };

  const handleAcceptContract = async () => {
    if (!hasSignature(signature) || isSigning) return;
    setIsSigning(true);
    setContractError(null);
    const outcome = await submitSignature(signature);
    setIsSigning(false);

    switch (outcome.kind) {
      case 'signed':
      case 'already_signed':
        completeContract(outcome.state);
        return;
      case 'invalid':
        setSignature('');
        setContractError({ message: CONTRACT_STRINGS.invalid, retryable: false });
        return;
      case 'locked':
        setContractError({ message: lockedMessage(outcome.reason), retryable: false });
        return;
      case 'retryable':
        setContractError({ message: CONTRACT_STRINGS.network, retryable: true });
        return;
      case 'failed':
        setContractError({ message: CONTRACT_STRINGS.failed, retryable: false });
        return;
    }
  };

  const signatureReady = hasSignature(signature);

  return (
    <View style={styles.container}>
      {/* Ambient background glow orbs */}
      <View style={[styles.glowOrb, styles.glowIndigo]} />
      <View style={[styles.glowOrb, styles.glowEmerald]} />

      {/* Responsibility Contract — the pet is born when the server accepts the signature */}
      <Modal visible animationType="slide" transparent>
        <View style={styles.modalBackdrop}>
          <View style={styles.contractModal}>
            <View style={styles.contractHeader}>
              <View style={styles.contractIconBadge}>
                <ScrollText color="#818cf8" size={22} />
              </View>
              <Text style={styles.contractTitle}>{CONTRACT_STRINGS.title}</Text>
            </View>

            <ScrollView style={styles.contractScroll}>
              <Text style={styles.contractBody}>{CONTRACT_TEXT}</Text>
            </ScrollView>

            <Text style={styles.padHint}>{CONTRACT_STRINGS.padHint}</Text>
            <SignaturePad
              value={signature}
              onChange={(path) => {
                setSignature(path);
                if (contractError && !contractError.retryable) setContractError(null);
              }}
              disabled={isSigning}
              accessibilityLabel={CONTRACT_STRINGS.padLabel}
            />
            <Pressable
              style={styles.clearButton}
              onPress={() => setSignature('')}
              disabled={isSigning || signature.length === 0}
            >
              <Eraser color="#94a3b8" size={14} />
              <Text style={styles.clearButtonText}>{CONTRACT_STRINGS.clear}</Text>
            </Pressable>

            {contractError && (
              <View style={styles.errorBox} testID="contract-error">
                <Text style={styles.errorText}>{contractError.message}</Text>
              </View>
            )}

            {contractError?.retryable ? (
              <Pressable
                style={({ pressed }) => [styles.primaryButton, isSigning && styles.buttonDisabled, pressed && styles.pressed]}
                onPress={handleAcceptContract}
                disabled={isSigning}
              >
                {isSigning ? (
                  <ActivityIndicator color="#ffffff" testID="contract-loading" />
                ) : (
                  <>
                    <RotateCcw color="#ffffff" size={18} />
                    <Text style={styles.primaryButtonText}>{CONTRACT_STRINGS.retry}</Text>
                  </>
                )}
              </Pressable>
            ) : (
              <Pressable
                style={({ pressed }) => [
                  styles.primaryButton,
                  (!signatureReady || isSigning) && styles.buttonDisabled,
                  pressed && styles.pressed,
                ]}
                onPress={handleAcceptContract}
                disabled={!signatureReady || isSigning}
              >
                {isSigning ? (
                  <ActivityIndicator color="#ffffff" testID="contract-loading" />
                ) : (
                  <Text style={styles.primaryButtonText}>{CONTRACT_STRINGS.accept}</Text>
                )}
              </Pressable>
            )}

            <Pressable
              style={styles.backButton}
              onPress={() => {
                void logout();
              }}
              disabled={isSigning}
            >
              <Text style={styles.backButtonText}>{CONTRACT_STRINGS.logout}</Text>
            </Pressable>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#020617',
  },
  glowOrb: {
    position: 'absolute',
    borderRadius: 9999,
  },
  glowIndigo: {
    width: 280,
    height: 280,
    top: 60,
    left: -80,
    backgroundColor: 'rgba(79, 70, 229, 0.2)',
  },
  glowEmerald: {
    width: 240,
    height: 240,
    bottom: 80,
    right: -60,
    backgroundColor: 'rgba(16, 185, 129, 0.12)',
  },
  errorBox: {
    marginTop: 14,
    padding: 12,
    backgroundColor: 'rgba(244, 63, 94, 0.12)',
    borderWidth: 1,
    borderColor: 'rgba(244, 63, 94, 0.35)',
    borderRadius: 12,
  },
  errorText: {
    fontSize: 13,
    color: '#fb7185',
    textAlign: 'center',
  },
  primaryButton: {
    marginTop: 18,
    height: 52,
    backgroundColor: '#4f46e5',
    borderRadius: 14,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    shadowColor: '#4f46e5',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.4,
    shadowRadius: 10,
    elevation: 4,
  },
  buttonDisabled: {
    backgroundColor: 'rgba(51, 65, 85, 0.6)',
    shadowOpacity: 0,
  },
  primaryButtonText: {
    fontSize: 16,
    fontWeight: '700',
    color: '#ffffff',
  },
  pressed: {
    opacity: 0.85,
    transform: [{ scale: 0.98 }],
  },
  backButton: {
    marginTop: 16,
    alignItems: 'center',
    padding: 6,
  },
  backButtonText: {
    fontSize: 13,
    color: '#64748b',
  },
  modalBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(0, 0, 0, 0.75)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 20,
  },
  contractModal: {
    width: '100%',
    maxHeight: '85%',
    backgroundColor: '#0f172a',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    borderRadius: 24,
    padding: 22,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 16 },
    shadowOpacity: 0.6,
    shadowRadius: 28,
  },
  contractHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    marginBottom: 16,
  },
  contractIconBadge: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: 'rgba(99, 102, 241, 0.2)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  contractTitle: {
    fontSize: 19,
    fontWeight: '700',
    color: '#ffffff',
  },
  contractScroll: {
    maxHeight: 200,
    marginBottom: 16,
  },
  contractBody: {
    fontSize: 14,
    lineHeight: 22,
    color: '#cbd5e1',
  },
  padHint: {
    fontSize: 13,
    color: '#94a3b8',
    marginBottom: 8,
  },
  clearButton: {
    flexDirection: 'row',
    alignSelf: 'flex-end',
    alignItems: 'center',
    gap: 4,
    paddingVertical: 6,
    paddingHorizontal: 4,
  },
  clearButtonText: {
    fontSize: 12,
    color: '#94a3b8',
  },
});
