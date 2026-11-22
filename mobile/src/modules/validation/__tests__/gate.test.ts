/** M5-08 admin switch »Validation signals« — pure gate rules. */
import type { ParentDashboardResponse } from '@/api/client';
import {
  needsUserValidationFlag,
  phase2RowMode,
  readDashboardValidationFlag,
  readUserValidationFlag,
  signupPickerVisible,
  validationSignalsOn,
} from '../gate';
import type { Phase2InterestState } from '../phase2';

/** Loose payloads as a cache / an older server can hold them. */
const dash = (payload: Record<string, unknown>) => payload as unknown as ParentDashboardResponse;
const open: Phase2InterestState = { submitted: false, notify: null, priceChoice: null };
const consent: Phase2InterestState = { submitted: true, notify: true, priceChoice: 'free' };
const withdrawn: Phase2InterestState = { submitted: true, notify: false, priceChoice: 'free' };

describe('family switch', () => {
  it('reads only booleans; anything else is "missing"', () => {
    expect(readDashboardValidationFlag(dash({ validation_signals: true }))).toBe(true);
    expect(readDashboardValidationFlag(dash({ validation_signals: false }))).toBe(false);
    expect(readDashboardValidationFlag(dash({ validation_signals: 'yes' }))).toBeNull();
    expect(readDashboardValidationFlag(dash({ pet: null }))).toBeNull();
    expect(readDashboardValidationFlag(undefined)).toBeNull();
    expect(readUserValidationFlag({ role: 'parent', validation_signals: true })).toBe(true);
    expect(readUserValidationFlag({ role: 'parent' })).toBeNull();
  });

  it('the dashboard field wins; GET /api/user is the fallback; missing = off', () => {
    expect(validationSignalsOn(true, false)).toBe(true);
    expect(validationSignalsOn(false, true)).toBe(false);
    expect(validationSignalsOn(null, true)).toBe(true);
    expect(validationSignalsOn(null, false)).toBe(false);
    expect(validationSignalsOn(null, null)).toBe(false);
  });

  it('asks GET /api/user only once the dashboard loaded without the field', () => {
    expect(needsUserValidationFlag(false, null)).toBe(false);
    expect(needsUserValidationFlag(true, null)).toBe(true);
    expect(needsUserValidationFlag(true, false)).toBe(false);
    expect(needsUserValidationFlag(true, true)).toBe(false);
  });
});

describe('»Dobili smo pravo žival« row', () => {
  it('switch on + a child → full entry', () => {
    expect(phase2RowMode(true, open, true)).toBe('full');
    expect(phase2RowMode(true, consent, true)).toBe('full');
  });

  it('switch off → hidden, except a live consent (GDPR withdrawal stays reachable)', () => {
    expect(phase2RowMode(false, open, true)).toBe('hidden');
    expect(phase2RowMode(false, withdrawn, true)).toBe('hidden');
    expect(phase2RowMode(false, consent, true)).toBe('withdrawOnly');
    expect(phase2RowMode(false, consent, false)).toBe('withdrawOnly');
  });

  it('no field (older server) or no child → hidden', () => {
    expect(phase2RowMode(true, null, true)).toBe('hidden');
    expect(phase2RowMode(true, open, false)).toBe('hidden');
  });
});

describe('sign-up picker', () => {
  it('only an explicit validation_signals_signup: true shows it', () => {
    expect(signupPickerVisible({ validation_signals_signup: true })).toBe(true);
    expect(signupPickerVisible({ validation_signals_signup: false })).toBe(false);
    expect(signupPickerVisible({ validation_signals_signup: 'true' })).toBe(false);
    expect(signupPickerVisible({})).toBe(false);
    // A failed / pending request has no data.
    expect(signupPickerVisible(undefined)).toBe(false);
  });
});
