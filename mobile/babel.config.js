/**
 * Plain babel-preset-expo (SDK 57) — no NativeWind (removed 2026-10-05, see
 * docs/product/DECISIONS.md: `className` styles were dropped in production builds).
 *
 * Worklets / Reanimated: babel-preset-expo adds `react-native-worklets/plugin` by itself
 * whenever that package resolves (node_modules/babel-preset-expo/build/configs/expo.js,
 * "Automatically add worklets or reanimated plugin when package is installed"), so it must
 * NOT be listed here as well — a second copy would run the transform twice. Opt out with
 * `['babel-preset-expo', { worklets: false }]` only if worklets are ever uninstalled.
 *
 * Styling is React Native `StyleSheet` only; `src/__tests__/noClassName.test.ts` fails
 * if a `className=` prop comes back.
 */
module.exports = function (api) {
  api.cache(true);
  return {
    presets: ['babel-preset-expo'],
  };
};
