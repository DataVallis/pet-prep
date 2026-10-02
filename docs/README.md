# PetPrep — dokumentacija

| Kje | Kaj | Jezik |
|---|---|---|
| `product/PRODUCT_SPEC.md` | **Kanonska** produktna specifikacija in pravila igre | SL |
| `business/BUSINESS_MODEL.md` | Ponudba, cene, lijak, unit economics, GTM, odprte poslovne odločitve | SL |
| `engineering/ARCHITECTURE.md` | Arhitektura *kot je zgrajena* (tabele, API, game loop, real-time) | EN |
| `engineering/AUDIT-2026-10-02.md` | Analiza stanja ob prevzemu: kaj dela, kaj ne, kaj manjka | SL |
| `engineering/ROADMAP.md` | Milestoni M0–M5 s taski (ID-ji za veje in commite) | SL |
| `engineering/DEPLOYMENT.md` | Hetzner runbook | EN |
| `decisions/` | ADR-ji od 012 naprej (001–011 so v root `README.md`) | EN |
| `source/` | Izvorni docx dokumenti pretvorjeni v Markdown (zgodovinski) | SL |

Stanje projekta iz dneva v dan: root `HANDOFF.md`. Navodila za AI agente: root `CLAUDE.md`.

**Pravilo:** produktna pravila → najprej `PRODUCT_SPEC.md`, nato koda. Sprememba API / sheme → posodobi `ARCHITECTURE.md`. Zaključen task → `ROADMAP.md` + `HANDOFF.md`.
