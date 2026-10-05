/**
 * M2-10a: parent sign-up form rules, request body and server-error mapping.
 */
import { ApiError } from '@/api/client';
import {
  SIGNUP_STRINGS,
  deviceTimezone,
  mapSignupError,
  signupBody,
  validateSignup,
  type SignupForm,
} from '@/modules/auth/signup';

const E = SIGNUP_STRINGS.errors;

const valid: SignupForm = {
  name: '  Mama   Ana ',
  email: ' Ana@Example.COM ',
  password: 'Varno1Geslo',
  passwordRepeat: 'Varno1Geslo',
  acceptTerms: true,
};

describe('validateSignup', () => {
  it('accepts a complete form', () => {
    expect(validateSignup(valid)).toEqual({});
  });

  it('requires a name of at most 60 characters', () => {
    expect(validateSignup({ ...valid, name: '   ' }).name).toBe(E.nameRequired);
    expect(validateSignup({ ...valid, name: 'Ž'.repeat(60) }).name).toBeUndefined();
    expect(validateSignup({ ...valid, name: 'a'.repeat(61) }).name).toBe(E.nameTooLong);
  });

  it('requires a valid e-mail', () => {
    expect(validateSignup({ ...valid, email: 'ana@' }).email).toBe(E.emailInvalid);
    expect(validateSignup({ ...valid, email: 'ana example.com' }).email).toBe(E.emailInvalid);
  });

  it.each([
    ['too short', 'Kratko1x'],
    ['no digit', 'BrezStevilke'],
    ['no upper case', 'malecrke123'],
    ['no lower case', 'VELIKECRKE123'],
  ])('rejects a weak password (%s)', (_label, password) => {
    expect(validateSignup({ ...valid, password, passwordRepeat: password }).password).toBe(E.passwordWeak);
  });

  it('requires the repeat to match', () => {
    expect(validateSignup({ ...valid, passwordRepeat: 'Varno1Gesl0' }).passwordRepeat).toBe(E.passwordMismatch);
  });

  it('requires the terms checkbox', () => {
    expect(validateSignup({ ...valid, acceptTerms: false }).acceptTerms).toBe(E.termsRequired);
  });
});

describe('signupBody', () => {
  it('normalises name and e-mail, sends the confirmation, terms and device timezone', () => {
    expect(signupBody(valid, 'iPhone', 'Europe/Ljubljana')).toEqual({
      name: 'Mama Ana',
      email: 'ana@example.com',
      password: 'Varno1Geslo',
      password_confirmation: 'Varno1Geslo',
      accept_terms: true,
      device_name: 'iPhone',
      timezone: 'Europe/Ljubljana',
    });
  });

  it('leaves the timezone out when the device has none (server default)', () => {
    expect(signupBody(valid, 'iPhone', undefined)).not.toHaveProperty('timezone');
  });

  it('reads the device timezone from Intl', () => {
    // Jest runs with TZ=UTC (jest.config.js).
    expect(deviceTimezone()).toBe('UTC');
  });
});

describe('mapSignupError', () => {
  const v422 = (errors: Record<string, string[]>) =>
    new ApiError('The given data was invalid.', 422, { message: 'invalid', errors });

  it('duplicate e-mail → "already registered" under the e-mail field', () => {
    expect(mapSignupError(v422({ email: ['The email has already been taken.'] }))).toEqual({
      fields: { email: E.emailTaken },
      general: null,
    });
  });

  it('maps password policy vs confirmation, terms and name', () => {
    expect(mapSignupError(v422({ password: ['The password field must contain at least one number.'] })).fields).toEqual({
      password: E.passwordWeak,
    });
    expect(mapSignupError(v422({ password: ['The password field confirmation does not match.'] })).fields).toEqual({
      passwordRepeat: E.passwordMismatch,
    });
    expect(mapSignupError(v422({ accept_terms: ['You must accept the terms.'] })).fields).toEqual({
      acceptTerms: E.termsRequired,
    });
    expect(mapSignupError(v422({ name: ['The name field must not be greater than 60 characters.'] })).fields).toEqual({
      name: E.nameTooLong,
    });
  });

  it('prefers the server codes over the English messages', () => {
    const err = new ApiError('x', 422, {
      message: 'x',
      errors: { email: ['Something new and unexpected.'], password: ['The password field confirmation does not match.'], name: ['x'] },
      codes: { email: 'email_taken', password: 'password_weak', name: 'name_invalid' },
    });
    expect(mapSignupError(err)).toEqual({
      fields: { email: E.emailTaken, password: E.passwordWeak, name: E.nameInvalid },
      general: null,
    });
    expect(
      mapSignupError(new ApiError('x', 422, { message: 'x', errors: { password: ['x'] }, codes: { password: 'password_mismatch' } })).fields,
    ).toEqual({ passwordRepeat: E.passwordMismatch });
    expect(
      mapSignupError(new ApiError('x', 422, { message: 'x', errors: { accept_terms: ['x'] }, codes: { accept_terms: 'terms_required' } })).fields,
    ).toEqual({ acceptTerms: E.termsRequired });
    expect(
      mapSignupError(new ApiError('x', 422, { message: 'x', errors: { timezone: ['x'] }, codes: { timezone: 'timezone_invalid' } })).general,
    ).toBe(E.timezoneInvalid);
  });

  it('timezone or unknown 422 → a general message', () => {
    expect(mapSignupError(v422({ timezone: ['bad'] })).general).toBe(E.timezoneInvalid);
    expect(mapSignupError(new ApiError('x', 422, null)).general).toBe(E.failed);
  });

  it('429 → wait message, with minutes from Retry-After', () => {
    expect(mapSignupError(new ApiError('Too Many Attempts.', 429, null, 90)).general).toBe(E.tooManyAttemptsMinutes(2));
    expect(mapSignupError(new ApiError('Too Many Attempts.', 429, null, null)).general).toBe(E.tooManyAttempts);
  });

  it('no connection → offline; 500 → generic failure', () => {
    expect(mapSignupError(new TypeError('Network request failed')).general).toBe(E.offline);
    expect(mapSignupError(new ApiError('Server Error', 500, null)).general).toBe(E.failed);
  });
});
