/** Jest mock of expo-haptics. */
export enum ImpactFeedbackStyle {
  Light = 'light',
  Medium = 'medium',
  Heavy = 'heavy',
  Soft = 'soft',
  Rigid = 'rigid',
}
export enum NotificationFeedbackType {
  Success = 'success',
  Warning = 'warning',
  Error = 'error',
}
export const impactAsync = jest.fn(() => Promise.resolve());
export const notificationAsync = jest.fn(() => Promise.resolve());
export const selectionAsync = jest.fn(() => Promise.resolve());
