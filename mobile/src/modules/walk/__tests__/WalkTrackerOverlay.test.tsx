/**
 * Walk overlay (M1-14) after the NativeWind → StyleSheet conversion (2026-10-05):
 * TestFlight showed it as unstyled black text in the top-left corner.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';
import { StyleSheet, type ViewStyle } from 'react-native';

import { normalizeChildState } from '@/modules/childPet/childPetView';
import type { StepPermission, StepSync } from '@/modules/steps/useStepSync';
import WalkTrackerOverlay, { WALK_STRINGS } from '@/modules/walk/WalkTrackerOverlay';
import { makeLiveChildState } from '@/test-utils/fixtures';

function makeStepSync(permission: StepPermission, overrides: Partial<StepSync> = {}): StepSync {
  return {
    permission,
    requestPermission: jest.fn(() => Promise.resolve()),
    syncNow: jest.fn(() => Promise.resolve()),
    isSyncing: false,
    ...overrides,
  };
}

function renderOverlay(permission: StepPermission, opts: { steps?: number; caretakers?: number; sync?: Partial<StepSync> } = {}) {
  const view = normalizeChildState(
    makeLiveChildState({
      steps: { steps_today: opts.steps ?? 1250, my_steps_today: 800, goal: 4000, energy_level: 30 },
      pet: { caretakers_count: opts.caretakers ?? 1 },
    }),
  );
  const stepSync = makeStepSync(permission, opts.sync);
  const onClose = jest.fn();
  render(<WalkTrackerOverlay view={view} stepSync={stepSync} onClose={onClose} />);
  return { stepSync, onClose };
}

const flat = (testID: string): ViewStyle => StyleSheet.flatten(screen.getByTestId(testID).props.style) as ViewStyle;

describe('WalkTrackerOverlay', () => {
  it('renders as a full-screen dark HUD overlay, not unstyled text', () => {
    renderOverlay('undetermined');

    const root = flat('walk-overlay');
    expect(root).toMatchObject({ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 });
    expect(root.zIndex).toBe(30);
    expect(root.backgroundColor).toMatch(/^rgba\(/);
    expect(root.justifyContent).toBe('center');

    const card = StyleSheet.flatten(screen.getByTestId('walk-overlay').props.children.props.style) as ViewStyle;
    expect(card.borderRadius).toBe(24);
    expect(card.backgroundColor).toMatch(/^rgba\(18, 22, 20/);
  });

  it('shows the title, steps / goal and energy from the server', () => {
    renderOverlay('granted');

    expect(screen.getByText(WALK_STRINGS.title)).toBeTruthy();
    expect(screen.getByText(/1.250 \/ 4.000 korakov/)).toBeTruthy();
    expect(screen.getByText('Energija 30 %')).toBeTruthy();
  });

  it('fills the progress bar proportionally', () => {
    renderOverlay('granted', { steps: 1000 });
    const fill = StyleSheet.flatten(screen.getByTestId('walk-progress').props.children.props.style) as ViewStyle;
    expect(fill.width).toBe('25%');
  });

  it('asks for the step permission with the hint and the green button', () => {
    const { stepSync } = renderOverlay('undetermined');

    expect(screen.getByText(WALK_STRINGS.allowHint)).toBeTruthy();
    fireEvent.press(screen.getByText(WALK_STRINGS.allow));
    expect(stepSync.requestPermission).toHaveBeenCalledTimes(1);
  });

  it('refreshes the steps when allowed, disabled while syncing', () => {
    const { stepSync } = renderOverlay('granted');
    fireEvent.press(screen.getByTestId('walk-refresh'));
    expect(stepSync.syncNow).toHaveBeenCalledTimes(1);
  });

  it('shows "Shranjujem …" and blocks the button while syncing', () => {
    renderOverlay('granted', { sync: { isSyncing: true } });
    expect(screen.getByText(WALK_STRINGS.syncing)).toBeTruthy();
    expect(screen.getByTestId('walk-refresh').props.accessibilityState).toMatchObject({ disabled: true });
  });

  it.each([
    ['denied', WALK_STRINGS.denied],
    ['unavailable', WALK_STRINGS.unavailable],
  ] as const)('explains a %s step counter', (permission, text) => {
    renderOverlay(permission);
    expect(screen.getByText(text)).toBeTruthy();
    expect(screen.queryByText(WALK_STRINGS.allow)).toBeNull();
  });

  it('shows my own steps only when the dog is shared', () => {
    renderOverlay('granted', { caretakers: 2 });
    expect(screen.getByText('Tvoji koraki danes: 800')).toBeTruthy();
  });

  it('celebrates a reached goal', () => {
    renderOverlay('granted', { steps: 4000 });
    expect(screen.getByText(WALK_STRINGS.goalReached)).toBeTruthy();
  });

  it('closes with the X button', () => {
    const { onClose } = renderOverlay('granted');
    fireEvent.press(screen.getByLabelText(WALK_STRINGS.close));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
