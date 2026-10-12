/**
 * Native fingerprint = OTA runtime version (M5-F10, DEPLOYMENT.md "Mobile releases").
 * `extra` is skipped: app.config.ts writes the commit SHA into `extra.gitSha`, which would
 * give every commit a new runtime version, so no OTA update would ever match a store build.
 * `extra` holds JS-only values (build identity, EAS project id), nothing native.
 * `google-services.json` (Android FCM) is ignored: the EAS build server has the real file
 * (file env var `GOOGLE_SERVICES_JSON`), CI does not — hashing it would make Android
 * runtimes never match. A change of the Firebase project still needs a store build (by hand).
 * @type {import('expo/fingerprint').Config}
 */
const { SourceSkips } = require('expo/fingerprint');

module.exports = {
  // `sourceSkips` REPLACES the default (it is not merged): keep the default
  // PackageJsonAndroidAndIosScriptsIfNotContainRun — `expo prebuild` on the EAS build server
  // rewrites the `android` / `ios` scripts to `expo run:*`, the Mac keeps `expo start --*`.
  sourceSkips: SourceSkips.PackageJsonAndroidAndIosScriptsIfNotContainRun | SourceSkips.ExpoConfigExtraSection,
  ignorePaths: ['**/google-services.json'],
};
