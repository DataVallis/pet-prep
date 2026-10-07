/**
 * Purchase / restore outcome → the parent-facing message (i18n namespace `purchases`).
 * Keep the outcome (a code) in component state and call this at render, so the text
 * follows a language switch (I18N.md "Text kept in component state").
 */

import { strings } from '@/i18n/strings';
import type { PurchaseOutcome, RestoreOutcome } from './purchases';

export const PURCHASE_STRINGS = strings('purchases', 'result');
export const RESTORE_STRINGS = strings('purchases', 'restore');

export function purchaseOutcomeMessage(outcome: PurchaseOutcome): string {
  switch (outcome.status) {
    case 'success':
      return outcome.serverConfirmed ? PURCHASE_STRINGS.success : PURCHASE_STRINGS.successPending;
    case 'cancelled':
      return PURCHASE_STRINGS.cancelled;
    case 'pending':
      return PURCHASE_STRINGS.pending;
    case 'already_owned':
      return PURCHASE_STRINGS.alreadyOwned;
    case 'network':
      return PURCHASE_STRINGS.network;
    case 'not_allowed':
      return PURCHASE_STRINGS.notAllowed;
    case 'unavailable':
      return PURCHASE_STRINGS.unavailable;
    case 'store_problem':
      return PURCHASE_STRINGS.storeProblem;
  }
}

export function restoreOutcomeMessage(outcome: RestoreOutcome): string {
  switch (outcome.status) {
    case 'restored':
      return RESTORE_STRINGS.restored;
    case 'nothing':
      return RESTORE_STRINGS.nothing;
    default:
      return purchaseOutcomeMessage(outcome);
  }
}
