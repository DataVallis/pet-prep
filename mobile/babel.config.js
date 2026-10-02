module.exports = function (api) {
  api.cache(true);
  const isTest = process.env.NODE_ENV === 'test' || process.env.JEST_WORKER_ID !== undefined;

  if (isTest) {
    return {
      presets: ['babel-preset-expo'],
      plugins: [],
    };
  }

  // NOTE: react-native-worklets/plugin is intentionally omitted.
  // It interferes with NativeWind v4's JSX runtime (jsxImportSource: 'nativewind'),
  // causing className styles to be silently dropped at runtime.
  // The app does not use Reanimated worklets/animated values, so the plugin is not needed.
  // If Reanimated animations are added later, use the `styled()` HOC approach instead
  // of jsxImportSource to avoid the conflict.
  return {
    presets: [
      ['babel-preset-expo', { jsxImportSource: 'nativewind' }],
      'nativewind/babel',
    ],
    plugins: [],
  };
};
