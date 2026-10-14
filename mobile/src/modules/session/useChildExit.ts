/**
 * The child's way out (HUD logout icon, »Starš?« on lock / error screens, the contract's
 * »Odjava«), M5-F12 + M2-11 (David 2026-10-14):
 * - a SHARED child session (a parent opened it on this phone with »Igraj kot«): the normal
 *   parent login opens — the parent's real sign-in is the gate; »Nazaj« returns to the child;
 * - the child's OWN phone (PIN login): the parental gate, then `logout()` (as in PR #233).
 */

import { useParentalGate } from '@/modules/parentalGate/useParentalGate';
import { useAppStore } from '@/store/appStore';
import { logout } from './logout';
import { openParentLogin } from './profileSwitch';

export function useChildExit() {
  const shared = useAppStore((s) => s.sharedSession);
  const gate = useParentalGate({ onPass: () => void logout() });
  return {
    /** A shared session: the exit leads to the parent login (label »Nazaj k staršu«). */
    shared,
    open: shared ? openParentLogin : gate.open,
    /** Render `<ParentalGate {...gateProps} />` (stays closed in a shared session). */
    gateProps: gate.props,
  };
}
