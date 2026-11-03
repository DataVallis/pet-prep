/** TanStack Query keys of »Želje« (M6-W04 / W05) — kept apart so push routing imports no UI. */

/** `GET /api/child/wishes` (child catalogue). */
export const CHILD_WISHES_KEY = ['child', 'wishes'] as const;
/** `GET /api/parent/wishes` (parent list). */
export const PARENT_WISHES_KEY = ['parent', 'wishes'] as const;
/** Store products (prices) of the wish items for the identified parent. */
/** M6-W03: `PetUpdated.event_type` values after which the wish lists are refetched (item content ready). */
export const WISH_REFRESH_EVENTS: readonly string[] = ['item_media_ready'];

export function refreshesWishes(eventType: string | null | undefined): boolean {
  return typeof eventType === 'string' && WISH_REFRESH_EVENTS.includes(eventType);
}

export const wishProductsKey = (parentId: number | null, productIds: readonly string[]) => ['parent', 'wishProducts', parentId, productIds.join(',')] as const;
