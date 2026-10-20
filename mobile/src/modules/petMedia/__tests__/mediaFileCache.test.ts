import { act, renderHook } from '@testing-library/react-native';

import {
  MAX_CACHE_BYTES,
  cacheNameFor,
  clearMediaCache,
  forgetLocalMedia,
  localMediaUri,
  setMediaFileStoreForTests,
  type CachedFileInfo,
  type MediaFileStore,
} from '@/modules/petMedia/mediaFileCache';
import { useLocalMediaUri } from '@/modules/petMedia/hooks';

const URL_A1 = 'https://api.petprep.si/api/media/12?expires=1000&v=abc123&signature=s1';
const URL_A1_RESIGNED = 'https://api.petprep.si/api/media/12?expires=2800&v=abc123&signature=s2';
const URL_A2 = 'https://api.petprep.si/api/media/12?expires=2800&v=def456&signature=s3';
const URL_HISTORY = 'https://api.petprep.si/api/media/history/7?expires=1000&v=0f0f&signature=s4';

interface Pending {
  url: string;
  name: string;
  resolve: (size: number) => void;
  reject: (error: Error) => void;
}

function fakeStore(sizeOf: (url: string) => number = () => 1000) {
  const files = new Map<string, CachedFileInfo>();
  const pending: Pending[] = [];
  let clock = 0;
  const store: MediaFileStore = {
    exists: (name) => files.has(name),
    uri: (name) => `file:///cache/pet-media/${name}`,
    download: (url, name) =>
      new Promise<number>((resolve, reject) => {
        pending.push({
          url,
          name,
          resolve: (size) => {
            clock += 1;
            files.set(name, { name, size, modified: clock });
            resolve(size);
          },
          reject,
        });
      }),
    remove: (name) => {
      files.delete(name);
    },
    list: () => [...files.values()],
    clear: () => files.clear(),
  };
  /** Finish the oldest pending download. */
  const finish = async (ok = true) => {
    const job = pending.shift();
    if (!job) throw new Error('nothing pending');
    if (ok) job.resolve(sizeOf(job.url));
    else job.reject(new Error('network'));
    // let the then/finally chain run
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
  };
  return { store, files, pending, finish };
}

afterEach(() => {
  setMediaFileStoreForTests(null);
});

describe('cacheNameFor', () => {
  it('names a file by media path + version, not by signature', () => {
    expect(cacheNameFor(URL_A1)).toBe('media_12_abc123');
    expect(cacheNameFor(URL_A1_RESIGNED)).toBe('media_12_abc123');
    expect(cacheNameFor(URL_A2)).toBe('media_12_def456');
    expect(cacheNameFor(URL_HISTORY)).toBe('media_history_7_0f0f');
  });

  it('never caches a URL that is not ours or has no version', () => {
    expect(cacheNameFor('https://example.com/video.mp4')).toBeNull();
    expect(cacheNameFor('https://api.petprep.si/api/media/12?expires=1&signature=x')).toBeNull();
    expect(cacheNameFor('https://api.petprep.si/api/media/../12?v=abc')).toBeNull();
  });
});

