# PetPrep — dokumentacija

| Kje | Kaj | Jezik |
|---|---|---|
| `product/PRODUCT_SPEC.md` | **Kanonska** produktna specifikacija in pravila igre | SL |
| `business/BUSINESS_MODEL.md` | Ponudba, cene, lijak, unit economics, GTM, odprte poslovne odločitve | SL |
| `business/REVENUE_MODELS.md` | Modeli prihodka — ideje in smer (naročnina za pravega ljubljenčka, zavarovalnice, prodaja prek spletne strani); vse *načrt* (David 11. 11. 2026) | SL |
| `business/VALIDATION_PLAN.md` | 8-tedenski načrt validacije: 100 plačljivih družin, hipoteze s pragovi, kaj je zamrznjeno, odločitve V1–V4 (David 10. 11. 2026) | SL |
| `engineering/ARCHITECTURE.md` | Arhitektura *kot je zgrajena* (tabele, API, game loop, real-time) | EN |
| `engineering/AUDIT-2026-10-02.md` | Analiza stanja ob prevzemu: kaj dela, kaj ne, kaj manjka | SL |
| `engineering/ROADMAP.md` | Milestoni M0–M5 s taski (ID-ji za veje in commite) | SL |
| `engineering/DEPLOYMENT.md` | Hetzner runbook | EN |
| `decisions/` | ADR-ji od 012 naprej (001–011 so v root `README.md`) | EN |
| `product/DECISIONS.md` | **Dnevnik odločitev** (kdo, kaj, zakaj) + odprta vprašanja | SL |
| `engineering/DIAGRAMS.md` | Diagrami (Mermaid): sistem, pairing, game loop, eskalacija, podatki, pipeline | EN |
| `audiences/` | Viri za starše, otroke, investitorje, partnerje (→ vodiči, 2-pagerji, decki) | SL/EN |
| `journey/BUILD_LOG.md` | **Dnevnik razvoja** — surovina za objave, investitorje, partnerje, starše in otroke | SL |
| `source/` | Izvorni docx dokumenti pretvorjeni v Markdown (zgodovinski) | SL |

Stanje projekta iz dneva v dan: root `HANDOFF.md`. Navodila za AI agente: root `CLAUDE.md`.

**Pravilo:** produktna pravila → najprej `PRODUCT_SPEC.md`, nato koda. Sprememba API / sheme → posodobi `ARCHITECTURE.md`. Zaključen task → `ROADMAP.md` + `HANDOFF.md`.
