/**
 * Submit the child's signature (M1-07b) and classify the result for the UI.
 *
 *  - 201 → `signed` with the new state (the pet is born)
 *  - 409 `contract_already_signed` → `already_signed`: continue with the state from
 *    the body (or a fresh `GET /api/child/pet` if the body has none)
 *  - 422 → `invalid` (ask for a new signature)
 *  - 423 → `locked` with the server's reason (hard_stopped, inactive, …)
 *  - network error, 5xx, 429 → `retryable`
 *  - anything else (401 is handled by the session's unauthorized handler, 403/404) → `failed`
 */

import { ApiError, api, type ChildPetState } from '@/api/client';

export type SignContractOutcome =
  | { kind: 'signed'; state: ChildPetState }
  | { kind: 'already_signed'; state: ChildPetState }
  | { kind: 'invalid' }
  | { kind: 'locked'; reason: string | null }
  | { kind: 'retryable' }
  | { kind: 'failed'; status: number };

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

/** The `state` key of a child action error body, when it looks like one. */
export function stateFromErrorBody(data: unknown): ChildPetState | null {
  if (!isRecord(data) || !isRecord(data.state)) return null;
  const { pet, steps } = data.state;
  if (!isRecord(pet) || typeof pet.id !== 'number' || !isRecord(steps)) return null;
  return data.state as unknown as ChildPetState;
}

function reasonFromErrorBody(data: unknown): string | null {
  return isRecord(data) && typeof data.reason === 'string' ? data.reason : null;
}

export async function submitSignature(svgPath: string): Promise<SignContractOutcome> {
  try {
    const response = await api.signContract({ signature_format: 'svg_path', signature: svgPath });
    return { kind: 'signed', state: response.state };
  } catch (error) {
    if (!(error instanceof ApiError)) return { kind: 'retryable' };

    switch (error.status) {
      case 409: {
        const state = stateFromErrorBody(error.data);
        if (state) return { kind: 'already_signed', state };
        try {
          return { kind: 'already_signed', state: await api.getChildPet() };
        } catch {
          return { kind: 'retryable' };
        }
      }
      case 422:
        return { kind: 'invalid' };
      case 423:
        return { kind: 'locked', reason: reasonFromErrorBody(error.data) };
      case 429:
        return { kind: 'retryable' };
      default:
        return error.status >= 500 ? { kind: 'retryable' } : { kind: 'failed', status: error.status };
    }
  }
}
