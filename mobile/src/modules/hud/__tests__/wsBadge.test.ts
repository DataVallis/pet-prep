import { WS_BADGE_STRINGS, wsBadge } from '@/modules/hud/wsBadge';

describe('wsBadge', () => {
  it('shows a green "V ŽIVO" when the channel is live', () => {
    expect(wsBadge('connected')).toEqual({ tone: 'live', label: 'V ŽIVO', accessibilityLabel: 'V ŽIVO' });
  });

  it('shows an amber "POVEZUJEM" while connecting', () => {
    expect(wsBadge('connecting')).toMatchObject({ tone: 'connecting', label: 'POVEZUJEM' });
  });

  it.each(['disconnected', 'reconnecting'] as const)(
    'shows only a calm polling icon (no text, no red) when %s — the HUD keeps polling',
    (status) => {
      expect(wsBadge(status)).toEqual({
        tone: 'polling',
        label: null,
        accessibilityLabel: WS_BADGE_STRINGS.polling,
      });
    },
  );

  it('never says "BREZ POVEZAVE" to the child', () => {
    expect(JSON.stringify(WS_BADGE_STRINGS)).not.toContain('BREZ POVEZAVE');
  });
});
