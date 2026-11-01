#!/usr/bin/env node
/**
 * Monthly Search Console report for petprep.si (David 2026-10-31: our own service account instead of
 * Supermetrics). Reads the key from env GSC_SERVICE_ACCOUNT_JSON (GitHub secret), signs a JWT with
 * node:crypto (no dependencies), queries the Search Console API and writes seo-report.json + .md.
 *
 *   node scripts/seo-report.mjs [YYYY-MM] [outDir]       # default: last full month, ./seo-report
 *
 * Property: env GSC_SITE (e.g. "sc-domain:petprep.si" or "https://petprep.si/"); empty → tries both.
 */
import { createSign } from "node:crypto";
import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const key = JSON.parse(process.env.GSC_SERVICE_ACCOUNT_JSON ?? "null");
if (!key?.client_email || !key?.private_key) {
  console.error("GSC_SERVICE_ACCOUNT_JSON is missing or not a service-account key.");
  process.exit(2);
}

const b64 = (x) => Buffer.from(typeof x === "string" ? x : JSON.stringify(x)).toString("base64url");
async function token() {
  const now = Math.floor(Date.now() / 1000);
  const head = b64({ alg: "RS256", typ: "JWT" });
  const claim = b64({ iss: key.client_email, scope: "https://www.googleapis.com/auth/webmasters.readonly", aud: "https://oauth2.googleapis.com/token", iat: now, exp: now + 3600 });
  const sig = createSign("RSA-SHA256").update(`${head}.${claim}`).sign(key.private_key).toString("base64url");
  const r = await fetch("https://oauth2.googleapis.com/token", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ grant_type: "urn:ietf:params:oauth:grant-type:jwt-bearer", assertion: `${head}.${claim}.${sig}` }),
  });
  if (!r.ok) throw new Error(`token: ${r.status} ${await r.text()}`);
  return (await r.json()).access_token;
}

/** First and last day of a month (YYYY-MM), and of the month before. */
function months(arg) {
  const d = new Date();
  const [y, m] = arg ? arg.split("-").map(Number) : [d.getUTCMonth() === 0 ? d.getUTCFullYear() - 1 : d.getUTCFullYear(), d.getUTCMonth() === 0 ? 12 : d.getUTCMonth()];
  const range = (yy, mm) => {
    const start = new Date(Date.UTC(yy, mm - 1, 1));
    const end = new Date(Date.UTC(yy, mm, 0));
    return { label: `${yy}-${String(mm).padStart(2, "0")}`, start: start.toISOString().slice(0, 10), end: end.toISOString().slice(0, 10) };
  };
  return { cur: range(y, m), prev: m === 1 ? range(y - 1, 12) : range(y, m - 1) };
}

const CARE = "/(care|skrb|ratgeber)/"; // care knowledge paths in every live language (pageSlugs.care)
const COLORING = "/(coloring-pages|pobarvanke|ausmalbilder)";

