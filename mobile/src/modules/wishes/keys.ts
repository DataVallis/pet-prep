/** TanStack Query keys of »Želje« (M6-W04 / W05) — kept apart so push routing imports no UI. */

/** `GET /api/child/wishes` (child catalogue). */
export const CHILD_WISHES_KEY = ['child', 'wishes'] as const;
/** `GET /api/parent/wishes` (parent list). */
export const PARENT_WISHES_KEY = ['parent', 'wishes'] as const;
/** Store products (prices) of the wish items for the identified parent. */
export const wishProductsKey = (parentId: number | null, productIds: readonly string[]) => ['parent', 'wishProducts', parentId, productIds.join(',')] as const;
