/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './App.tsx',
    './index.ts',
    './src/**/*.{js,jsx,ts,tsx}',
  ],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // PetPrep Status Colors (Traffic Light System)
        'status-excellent': '#10B981',
        'status-warning': '#F59E0B',
        'status-critical': '#EF4444',
      },
    },
  },
  plugins: [],
};
