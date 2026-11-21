import { ApiError } from '@/api/client';

import { initialPhase2Form, mapPhase2Error, phase2Body, readPhase2Interest, thanksKind, validatePhase2 } from '../phase2';

describe('H7 fake door', () => {
  it('reads phase2_interest loosely; missing = older server', () => {
    expect(readPhase2Interest({ pet: null })).toBeNull();
    expect(readPhase2Interest(undefined)).toBeNull();
    expect(readPhase2Interest({ phase2_interest: null })).toBeNull();
    expect(readPhase2Interest({ phase2_interest: { submitted: 'yes' } })).toBeNull();
    expect(readPhase2Interest({ phase2_interest: { submitted: false } })).toEqual({ submitted: false, notify: null });
    expect(readPhase2Interest({ phase2_interest: { submitted: true, notify: false } })).toEqual({ submitted: true, notify: false });
  });

  it('prefills the account e-mail', () => {
    expect(initialPhase2Form('ana@example.com')).toEqual({ price: null, notify: false, email: 'ana@example.com' });
    expect(initialPhase2Form(null).email).toBe('');
  });

  it('needs a price; the e-mail only with notify', () => {
    expect(validatePhase2({ price: null, notify: false, email: '' })).toBe('price_required');
    expect(validatePhase2({ price: 'free', notify: false, email: '' })).toBeNull();
    expect(validatePhase2({ price: 'free', notify: true, email: '  ' })).toBe('email_required');
    expect(validatePhase2({ price: 'free', notify: true, email: 'nope' })).toBe('email_invalid');
    expect(validatePhase2({ price: 'monthly_2_99', notify: true, email: 'Ana@Example.com ' })).toBeNull();
  });

  it('builds the contract body; no e-mail without notify', () => {
    expect(phase2Body({ price: 'monthly_5_99', notify: true, email: ' Ana@Example.com ' })).toEqual({
      price_choice: 'monthly_5_99',
      notify: true,
      email: 'ana@example.com',
    });
    expect(phase2Body({ price: 'free', notify: false, email: 'ana@example.com' })).toEqual({ price_choice: 'free', notify: false, email: null });
  });

  it('maps 422 email_required and other failures', () => {
    expect(mapPhase2Error(new ApiError('x', 422, { message: 'x', code: 'email_required' }))).toBe('email_required');
    expect(mapPhase2Error(new ApiError('x', 422, { message: 'x', errors: { email: ['bad'] } }))).toBe('email_invalid');
    expect(mapPhase2Error(new ApiError('x', 500, null))).toBe('failed');
    expect(mapPhase2Error(new TypeError('Network request failed'))).toBe('offline');
  });

  it('promises a notification only when the parent asked for one (or we do not know)', () => {
    expect(thanksKind(true)).toBe('notify');
    expect(thanksKind(null)).toBe('notify');
    expect(thanksKind(false)).toBe('plain');
  });
});
