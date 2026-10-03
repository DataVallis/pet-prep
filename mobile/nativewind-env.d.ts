/// <reference types="nativewind/types" />

// NOTE: This file is required by NativeWind for TypeScript type checking.
// It declares the `className` prop on all React Native components.

// Global stylesheets (e.g. `import './src/global.css'` in App.tsx) are side-effect
// imports handled by Metro + NativeWind; TS 6 checks side-effect imports by default.
declare module '*.css';
