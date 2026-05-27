# AGENT PROMPT — Mellon Forge VTT: Revisione cartella /api

## Contesto del progetto

Stai revisionando il backend PHP di **Mellon Forge VTT**, un Virtual Tabletop con griglia esagonale.

**Stack:**
- Backend PHP su **Railway** (container Docker con PHP-FPM + Nginx — **stateless**, nessuna sessione PHP)
- Database **Supabase** (PostgreSQL + Auth + Realtime)
- Frontend statico su **Netlify** — chiama `/api/<file>.php` con Bearer JWT

**Autenticazione:**
- Stateless via **JWT Supabase** nell'header `Authorization: Bearer <token>`
- Ogni richiesta viene verificata chiamando `GET /auth/v1/user` su Supabase
- **NON si usano `$_SESSION`** — Railway è stateless, le sessioni PHP non persistono
- L'unica eccezione è `desktop.php` che usa l'header `X-Desktop-Api-Key`

**Variabili d'ambiente Railway:**
```
SUPABASE_URL          → base URL Supabase
SUPABASE_ANON_KEY     → anon/public key
SUPABASE_SERVICE_KEY  → service role key (admin)
DESKTOP_API_KEY       → chiave per l'app desktop
FRONTEND_URL          → URL Netlify (per CORS)
```

---

## Struttura risposta JSON standard

Tutti gli endpoint usano questa struttura:
```json
{ "success": true|false, "data": <payload>, "message": "<testo>" }
```
Helpers in `config.php`:
- `ok($data, $msg)` → `success: true`
- `err($msg, $code)` → `success: false` + http_response_code

---

## File da revisionare

### `api/config.php`
Il cuore del backend. Contiene:
- Le 4 costanti lette da `getenv()`: `SB_URL`, `SB_ANON_KEY`, `SB_SERVICE_KEY`, `DESKTOP_API_KEY`
- Blocco CORS con `Access-Control-Allow-Origin` da `getenv('FRONTEND_URL')`
- Helpers: `get_body()`, `ok()`, `err()`, `respond()`
- `sb_request()` — wrapper cURL per REST Supabase (usa service key di default)
- `sb_auth()` — wrapper per `/auth/v1/*` (usa anon key)
- `get_bearer_token()` — legge `HTTP_AUTHORIZATION` o `REDIRECT_HTTP_AUTHORIZATION`
- `get_user_from_token()` — verifica JWT chiamando `/auth/v1/user`
- `require_auth()` — chiama `get_user_from_token()`, errore 401 se null
- `require_gm()` — chiama `require_auth()` + verifica `gm_id` sulla campagna
- `require_participant()` — verifica GM o iscritto in `campaign_players`

**Regole critiche:**
- Nessun `session_start()` o `$_SESSION`
- `require_auth()` dichiarata UNA SOLA VOLTA
- `CORS` deve includere `X-Desktop-Api-Key` in `Allow-Headers`
- Controllo `!SB_URL || !SB_SERVICE_KEY` con exit immediato se mancanti

---

### `api/auth.php`
- `GET ?action=me` → chiama `get_user_from_token()`, ritorna utente o 401
- `POST {action:'login'}` → chiama `sb_auth('/token?grant_type=password')`, ritorna `{user, token}` in `data`
- `POST {action:'register'}` → crea utente via Admin API (`/auth/v1/admin/users` con `SB_SERVICE_KEY`), inserisce profilo in `public.users`, fa auto-login, ritorna `{user, token}` in `data`
- `POST {action:'logout'}` → invalida token via `/auth/v1/logout`

**Regola critica:** il login deve ritornare `ok(['user' => $user, 'token' => $accessToken])` — il JS legge `res.data.user` e `res.data.token`.

---

### `api/campaigns.php`
- `GET` → lista campagne utente (GM + player), con `active_session_id` e `player_count`
- `POST {action:'create'}` → crea campagna + aggiunge GM in `campaign_players`
- `POST {action:'update'}` → solo GM, campi whitelist `['title','description','system','status']`
- `POST {action:'delete'}` → solo GM
- `POST {action:'invite'}` → solo GM, cerca utente per email via Admin API
- `POST {action:'kick'}` → solo GM

**Regola critica:** ogni azione POST deve chiamare `require_auth()` o `require_gm()` come prima cosa.

---

### `api/sessions.php`
- `GET ?session_id=UUID` → sessione con mappa annidata e campagna. **NON deve usare `$_SESSION`**
- `POST {action:'open'}` → solo GM, chiude sessioni precedenti, crea nuova, imposta `campaigns.active=true`
- `POST {action:'close'}` → solo GM, imposta `status=ended`, `campaigns.active=false`
- `POST {action:'create_map'}` → solo GM
- `POST {action:'set_map'}` → solo GM

**Regola critica:** nessun `$_SESSION` — Railway è stateless.

---