async function main() {
  const [arg, outDir = "seo-report"] = process.argv.slice(2);
  const { cur, prev } = months(arg);
  const access = await token();
  const sites = process.env.GSC_SITE ? [process.env.GSC_SITE] : ["sc-domain:petprep.si", "https://petprep.si/"];
  let site = null;
  const q = async (s, range, body) => {
    const r = await fetch(`https://www.googleapis.com/webmasters/v3/sites/${encodeURIComponent(s)}/searchAnalytics/query`, {
      method: "POST",
      headers: { authorization: `Bearer ${access}`, "content-type": "application/json" },
      body: JSON.stringify({ startDate: range.start, endDate: range.end, dataState: "final", ...body }),
    });
    if (!r.ok) throw Object.assign(new Error(`${s}: ${r.status} ${await r.text()}`), { status: r.status });
    return (await r.json()).rows ?? [];
  };
  for (const s of sites) {
    try {
      await q(s, cur, { rowLimit: 1 });
      site = s;
      break;
    } catch (e) {
      if (e.status !== 403 && e.status !== 404) throw e;
      console.error(`no access to ${s}`);
    }
  }
  if (!site) throw new Error(`The service account ${key.client_email} has no access to ${sites.join(" or ")} — add it as a user in Search Console.`);

  const filter = (regex) => ({ dimensionFilterGroups: [{ filters: [{ dimension: "page", operator: "includingRegex", expression: regex }] }] });
  const totals = async (range, regex) => {
    const [row] = await q(site, range, regex ? filter(regex) : {});
    return row ? { clicks: row.clicks, impressions: row.impressions, ctr: row.ctr, position: row.position } : { clicks: 0, impressions: 0, ctr: 0, position: null };
  };
  const report = {
    site,
    month: cur.label,
    previous: prev.label,
    totals: {
      site: { cur: await totals(cur), prev: await totals(prev) },
      care: { cur: await totals(cur, CARE), prev: await totals(prev, CARE) },
      coloring: { cur: await totals(cur, COLORING), prev: await totals(prev, COLORING) },
    },
    topCarePages: (await q(site, cur, { dimensions: ["page"], rowLimit: 10, ...filter(CARE) })).map((r) => ({ page: r.keys[0], clicks: r.clicks, impressions: r.impressions, position: r.position })),
    topCareQueries: (await q(site, cur, { dimensions: ["query"], rowLimit: 15, ...filter(CARE) })).map((r) => ({ query: r.keys[0], clicks: r.clicks, impressions: r.impressions, position: r.position })),
    nearMisses: (await q(site, cur, { dimensions: ["page"], rowLimit: 500, ...filter(CARE) }))
      .filter((r) => r.clicks === 0 && r.position >= 4 && r.position <= 15)
      .slice(0, 20)
      .map((r) => ({ page: r.keys[0], impressions: r.impressions, position: r.position })),
    topQueries: (await q(site, cur, { dimensions: ["query"], rowLimit: 15 })).map((r) => ({ query: r.keys[0], clicks: r.clicks, impressions: r.impressions, position: r.position })),
    pagesWithImpressions: { care: (await q(site, cur, { dimensions: ["page"], rowLimit: 5000, ...filter(CARE) })).length },
  };

  const n = (x) => (x == null ? "–" : Number.isInteger(x) ? String(x) : x.toFixed(1));
  const pct = (x) => `${(x * 100).toFixed(1)} %`;
  const line = (name, t) => `| ${name} | ${n(t.cur.clicks)} (${n(t.prev.clicks)}) | ${n(t.cur.impressions)} (${n(t.prev.impressions)}) | ${pct(t.cur.ctr)} | ${n(t.cur.position)} |`;
  const md = [
    `# Search Console — ${report.month} (${site})`,
    "",
    `| | Klikov (prejšnji mesec) | Prikazov (prejšnji) | CTR | Povp. položaj |`,
    "|---|---|---|---|---|",
    line("Celotna stran", report.totals.site),
    line("Skrb (baza znanja)", report.totals.care),
    line("Pobarvanke", report.totals.coloring),
    "",
    "## Najbolj prikazane strani skrbi",
    ...report.topCarePages.map((r) => `- ${r.page} — ${n(r.impressions)} prikazov, ${n(r.clicks)} klikov, položaj ${n(r.position)}`),
    "",
    "## Iskanja, ki pripeljejo na strani skrbi",
    ...report.topCareQueries.map((r) => `- „${r.query}“ — ${n(r.impressions)} prikazov, ${n(r.clicks)} klikov, položaj ${n(r.position)}`),
    "",
    "## Skoraj (položaj 4–15, 0 klikov)",
    ...report.nearMisses.map((r) => `- ${r.page} — ${n(r.impressions)} prikazov, položaj ${n(r.position)}`),
    "",
    "## Najpogostejša iskanja (cela stran)",
    ...report.topQueries.map((r) => `- „${r.query}“ — ${n(r.impressions)} prikazov, ${n(r.clicks)} klikov, položaj ${n(r.position)}`),
    "",
    `Strani skrbi s prikazi: ${report.pagesWithImpressions.care}.`,
  ].join("\n");
  mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, "seo-report.json"), JSON.stringify(report, null, 2));
  writeFileSync(join(outDir, "seo-report.md"), md + "\n");
  console.log(md);
}

main().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
