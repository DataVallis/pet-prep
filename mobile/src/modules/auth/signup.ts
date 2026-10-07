/**
 * Parent self-registration (M2-10a): form validation, device timezone, request
 * body and server-error mapping. Pure functions — the screen only wires them up.
 *
 * Rules mirror `RegisterParentRequest` on the backend: name 1–60 characters,
 * valid e-mail, password ≥ 10 with upper + lower case and a digit, repeat equal,
 * terms accepted. The server stays the authority (it re-validates everything).
 */

import {
  ApiError,
  api,
  saveAuthToken,
  type RegisterErrorBody,
  type RegisterErrorCode,
  type RegisterParentRequest,
  type RegisterResponse,
  type ValidationErrorBody,
} from '@/api/client';
import type { SignInPayload } from '@/store/appStore';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** All user-visible strings of the sign-up flow (`auth:signup`, M1-18). */
export const SIGNUP_STRINGS = strings('auth', 'signup', {
  errors: {
    tooManyAttemptsMinutes: (minutes: number) => t('auth:signup.errors.tooManyAttemptsMinutes', { minutes }),
  },
});

/**
 * Legal texts. TODO(M2-10a): placeholders — the real terms of use and privacy policy
 * must be written (growth-marketer + lawyer) and published before the beta.
 */
export const TERMS_URL = 'https://petprep.si/pogoji';
export const PRIVACY_URL = 'https://petprep.si/zasebnost';

export const NAME_MAX_LENGTH = 60;
export const PASSWORD_MIN_LENGTH = 10;

export interface SignupForm {
  name: string;
  email: string;
  password: string;
  passwordRepeat: string;
  acceptTerms: boolean;
}

export type SignupField = 'name' | 'email' | 'password' | 'passwordRepeat' | 'acceptTerms';

/** Field → message (only fields with a problem are present). */
export type SignupErrors = Partial<Record<SignupField, string>>;

const E = SIGNUP_STRINGS.errors;

/** Same idea as the backend: trim and collapse inner whitespace. */
export function normalizeName(name: string): string {
  return name.trim().replace(/\s+/g, ' ');
}

export function normalizeEmail(email: string): string {
  return email.trim().toLowerCase();
}

/**
 * Pragmatic check (one @, a dot in the domain, no spaces). Deliberately STRICTER than
 * the server (`email:rfc` also accepts `ana@localhost`): a parent's real address always
 * has a domain with a dot, and a typo is caught before the request. The server stays
 * the authority for everything this check lets through.
 */
