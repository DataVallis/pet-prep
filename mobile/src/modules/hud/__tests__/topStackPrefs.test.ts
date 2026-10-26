/** David 2026-10-26: the collapsed / expanded top stack is remembered on the device. */
import {
  DEFAULT_TOP_STACK_PREFS,
  parseTopStackPrefs,
  setTopStackPrefsStoreForTests,
  TOP_STACK_PREFS_KEY,
  useTopStackPrefs,
  type TopStackPrefsStore,
} from '@/modules/hud/topStackPrefs';

function memoryStore(initial: Record<string, string> = {}) {
  const data = { ...initial };
  const store: TopStackPrefsStore & { data: Record<string, string> } = {
    data,
    getItemAsync: jest.fn(async (key: string) => data[key] ?? null),
    setItemAsync: jest.fn(async (key: string, value: string) => {
      data[key] = value;
    }),
  };
  return store;
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('topStackPrefs', () => {
  it('parses tolerantly', () => {
    expect(parseTopStackPrefs(null)).toEqual(DEFAULT_TOP_STACK_PREFS);
    expect(parseTopStackPrefs('nonsense')).toEqual(DEFAULT_TOP_STACK_PREFS);
    expect(parseTopStackPrefs('[1]')).toEqual({ collapsed: false, seen: [] });
    expect(parseTopStackPrefs(JSON.stringify({ collapsed: true, seen: ['a:0', 3] }))).toEqual({ collapsed: true, seen: ['a:0'] });
  });

  it('reads the stored choice once and writes every change', async () => {
    const store = memoryStore({ [TOP_STACK_PREFS_KEY]: JSON.stringify({ collapsed: true, seen: ['take-out:0'] }) });
    setTopStackPrefsStoreForTests(store);
    expect(useTopStackPrefs.getState()).toMatchObject({ collapsed: false, hydrated: false });

    await useTopStackPrefs.getState().hydrate();
    expect(useTopStackPrefs.getState()).toMatchObject({ collapsed: true, seen: ['take-out:0'], hydrated: true });
    await useTopStackPrefs.getState().hydrate();
    expect(store.getItemAsync).toHaveBeenCalledTimes(1);

    useTopStackPrefs.getState().setCollapsed(false);
    await flush();
    expect(JSON.parse(store.data[TOP_STACK_PREFS_KEY])).toEqual({ collapsed: false, seen: ['take-out:0'] });

    // Unchanged alert tokens → no write.
    const writes = (store.setItemAsync as jest.Mock).mock.calls.length;
    useTopStackPrefs.getState().setSeen(['take-out:0']);
    await flush();
    expect(store.setItemAsync).toHaveBeenCalledTimes(writes);
  });

  it("a tap before the store answers wins over the stored value", async () => {
    const store = memoryStore({ [TOP_STACK_PREFS_KEY]: JSON.stringify({ collapsed: false, seen: [] }) });
    setTopStackPrefsStoreForTests(store);
    const reading = useTopStackPrefs.getState().hydrate();
    useTopStackPrefs.getState().setCollapsed(true);
    await reading;
    expect(useTopStackPrefs.getState()).toMatchObject({ collapsed: true, hydrated: true });
  });

  it('a broken store = expanded, and the toggle still works', async () => {
    setTopStackPrefsStoreForTests({
      getItemAsync: () => Promise.reject(new Error('keychain')),
      setItemAsync: () => Promise.reject(new Error('keychain')),
    });
    await useTopStackPrefs.getState().hydrate();
    expect(useTopStackPrefs.getState()).toMatchObject({ collapsed: false, hydrated: true });
    useTopStackPrefs.getState().setCollapsed(true);
    await flush();
    expect(useTopStackPrefs.getState().collapsed).toBe(true);
  });
});
