/**
 * Health Connect privacy-policy rationale (Android; Google Play health declaration).
 *
 * When a user taps the privacy-policy link in the Health Connect permission dialog, Android
 * starts the app with `androidx.health.ACTION_SHOW_PERMISSIONS_RATIONALE` (Android ≤ 13) or
 * `VIEW_PERMISSION_USAGE` + category `HEALTH_PERMISSIONS` (Android 14+, through the
 * `ViewPermissionUsageActivity` alias). The `react-native-health-connect` plugin points both
 * at MainActivity, which just opened the app. Google requires the privacy policy to be shown.
 *
 * This plugin adds a tiny native `HealthPermissionsRationaleActivity` that opens the PetPrep
 * privacy policy in the browser (Slovenian page for a Slovenian phone, English otherwise) and
 * closes itself, and moves both entry points from MainActivity to it.
 *
 * Must run AFTER the react-native-health-connect manifest mod. Expo runs manifest mods in reverse
 * plugin order, so list it BEFORE "react-native-health-connect" in app.json (verified with
 * `expo prebuild`; guarded by src/config/__tests__/healthConnectRationale.test.ts).
 */
const fs = require('node:fs');
const path = require('node:path');
const { AndroidConfig, withAndroidManifest, withDangerousMod } = require('expo/config-plugins');

const RATIONALE_ACTION = 'androidx.health.ACTION_SHOW_PERMISSIONS_RATIONALE';
const VIEW_PERMISSION_USAGE_ALIAS = 'ViewPermissionUsageActivity';
const ACTIVITY = '.HealthPermissionsRationaleActivity';
const CLASS_NAME = 'HealthPermissionsRationaleActivity';

const PRIVACY_URL_EN = 'https://petprep.si/privacy';
const PRIVACY_URL_SL = 'https://petprep.si/sl/zasebnost';

function activitySource(packageName) {
  return `package ${packageName}

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import java.util.Locale

/**
 * Opened by Health Connect when the user taps "privacy policy" in its permission dialog
 * (plugins/withHealthConnectPrivacyRationale.js). Shows the PetPrep privacy policy and closes.
 */
class ${CLASS_NAME} : Activity() {
  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    val url = if (Locale.getDefault().language == "sl") PRIVACY_URL_SL else PRIVACY_URL_EN
    try {
      startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (e: Exception) {
      // No browser installed: nothing to show, just close.
    }
    finish()
  }

  private companion object {
    const val PRIVACY_URL_EN = "${PRIVACY_URL_EN}"
    const val PRIVACY_URL_SL = "${PRIVACY_URL_SL}"
  }
}
`;
}

const actionOf = (filter) => (filter.action || []).map((a) => a.$['android:name']);

/** Pure manifest change (exported for tests). */
function applyRationaleActivity(manifest) {
  const application = AndroidConfig.Manifest.getMainApplicationOrThrow(manifest);
  const mainActivity = AndroidConfig.Manifest.getMainActivityOrThrow(manifest);

  mainActivity['intent-filter'] = (mainActivity['intent-filter'] || []).filter(
    (filter) => !actionOf(filter).includes(RATIONALE_ACTION),
  );

  application.activity = (application.activity || []).filter((a) => a.$['android:name'] !== ACTIVITY);
  application.activity.push({
    $: {
      'android:name': ACTIVITY,
      'android:exported': 'true',
      'android:excludeFromRecents': 'true',
      'android:noHistory': 'true',
      'android:theme': '@android:style/Theme.Translucent.NoTitleBar',
    },
    'intent-filter': [{ action: [{ $: { 'android:name': RATIONALE_ACTION } }] }],
  });

  for (const alias of application['activity-alias'] || []) {
    if (alias.$['android:name'] === VIEW_PERMISSION_USAGE_ALIAS) alias.$['android:targetActivity'] = ACTIVITY;
  }
  return manifest;
}

const withHealthConnectPrivacyRationale = (config) => {
  config = withAndroidManifest(config, (cfg) => {
    cfg.modResults = applyRationaleActivity(cfg.modResults);
    return cfg;
  });
  return withDangerousMod(config, [
    'android',
    (cfg) => {
      const packageName = cfg.android && cfg.android.package;
      if (!packageName) throw new Error('withHealthConnectPrivacyRationale: android.package is required');
      const dir = path.join(cfg.modRequest.platformProjectRoot, 'app/src/main/java', ...packageName.split('.'));
      fs.mkdirSync(dir, { recursive: true });
      fs.writeFileSync(path.join(dir, `${CLASS_NAME}.kt`), activitySource(packageName));
      return cfg;
    },
  ]);
};

module.exports = withHealthConnectPrivacyRationale;
module.exports.applyRationaleActivity = applyRationaleActivity;
module.exports.activitySource = activitySource;
module.exports.PRIVACY_URL_EN = PRIVACY_URL_EN;
module.exports.PRIVACY_URL_SL = PRIVACY_URL_SL;
