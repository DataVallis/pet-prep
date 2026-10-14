/**
 * M5-R21: colour labels come from `GET /api/breeds` in the request language. The request
 * carries the app language (`Accept-Language`) and the language is part of the query key, so
 * a language switch loads the labels again in the new language.
 */
import { act, renderHook, waitFor } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import * as SecureStore from 'expo-secure-store';
import type { ReactNode } from 'react';

import { breedCatalogueKey, useBreedCatalogue } from '@/hooks/queries/useBreedCatalogue';
import { i18n } from '@/i18n';

const LABELS: Record<string, string> = { 'sl-SI': 'Črno-bel', 'en-GB': 'Black and white' };

function catalogueFor(lang: string) {
  return {
    species: ['dog'],
    breeds: [
      { breed: 'mutt', slug: 'mutt', species: 'dog', premium: false, free_plan_allowed: true, challenge_allowed: false, label_key: 'breeds.mutt', search_keywords: [], sort_order: 0, colours: [] },
      {
        breed: 'border_collie',
        slug: 'border-collie',
        species: 'dog',
        premium: true,
        free_plan_allowed: false,
        challenge_allowed: true,
        label_key: 'breeds.border_collie',
        search_keywords: [],
        sort_order: 10,
        colours: [{ key: 'black-white', label: LABELS[lang] ?? '?' }],
      },
    ],
  };
}

/** A server that answers in the language the app asked for. */
function mockServer() {
  const fetchMock = jest.fn((_url: string, init: { headers: Record<string, string> }) =>
    Promise.resolve({
      ok: true,
      status: 200,
      headers: { get: () => null },
      json: () => Promise.resolve(catalogueFor(init.headers['Accept-Language'])),
    }),
  );
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  return fetchMock;
}

const label = (catalogue: ReturnType<typeof useBreedCatalogue>['catalogue']) =>
  catalogue?.breeds.find((b) => b.breed === 'border_collie')?.colours[0]?.label;

describe('useBreedCatalogue — language of the colour labels (M5-R21)', () => {
  beforeEach(async () => {
    (SecureStore.getItemAsync as jest.Mock).mockResolvedValue('parent-token');
    await i18n.changeLanguage('sl');
  });

  afterAll(async () => {
    await i18n.changeLanguage('sl');
  });

  it('the language is part of the query key', () => {
    expect(breedCatalogueKey(['training'], 'sl-SI')).not.toEqual(breedCatalogueKey(['training'], 'en-GB'));
    expect(breedCatalogueKey(['training'])).toEqual(breedCatalogueKey(['training'], 'sl-SI'));
  });

  it('asks in the app language and loads the labels again after a language switch', async () => {
    const fetchMock = mockServer();
    const client = new QueryClient({ defaultOptions: { queries: { gcTime: Infinity, retry: false } } });
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
    const { result } = renderHook(() => useBreedCatalogue(), { wrapper });

    await waitFor(() => expect(label(result.current.catalogue)).toBe('Črno-bel'));
    expect(fetchMock.mock.calls[0][1].headers['Accept-Language']).toBe('sl-SI');
    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/api\/breeds\?/);

    await act(async () => {
      await i18n.changeLanguage('en');
    });
    await waitFor(() => expect(label(result.current.catalogue)).toBe('Black and white'));
    expect(fetchMock.mock.calls.at(-1)?.[1].headers['Accept-Language']).toBe('en-GB');
  });
});
