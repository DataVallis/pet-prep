/**
 * M3-04 / M3-05: the native adapters ask for step READ access only and read one number.
 * The libraries are mocked (they are native and can't run in Jest).
 */
import { Linking } from 'react-native';

jest.mock('@kingstinct/react-native-healthkit', () => ({
  AuthorizationRequestStatus: { unknown: 0, shouldRequest: 1, unnecessary: 2 },
  getRequestStatusForAuthorization: jest.fn(async () => 1),
  isHealthDataAvailableAsync: jest.fn(async () => true),
  isProtectedDataAvailable: jest.fn(() => true),
  queryStatisticsForQuantity: jest.fn(async () => ({ sumQuantity: { unit: 'count', quantity: 5321.7 }, sources: [] })),
  requestAuthorization: jest.fn(async () => true),
}));

jest.mock('react-native-health-connect', () => ({
  SdkAvailabilityStatus: { SDK_UNAVAILABLE: 1, SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED: 2, SDK_AVAILABLE: 3 },
  getSdkStatus: jest.fn(async () => 3),
  initialize: jest.fn(async () => true),
  getGrantedPermissions: jest.fn(async () => []),
  requestPermission: jest.fn(async () => []),
  aggregateRecord: jest.fn(async () => ({ recordType: 'Steps', COUNT_TOTAL: 812, dataOrigins: [] })),
  openHealthConnectSettings: jest.fn(),
}));

import * as HK from '@kingstinct/react-native-healthkit';
import * as HC from 'react-native-health-connect';

import { createHealthConnectAdapter, HC_REQUESTED_KEY } from '@/modules/steps/health/healthConnectAdapter';
import { createHealthKitAdapter, HK_STEP_COUNT } from '@/modules/steps/health/healthKitAdapter';

const START = new Date('2026-10-03T22:00:00Z');
const END = new Date('2026-10-04T10:00:00Z');

function memoryStore() {
  const data: Record<string, string> = {};
  return {
    data,
    getItemAsync: jest.fn(async (key: string) => data[key] ?? null),
    setItemAsync: jest.fn(async (key: string, value: string) => {
      data[key] = value;
    }),
  };
}

beforeEach(() => jest.clearAllMocks());

describe('HealthKit adapter (iOS)', () => {
  it('asks for READ access to step count only — no write types, no other data', async () => {
    const hk = createHealthKitAdapter();
    expect(hk.source).toBe('healthkit');
    expect(await hk.access()).toBe('undetermined');
    (HK.getRequestStatusForAuthorization as jest.Mock).mockResolvedValueOnce(2);
    expect(await hk.requestAccess()).toBe('connected');
    expect(HK.requestAuthorization).toHaveBeenCalledWith({ toRead: [HK_STEP_COUNT] });
    const [request] = (HK.requestAuthorization as jest.Mock).mock.calls[0] as [Record<string, unknown>];
    expect(Object.keys(request)).toEqual(['toRead']);
  });

  it('reads today’s de-duplicated sum (statistics query, cumulativeSum) for the given window', async () => {
    const steps = await createHealthKitAdapter().readSteps(START, END);
    expect(steps).toBe(5321);
    expect(HK.queryStatisticsForQuantity).toHaveBeenCalledWith(HK_STEP_COUNT, ['cumulativeSum'], {
      unit: 'count',
      filter: { date: { startDate: START, endDate: END } },
    });
  });

  it('no samples (denied read or no walking) → 0; locked phone → throws', async () => {
    (HK.queryStatisticsForQuantity as jest.Mock).mockResolvedValueOnce({ sources: [] });
    expect(await createHealthKitAdapter().readSteps(START, END)).toBe(0);
    (HK.isProtectedDataAvailable as jest.Mock).mockReturnValueOnce(false);
    await expect(createHealthKitAdapter().readSteps(START, END)).rejects.toThrow('locked');
  });

  it('availability follows isHealthDataAvailable', async () => {
    (HK.isHealthDataAvailableAsync as jest.Mock).mockResolvedValueOnce(false);
    expect(await createHealthKitAdapter().availability()).toBe('unavailable');
  });
});

describe('Health Connect adapter (Android)', () => {
  it('maps the SDK status: available / needs update (install or update) / unavailable', async () => {
    const hc = createHealthConnectAdapter(memoryStore());
    expect(await hc.availability()).toBe('available');
    (HC.getSdkStatus as jest.Mock).mockResolvedValueOnce(2);
    expect(await hc.availability()).toBe('needs_update');
    (HC.getSdkStatus as jest.Mock).mockResolvedValueOnce(1);
    expect(await hc.availability()).toBe('unavailable');
  });

  it('never asked → undetermined; asked and refused → denied; READ_STEPS granted → connected', async () => {
    const store = memoryStore();
    const hc = createHealthConnectAdapter(store);
    expect(await hc.access()).toBe('undetermined');

    expect(await hc.requestAccess()).toBe('denied');
    expect(HC.requestPermission).toHaveBeenCalledWith([{ accessType: 'read', recordType: 'Steps' }]);
    expect(store.data[HC_REQUESTED_KEY]).toBe('1');
    expect(await hc.access()).toBe('denied');

    (HC.getGrantedPermissions as jest.Mock).mockResolvedValueOnce([{ accessType: 'read', recordType: 'Steps' }]);
    expect(await hc.access()).toBe('connected');
  });

  it('a write or other permission is not READ_STEPS', async () => {
    (HC.getGrantedPermissions as jest.Mock).mockResolvedValueOnce([
      { accessType: 'write', recordType: 'Steps' },
      { accessType: 'read', recordType: 'HeartRate' },
    ]);
    expect(await createHealthConnectAdapter(memoryStore()).access()).toBe('undetermined');
  });

  it('reads the aggregated step total (de-duplicated by Health Connect) for the window', async () => {
    expect(await createHealthConnectAdapter(memoryStore()).readSteps(START, END)).toBe(812);
    expect(HC.aggregateRecord).toHaveBeenCalledWith({
      recordType: 'Steps',
      timeRangeFilter: { operator: 'between', startTime: START.toISOString(), endTime: END.toISOString() },
    });
  });

  it('initialization failure throws (caller falls back) and is retried next time', async () => {
    (HC.initialize as jest.Mock).mockResolvedValueOnce(false);
    const hc = createHealthConnectAdapter(memoryStore());
    await expect(hc.readSteps(START, END)).rejects.toThrow('not initialized');
    expect(await hc.readSteps(START, END)).toBe(812);
    expect(HC.initialize).toHaveBeenCalledTimes(2);
  });

  it('opens Health Connect settings and the Play Store page (web fallback)', async () => {
    const openURL = jest.spyOn(Linking, 'openURL').mockRejectedValueOnce(new Error('no market')).mockResolvedValueOnce(true);
    const hc = createHealthConnectAdapter(memoryStore());
    await hc.openSettings();
    expect(HC.openHealthConnectSettings).toHaveBeenCalled();
    await hc.openStore();
    expect(openURL).toHaveBeenNthCalledWith(1, expect.stringMatching(/^market:\/\/details\?id=com\.google\.android\.apps\.healthdata/));
    expect(openURL).toHaveBeenNthCalledWith(2, 'https://play.google.com/store/apps/details?id=com.google.android.apps.healthdata');
  });
});
