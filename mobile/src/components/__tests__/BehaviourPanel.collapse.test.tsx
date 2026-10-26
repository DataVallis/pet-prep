/**
 * David 2026-10-26: a collapsed HUD top stack expands by itself once for a NEW urgent item
 * (new mess, take-out clock turning orange / red) — never again for the same item.
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';

import { Hourglass } from 'lucide-react-native';

import BehaviourPanel, { type StackCardBody } from '@/components/BehaviourPanel';
import { Text } from '@/components/ui/Text';
import i18n from '@/i18n';
import { EMPTY_BEHAVIOUR, type ChildBehaviour, type TakeOutCountdown, type TakeOutLevel } from '@/modules/behaviour/behaviour';
import { useTopStackPrefs } from '@/modules/hud/topStackPrefs';
import { makeBehaviourEvent } from '@/test-utils/fixtures';

const clock = (level: TakeOutLevel): TakeOutCountdown => ({
  line: 'Kuža mora ven v ~1 h.',
  hint: '~1 h',
  due: false,
  level,
  howTo: null,
  short: `Ven čez ~1 h (${level})`,
});

const accident = (id: number): ChildBehaviour => ({
  ...EMPTY_BEHAVIOUR,
  active_events: [{ ...makeBehaviourEvent('accident'), id }],
  scene: 'accident',
});

const pendingNotice: StackCardBody = { node: <Text>Kuža se pripravlja…</Text>, short: 'Kuža se pripravlja…', icon: Hourglass };

function Panel({
  behaviour = EMPTY_BEHAVIOUR,
  countdown,
  notice = null,
}: {
  behaviour?: ChildBehaviour;
  countdown: TakeOutCountdown | null;
  notice?: StackCardBody | null;
}) {
  return (
    <BehaviourPanel
      behaviour={behaviour}
      countdown={countdown}
      notice={notice}
      videoState={null}
      onResolveChewing={jest.fn()}
      resolveBusy={false}
      reduceMotion
    />
  );
}

async function renderPanel(props: { behaviour?: ChildBehaviour; countdown: TakeOutCountdown | null }) {
  const view = render(<Panel {...props} />);
  await waitFor(() => expect(useTopStackPrefs.getState().hydrated).toBe(true));
  return view;
}

const isCollapsed = () => screen.queryByTestId('hud-top-stack-collapsed') !== null;

describe('BehaviourPanel auto-expand', () => {
  it('a calm clock turning orange, then red, expands once each; the same state never again', async () => {
    const view = await renderPanel({ countdown: clock('calm') });
    fireEvent.press(screen.getByTestId('hud-top-stack-collapse'));
    expect(isCollapsed()).toBe(true);
    // Calm pill: not urgent.
    expect(screen.getByTestId('hud-top-stack-pill-text').props.children).toBe('Ven čez ~1 h (calm)');

    view.rerender(<Panel countdown={clock('soon')} />);
    expect(isCollapsed()).toBe(false);

    fireEvent.press(screen.getByTestId('hud-top-stack-collapse'));
    view.rerender(<Panel countdown={clock('soon')} />);
    expect(isCollapsed()).toBe(true);

    view.rerender(<Panel countdown={clock('urgent')} />);
    expect(isCollapsed()).toBe(false);
  });

  it('a new mess expands; the same mess after collapsing again does not; another one does', async () => {
    const view = await renderPanel({ countdown: clock('calm') });
    fireEvent.press(screen.getByTestId('hud-top-stack-collapse'));
    view.rerender(<Panel countdown={clock('calm')} behaviour={accident(1)} />);
    expect(isCollapsed()).toBe(false);
    expect(screen.getByTestId('hud-scene-accident')).toBeTruthy();

    fireEvent.press(screen.getByTestId('hud-top-stack-collapse'));
    view.rerender(<Panel countdown={clock('calm')} behaviour={accident(1)} />);
    expect(isCollapsed()).toBe(true);
    expect(screen.getByTestId('hud-top-stack-pill-text').props.children).toBe('Luža na tleh');

    view.rerender(<Panel countdown={clock('calm')} behaviour={accident(2)} />);
    expect(isCollapsed()).toBe(false);
  });

  it('calm cards never expand a collapsed stack', async () => {
    const view = await renderPanel({ countdown: clock('calm') });
    fireEvent.press(screen.getByTestId('hud-top-stack-collapse'));
    view.rerender(<Panel countdown={clock('calm')} notice={pendingNotice} />);
    expect(isCollapsed()).toBe(true);
    expect(screen.getByText('+1')).toBeTruthy();
  });

  it('English labels', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    try {
      await renderPanel({ countdown: clock('calm') });
      expect(screen.getByTestId('hud-top-stack-collapse').props.accessibilityLabel).toBe('Hide messages');
      fireEvent.press(screen.getByTestId('hud-top-stack-collapse'));
      expect(screen.getByTestId('hud-top-stack-pill').props.accessibilityLabel).toBe('Ven čez ~1 h (calm). Show messages (1)');
    } finally {
      await act(async () => {
        await i18n.changeLanguage('sl');
      });
    }
  });
});
