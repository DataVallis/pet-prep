/**
 * M2-01d — TanStack Query hooks of the Responsibility Certificate.
 *
 * `useGenerateCertificate` posts the form, writes the returned payload into the cached
 * dashboard (the child's `certificate`) right away and then refetches the dashboard (and
 * the certificates list, if cached). `useOpenCertificatePdf` opens the signed PDF URL in
 * the system viewer (`Linking.openURL` — Safari / the Android browser render the PDF and
 * offer Share / Download), refreshing the URL first because it expires after 60–90 min.
 * No native module is needed (no expo-sharing / expo-web-browser in the app).
 */

import { useCallback, useRef, useState } from 'react';
import { Linking } from 'react-native';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api, type ParentDashboardResponse } from '@/api/client';
import { parentDashboardKey } from '@/modules/family/live';
import { readCertificate, type CertificateFormValues, type ParentCertificate } from '@/modules/certificate/certificate';

export const parentCertificatesKey = ['parent', 'certificates'] as const;

/** The cached dashboard with one child's `certificate` replaced (same object when nothing matches). */
export function setDashboardCertificate(
  old: ParentDashboardResponse | undefined,
  certificate: ParentCertificate,
): ParentDashboardResponse | undefined {
  const family = old?.family as { children?: unknown } | null | undefined;
  if (!old || !family || !Array.isArray(family.children)) return old;
  let changed = false;
  const children = family.children.map((raw: unknown) => {
    const child = raw as { id?: unknown; certificate?: unknown };
    const current = readCertificate(child.certificate);
    if (child.id !== certificate.child_id || (current !== null && current.id !== certificate.id)) return raw;
    changed = true;
    return { ...child, certificate };
  });
  if (!changed) return old;
  return { ...old, family: { ...old.family, children } } as unknown as ParentDashboardResponse;
}

export interface GenerateCertificateVariables {
  certificateId: number;
  values: CertificateFormValues;
}

/** POST …/generate → the new payload (null when the body is unreadable — the refetch still runs). */
export function useGenerateCertificate() {
  const queryClient = useQueryClient();
  return useMutation<ParentCertificate | null, unknown, GenerateCertificateVariables>({
    mutationFn: async ({ certificateId, values }) => readCertificate(await api.generateCertificate(certificateId, values)),
    retry: false,
    onSuccess: (certificate) => {
      if (certificate !== null) {
        queryClient.setQueryData<ParentDashboardResponse>(parentDashboardKey, (old) => setDashboardCertificate(old, certificate));
      }
    },
    onSettled: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: parentDashboardKey }),
        queryClient.invalidateQueries({ queryKey: parentCertificatesKey }),
      ]);
    },
  });
}

/**
 * Open a certificate PDF. `refresh` fetches a fresh signed URL (dashboard / child state);
 * when it fails or returns null the URL at hand is used. `failed` drives a short inline
 * error; `opening` disables the button.
 */
export function useOpenCertificatePdf(refresh: () => Promise<string | null>) {
  const [opening, setOpening] = useState(false);
  const [failed, setFailed] = useState(false);
  const busy = useRef(false);

  const open = useCallback(
    async (current: string | null) => {
      if (busy.current) return;
      busy.current = true;
      setOpening(true);
      setFailed(false);
      try {
        let url = current;
        try {
          url = (await refresh()) ?? current;
        } catch {
          // Keep the URL we have; it is valid for at least an hour after it was issued.
        }
        if (url === null) throw new Error('no certificate url');
        await Linking.openURL(url);
      } catch {
        setFailed(true);
      } finally {
        busy.current = false;
        setOpening(false);
      }
    },
    [refresh],
  );

  return { open, opening, failed };
}
