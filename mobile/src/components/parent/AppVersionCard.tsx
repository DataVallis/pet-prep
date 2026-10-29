/**
 * »Različica aplikacije« at the bottom of the parent's Nadzor (David 2026-10-29): which store
 * build and which JS bundle this phone runs — »3.1.9 (42) · posodobitev 6bf527d · 29. 10. 13:17«
 * or »… · vgrajena različica« (`modules/appVersion/appVersion.ts`). Tap / long-press copies the
 * full details (version, build, runtime, update id, commit, channel) when the clipboard module
 * exists. »Preveri posodobitve« checks + downloads (bounded, `updateCheck.ts`) and only OFFERS
 * »Znova zaženi zdaj« — never an automatic reload. Read lazily on render; nothing at boot.
 * Parent only — the child sees just the muted line in `ChildSettingsSheet`.
 */

import { useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Copy, RefreshCw, RotateCcw } from 'lucide-react-native';

import { Card, PARENT_COLORS as C, SectionTitle, parentStyles } from '@/components/parent/ParentUi';
import { Text } from '@/components/ui/Text';
import { strings } from '@/i18n/strings';
import { t } from '@/i18n';
import { appVersionFrom, formatAppVersionDetails, formatAppVersionLine } from '@/modules/appVersion/appVersion';
import { readAppVersionSources } from '@/modules/appVersion/appVersionSource';
import { canCheckForUpdates, checkAndFetchUpdate, restartIntoUpdate, type UpdateCheckOutcome } from '@/modules/appVersion/updateCheck';
import { copyTextToClipboard, isPinClipboardAvailable, type CopyPinResult } from '@/modules/pairing/pinClipboard';
import { deviceTimeZone } from '@/modules/childPet/familyTime';

/** User-visible strings (`parent:appVersion`). */
export const APP_VERSION_STRINGS = strings('parent', 'appVersion');

type CheckState = 'idle' | 'checking' | UpdateCheckOutcome | 'restarting' | 'restart_failed';

interface AppVersionCardProps {
  /** The family's IANA zone (update time »29. 10. 13:17«); null → the device zone. */
  timeZone: string | null;
}

export default function AppVersionCard({ timeZone }: AppVersionCardProps) {
  const S = APP_VERSION_STRINGS;
  const zone = timeZone ?? deviceTimeZone();
  const info = useMemo(() => appVersionFrom(readAppVersionSources()), []);
  const line = formatAppVersionLine(info, zone);
  const canCopy = useMemo(() => isPinClipboardAvailable(), []);
  const canCheck = useMemo(() => canCheckForUpdates(), []);
  const [copy, setCopy] = useState<CopyPinResult | null>(null);
  const [check, setCheck] = useState<CheckState>('idle');
  const busy = useRef(false);

  const copyDetails = async () => {
    if (!canCopy) return;
    setCopy(await copyTextToClipboard(formatAppVersionDetails(info, zone)));
  };

  const runCheck = async () => {
    if (busy.current || !canCheck) return;
    busy.current = true;
    setCheck('checking');
    try {
      setCheck(await checkAndFetchUpdate());
    } finally {
      busy.current = false;
    }
  };

  const restart = async () => {
    if (busy.current) return;
    busy.current = true;
    setCheck('restarting');
    const ok = await restartIntoUpdate();
    busy.current = false;
    if (!ok) setCheck('restart_failed');
  };

  const status = statusText(check);
  const checking = check === 'checking';
  const ready = check === 'ready' || check === 'restarting' || check === 'restart_failed';

  return (
    <Card testID="app-version-card">
      <SectionTitle>{S.title}</SectionTitle>
      <Pressable
        onPress={canCopy ? () => void copyDetails() : undefined}
        onLongPress={canCopy ? () => void copyDetails() : undefined}
        disabled={!canCopy}
        accessibilityRole={canCopy ? 'button' : 'text'}
        accessibilityLabel={t('parent:appVersion.rowA11y', { line })}
        accessibilityHint={canCopy ? S.copyA11yHint : undefined}
        style={({ pressed }) => [styles.row, pressed && styles.pressed]}
        testID="app-version-row"
      >
        <Text style={styles.line} selectable={!canCopy} testID="app-version-line">
          {line}
        </Text>
        {canCopy && <Copy color={C.muted} size={16} />}
      </Pressable>
      {canCopy && (
        <Text style={parentStyles.muted} accessibilityLiveRegion="polite" testID="app-version-copy-status">
          {copy === 'copied' ? S.copied : copy === 'failed' || copy === 'unavailable' ? S.copyFailed : S.copyHint}
        </Text>
      )}

      {ready ? (
        <Pressable
          onPress={() => void restart()}
          disabled={check === 'restarting'}
          accessibilityRole="button"
          accessibilityState={{ disabled: check === 'restarting', busy: check === 'restarting' }}
          style={({ pressed }) => [styles.button, styles.primary, pressed && styles.pressed]}
          testID="app-version-restart"
        >
          {check === 'restarting' ? <ActivityIndicator color={C.card} /> : <RotateCcw color={C.card} size={17} />}
          <Text style={styles.primaryText}>{S.restart}</Text>
        </Pressable>
      ) : (
        <Pressable
          onPress={() => void runCheck()}
          disabled={!canCheck || checking}
          accessibilityRole="button"
          accessibilityState={{ disabled: !canCheck || checking, busy: checking }}
          style={({ pressed }) => [styles.button, (!canCheck || checking) && styles.disabled, pressed && styles.pressed]}
          testID="app-version-check"
        >
          {checking ? <ActivityIndicator color={C.accent} /> : <RefreshCw color={C.accent} size={17} />}
          <Text style={styles.buttonText}>{checking ? S.checking : S.check}</Text>
        </Pressable>
      )}
      {(status || !canCheck) && (
        <Text
          style={[parentStyles.muted, (check === 'error' || check === 'timeout' || check === 'restart_failed') && styles.error]}
          accessibilityLiveRegion="polite"
          testID="app-version-status"
        >
          {canCheck ? status : S.unavailable}
        </Text>
      )}
    </Card>
  );
}

function statusText(check: CheckState): string | null {
  const S = APP_VERSION_STRINGS;
  switch (check) {
    case 'up_to_date':
      return S.upToDate;
    case 'ready':
    case 'restarting':
      return `${S.ready} ${S.readyHint}`;
    case 'restart_failed':
      return S.restartFailed;
    case 'error':
      return S.error;
    case 'timeout':
      return S.timeout;
    case 'unavailable':
      return S.unavailable;
    default:
      return null;
  }
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 8, minHeight: 32 },
  line: { flex: 1, fontSize: 14, color: C.text, fontVariant: ['tabular-nums'] },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: 46,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
  },
  buttonText: { fontSize: 15, fontWeight: '700', color: C.accent },
  primary: { backgroundColor: C.accent, borderColor: C.accent },
  primaryText: { fontSize: 15, fontWeight: '700', color: C.card },
  disabled: { opacity: 0.55 },
  error: { color: C.redText },
  pressed: { opacity: 0.85 },
});
