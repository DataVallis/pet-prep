/**
 * Tests for the Breed Paywall screen logic.
 */

// Breed pricing configuration (mirrors backend breed_configs)
const BREED_CONFIGS = {
  mutt: {
    slug: 'mutt',
    dailyStepsRequired: 4000,
    hungerDecayRate: 8.0,
    premiumUnlock: false,
    price: null,
    label: 'Mutt',
    description: 'Standard decay parameters. Great for first-time pet owners.',
  },
  border_collie: {
    slug: 'border_collie',
    dailyStepsRequired: 10000,
    hungerDecayRate: 12.0,
    premiumUnlock: true,
    price: '4.99 €',
    label: 'Border Collie',
    description: 'High-energy breed with faster decay. Needs more attention!',
  },
} as const;

describe('Breed Paywall - Configuration', () => {
  it('has Mutt as free tier', () => {
    expect(BREED_CONFIGS.mutt.premiumUnlock).toBe(false);
    expect(BREED_CONFIGS.mutt.price).toBeNull();
  });

  it('has Border Collie as premium tier', () => {
    expect(BREED_CONFIGS.border_collie.premiumUnlock).toBe(true);
    expect(BREED_CONFIGS.border_collie.price).toBe('4.99 €');
  });

  it('Border Collie requires more daily steps', () => {
    expect(BREED_CONFIGS.border_collie.dailyStepsRequired).toBeGreaterThan(
      BREED_CONFIGS.mutt.dailyStepsRequired,
    );
  });

  it('Border Collie has faster hunger decay', () => {
    expect(BREED_CONFIGS.border_collie.hungerDecayRate).toBeGreaterThan(
      BREED_CONFIGS.mutt.hungerDecayRate,
    );
  });
});

describe('Breed Paywall - Purchase Logic', () => {
  // Mock purchase state machine
  type PurchaseState = 'idle' | 'loading' | 'success' | 'error';

  function getPurchaseButtonLabel(state: PurchaseState, isPremium: boolean): string {
    if (isPremium) return 'Current Breed';
    switch (state) {
      case 'loading': return 'Processing...';
      case 'success': return 'Unlocked!';
      case 'error': return 'Try Again';
      default: return 'Unlock Now';
    }
  }

  function isPurchaseButtonDisabled(state: PurchaseState, isPremium: boolean): boolean {
    return isPremium || state === 'loading';
  }

  it('shows "Unlock Now" when idle and not premium', () => {
    expect(getPurchaseButtonLabel('idle', false)).toBe('Unlock Now');
    expect(isPurchaseButtonDisabled('idle', false)).toBe(false);
  });

  it('shows "Processing..." during loading', () => {
    expect(getPurchaseButtonLabel('loading', false)).toBe('Processing...');
    expect(isPurchaseButtonDisabled('loading', false)).toBe(true);
  });

  it('shows "Unlocked!" on success', () => {
    expect(getPurchaseButtonLabel('success', false)).toBe('Unlocked!');
  });

  it('shows "Try Again" on error', () => {
    expect(getPurchaseButtonLabel('error', false)).toBe('Try Again');
    expect(isPurchaseButtonDisabled('error', false)).toBe(false);
  });

  it('shows "Current Breed" when already premium', () => {
    expect(getPurchaseButtonLabel('idle', true)).toBe('Current Breed');
    expect(isPurchaseButtonDisabled('idle', true)).toBe(true);
  });

  it('disables button during loading regardless of premium status', () => {
    expect(isPurchaseButtonDisabled('loading', true)).toBe(true);
  });
});

describe('Breed Paywall - Restore Purchases', () => {
  type RestoreState = 'idle' | 'loading' | 'restored' | 'no_purchases' | 'error';

  function getRestoreButtonLabel(state: RestoreState): string {
    switch (state) {
      case 'loading': return 'Restoring...';
      case 'restored': return 'Purchases Restored';
      case 'no_purchases': return 'No Purchases Found';
      case 'error': return 'Restore Failed';
      default: return 'Restore Purchases';
    }
  }

  it('shows "Restore Purchases" when idle', () => {
    expect(getRestoreButtonLabel('idle')).toBe('Restore Purchases');
  });

  it('shows "Restoring..." during loading', () => {
    expect(getRestoreButtonLabel('loading')).toBe('Restoring...');
  });

  it('shows "Purchases Restored" on success', () => {
    expect(getRestoreButtonLabel('restored')).toBe('Purchases Restored');
  });

  it('shows "No Purchases Found" when nothing to restore', () => {
    expect(getRestoreButtonLabel('no_purchases')).toBe('No Purchases Found');
  });

  it('shows "Restore Failed" on error', () => {
    expect(getRestoreButtonLabel('error')).toBe('Restore Failed');
  });
});
