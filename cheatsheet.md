# 🐾 PetPrep — Production Cheatsheet & Access Guide

Tukaj so zbrani vsi dostopi, povezave, poverilnice in navodila za upravljanje produkcijskega okolja PetPrep.

---

## 🌐 1. Produkcijske povezave & Domene

| Storitev | Povezava / URL | Opis |
| :--- | :--- | :--- |
| **Glavna stran / Landing** | [https://petprep.si](https://petprep.si) | Spletna stran projekta |
| **Glavni API (HTTPS)** | [https://api.petprep.si](https://api.petprep.si) | REST API za mobilno aplikacijo |
| **Health Check** | [https://api.petprep.si/up](https://api.petprep.si/up) | Status delovanja strežnika (HTTP 200) |
| **Filament Admin Panel** | [https://api.petprep.si/admin](https://api.petprep.si/admin) | Nadzorna plošča za upravljanje baze |
| **Reverb WebSockets (WSS)** | `wss://api.petprep.si` | Real-time sinhronizacija dogodkov |
| **Hetzner IP Naslov** | `138.199.172.97` | IP naslov produkcijskega strežnika |

---

## 🔑 2. Prijavni računi (v produkcijski bazi)

### 👑 Superadmin (Filament Admin Panel)
- **Dostop:** [https://api.petprep.si/admin](https://api.petprep.si/admin)
- **Email:** `admin@petprep.io`
- **Geslo:** `Password123!`

### 👨‍👩‍👧 Testni starševski račun (Parent)
- **Email:** `parent@test.com`
- **Geslo:** `password`

### 🧒 Testni otroški račun (Child)
- **Email:** `child@test.com`
- **Geslo:** `password`
- *(Povezan s staršem, ima ustvarjenega kužka Mutt)*

---

## 🗄️ 3. Povezava na PostgreSQL bazo preko TablePlus (SSH Tunnel)

Za varno povezavo do produkcijske PostgreSQL baze (brez odpiranja javnih portov) v **TablePlus** ustvari novo povezavo (**PostgreSQL**) s temi nastavitvami:

### 🔹 Zavihek "General":
- **Host / Socket:** `127.0.0.1` (ali `postgres`)
- **Port:** `5432`
- **User:** `petprep_user`
- **Password:** `9658db233433f7a3763e4f0b008906f1b8fbac12456cdcd1`
- **Database:** `petprep_production`

### 🔹 Zavihek "Over SSH" (SSH Tunnel):
- **Server:** `138.199.172.97`
- **Port:** `22`
- **User:** `root` (ali `deploy`)
- **Authentication:** `Use Private Key`
- **Key file:** Izberi svoj lokalni SSH ključ (npr. `~/.ssh/id_rsa` ali `~/.ssh/petprep_deploy_key`)

*(Klikni **Test** in nato **Connect**).*

---

## 📱 4. Mobilna aplikacija (Expo Dev)

Mobilna aplikacija je sedaj nastavljena, da se neposredno povezuje na produkcijski API preko varne HTTPS povezave:

- **`EXPO_PUBLIC_API_URL`**: `https://api.petprep.si`
- **`EXPO_PUBLIC_REVERB_HOST`**: `api.petprep.si`
- **`EXPO_PUBLIC_REVERB_PORT`**: `443`
- **`EXPO_PUBLIC_REVERB_SCHEME`**: `https`

Zagon Expo aplikacije:
```bash
cd mobile
npx expo start --clear
```
V aplikaciji klikni **`🧒 Otrok (HUD)`** ali **`👨‍👩‍👧 Starš (Nadzor)`** za takojšen test.


---

## 🛠️ 5. Koristni ukazi na strežniku

Povezava na strežnik preko SSH:
```bash
ssh -i ~/.ssh/petprep_deploy_key deploy@138.199.172.97
```

### Preverjanje stanja vseh kontejnerjev:
```bash
docker compose -f /opt/petprep/repo/backend/compose.production.yaml ps
```

### Pregled dnevnikov (Logs) v živo:
```bash
# Laravel API logi
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f app

# Reverb WebSockets logi
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f reverb

# Queue Worker logi
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f queue

# Scheduler (minutni game loop) logi
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f scheduler

# Caddy (HTTPS / TLS) logi
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f caddy
```

### Ročni zagon varnostne kopije (Backup):
```bash
/opt/petprep/scripts/backup-production-db.sh
```

### Obnovitev baze iz varnostne kopije (Restore):
```bash
/opt/petprep/scripts/restore-production-db.sh /opt/petprep/backups/<ime_fajla>.sql.gz
```

### Ročni zagon deploymenta:
```bash
/opt/petprep/scripts/deploy-production.sh [COMMIT_SHA]
```

---

## 🚀 6. GitHub Actions CI/CD (Samodejni deployment)

Ob vsakem `git push origin main` GitHub Actions samodejno:
1. Požene Pest teste backend aplikacije.
2. Če testi uspejo, se preko SSH poveže na Hetzner strežnik.
3. Naloži posodobljeno kodo in požene `deploy-production.sh`.
4. Izvede migracije, optimizira predpomnilnike in ponovno zažene servise.
5. Preveri delovanje preko `/up` zdravstvene točke.
