#!/usr/bin/env python3
"""Builds the free lead PDF "Summary for a Labrador" (SL + EN) for the real-cost prototype.

Usage: python3 build_summary.py   (needs Google Chrome / Chromium on PATH)
Numbers mirror src/main.js (PROFILES.adult, LIFE_YEARS, COST, RATES) — change them in both places.
"""
import base64, pathlib, shutil, subprocess, tempfile

HERE = pathlib.Path(__file__).resolve().parent
BRAND = HERE.parents[3] / "brand"
LIFE_YEARS = 13.1
WALK_MIN, MEALS, BAGS, TRAIN_MIN, GROOM_WEEK, STEPS_PER_MIN = 90, 2, 2, 5, 1, 100
MONTHLY_GBP, SETUP_GBP = 116, 560
EUR, USD = 1 / 0.83559, 1.1052 / 0.83559  # ECB euro reference rates, 13 November 2026


def num(v, lang, d=0):
    v = int(v * 10**d + 0.5) / 10**d  # round half up, like the page
    s = f"{v:,.{d}f}"
    return s.replace(",", "X").replace(".", ",").replace("X", ".") if lang == "sl" else s


def font_css():
    out = []
    for fam, files in {"Bricolage Grotesque": ["700", "800"], "Instrument Sans": ["400", "500", "600", "700"]}.items():
        for w in files:
            f = BRAND / "fonts" / f"{fam.replace(' ', '')}-{w}.woff2"
            b64 = base64.b64encode(f.read_bytes()).decode()
            out.append(f"@font-face{{font-family:'{fam}';font-weight:{w};src:url(data:font/woff2;base64,{b64}) format('woff2')}}")
    return "".join(out)


T = {
    "sl": dict(
        title="Koliko dela je labradorec?", kicker="Povzetek za starše", file="petprep-povzetek-labradorec-sl.pdf",
        intro="Številke za odraslega labradorca. Preden se odločite za psa, jih preberite skupaj z otrokom.",
        care="Skrb", cols=["Dan", "Teden", "Leto", "13 let"],
        rows=["Sprehodi", "Koraki", "Obroki", "Vrečke za kakce", "Vaja", "Krtačenje"], h="h", min="min",
        src_tag="iz vira", rule_tag="pravilo PetPrep",
        puppy_h="Mladiček je drugačen",
        puppy="Pri 2 mesecih je 4-krat na dan in mora ven približno vsaki 2 uri, ker zdrži približno 1 uro na mesec starosti. Spi 15 do 20 ur na dan. Sprehodi so še kratki.",
        cost="Stroški", cost_rows=["Na začetku", "Na mesec", "Na leto", "V 13 letih"],
        cost_note="Najmanjši stroški za velikega psa po oceni PDSA (Združeno kraljestvo), brez nakupa psa in brez nepričakovanih veterinarskih stroškov. Zneski z ~ so preračunani po tečaju ECB z dne 13. 11. 2026 (1 £ = 1,1968 € = 1,3227 $). Cene v vaši državi so lahko drugačne.",
        promise="Obljuba", promise_p="Labradorec bi od mene potreboval vsak dan:",
        items=["90 minut sprehoda, tudi ko dežuje", "hranjenje 2-krat na dan ob pravem času", "svežo vodo", "pobiranje za njim na vsakem sprehodu", "kratko vajo in krtačenje enkrat na teden"],
        sign=["Podpis otroka", "Podpis starša", "Datum"],
        next_h="Naslednji korak", next="V aplikaciji PetPrep otrok 12 tednov skrbi za realističnega AI psa, vi pa vidite, ali obveznosti res opravlja. Več na petprep.si.",
        sources_h="Viri", sources="Gibanje 90 minut: Guide Dogs UK. Obroki po starosti, vaje 5 do 10 minut, mladiček 1 ura na mesec starosti: viri v registru pasem na petprep.si. Spanje: The Royal Kennel Club. Življenjska doba 13,1 leta: McMillan idr. 2024. Stroški: PDSA, The cost of owning a dog. Tečaj: referenčni tečaji ECB, 13. 11. 2026. Koraki (100 na minuto) in 2 vrečki na dan sta pravili simulacije PetPrep, ne vir. Izračun za 13 let uporabi številke odraslega psa za vsa leta.",
        foot="PetPrep · petprep.si · Informativno, ni veterinarski ali finančni nasvet.",
    ),
    "en": dict(
        title="How much work is a Labrador?", kicker="Summary for parents", file="petprep-summary-labrador-en.pdf",
        intro="Numbers for an adult Labrador. Read them with your child before you decide on a dog.",
        care="Care", cols=["Day", "Week", "Year", "13 years"],
        rows=["Walks", "Steps", "Meals", "Poo bags", "Training", "Brushing"], h="h", min="min",
        src_tag="sourced", rule_tag="PetPrep rule",
        puppy_h="A puppy is different",
        puppy="At 2 months it eats 4 times a day and has to go out about every 2 hours, because it can hold on for about 1 hour per month of age. It sleeps 15 to 20 hours a day. Walks are still short.",
        cost="Costs", cost_rows=["Getting started", "Per month", "Per year", "Over 13 years"],
        cost_note="Minimum costs for a large dog as estimated by PDSA (UK), not counting buying the dog or unexpected vet bills. Amounts with ~ are converted at the ECB rate of 13 November 2026 (£1 = €1.1968 = $1.3227). Prices in your country may differ.",
        promise="The promise", promise_p="A Labrador would need from me every day:",
        items=["90 minutes of walking, in the rain too", "meals twice a day, on time", "fresh water", "picking up after it on every walk", "a short lesson, and brushing once a week"],
        sign=["Child's signature", "Parent's signature", "Date"],
        next_h="Next step", next="In the PetPrep app a child looks after a realistic AI dog for 12 weeks, and you see whether the jobs really get done. More at petprep.si.",
        sources_h="Sources", sources="Exercise 90 minutes: Guide Dogs UK. Meals by age, 5 to 10 minute lessons, puppy 1 hour per month of age: sources in the breed register at petprep.si. Sleep: The Royal Kennel Club. Lifespan 13.1 years: McMillan et al. 2024. Costs: PDSA, The cost of owning a dog. Exchange rate: ECB euro reference rates, 13 November 2026. Steps (100 per minute) and 2 bags a day are PetPrep simulation rules, not a source. The 13-year sum uses adult-dog numbers for every year.",
        foot="PetPrep · petprep.si · For information only, not veterinary or financial advice.",
    ),
}


