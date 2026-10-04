import { Component, useEffect, type ErrorInfo, type ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { isAwaitingContract, useAppStore, type LockState } from '@/store/appStore';
import { logout } from '@/modules/session/logout';
import { useSessionBootstrap } from '@/modules/session/useSessionBootstrap';
import SplashScreen from '@/screens/SplashScreen';
import StartScreen from '@/screens/StartScreen';
import ContractScreen from '@/screens/ContractScreen';
import LockedScreen from '@/screens/LockedScreen';
import ChildHudScreen from '@/screens/ChildHudScreen';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';

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
    console.error('AppNavigator ErrorBoundary caught:', error, errorInfo);
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <View style={styles.errorContainer}>
          <Text style={styles.errorTitle}>Nekaj je šlo narobe</Text>
          <Text style={styles.errorMessage}>{this.state.error?.message}</Text>
        </View>
      );
    }
    return this.props.children;
  }
}

/** Logs the legacy child session out once, showing the start screen meanwhile. */
function SignOutChildWithoutPet() {
  useEffect(() => {
    void logout();
  }, []);
  return <StartScreen />;
}

export default function AppNavigator() {
  const authToken = useAppStore((s) => s.authToken);
  const user = useAppStore((s) => s.user);
  const pet = useAppStore((s) => s.pet);
  const lockState = useAppStore((s) => s.lockState);
  const bootStatus = useAppStore((s) => s.bootStatus);
  const { retry } = useSessionBootstrap();

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
      <View style={styles.root}>
        {!isAuthenticated ? (
          // "Sem starš" (e-mail) or "Sem otrok" (PIN only, M2-02).
          <StartScreen />
        ) : user.role === 'parent' ? (
          <ParentDashboardScreen />
        ) : (
          <>
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
              <ChildHudScreen />
            )}
            {isLocked && <LockedScreen />}
          </>
        )}
      </View>
    </ErrorBoundary>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: '#020617',
  },
  errorContainer: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#0f172a',
    padding: 24,
  },
  errorTitle: {
    fontSize: 18,
    fontWeight: 'bold',
    color: '#ef4444',
  },
  errorMessage: {
    marginTop: 8,
    fontSize: 14,
    color: '#94a3b8',
    textAlign: 'center',
  },
});
