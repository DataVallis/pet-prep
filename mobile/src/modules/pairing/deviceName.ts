/**
 * `device_name` for the child's token (M2-02). Shown to nobody but stored with the
 * token, so it must not carry the child's name: we use only the hardware model
 * from React Native's `Platform.constants` (no new native dependency — expo-device
 * is not installed). The user-chosen device name ("Majin iPhone") is never used.
 */

import { Platform } from 'react-native';

export const FALLBACK_DEVICE_NAME = 'Telefon';
/** Backend limit (`PinLoginRequest`: device_name ≤ 100). */
const MAX_LENGTH = 100;

interface PlatformInfo {
  OS: string;
  constants: Record<string, unknown>;
}

function text(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

export function deviceName(platform: PlatformInfo = Platform): string {
  let name = '';
  if (platform.OS === 'android') {
    const brand = text(platform.constants.Brand);
    const model = text(platform.constants.Model);
    name = model.toLowerCase().startsWith(brand.toLowerCase()) ? model : `${brand} ${model}`.trim();
  } else if (platform.OS === 'ios') {
    const idiom = text(platform.constants.interfaceIdiom);
    name = idiom === 'pad' ? 'iPad' : idiom === 'phone' ? 'iPhone' : '';
  }
  return (name || FALLBACK_DEVICE_NAME).slice(0, MAX_LENGTH);
}
