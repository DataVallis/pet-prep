/**
 * Manual mock for lucide-react-native in test environment.
 * Renders all icons as simple View components.
 */

const React = require('react');
const { View } = require('react-native');

const MockIcon = (props) => React.createElement(View, props);

// Common icon names used throughout the PetPrep app
const icons = [
  'Beef', 'Droplet', 'Footprints', 'Sparkles', 'PawPrint', 'Lock',
  'Pencil', 'ScrollText', 'X', 'Check', 'Play', 'Square', 'Wifi',
  'WifiOff', 'AlertTriangle', 'Heart', 'Activity', 'Clock', 'Moon',
  'Sun', 'Zap', 'Shield', 'ShieldAlert', 'RefreshCw', 'Trash2',
  'AlertCircle', 'CheckCircle', 'LayoutDashboard', 'ChevronLeft',
  'Crown', 'RotateCcw', 'Settings',
];

const moduleExports = {};
icons.forEach((name) => { moduleExports[name] = MockIcon; });
moduleExports.default = MockIcon;

module.exports = moduleExports;
