/**
 * Tester feedback (David 2026-10-25): one shared required-field marker for every form.
 */
import { render, screen } from '@testing-library/react-native';

import { FieldLabel, REQUIRED_MARK, RequiredLegend, requiredA11yLabel } from '@/components/ui/RequiredLabel';
import { shouldCloseOnSwipe, startsSwipe, SWIPE_CLOSE_DY, SWIPE_CLOSE_VY } from '@/components/ui/useSwipeToClose';
import { i18n } from '@/i18n';

describe('requiredA11yLabel', () => {
  it('adds "obvezno" / "required" only for required fields', async () => {
    expect(requiredA11yLabel('Vzdevek')).toBe('Vzdevek, obvezno');
    expect(requiredA11yLabel('Vzdevek', false)).toBe('Vzdevek');
    await i18n.changeLanguage('en');
    try {
      expect(requiredA11yLabel('Nickname')).toBe('Nickname, required');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });
});

describe('FieldLabel', () => {
  it('required: label + asterisk, read as one element "<label>, obvezno" (the asterisk is never read)', () => {
    render(
      <FieldLabel required accessibilityRole="header" testID="lbl">
        Načrt
      </FieldLabel>,
    );
    const label = screen.getByTestId('lbl');
    expect(label).toHaveTextContent(`Načrt${REQUIRED_MARK}`);
    expect(label.props.accessible).toBe(true);
    expect(label.props.accessibilityLabel).toBe('Načrt, obvezno');
    expect(label.props.accessibilityRole).toBe('header');
    const mark = screen.getByTestId('required-mark', { includeHiddenElements: true });
    expect(mark.props.importantForAccessibility).toBe('no');
    expect(screen.getByText('Načrt')).toBeTruthy();
  });

  it('optional: no asterisk, the plain label', () => {
    render(<FieldLabel testID="lbl">Letnica rojstva</FieldLabel>);
    expect(screen.queryByTestId('required-mark', { includeHiddenElements: true })).toBeNull();
    expect(screen.getByTestId('lbl').props.accessibilityLabel).toBe('Letnica rojstva');
  });
});

describe('RequiredLegend', () => {
  it('"* obvezno polje", spoken as a sentence', () => {
    render(<RequiredLegend />);
    const legend = screen.getByTestId('required-legend');
    expect(legend).toHaveTextContent('* obvezno polje');
    expect(legend.props.accessibilityLabel).toBe('Polja z zvezdico so obvezna.');
  });
});

describe('swipe to close (breed sheet)', () => {
  it('closes past the distance or speed threshold only', () => {
    expect(shouldCloseOnSwipe(SWIPE_CLOSE_DY + 1, 0)).toBe(true);
    expect(shouldCloseOnSwipe(10, SWIPE_CLOSE_VY + 0.1)).toBe(true);
    expect(shouldCloseOnSwipe(SWIPE_CLOSE_DY, SWIPE_CLOSE_VY)).toBe(false);
  });

  it('starts only on a mostly vertical downward move', () => {
    expect(startsSwipe(0, 9)).toBe(true);
    expect(startsSwipe(20, 9)).toBe(false);
    expect(startsSwipe(0, -20)).toBe(false);
  });
});