### `api/tokens.php`
- `GET ?session_id=UUID` → token della mappa attiva, filtrati per visibilità se non GM
- `POST {action:'add'}` → solo GM, verifica esagono libero
- `POST {action:'move'}` → GM muove tutto, giocatore solo il proprio token (verifica `character.user_id`)
- `POST {action:'delete'}` → solo GM
- `POST {action:'toggle_visibility'}` → solo GM
- `POST {action:'set_conditions'}` → solo GM
- `POST {action:'saveFog'}` → solo GM, valida struttura `[{col:int, row:int}]`

---

### `api/chat.php`
- `GET ?session_id=UUID` → messaggi con filtro whisper (visibili solo a mittente/destinatario/GM)
- `POST` → invia messaggio, valida tipo `['ic','ooc','whisper','system']`, solo GM per `system`
- Whisper senza `whisper_to` → default al GM della campagna

---

### `api/dice.php`
- `GET ?session_id=UUID` → storico tiri, filtra quelli privati
- `POST` → valida `dice_expression` con regex `[0-9d+\-\s]+`, salva in `dice_rolls`

---

### `api/characters.php`
- `GET ?campaign_id=UUID` → lista personaggi, include `owner_username`
- `GET ?id=UUID` → singolo personaggio
- `POST {action:'create'}` → solo GM (NPC)
- `POST {action:'update_hp'}` → proprietario o GM, clamp tra 0 e `hp_max`
- `POST {action:'update_stats'}` → merge con stats esistenti
- `POST {action:'update_inventory'}` → proprietario o GM
- `POST {action:'delete'}` → proprietario o GM

---

### `api/desktop.php`
- Autenticazione via header `X-Desktop-Api-Key` (confronto con `DESKTOP_API_KEY` da env)
- **Non usa JWT** — autenticazione separata per l'app desktop
- Azioni: `ping`, `list_campaigns`, `create_character`, `update_character`, `list_characters`, `delete_character`
- Helper `_user_id_by_email()` usa `/auth/v1/admin/users?email=` con `SB_SERVICE_KEY`

---

## Checklist di revisione

### ✅ Stateless — nessuna sessione PHP
- [ ] Nessun `session_start()` in nessun file
- [ ] Nessun `$_SESSION` in nessun file
- [ ] `require_auth()` usa solo `get_bearer_token()` + chiamata a Supabase

### ✅ Autenticazione
- [ ] Ogni endpoint protetto chiama `require_auth()`, `require_gm()` o `require_participant()` come prima cosa
- [ ] `desktop.php` verifica `X-Desktop-Api-Key` prima di qualsiasi altra operazione
- [ ] `require_auth()` dichiarata UNA SOLA VOLTA (solo in `config.php`)
- [ ] Nessun endpoint espone dati senza autenticazione (eccetto `OPTIONS`)

### ✅ Struttura risposte
- [ ] Login e register ritornano `ok(['user' => ..., 'token' => ...])` — i dati in `data`, non al livello root
- [ ] Tutti gli errori usano `err($msg, $code)` con HTTP code corretto (400/401/403/404/500)
- [ ] Nessun `echo` o `print` fuori da `ok()`/`err()`

### ✅ CORS e headers
- [ ] `config.php` imposta `Access-Control-Allow-Headers` con `X-Desktop-Api-Key`
- [ ] Risposta OPTIONS con 204 e exit immediato
- [ ] `FRONTEND_URL` usato per `Allow-Origin` (non `*` hardcoded)

### ✅ Variabili d'ambiente
- [ ] Nessun valore hardcoded (URL, chiavi) — solo `getenv()`
- [ ] Controllo di sicurezza: se `SB_URL` o `SB_SERVICE_KEY` sono vuoti → 500 + exit
- [ ] `DESKTOP_API_KEY` non vuota controllata in `desktop.php`

### ✅ Sicurezza query Supabase
- [ ] Nessuna interpolazione diretta di input utente nelle query senza sanitizzazione (UUID validation)
- [ ] Whitelist campi aggiornabili in `update` (no mass-assignment)
- [ ] Validazione tipi: `(int)`, `trim()`, `in_array()` sui valori accettati

### ✅ Funzioni helper duplicate
- [ ] `_is_gm()` dichiarata in ogni file che la usa (è locale, non in `config.php`) — ok se duplicata
- [ ] `_campaign_of_map()` solo in `tokens.php`
- [ ] `_get_char_or_fail()` solo in `characters.php`
- [ ] Nessuna funzione globale ridichiarata tra file diversi

---

## Cosa fare se trovi un problema

1. **Descrivi il problema** con file, riga e motivo
2. **Proponi il fix** con il codice PHP corretto
3. **Classifica la gravità:**
   - 🔴 CRITICO — rompe funzionalità o è un buco di sicurezza
   - 🟡 IMPORTANTE — causa bug in certi scenari
   - 🔵 MINORE — pulizia, ottimizzazione, edge case raro

## Output atteso

```
FILE: api/nome-file.php
  ✅ OK — descrizione controllo superato
  🔴 CRITICO — descrizione + riga + fix
  🟡 IMPORTANTE — descrizione + riga + fix
  🔵 MINORE — descrizione + suggerimento

SOMMARIO: X critici, Y importanti, Z minori
```

Se tutto è ok: `✅ TUTTO OK` con breve sommario di cosa hai verificato.
