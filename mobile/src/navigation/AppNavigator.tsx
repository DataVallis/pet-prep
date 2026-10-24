import { Component, useEffect, useRef, type ErrorInfo, type ReactNode } from 'react';
import { BackHandler, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';

import { isAwaitingContract, useAppStore, type LockState } from '@/store/appStore';
import { logout } from '@/modules/session/logout';
import { useSessionBootstrap } from '@/modules/session/useSessionBootstrap';
import { useServerRecovery } from '@/modules/connectivity/serverRecovery';
import { useServerOutage } from '@/store/serverStatusStore';
import { usePushNotifications } from '@/modules/push/usePushNotifications';
import { usePurchasesSession } from '@/modules/purchases/usePurchases';
import SplashScreen from '@/screens/SplashScreen';
import StartScreen from '@/screens/StartScreen';
import ParentLoginScreen from '@/screens/ParentLoginScreen';
import { afterParentSignIn } from '@/modules/session/profileSwitch';
import { useParentalGate } from '@/modules/parentalGate/useParentalGate';
import ParentalGate from '@/components/ParentalGate';
import ContractScreen from '@/screens/ContractScreen';
import LockedScreen from '@/screens/LockedScreen';
import ChildHudScreen from '@/screens/ChildHudScreen';
import HudErrorBoundary from '@/components/HudErrorBoundary';
import { logRenderError } from '@/utils/logRenderError';
import { setErrorRoute } from '@/modules/errorReporting/reporter';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';
import { palette } from '@/theme';
import { useDarkStatusBar } from '@/components/ui/useDarkStatusBar';
import { useTranslation } from 'react-i18next';
import { setTextSpecies, t } from '@/i18n';

const LOCKED_STATES: LockState[] = ['game_over', 'hard_stop', 'payment_required', 'illness', 'inactive'];

interface ErrorBoundaryProps {
  children: ReactNode;
}

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { hasError: false, error: null };

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
    logRenderError('app', error, errorInfo);
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <View style={styles.errorContainer}>
          <Text style={styles.errorTitle}>{t('common:errors.crashTitle')}</Text>
          <Text style={styles.errorMessage}>{this.state.error?.message}</Text>
        </View>
      );
    }
    return this.props.children;
  }
}

const logHudError = (error: Error, info: ErrorInfo): void => logRenderError('child-hud', error, info);
const logLockedError = (error: Error, info: ErrorInfo): void => logRenderError('locked-overlay', error, info);

/** M5-05: which screen AppNavigator shows, as a short name for error reports. */
export function routeName(role: string | null, parentLoginOpen: boolean, awaitingContract: boolean | null, locked: boolean): string {
  if (role === null) return 'start';
  if (role === 'parent') return 'parent-dashboard';
  if (parentLoginOpen) return 'parent-login';
  if (awaitingContract === null) return 'child-no-pet';
  if (awaitingContract) return locked ? 'contract-locked' : 'contract';
  return locked ? 'child-locked' : 'child-hud';
}

/** Logs the legacy child session out once, showing the start screen meanwhile. */
function SignOutChildWithoutPet() {
  useEffect(() => {
    void logout();
  }, []);
  return <StartScreen />;
}

/** The child simulator is dark (CGP v2): light status-bar text while it is shown. */
function DarkStatusBar() {
  useDarkStatusBar();
  return null;
}

/**
 * M2-11: whenever a parent account becomes active (login, sign-up, Google / Apple, restore),
 * close shared children's step windows and drop other parents' stored child sessions.
 */
function useParentSignInHousekeeping(): void {
  const parentId = useAppStore((s) => (s.authToken !== null && s.bootStatus === 'ready' && s.user?.role === 'parent' ? s.user.id : null));
  useEffect(() => {
    if (parentId === null) return;
    void afterParentSignIn(parentId).catch(() => undefined);
  }, [parentId]);
}