def hours(m, t, lang):
    return f"{num(m / 60, lang, 0 if m >= 6000 else 1)} {t['h']}" if m >= 60 else f"{num(m, lang)} {t['min']}"


def money(gbp, lang, r=1):
    e, u = round(gbp * EUR / r) * r, round(gbp * USD / r) * r
    if lang == "sl":
        return f"<b>{num(round(gbp / r) * r, lang)} £</b><span>~{num(e, lang)} € · ~{num(u, lang)} $</span>"
    return f"<b>£{num(round(gbp / r) * r, lang)}</b><span>~€{num(e, lang)} · ~${num(u, lang)}</span>"


def html(lang):
    t = T[lang]
    days = [1, 7, 365.25, 365.25 * LIFE_YEARS]
    rows = [
        (t["rows"][0], "src", [hours(WALK_MIN * d, t, lang) for d in days]),
        (t["rows"][1], "rule", [num(WALK_MIN * STEPS_PER_MIN * d, lang) for d in days]),
        (t["rows"][2], "src", [num(MEALS * d, lang) for d in days]),
        (t["rows"][3], "rule", [num(BAGS * d, lang) for d in days]),
        (t["rows"][4], "src", [hours(TRAIN_MIN * d, t, lang) for d in days]),
        (t["rows"][5], "src", ["–"] + [num(GROOM_WEEK * d / 7, lang) for d in days[1:]]),
    ]
    year = MONTHLY_GBP * 12
    life = SETUP_GBP + year * LIFE_YEARS
    costs = [money(SETUP_GBP, lang), money(MONTHLY_GBP, lang), money(year, lang), money(life, lang, 10)]
    logo = (BRAND / "logo" / "petprep-logo-horizontal.svg").read_text()
    tr = "".join(f"<tr><th>{n}<em class='{k}'>{t[k + '_tag']}</em></th>" + "".join(f"<td>{v}</td>" for v in vals) + "</tr>" for n, k, vals in rows)
    return f"""<!DOCTYPE html><html lang="{lang}"><head><meta charset="utf-8"><title>{t['title']}</title><style>
{font_css()}
@page{{size:A4;margin:16mm 16mm 14mm}}*{{box-sizing:border-box}}
body{{font-family:'Instrument Sans',sans-serif;color:#121614;font-size:10.5pt;line-height:1.45;margin:0}}
h1,h2,.n{{font-family:'Bricolage Grotesque',sans-serif;font-weight:800;letter-spacing:-.02em;margin:0}}
h1{{font-size:27pt;line-height:1.05}}h2{{font-size:15pt;margin:0 0 6pt}}
header{{display:flex;justify-content:space-between;align-items:center;margin-bottom:14pt}}header svg{{height:30pt;width:auto}}
.kick{{font-weight:700;color:#1A7A55;font-size:10pt}}.lede{{color:#5A635D;font-size:11.5pt;margin:6pt 0 14pt}}
section{{margin-bottom:14pt;break-inside:avoid}}
table{{width:100%;border-collapse:collapse}}th,td{{padding:6pt 4pt;border-bottom:.6pt solid #E1E6E1;text-align:right}}
thead th{{font-size:9pt;color:#5A635D;font-weight:600}}tbody th{{text-align:left;font-weight:500}}
td{{font-family:'Bricolage Grotesque',sans-serif;font-weight:700;font-size:11.5pt;white-space:nowrap}}
em{{font-style:normal;font-size:7.5pt;font-weight:600;border-radius:99pt;padding:1pt 6pt;margin-left:6pt}}
em.src{{background:#E3F7EE;color:#1A7A55}}em.rule{{background:#EEF0EE;color:#5A635D}}
.box{{background:#F3F5F2;border-radius:12pt;padding:11pt 13pt}}.note{{font-size:8.5pt;color:#5A635D;margin-top:6pt}}
.costs{{display:grid;grid-template-columns:repeat(4,1fr);gap:7pt}}.costs div{{background:#F3F5F2;border-radius:10pt;padding:8pt 9pt}}
.costs small{{display:block;color:#5A635D;font-weight:600;font-size:8.5pt}}.costs b{{font-family:'Bricolage Grotesque',sans-serif;font-size:14pt;display:block}}
.costs span{{font-size:8.5pt;color:#5A635D}}
ul{{list-style:none;padding:0;margin:6pt 0 0}}li{{position:relative;padding:3.5pt 0 3.5pt 22pt;font-weight:500}}
li::before{{content:"";position:absolute;left:0;top:4pt;width:12pt;height:12pt;border:1.4pt solid #121614;border-radius:4pt}}
.sign{{display:grid;grid-template-columns:1.3fr 1.3fr .8fr;gap:16pt;margin-top:26pt}}.sign div{{border-top:1pt solid #121614;padding-top:3pt;font-size:8.5pt;color:#5A635D}}
.next{{background:#7FE0B4;border-radius:12pt;padding:11pt 13pt}}
footer{{font-size:8pt;color:#5A635D;margin-top:10pt}}.page2{{break-before:page}}
</style></head><body>
<header>{logo}<span class="kick">{t['kicker']}</span></header>
<h1>{t['title']}</h1><p class="lede">{t['intro']}</p>
<section><h2>{t['care']}</h2><table><thead><tr><th></th>{''.join(f'<th>{c}</th>' for c in t['cols'])}</tr></thead><tbody>{tr}</tbody></table></section>
<section class="box"><h2>{t['puppy_h']}</h2>{t['puppy']}</section>
<section><h2>{t['cost']}</h2><div class="costs">{''.join(f'<div><small>{n}</small>{c}</div>' for n, c in zip(t['cost_rows'], costs))}</div><p class="note">{t['cost_note']}</p></section>
<section class="page2"><h2>{t['promise']}</h2><p>{t['promise_p']}</p><ul>{''.join(f'<li>{i}</li>' for i in t['items'])}</ul>
<div class="sign">{''.join(f'<div>{s}</div>' for s in t['sign'])}</div></section>
<section class="next"><h2>{t['next_h']}</h2>{t['next']}</section>
<section><h2>{t['sources_h']}</h2><p class="note" style="margin:0">{t['sources']}</p></section>
<footer>{t['foot']}</footer></body></html>"""


def main():
    chrome = shutil.which("google-chrome") or shutil.which("chromium") or shutil.which("chromium-browser")
    for lang in T:
        with tempfile.TemporaryDirectory() as d:
            src = pathlib.Path(d) / "s.html"
            src.write_text(html(lang), encoding="utf-8")
            out = HERE / T[lang]["file"]
            subprocess.run([chrome, "--headless=new", "--no-sandbox", "--disable-gpu", "--no-pdf-header-footer", f"--print-to-pdf={out}", src.as_uri()], check=True, capture_output=True)
            print(out.name, out.stat().st_size)


if __name__ == "__main__":
    main()
