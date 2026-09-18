/**
 * ParentAppNavigator — top-level navigator for the Parent profile.
 *
 * Renders the PairingScreen (parent role) when no auth token is present,
 * and the ParentDashboardScreen once paired. Wrapped in an ErrorBoundary
 * that mirrors the pattern used in AppNavigator.
 */

import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Text, View } from 'react-native';

import { useAppStore } from '@/store/appStore';
import PairingScreen from '@/screens/PairingScreen';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';

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
    console.error('ParentAppNavigator ErrorBoundary caught:', error, errorInfo);
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <View className="flex-1 items-center justify-center bg-slate-50 p-6">
          <Text className="text-lg font-bold text-rose-600">Something went wrong</Text>
          <Text className="mt-2 text-sm text-slate-500">{this.state.error?.message}</Text>
        </View>
      );
    }
    return this.props.children;
  }
}

export default function ParentAppNavigator() {
  const authToken = useAppStore((s) => s.authToken);
  const pet = useAppStore((s) => s.pet);

  const isPaired = authToken !== null && pet !== null;

  return (
    <ErrorBoundary>
      <View className="flex-1">
        {isPaired ? <ParentDashboardScreen /> : <PairingScreen />}
      </View>
    </ErrorBoundary>
  );
}
