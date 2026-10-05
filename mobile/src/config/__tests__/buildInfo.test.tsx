/**
 * Build identity: app.config.ts resolves the git sha (EAS env → local git → "dev"),
 * the app reads it via expo-constants and shows "v{version} · {sha}" on the start
 * screen and in the parent's Nadzor.
 */
import { render, screen } from '@testing-library/react-native';

import { resolveGitSha } from '../../../app.config';
import BuildLabel from '@/components/BuildLabel';
import { buildInfoFrom, formatBuildLabel, getBuildInfo } from '@/config/buildInfo';
import StartScreen from '@/screens/StartScreen';
import ControlsScreen from '@/screens/parent/ControlsScreen';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('expo-constants', () => ({
  __esModule: true,
  default: { expoConfig: { name: 'PetPrep', slug: 'petprep', version: '1.10.2', extra: { appVersion: '1.10.2', gitSha: 'abc1234' } } },
}));

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getQuietHours: jest.fn(() => new Promise(() => undefined)) } };
});

describe('resolveGitSha (app.config.ts)', () => {
  const noGit = () => {
    throw new Error('not a git repo');
  };

  it('prefers the EAS build commit (short)', () => {
    expect(resolveGitSha({ EAS_BUILD_GIT_COMMIT_HASH: '0123456789abcdef' }, noGit)).toBe('0123456');
  });

  it('falls back to local git, then "dev"', () => {
    expect(resolveGitSha({}, () => 'f00ba12\n')).toBe('f00ba12');
    expect(resolveGitSha({ EAS_BUILD_GIT_COMMIT_HASH: '  ' }, () => 'f00ba12')).toBe('f00ba12');
    expect(resolveGitSha({}, noGit)).toBe('dev');
    expect(resolveGitSha({}, () => '')).toBe('dev');
  });
});

describe('build info', () => {
  it('reads extra, with safe defaults', () => {
    expect(buildInfoFrom({ version: '1.0.0', extra: { appVersion: '1.2.3', gitSha: 'abc1234' } })).toEqual({ version: '1.2.3', sha: 'abc1234' });
    expect(buildInfoFrom({ version: '1.0.0', extra: {} })).toEqual({ version: '1.0.0', sha: 'dev' });
    expect(buildInfoFrom(null)).toEqual({ version: '0.0.0', sha: 'dev' });
    expect(buildInfoFrom({ extra: { gitSha: 42 } })).toEqual({ version: '0.0.0', sha: 'dev' });
  });

  it('formats "v{version} · {sha}" from expo-constants', () => {
    expect(getBuildInfo()).toEqual({ version: '1.10.2', sha: 'abc1234' });
    expect(formatBuildLabel(getBuildInfo())).toBe('v1.10.2 · abc1234');
    render(<BuildLabel />);
    expect(screen.getByTestId('build-label')).toHaveTextContent('v1.10.2 · abc1234');
  });

  it('is shown at the bottom of the start screen', () => {
    render(<StartScreen />);
    expect(screen.getByText('v1.10.2 · abc1234')).toBeTruthy();
  });

  it('is shown in the parent Nadzor "O aplikaciji" section', () => {
    renderWithQuery(<ControlsScreen onBack={jest.fn()} family={null} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId('controls-about')).toHaveTextContent(/O aplikaciji.*v1\.10\.2 · abc1234/);
  });
});
