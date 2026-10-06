import { render, screen } from '@testing-library/react-native';

import { BrandLogo, BrandMark } from '@/components/brand/BrandLogo';

describe('BrandLogo', () => {
  it('renders the mark', () => {
    render(<BrandMark />);
    expect(screen.getByLabelText('PetPrep')).toBeTruthy();
  });
  it('renders the horizontal logo', () => {
    render(<BrandLogo />);
    expect(screen.getByTestId('brand-logo')).toBeTruthy();
  });
});
