#!/usr/bin/env node
/**
 * Care carousels for Instagram / TikTok (M6-K, David 2026-10-31): "What can dogs / cats eat?"
 * built ONLY from the verified export docs/research/care-knowledge.json — every word on a slide
 * is the same as on petprep.si, so a post can never say more than the sources.
 *
 *   node scripts/care-carousel.mjs <species> <locale> <outDir>     e.g. dog en /tmp/out
 *
 * Writes slide-01.html … and renders them to PNG (1080×1350) with headless Chrome
 * (CHROME=/path/to/chrome, default google-chrome). Brand: brand/README.md (CGP v2).
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const [species = 'dog', L = 'en', out = join(ROOT, 'tmp-carousel')] = process.argv.slice(2);
const data = JSON.parse(readFileSync(join(ROOT, 'docs/research/care-knowledge.json'), 'utf8'));
const foods = data.foods.filter((f) => f.species === species && f.locales.includes(L));
if (!foods.length) throw new Error(`no published ${species} foods in ${L}`);

const font = (f) => `data:font/woff2;base64,${readFileSync(join(ROOT, 'brand/fonts', f)).toString('base64')}`;
const mark = readFileSync(join(ROOT, 'brand/logo/petprep-mark.svg'), 'utf8');
const markDark = readFileSync(join(ROOT, 'brand/logo/petprep-mark-on-dark.svg'), 'utf8');

const W = {
  en: {
    dog: { plural: 'dogs', q: 'What can dogs actually eat?' },
    cat: { plural: 'cats', q: 'What can cats actually eat?' },
    eyebrow: 'Care · every answer sourced',
    coverSub: (n) => `${n} foods checked against vet and welfare sources. Swipe →`,
    toxic: 'Toxic — call your vet',
    dangerous: 'Dangerous',
    notRec: 'Not recommended',
    small: 'OK in small amounts',
    smallSub: 'Plain, no seasoning, as an occasional treat.',
    myth: 'Not poison — but still a no',
    mythSub: 'Scary lists often get these wrong.',
    ateTitle: 'Ate something toxic?',
    ate: ['Call your vet or a pet poison line right away.', 'Note what was eaten and how much.', "Don't wait for signs."],
    cta: (n) => `All ${n} foods, the reasons and the sources:`,
    url: (sp) => `petprep.si/care/${sp}`,
    footer: 'General information, not vet-reviewed. When in doubt, call your vet.',
    tag: 'Ready for a pet. There for its whole life.',
  },
  sl: {
    dog: { plural: 'psi', q: 'Kaj lahko psi res jedo?' },
    cat: { plural: 'mačke', q: 'Kaj lahko mačke res jedo?' },
    eyebrow: 'Skrb · vsak odgovor z virom',
    coverSub: (n) => `Preverjenih živil: ${n}, po veterinarskih virih. Podrsaj →`,
    toxic: 'Strupeno — pokličite veterinarja',
    dangerous: 'Nevarno',
    notRec: 'Ni priporočljivo',
    small: 'V majhnih količinah v redu',
    smallSub: 'Brez začimb, kot občasna poslastica.',
    myth: 'Ni strup — a vseeno ne',
    mythSub: 'Strašljivi seznami jih pogosto zgrešijo.',
    ateTitle: 'Je pojedel kaj strupenega?',
    ate: ['Takoj pokličite veterinarja.', 'Zapišite, kaj in koliko je pojedel.', 'Ne čakajte na znake.'],
    cta: (n) => `Vseh ${n} živil, razlogi in viri:`,
    url: (sp) => `petprep.si/sl/skrb/${sp}`,
    footer: 'Splošne informacije, niso veterinarsko pregledane. Ko ste v dvomih, pokličite veterinarja.',
    tag: 'Pripravljeni na žival. Ob njej vse življenje.',
  },
}[L];
const spSlug = { en: { dog: 'dogs', cat: 'cats' }, sl: { dog: 'psi', cat: 'macke' } }[L][species];

const by = (lv) => foods.filter((f) => f.level === lv).map((f) => f.name[L]).sort((a, b) => a.localeCompare(b, L));
const sentences = (t) => (t.match(/[^.!?]+[.!?]+/g) ?? [t]).map((x) => x.trim());
// The reason, not the verdict: skip an opening "No, it is best not to." style sentence.
const reason = (t) => { const s = sentences(t); return (s.length > 1 && !/\b(because|fat|lact|digest|bacteria|salmonella|stomach|vomit|diarr|pancrea|biotin)/i.test(s[0]) ? s[1] : s[0]); };
// "Myth" slide: not_recommended foods that viral lists call deadly (only those we have).
const mythSlugs = ['almonds', 'milk', 'cheese', 'raw-eggs', 'walnuts'];
const myths = mythSlugs.map((s) => foods.find((f) => f.slug === s && f.level === 'not_recommended')).filter(Boolean).slice(0, 3);

const css = `
@font-face{font-family:B;src:url(${font('BricolageGrotesque-800.woff2')});font-weight:800}
@font-face{font-family:B;src:url(${font('BricolageGrotesque-700.woff2')});font-weight:700}
@font-face{font-family:I;src:url(${font('InstrumentSans-400.woff2')});font-weight:400}
@font-face{font-family:I;src:url(${font('InstrumentSans-600.woff2')});font-weight:600}
*{box-sizing:border-box;margin:0}
html,body{width:1080px;height:1350px}
body{font-family:I;background:#F3F5F2;color:#121614;padding:84px 80px;display:flex;flex-direction:column;gap:36px;position:relative}
.dark{background:#121614;color:#F3F5F2}
.eyebrow{font:600 26px I;letter-spacing:.14em;text-transform:uppercase;color:#1A7A55}
.dark .eyebrow{color:#7FE0B4}
h1{font:800 120px/0.98 B;letter-spacing:-.035em}
.cover{flex:1;display:flex;flex-direction:column;justify-content:center;gap:40px}
.chips{display:flex;flex-wrap:wrap;gap:16px}
.hero svg{width:180px;height:180px}
h2{font:800 70px/1.05 B;letter-spacing:-.025em}
p.lead{font:400 38px/1.4 I;color:#5A635D}
.dark p.lead{color:#A3ADA6}
.badge{align-self:flex-start;padding:16px 32px;border-radius:999px;font:600 34px I}
.toxic{background:#B93125;color:#fff}.dangerous{background:#FDE2DE;color:#B93125}.notrec{background:#FFF1C7;color:#8A6500}.small{background:#DDF6EA;color:#1A7A55}
ul.grid{list-style:none;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:18px 28px}
ul.grid li{background:#fff;border:2px solid #DDE3DE;border-radius:26px;padding:34px 34px;font:700 48px B}
.card{background:#fff;border:2px solid #DDE3DE;border-radius:28px;padding:26px 32px;display:flex;flex-direction:column;gap:8px}
.card b{font:800 42px B}.card span{font:400 30px/1.35 I;color:#5A635D}
.foot{position:absolute;left:80px;right:80px;bottom:64px;display:flex;justify-content:space-between;align-items:center;font:400 24px I;color:#5A635D}
.dark .foot{color:#A3ADA6}
.foot svg{width:64px;height:64px}
.big{font:800 64px/1.1 B}
.mint{background:#7FE0B4;color:#121614;border-radius:30px;padding:40px 44px;font:700 46px/1.25 B}
ol{padding-left:0;list-style:none;display:flex;flex-direction:column;gap:26px;font:600 46px/1.3 I}
ol li::before{content:"→ ";color:#7FE0B4}
`;
const foot = (n, total, dark) => `<div class="foot"><span>${n}/${total}</span>${dark ? markDark : mark}</div>`;
const page = (body, dark = false) => `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body class="${dark ? 'dark' : ''}">${body}</body></html>`;
const list = (names) => `<ul class="grid">${names.map((n) => `<li>${n}</li>`).join('')}</ul>`;

const slides = [];
const pick = (slugs, lv) => slugs.map((sl) => foods.find((f) => f.slug === sl && f.level === lv)).find(Boolean) ?? foods.find((f) => f.level === lv);
const ex = [pick(['grapes', 'chocolate'], 'toxic'), pick(['almonds', 'milk'], 'not_recommended'), pick(['cooked-chicken', 'salmon'], 'small_amounts')].filter(Boolean);
const cls = { toxic: 'toxic', dangerous: 'dangerous', not_recommended: 'notrec', small_amounts: 'small' };
slides.push(page(`<div class="hero">${markDark}</div><div class="cover"><p class="eyebrow">${W.eyebrow}</p><h1>${W[species].q}</h1><div class="chips">${ex.map((f) => `<span class="badge ${cls[f.level]}" style="align-self:auto">${f.name[L]}</span>`).join('')}</div><p class="lead">${W.coverSub(foods.length)}</p></div>`, true));
const toxic = by('toxic');
const per = toxic.length > 10 ? Math.ceil(toxic.length / Math.ceil(toxic.length / 10)) : toxic.length;
for (let i = 0; i < toxic.length; i += per) slides.push(page(`<span class="badge toxic">${W.toxic}</span>${list(toxic.slice(i, i + per))}`));
const danger = by('dangerous');
if (danger.length) slides.push(page(`<span class="badge dangerous">${W.dangerous}</span>${list(danger.slice(0, 10))}`));
if (myths.length) slides.push(page(`<span class="badge notrec">${W.notRec}</span><h2>${W.myth}</h2><p class="lead">${W.mythSub}</p>${myths.map((f) => `<div class="card"><b>${f.name[L]}</b><span>${reason(f.answer[L])}</span></div>`).join('')}`));
const small = by('small_amounts');
if (small.length) slides.push(page(`<span class="badge small">${W.small}</span><p class="lead">${W.smallSub}</p>${list(small.slice(0, 10))}`));
slides.push(page(`<h2>${W.ateTitle}</h2><ol>${W.ate.map((x) => `<li>${x}</li>`).join('')}</ol><div class="mint">${W.cta(foods.length)}<br>${W.url(spSlug)}</div><p class="lead" style="font-size:28px">${W.footer}</p>`, true));

mkdirSync(out, { recursive: true });
const chrome = process.env.CHROME || 'google-chrome';
slides.forEach((html, i) => {
  const n = String(i + 1).padStart(2, '0');
  const withFoot = html.replace('</body>', `${foot(i + 1, slides.length, html.includes('class="dark"'))}</body>`);
  const file = join(out, `slide-${n}.html`);
  writeFileSync(file, withFoot);
  execFileSync(chrome, ['--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
    '--window-size=1080,1350', `--screenshot=${join(out, `slide-${n}.png`)}`, `file://${file}`], { stdio: 'ignore' });
});
console.log(`${slides.length} slides → ${out}`);