export default function AppNavigator() {
  const authToken = useAppStore((s) => s.authToken);
  const user = useAppStore((s) => s.user);
  const parentLoginOpen = useAppStore((s) => s.parentLoginOpen);
  const setParentLoginOpen = useAppStore((s) => s.setParentLoginOpen);
  const pet = useAppStore((s) => s.pet);
  const lockState = useAppStore((s) => s.lockState);
  const bootStatus = useAppStore((s) => s.bootStatus);
  // M1-18: re-render the whole tree when the language changes (screens read their
  // strings at render time through `strings()` / `t()`).
  useTranslation();
  const { retry } = useSessionBootstrap();
  // Launch splash "Odjava" (offline / long outage): always behind the parental gate.
  const splashSignOut = useParentalGate({
    onPass: () => {
      void logout();
    },
  });
  // M3-02: register this install for pushes on sign-in, route tapped pushes.
  usePushNotifications();
  // M3-07: identify a parent session with RevenueCat (never a child; no-op without keys).
  usePurchasesSession();
  useParentSignInHousekeeping();
  // M5-F13: while the server is updating (deploy), probe it and refetch everything once it is back.
  useServerRecovery();
  const serverUpdating = useServerOutage();
  // M5-F13: a launch during a deploy (restore got 503) retries the restore by itself once
  // the server is back — the user never has to tap "Poskusi znova".
  const wasUpdating = useRef(serverUpdating);
  useEffect(() => {
    const ended = wasUpdating.current && !serverUpdating;
    wasUpdating.current = serverUpdating;
    if (ended && bootStatus === 'offline') retry();
  }, [serverUpdating, bootStatus, retry]);
  // M2-11: Android back on the parent login over a shared child session → back to the child.
  useEffect(() => {
    if (!parentLoginOpen) return;
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      setParentLoginOpen(false);
      return true;
    });
    return () => subscription.remove();
  }, [parentLoginOpen, setParentLoginOpen]);

  if (bootStatus !== 'ready') {
    // M5-05: the screen NAME in error reports (never a param / id).
    setErrorRoute('splash');
    return (
      <>
        <SplashScreen mode={bootStatus} updating={serverUpdating} onRetry={retry} onLogout={splashSignOut.open} />
        {/* M5-F12: the role isn't known yet — a child may be holding the phone (QA M2 2026-10-19). */}
        <ParentalGate {...splashSignOut.props} />
      </>
    );
  }

  const isLocked = LOCKED_STATES.includes(lockState);
  const isAuthenticated = authToken !== null && user !== null;
  // M5-R06-08b: child texts follow the child's pet ("muca" for a cat) — the contract and
  // the lock screen before / above the HUD too. Never on the parent side (a family may have
  // a dog and a cat; parent helpers take the species explicitly).
  setTextSpecies(isAuthenticated && user.role !== 'parent' ? (pet?.species ?? null) : null);
  // M5-05: the screen NAME in error reports (never a param / id).
  setErrorRoute(routeName(isAuthenticated ? user.role : null, parentLoginOpen, pet === null ? null : isAwaitingContract(pet), isLocked));

  return (
    <ErrorBoundary>
      <View style={[styles.root, isAuthenticated && user.role !== 'parent' && pet !== null && styles.rootChild]}>
        {!isAuthenticated ? (
          // "Sem starš" (e-mail) or "Sem otrok" (PIN only, M2-02).
          <StartScreen />
        ) : user.role === 'parent' ? (
          <ParentDashboardScreen />
        ) : parentLoginOpen ? (
          // M2-11 »Nazaj k staršu« in a shared child session: the parent's real sign-in.
          // »Nazaj« returns to the child; a successful login makes the parent active.
          <ParentLoginScreen onBack={() => setParentLoginOpen(false)} />
        ) : (
          <>
            {pet !== null && <DarkStatusBar />}
            {pet === null ? (
              // A child session without a pet can only be a legacy e-mail child account
              // (pin-login always returns a pet). Its token must not linger: sign it out
              // (server revoke) and show the start screen — the child then signs in with
              // the PIN their parent generates, which pairs the profile (M2-02).
              <SignOutChildWithoutPet />
            ) : isAwaitingContract(pet) ? (
              // The pet waits for this child's contract (M1-07b / M2-01) — also after a restart.
              <ContractScreen />
            ) : (
              // A render error in the HUD shows a retry card, not the dead app (hotfix 2026-10-06).
              <HudErrorBoundary onError={logHudError}>
                <ChildHudScreen />
              </HudErrorBoundary>
            )}
            {isLocked && (
              // A crash in the lock overlay must not take the session down either.
              <HudErrorBoundary overlay onError={logLockedError}>
                <LockedScreen />
              </HudErrorBoundary>
            )}
          </>
        )}
      </View>
    </ErrorBoundary>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: palette.fog,
  },
  rootChild: {
    backgroundColor: palette.graphite,
  },
  errorContainer: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: palette.graphite,
    padding: 24,
  },
  errorTitle: {
    fontSize: 18,
    fontWeight: 'bold',
    color: palette.dangerDark,
  },
  errorMessage: {
    marginTop: 8,
    fontSize: 14,
    color: palette.n400,
    textAlign: 'center',
  },
});
