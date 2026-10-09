/**
 * Manual Jest mock for expo-clipboard (child PIN paste, M2-02). Tests script the text with
 * `(Clipboard.getStringAsync as jest.Mock).mockResolvedValueOnce('123 456')`; the default is
 * an empty clipboard. `pinClipboard.ts` also needs `setClipboardProbeForTests(() => true)`.
 */
export const getStringAsync = jest.fn((): Promise<string> => Promise.resolve(''));
export const setStringAsync = jest.fn((): Promise<boolean> => Promise.resolve(true));
export const hasStringAsync = jest.fn((): Promise<boolean> => Promise.resolve(true));
