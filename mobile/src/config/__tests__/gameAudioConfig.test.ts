/**
 * GAMES_SPEC phase 1: expo-audio only PLAYS short sounds in the games. The child app never
 * records, never plays in the background and never asks for the microphone — guard the config
 * plugin options and the Android permission block (minimum permissions for a kids app).
 */
import appJson from '../../../app.json';

type Plugin = string | [string, Record<string, unknown>];

describe('expo-audio config (games play sounds only)', () => {
  const plugins = appJson.expo.plugins as Plugin[];
  const audio = plugins.find((p): p is [string, Record<string, unknown>] => Array.isArray(p) && p[0] === 'expo-audio');

  it('no recording, no background audio, no Android microphone permission', () => {
    expect(audio).toBeDefined();
    expect(audio?.[1]).toMatchObject({ recordAudioAndroid: false, enableBackgroundPlayback: false, enableBackgroundRecording: false });
    expect(appJson.expo.android.blockedPermissions).toContain('android.permission.RECORD_AUDIO');
    expect(appJson.expo.android.permissions).not.toContain('android.permission.RECORD_AUDIO');
  });

  it('the iOS purpose string says the microphone is never used', () => {
    expect(String(audio?.[1].microphonePermission)).toMatch(/never uses the microphone/);
  });
});
