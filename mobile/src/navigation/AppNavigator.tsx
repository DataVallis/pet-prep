import { Component, type ErrorInfo, type ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { useAppStore, type LockState } from '@/store/appStore';
import { logout } from '@/modules/session/logout';
import { useSessionBootstrap } from '@/modules/session/useSessionBootstrap';
import SplashScreen from '@/screens/SplashScreen';
import PairingScreen from '@/screens/PairingScreen';
import LockedScreen from '@/screens/LockedScreen';
import ChildHudScreen from '@/screens/ChildHudScreen';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';

const LOCKED_STATES: LockState[] = ['game_over', 'hard_stop', 'illness'];

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
          <PairingScreen />
        ) : user.role === 'parent' ? (
          <ParentDashboardScreen />
        ) : (
          <>
            {pet !== null ? <ChildHudScreen /> : <PairingScreen initialStep="pin" />}
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