export function isValidEmail(email: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

export function isStrongPassword(password: string): boolean {
  return (
    password.length >= PASSWORD_MIN_LENGTH &&
    /\p{Lu}/u.test(password) &&
    /\p{Ll}/u.test(password) &&
    /\p{N}/u.test(password)
  );
}

/** Client-side validation; an empty object means the form may be sent. */
export function validateSignup(form: SignupForm): SignupErrors {
  const errors: SignupErrors = {};
  const name = normalizeName(form.name);
  if (name.length === 0) errors.name = E.nameRequired;
  else if ([...name].length > NAME_MAX_LENGTH) errors.name = E.nameTooLong;

  if (!isValidEmail(normalizeEmail(form.email))) errors.email = E.emailInvalid;

  if (!isStrongPassword(form.password)) errors.password = E.passwordWeak;
  else if (form.password !== form.passwordRepeat) errors.passwordRepeat = E.passwordMismatch;

  if (!form.acceptTerms) errors.acceptTerms = E.termsRequired;
  return errors;
}

/** The device's IANA timezone (e.g. "Europe/Ljubljana"); undefined → server default. */
export function deviceTimezone(): string | undefined {
  try {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    return typeof zone === 'string' && zone.length > 0 ? zone : undefined;
  } catch {
    return undefined;
  }
}

/** `POST /api/register` body from a validated form. */
export function signupBody(
  form: SignupForm,
  deviceName: string,
  /** Usually `deviceTimezone()`; undefined → the server uses Europe/Ljubljana. */
  timezone: string | undefined,
): RegisterParentRequest {
  const body: RegisterParentRequest = {
    name: normalizeName(form.name),
    email: normalizeEmail(form.email),
    password: form.password,
    password_confirmation: form.passwordRepeat,
    accept_terms: true,
    device_name: deviceName,
  };
  if (timezone !== undefined) body.timezone = timezone;
  return body;
}

export interface SignupFailure {
  /** Per-field messages to show under the inputs. */
  fields: SignupErrors;
  /** Message for the form as a whole (429, offline, unknown 422 field, server error). */
  general: string | null;
}

function validationErrors(data: unknown): Record<string, string[]> {
  if (typeof data !== 'object' || data === null || !('errors' in data)) return {};
  const { errors } = data as ValidationErrorBody;
  if (typeof errors !== 'object' || errors === null) return {};
  return errors;
}

function errorCodes(data: unknown): Partial<Record<string, RegisterErrorCode>> {
  if (typeof data !== 'object' || data === null || !('codes' in data)) return {};
  const { codes } = data as RegisterErrorBody;
  if (typeof codes !== 'object' || codes === null) return {};
  return codes;
}

/** Server code → form field + Slovenian message (timezone is handled as a general error). */
const CODE_TO_FIELD: Partial<Record<RegisterErrorCode, { field: SignupField; message: string }>> = {
  name_invalid: { field: 'name', message: E.nameInvalid },
  email_invalid: { field: 'email', message: E.emailInvalid },
  email_taken: { field: 'email', message: E.emailTaken },
  password_weak: { field: 'password', message: E.passwordWeak },
  password_mismatch: { field: 'passwordRepeat', message: E.passwordMismatch },
  terms_required: { field: 'acceptTerms', message: E.termsRequired },
};

/** Did the server reject (only or also) the timezone? */
export function isTimezoneRejection(err: unknown): boolean {
  if (!(err instanceof ApiError) || err.status !== 422) return false;
  return errorCodes(err.data).timezone !== undefined || validationErrors(err.data).timezone !== undefined;
}

/** Maps a failed `api.register` call to what the form shows (in the app language). */
export function mapSignupError(err: unknown): SignupFailure {
  if (!(err instanceof ApiError)) return { fields: {}, general: E.offline };

  if (err.status === 429) {
    const seconds = err.retryAfterSeconds;
    return {
      fields: {},
      general: seconds !== null && seconds > 0 ? E.tooManyAttemptsMinutes(Math.ceil(seconds / 60)) : E.tooManyAttempts,
    };
  }

  if (err.status === 422) {
    const server = validationErrors(err.data);
    const codes = errorCodes(err.data);
    const fields: SignupErrors = {};

    // 1) Machine-readable codes (PR #25) — preferred.
    for (const code of Object.values(codes)) {
      if (code === undefined) continue;
      const mapped = CODE_TO_FIELD[code];
      if (mapped) fields[mapped.field] = mapped.message;
    }

    // 2) Fallback for fields without a code: Laravel's English messages.
    if (server.name && codes.name === undefined) {
      fields.name = server.name.some((m) => /greater than|must not/i.test(m)) ? E.nameTooLong : E.nameRequired;
    }
    if (server.email && codes.email === undefined) {
      fields.email = server.email.some((m) => /taken/i.test(m)) ? E.emailTaken : E.emailInvalid;
    }
    if (server.password && codes.password === undefined) {
      const mismatchOnly = server.password.every((m) => /confirmation/i.test(m));
      if (mismatchOnly) fields.passwordRepeat = E.passwordMismatch;
      else fields.password = E.passwordWeak;
    }
    if (server.accept_terms && codes.accept_terms === undefined) fields.acceptTerms = E.termsRequired;

    const timezoneFailed = codes.timezone !== undefined || server.timezone !== undefined;
    const general = timezoneFailed ? E.timezoneInvalid : Object.keys(fields).length === 0 ? E.failed : null;
    return { fields, general };
  }

  return { fields: {}, general: E.failed };
}

/**
 * Send the sign-up and store the token exactly like a login. Returns the payload for
 * `appStore.signIn()` — AppNavigator then routes the parent to the dashboard, whose
 * empty state offers "Dodaj otroka". Throws what `api.register` throws (map it with
 * `mapSignupError`).
 */
export async function performSignup(body: RegisterParentRequest): Promise<SignInPayload> {
  let response: RegisterResponse;
  try {
    response = await api.register(body);
  } catch (err) {
    // The server maps device aliases itself; if it still refuses the device's zone,
    // retry ONCE without it (the family gets Europe/Ljubljana, changeable in settings).
    if (body.timezone === undefined || !isTimezoneRejection(err)) throw err;
    const withoutTimezone: RegisterParentRequest = { ...body };
    delete withoutTimezone.timezone;
    response = await api.register(withoutTimezone);
  }
  await saveAuthToken(response.token);
  return {
    token: response.token,
    user: response.user,
    pet: response.pet,
    awaitingContract: response.awaiting_contract,
  };
}
