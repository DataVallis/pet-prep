/**
 * Native fingerprint = OTA runtime version (M5-F10, DEPLOYMENT.md "Mobile releases").
 * `extra` is skipped: app.config.ts writes the commit SHA into `extra.gitSha`, which would
 * give every commit a new runtime version, so no OTA update would ever match a store build.
 * `extra` holds JS-only values (build identity, EAS project id), nothing native.
 * `google-services.json` (Android FCM) is ignored: the EAS build server has the real file
 * (file env var `GOOGLE_SERVICES_JSON`), CI does not — hashing it would make Android
 * runtimes never match. A change of the Firebase project still needs a store build (by hand).
 * On the build server EAS stores the file env var outside the project under another name
 * (`GOOGLE_SERVICES_JSON` = its path), and the fingerprint hashes the file that
 * `android.googleServicesFile` points to — so that path is ignored too, or the first Android
 * build fails with "Runtime version calculated on local machine not equal …" (2026-10-19).
 * @type {import('expo/fingerprint').Config}
 */
const path = require('node:path');
const { SourceSkips } = require('expo/fingerprint');

/** Paths (relative to mobile/, as the fingerprint matches them) of the Firebase config. */
function googleServicesIgnorePaths(vars = process.env, root = __dirname) {
  const paths = ['**/google-services.json'];
  const fromEas = vars.GOOGLE_SERVICES_JSON && vars.GOOGLE_SERVICES_JSON.trim();
  if (fromEas) paths.push(path.relative(root, path.resolve(root, fromEas)).split(path.sep).join('/'));
  return paths;
}

module.exports = {
  // `sourceSkips` REPLACES the default (it is not merged): keep the default
  // PackageJsonAndroidAndIosScriptsIfNotContainRun — `expo prebuild` on the EAS build server
  // rewrites the `android` / `ios` scripts to `expo run:*`, the Mac keeps `expo start --*`.
  sourceSkips: SourceSkips.PackageJsonAndroidAndIosScriptsIfNotContainRun | SourceSkips.ExpoConfigExtraSection,
  ignorePaths: googleServicesIgnorePaths(),
  googleServicesIgnorePaths,
};
