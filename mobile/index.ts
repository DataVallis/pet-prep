import { registerRootComponent } from 'expo';

import App from './App';
import { defineStepSyncTask } from './src/modules/steps/backgroundSteps';

// M3-06: background tasks must be defined at module scope on every JS start — also when
// iOS launches the app in the background only to run the step sync.
try {
  defineStepSyncTask();
} catch {
  // A binary without the task module: the open-app sync still works.
}

// registerRootComponent calls AppRegistry.registerComponent('main', () => App);
// It also ensures that whether you load the app in Expo Go or in a native build,
// the environment is set up appropriately
registerRootComponent(App);
