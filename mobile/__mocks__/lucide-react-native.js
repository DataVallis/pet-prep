/**
 * Manual mock for lucide-react-native in the test environment.
 * Every named icon (present or future) renders as a plain View, so screens
 * don't break tests when they start using a new icon.
 */

const React = require('react');
const { View } = require('react-native');

const MockIcon = (props) => React.createElement(View, props);

module.exports = new Proxy(
  { __esModule: true, default: MockIcon },
  {
    get(target, prop) {
      if (prop in target) return target[prop];
      if (typeof prop === 'string' && /^[A-Z]/.test(prop)) return MockIcon;
      return undefined;
    },
  },
);
