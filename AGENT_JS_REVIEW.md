# AGENT PROMPT — Mellon Forge VTT: Revisione cartella /js

## Contesto del progetto

Stai revisionando il frontend JS di **Mellon Forge VTT**, un Virtual Tabletop con griglia esagonale.

**Stack:**
- Frontend statico su **Netlify** (HTML + JS Vanilla + Tailwind CSS)
- Backend PHP su **Railway** (proxy API)
- Database **Supabase** (PostgreSQL + Auth + Realtime)

**Autenticazione:**
- Stateless via **JWT** salvato in `localStorage` con chiave `mf_token`
- Utente salvato in `localStorage` con chiave `mf_user`
- Il token viene inviato ad ogni chiamata API come header `Authorization: Bearer <token>`
- NON si usano cookie di sessione PHP (Railway è stateless)

**Struttura URL:**
- Frontend: `https://mellon-forge.netlify.app`
- Backend PHP: `https://mellon-forge-production.up.railway.app`
- Chiamate API: sempre via `/api/<file>.php` — il proxy `netlify.toml` le gira a Railway

---

## File da revisionare

### `js/config.js`
Contiene:
- `CONFIG.SUPABASE_URL` e `CONFIG.SUPABASE_ANON_KEY` — placeholder sostituiti da `build.sh` a build time con le env vars di Netlify
- `CONFIG.API_BASE = '/api'`
- `getSupabaseClient()` — inizializza il client Supabase JS per il Realtime, con `autoRefreshToken: false`, `persistSession: false`, `detectSessionInUrl: false`

**Deve essere caricato per primo in ogni pagina HTML.**

---

### `js/auth.js`
Contiene il modulo `Auth` (IIFE) con:
- `TOKEN_KEY = 'mf_token'`, `USER_KEY = 'mf_user'`
- `login(email, password)` → chiama `auth.php`, salva `res.data.user` e `res.data.token` in localStorage
- `register(email, password, username)` → chiama `auth.php`, salva `res.data.user` e `res.data.token`
- `logout()` → svuota localStorage, redirect a `login.html`
- `getUser()` → legge da localStorage, valida (non null, non stringa "undefined"), ritorna oggetto o null
- `requireAuth()` → chiama `getUser()`, se null fa redirect a `login.html`
- `isGM(campaign)` → confronta `user.id` con `campaign.gm_id`
- `getToken()` → ritorna il token da localStorage

**Regole critiche:**
- La risposta PHP ha struttura `{ success, data: { user, token }, message }` — i dati sono SEMPRE in `res.data`, MAI in `res.user` o `res.token` direttamente
- `getUser()` deve proteggere da valori `"undefined"` (stringa) in localStorage
- `requireAuth()` deve essere la prima chiamata in ogni pagina protetta

---

### `js/campaigns.js`
Pagina campagne. Regole:
- Deve chiamare `Auth.requireAuth()` come prima istruzione
- Helper `api()` deve avere UN SOLO blocco `headers` con `Content-Type` e `Authorization: Bearer <token>` tramite `Auth.getToken()`
- NON deve usare `credentials: 'include'`
- I redirect devono essere solo: `game.html` per entrare in sessione, `login.html` mai esplicitamente (gestito da `requireAuth`)
- La funzione `loadCampaigns()` legge `res.data` (array di campagne)
- `toggleSession()` gestisce apertura/chiusura sessione (solo GM)
- `enterSession()` fa redirect a `game.html?campaign=<id>&session=<id>`

---

### `js/game.js`
Pagina di gioco. Regole:
- Helper `api()` globale deve usare `localStorage.getItem('mf_token')` direttamente (game.js non ha accesso a `Auth.getToken()` nel suo scope globale)
- NON deve usare `credentials: 'include'`
- UN SOLO blocco `headers` per ogni `fetch()`
- `Game.init()` deve essere chiamato su `DOMContentLoaded`
- Redirect legittimi: solo `campaigns.html` in caso di sessione non valida o chiusura sessione
- Il Realtime Supabase usa `getSupabaseClient()` da `config.js`
- `_mapId` deve essere salvato globalmente dopo il caricamento della sessione per usarlo nei filtri Realtime

---

## Checklist di revisione

Per ogni file controlla:

### ✅ Autenticazione
- [ ] Il token JWT viene letto da `localStorage.getItem('mf_token')`
- [ ] Ogni `fetch()` ha `Authorization: Bearer <token>` negli headers
- [ ] Nessun `credentials: 'include'`
- [ ] Nessun header `headers:` duplicato nello stesso oggetto `opts`
- [ ] `res.data.user` e `res.data.token` (non `res.user`/`res.token`)

### ✅ Redirect
- [ ] Nessun redirect inaspettato a `login.html` fuori da `Auth.requireAuth()` e `Auth.logout()`
- [ ] `campaigns.html` protetta da `requireAuth()` come prima istruzione
- [ ] `game.html` protetta da `requireAuth()` come prima istruzione
- [ ] `login.html` NON ha `requireAuth()` (sarebbe un loop infinito)

### ✅ Struttura risposte API
- [ ] Tutti i dati letti da `res.data` (non da `res` direttamente)
- [ ] Messaggi di errore letti da `res.message`
- [ ] Controllo `res.success === true` prima di usare `res.data`

### ✅ localStorage
- [ ] Chiavi usate: solo `mf_token` e `mf_user`
- [ ] Nessun riferimento a vecchie chiavi (`hb_token`, `hb_user`, `session`, ecc.)
- [ ] `getUser()` protegge da `null`, `"undefined"` (stringa), JSON malformato

### ✅ Ordine caricamento script nelle HTML
- [ ] `login.html`:     `config.js` → `auth.js`
- [ ] `campaigns.html`: `config.js` → `auth.js` → `campaigns.js`
- [ ] `game.html`:      `config.js` → `auth.js` → `game.js` (+ Supabase CDN prima di tutto)

### ✅ config.js
- [ ] `CONFIG.API_BASE` = `'/api'`
- [ ] `getSupabaseClient()` usa `autoRefreshToken: false`, `persistSession: false`, `detectSessionInUrl: false`
- [ ] I placeholder `YOUR_PROJECT_ID` e `YOUR_ANON_KEY` sono presenti nel repo (vengono sostituiti da `build.sh`)

---

## Cosa fare se trovi un problema

1. **Descrivi il problema** con file, riga e motivo
2. **Proponi il fix** con il codice corretto
3. **Spiega l'impatto** — cosa si romperebbe a runtime se non viene fixato
4. Non modificare la logica di business (rendering campagne, hex grid, dadi, chat) a meno che non sia direttamente legato a un bug di autenticazione o redirect

## Output atteso

Un report strutturato così:
```
FILE: js/nome-file.js
  ✅ OK — descrizione controllo superato
  ❌ PROBLEMA — descrizione + riga + fix proposto
  ⚠️  ATTENZIONE — non è un bug ma potrebbe esserlo in certi scenari

SOMMARIO: X problemi trovati, Y avvertimenti
```

Se tutto è ok per tutti i file, rispondi con `✅ TUTTO OK — nessun problema trovato` e un breve sommario di cosa hai verificato.
