/**
 * CGP v2 type: the brand Text maps fontWeight to the matching Instrument Sans face and
 * keeps Bricolage headings, so no style needs to name a font (and Android never fakes bold).
 */
import { StyleSheet } from 'react-native';
import { render, screen } from '@testing-library/react-native';

import { Text, brandFontStyle } from '@/components/ui/Text';
import { fonts } from '@/theme';
import { setBrandFontsReady } from '@/theme/typography';

beforeEach(() => setBrandFontsReady(true));
afterEach(() => setBrandFontsReady(false));

describe('brandFontStyle', () => {
  it('defaults to Instrument Sans Regular', () => {
    expect(brandFontStyle(undefined)).toEqual({ fontFamily: fonts.body, fontWeight: undefined });
  });

  it.each([
    ['400', fonts.body],
    ['500', fonts.bodyMedium],
    ['600', fonts.bodySemiBold],
    ['700', fonts.bodyBold],
    ['800', fonts.bodyBold],
    ['bold', fonts.bodyBold],
  ] as const)('weight %s → %s', (weight, family) => {
    expect(brandFontStyle({ fontWeight: weight }).fontFamily).toBe(family);
  });

  it('keeps a Bricolage heading and matches its face to the weight', () => {
    expect(brandFontStyle({ fontFamily: fonts.display }).fontFamily).toBe(fonts.display);
    expect(brandFontStyle({ fontFamily: fonts.display, fontWeight: '700' }).fontFamily).toBe(fonts.displayBold);
  });

  it('leaves any other explicit family alone', () => {
    expect(brandFontStyle({ fontFamily: 'Menlo', fontWeight: '700' })).toEqual({});
  });

  it('reads the flattened style (arrays, later entries win)', () => {
    expect(brandFontStyle([{ fontWeight: '400' }, { fontWeight: '600' }]).fontFamily).toBe(fonts.bodySemiBold);
  });
});

describe('brandFontStyle before the fonts are registered', () => {
  it('leaves the style alone (system font keeps its fontWeight)', () => {
    setBrandFontsReady(false);
    expect(brandFontStyle({ fontWeight: '700' })).toEqual({});
  });
});

describe('Text', () => {
  it('nested text without weight/family inherits the parent face', () => {
    render(
      <Text style={{ fontWeight: '700' }}>
        Krepko <Text style={{ color: 'red' }}>povezava</Text>
      </Text>,
    );
    const inner = StyleSheet.flatten(screen.getByText('povezava').props.style);
    expect(inner.fontFamily).toBeUndefined();
    expect(brandFontStyle({ fontWeight: '600' }, true).fontFamily).toBe(fonts.bodySemiBold);
  });

  it('without registered fonts renders the plain style', () => {
    setBrandFontsReady(false);
    render(<Text style={{ fontWeight: '700' }}>Sistem</Text>);
    expect(StyleSheet.flatten(screen.getByText('Sistem').props.style)).toEqual({ fontWeight: '700' });
  });

  it('renders the brand face and drops fontWeight', () => {
    render(<Text style={{ fontWeight: '700', fontSize: 14 }}>Pozdravljen</Text>);
    const style = StyleSheet.flatten(screen.getByText('Pozdravljen').props.style);
    expect(style).toMatchObject({ fontFamily: fonts.bodyBold, fontSize: 14 });
    expect(style.fontWeight).toBeUndefined();
  });
});
