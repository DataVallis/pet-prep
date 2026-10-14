/**
 * Parental gate (David 2026-10-14; Apple App Store guideline 1.3 "Kids Category" style):
 * before a child-side action that only a parent should take (sign the child out, later the
 * profile switch), the app asks an adult question — a random multiplication — answered
 * with the numeric keyboard. Small children can't read or solve it; no hint is shown.
 *
 * Rules: a new random question every time the gate opens and after every wrong answer
 * (no trial-and-error on one question); after `MAX_ATTEMPTS` wrong answers the gate closes.
 * Pure functions — `useParentalGate` holds the state.
 */

export const MAX_ATTEMPTS = 3;
/** Factors 4–9: always a two-digit product (16–81), no ×1 / ×2 / ×3 freebies. */
export const MIN_FACTOR = 4;
export const MAX_FACTOR = 9;

export interface GateQuestion {
  a: number;
  b: number;
}

/** `random` returns [0, 1) like `Math.random` (injectable for tests). */
export function makeQuestion(random: () => number = Math.random, previous?: GateQuestion | null): GateQuestion {
  const factor = () => MIN_FACTOR + Math.floor(random() * (MAX_FACTOR - MIN_FACTOR + 1));
  let next: GateQuestion = { a: factor(), b: factor() };
  // Never the same question twice in a row (a few tries; the random source may be fixed in tests).
  for (let i = 0; i < 5 && previous && next.a === previous.a && next.b === previous.b; i += 1) {
    next = { a: factor(), b: factor() };
  }
  return next;
}

/** True when the typed text is the product (surrounding spaces forgiven, digits only). */
export function isCorrectAnswer(question: GateQuestion, typed: string): boolean {
  const text = typed.trim();
  return /^[0-9]{1,3}$/.test(text) && Number(text) === question.a * question.b;
}
