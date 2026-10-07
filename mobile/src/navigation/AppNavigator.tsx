import { Component, useEffect, type ErrorInfo, type ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';

import { isAwaitingContract, useAppStore, type LockState } from '@/store/appStore';
import { logout } from '@/modules/session/logout';
import { useSessionBootstrap } from '@/modules/session/useSessionBootstrap';
import { usePushNotifications } from '@/modules/push/usePushNotifications';
import { usePurchasesSession } from '@/modules/purchases/usePurchases';
import SplashScreen from '@/screens/SplashScreen';
import StartScreen from '@/screens/StartScreen';
import ContractScreen from '@/screens/ContractScreen';
import LockedScreen from '@/screens/LockedScreen';
import ChildHudScreen from '@/screens/ChildHudScreen';
import HudErrorBoundary from '@/components/HudErrorBoundary';
import { logRenderError } from '@/utils/logRenderError';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';
import { palette } from '@/theme';
import { useDarkStatusBar } from '@/components/ui/useDarkStatusBar';
import { useTranslation } from 'react-i18next';
import { t } from '@/i18n';

const LOCKED_STATES: LockState[] = ['game_over', 'hard_stop', 'illness', 'inactive'];

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

export default function AppNavigator() {
  const authToken = useAppStore((s) => s.authToken);
  const user = useAppStore((s) => s.user);
  const pet = useAppStore((s) => s.pet);
  const lockState = useAppStore((s) => s.lockState);
  const bootStatus = useAppStore((s) => s.bootStatus);
  // M1-18: re-render the whole tree when the language changes (screens read their
  // strings at render time through `strings()` / `t()`).
  useTranslation();
  const { retry } = useSessionBootstrap();
  // M3-02: register this install for pushes on sign-in, route tapped pushes.
  usePushNotifications();
  // M3-07: identify a parent session with RevenueCat (never a child; no-op without keys).
  usePurchasesSession();

  if (bootStatus !== 'ready') {
    return (
      <SplashScreen
        mode={bootStatus}
        onRetry={retry}
        onLogout={() => {
          void logout();
        }}
      />
    );
  }

  const isLocked = LOCKED_STATES.includes(lockState);
  const isAuthenticated = authToken !== null && user !== null;

  return (
    <ErrorBoundary>
      <View style={[styles.root, isAuthenticated && user.role !== 'parent' && pet !== null && styles.rootChild]}>
        {!isAuthenticated ? (
          // "Sem starš" (e-mail) or "Sem otrok" (PIN only, M2-02).
          <StartScreen />
        ) : user.role === 'parent' ? (
          <ParentDashboardScreen />
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
