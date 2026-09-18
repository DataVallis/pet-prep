import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Text, View } from 'react-native';

import { useAppStore, type LockState } from '@/store/appStore';
import PairingScreen from '@/screens/PairingScreen';
import LockedScreen from '@/screens/LockedScreen';
import ChildHudScreen from '@/screens/ChildHudScreen';

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
        <View className="flex-1 items-center justify-center bg-slate-900 p-6">
          <Text className="text-lg font-bold text-red-500">Something went wrong</Text>
          <Text className="mt-2 text-sm text-slate-400">{this.state.error?.message}</Text>
        </View>
      );
    }
    return this.props.children;
  }
}

export default function AppNavigator() {
  const authToken = useAppStore((s) => s.authToken);
  const pet = useAppStore((s) => s.pet);
  const lockState = useAppStore((s) => s.lockState);

  const isLocked = LOCKED_STATES.includes(lockState);
  const isPaired = authToken !== null && pet !== null;

  return (
    <ErrorBoundary>
      <View className="flex-1">
        {isPaired ? <ChildHudScreen /> : <PairingScreen />}
        {isLocked && <LockedScreen />}
      </View>
    </ErrorBoundary>
  );
}
