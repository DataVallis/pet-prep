module.exports = function (api) {
  api.cache(true);
  const isTest = process.env.NODE_ENV === 'test' || process.env.JEST_WORKER_ID !== undefined;

  if (isTest) {
    // In tests, use minimal presets without NativeWind's CSS interop
    return {
      presets: ['babel-preset-expo'],
      plugins: [],
    };
  }

  return {
    presets: [
      ['babel-preset-expo', { jsxImportSource: 'nativewind' }],
      'nativewind/babel',
    ],
    // react-native-worklets/plugin MUST be last
    plugins: ['react-native-worklets/plugin'],
  };
};
