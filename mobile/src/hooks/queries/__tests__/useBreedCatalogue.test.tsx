/**
 * M5-R06-02: the picker catalogue query — server data, fallback dogs on failure.
 */
import { act, renderHook, waitFor } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { ApiError, api } from '@/api/client';
import { breedCatalogueKey, CATALOGUE_LOADING_LIMIT_MS, useBreedCatalogue } from '@/hooks/queries/useBreedCatalogue';
import { FALLBACK_CATALOGUE } from '@/modules/petProfile/picker';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getBreedCatalogue: jest.fn() } };
});

const getBreedCatalogue = api.getBreedCatalogue as jest.Mock;

function setup(options: Parameters<typeof useBreedCatalogue>[0] = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { gcTime: Infinity, retryDelay: 0 } } });
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return renderHook(() => useBreedCatalogue(options), { wrapper });
}

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

const CATS_ON = {
  species: ['dog', 'cat'],
  breeds: [
    { breed: 'mutt', slug: 'mutt', species: 'dog', premium: false, free_plan_allowed: true, challenge_allowed: false, label_key: 'breeds.mutt', search_keywords: [], sort_order: 0 },
    { breed: 'domestic_cat', slug: 'domestic-cat', species: 'cat', premium: false, free_plan_allowed: true, challenge_allowed: false, label_key: 'breeds.domestic_cat', search_keywords: [], sort_order: 0 },
  ],
};

describe('useBreedCatalogue', () => {
  beforeEach(() => getBreedCatalogue.mockReset());

  it('loading → null, then the server catalogue (server cats on: dogs and cats)', async () => {
    getBreedCatalogue.mockResolvedValue(CATS_ON);
    const { result } = setup();
    expect(result.current.catalogue).toBeNull();
    // M5-R06-09: this build can show cats (CAT_UI_READY on).
    await waitFor(() => expect(result.current.catalogue?.species).toEqual(['dog', 'cat']));
    expect(result.current.isFallback).toBe(false);
    // This build's features: species_cat is declared.
    expect(getBreedCatalogue).toHaveBeenCalledWith(['behaviour_events', 'training', 'species_cat'], expect.anything());
  });

  it('server cats off (PETPREP_CATS_ENABLED=false): dogs only, no fallback, no error', async () => {
    getBreedCatalogue.mockResolvedValue({ species: ['dog'], breeds: CATS_ON.breeds.filter((b) => b.species === 'dog') });
    const { result } = setup();
    await waitFor(() => expect(result.current.catalogue?.species).toEqual(['dog']));
    expect(result.current.catalogue?.breeds.map((b) => b.breed)).toEqual(['mutt']);
    expect(result.current.isFallback).toBe(false);
  });

  it('server error → after one retry the fallback dogs, so dog onboarding never breaks', async () => {
    getBreedCatalogue.mockRejectedValue(new ApiError('x', 500));
    const { result } = setup();
    await waitFor(() => expect(result.current.catalogue).toBe(FALLBACK_CATALOGUE));
    expect(getBreedCatalogue).toHaveBeenCalledTimes(2);
    expect(result.current.isFallback).toBe(true);
  });

  it('offline → the fallback dogs', async () => {
    getBreedCatalogue.mockRejectedValue(new TypeError('Network request failed'));
    const { result } = setup();
    await waitFor(() => expect(result.current.catalogue).toBe(FALLBACK_CATALOGUE));
  });

  it('a 4xx is not retried; a malformed body falls back too', async () => {
    getBreedCatalogue.mockRejectedValueOnce(new ApiError('x', 403));
    const first = setup();
    await waitFor(() => expect(first.result.current.catalogue).toBe(FALLBACK_CATALOGUE));
    expect(getBreedCatalogue).toHaveBeenCalledTimes(1);

    getBreedCatalogue.mockResolvedValueOnce({ unexpected: true });
    const second = setup();
    await waitFor(() => expect(second.result.current.catalogue).toBe(FALLBACK_CATALOGUE));
    expect(second.result.current.isFallback).toBe(true);
  });

  it('QA #94 m1: a hung request shows the fallback after 5 s; a late answer still replaces it', async () => {
    jest.useFakeTimers();
    try {
      let answer: (body: unknown) => void = () => undefined;
      getBreedCatalogue.mockImplementation(() => new Promise((resolve) => (answer = resolve)));
      const { result } = setup();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(CATALOGUE_LOADING_LIMIT_MS - 100);
      });
      expect(result.current.catalogue).toBeNull();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(200);
      });
      expect(result.current.catalogue).toBe(FALLBACK_CATALOGUE);
      expect(result.current.isFallback).toBe(true);
      await act(async () => {
        answer(CATS_ON);
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(result.current.isFallback).toBe(false);
      expect(result.current.catalogue?.species).toEqual(['dog', 'cat']); // the late answer (server cats on) wins
    } finally {
      jest.useRealTimers();
    }
  });

  it('disabled → no request', async () => {
    const { result } = setup({ enabled: false });
    await flush();
    expect(getBreedCatalogue).not.toHaveBeenCalled();
    expect(result.current.catalogue).toBeNull();
  });

  it('the cache key depends on the declared features', () => {
    expect(breedCatalogueKey(['training', 'behaviour_events'])).toEqual(breedCatalogueKey(['behaviour_events', 'training']));
    expect(breedCatalogueKey(['behaviour_events', 'training', 'species_cat'])).not.toEqual(breedCatalogueKey(['behaviour_events', 'training']));
  });
});