describe('localMediaUri', () => {
  it('serves the remote URL first, downloads once, then the local copy — also after a re-sign', async () => {
    const fs = fakeStore();
    setMediaFileStoreForTests(fs.store);

    expect(localMediaUri(URL_A1, 'video')).toBe(URL_A1);
    expect(localMediaUri(URL_A1_RESIGNED, 'video')).toBe(URL_A1_RESIGNED); // same file, still downloading
    expect(fs.pending).toHaveLength(1);
    await fs.finish();

    expect(localMediaUri(URL_A1_RESIGNED, 'video')).toBe('file:///cache/pet-media/media_12_abc123.mp4');
    expect(fs.pending).toHaveLength(0);
  });

  it('a new version is a new download and replaces the old copy', async () => {
    const fs = fakeStore();
    setMediaFileStoreForTests(fs.store);
    localMediaUri(URL_A1, 'video');
    await fs.finish();
    expect(localMediaUri(URL_A2, 'video')).toBe(URL_A2);
    await fs.finish();
    expect([...fs.files.keys()]).toEqual(['media_12_def456.mp4']);
  });

  it('runs at most two downloads at a time', async () => {
    const fs = fakeStore();
    setMediaFileStoreForTests(fs.store);
    localMediaUri('https://api.petprep.si/api/media/1?v=a', 'video');
    localMediaUri('https://api.petprep.si/api/media/2?v=a', 'video');
    localMediaUri('https://api.petprep.si/api/media/3?v=a', 'video');
    expect(fs.pending).toHaveLength(2);
    await fs.finish();
    expect(fs.pending).toHaveLength(2);
    expect(fs.pending[1].name).toBe('media_3_a.mp4');
  });

  it('a failed download stays remote and is tried again next time', async () => {
    const fs = fakeStore();
    setMediaFileStoreForTests(fs.store);
    localMediaUri(URL_A1, 'video');
    await fs.finish(false);
    expect(localMediaUri(URL_A1, 'video')).toBe(URL_A1);
    expect(fs.pending).toHaveLength(1);
  });

  it('deletes the oldest copies over the size cap, never the one just stored', async () => {
    const half = Math.floor(MAX_CACHE_BYTES / 2) + 1;
    const fs = fakeStore(() => half);
    setMediaFileStoreForTests(fs.store);
    localMediaUri('https://api.petprep.si/api/media/1?v=a', 'video');
    await fs.finish();
    localMediaUri('https://api.petprep.si/api/media/2?v=a', 'video');
    await fs.finish();
    expect([...fs.files.keys()]).toEqual(['media_2_a.mp4']);
  });

  it('forgetLocalMedia deletes a broken copy; clearMediaCache deletes everything, also a late download', async () => {
    const fs = fakeStore();
    setMediaFileStoreForTests(fs.store);
    localMediaUri(URL_A1, 'video');
    await fs.finish();
    forgetLocalMedia(URL_A1_RESIGNED, 'video');
    expect(fs.files.size).toBe(0);

    localMediaUri(URL_HISTORY, 'video');
    clearMediaCache(); // logout while it downloads
    await fs.finish();
    expect(fs.files.size).toBe(0);
  });

  it('without the native module everything stays remote', () => {
    setMediaFileStoreForTests(null);
    expect(localMediaUri(URL_A1, 'video')).toBe(URL_A1);
    expect(localMediaUri(null, 'video')).toBeNull();
    expect(() => clearMediaCache()).not.toThrow();
  });
});

describe('useLocalMediaUri', () => {
  it('keeps the remote URL while shown (no swap mid-play) and uses the copy on the next mount', async () => {
    const fs = fakeStore();
    setMediaFileStoreForTests(fs.store);
    const first = renderHook(({ url }: { url: string | null }) => useLocalMediaUri(url, 'video'), { initialProps: { url: URL_A1 } });
    expect(first.result.current.uri).toBe(URL_A1);
    await act(async () => {
      await fs.finish();
    });
    first.rerender({ url: URL_A1_RESIGNED });
    expect(first.result.current.uri).toBe(URL_A1_RESIGNED); // same file, decided at mount
    first.unmount();

    const second = renderHook(() => useLocalMediaUri(URL_A1_RESIGNED, 'video'));
    expect(second.result.current.uri).toBe('file:///cache/pet-media/media_12_abc123.mp4');

    // A broken copy: handled, deleted, back to the remote URL.
    let handled = false;
    act(() => {
      handled = second.result.current.onLocalError();
    });
    expect(handled).toBe(true);
    expect(second.result.current.uri).toBe(URL_A1_RESIGNED);
    expect(fs.files.size).toBe(0);
    expect(second.result.current.onLocalError()).toBe(false);
  });
});
