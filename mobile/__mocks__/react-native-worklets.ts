/** Jest mock of react-native-worklets: everything runs synchronously on the JS thread. */
export const scheduleOnRN = <A extends unknown[]>(fn: (...args: A) => unknown, ...args: A): void => {
  fn(...args);
};
export const scheduleOnUI = scheduleOnRN;
export const runOnJS = <F,>(fn: F): F => fn;
export const runOnUI = <F,>(fn: F): F => fn;
