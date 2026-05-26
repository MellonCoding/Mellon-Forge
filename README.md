# MELLON FORGE VTT — Documentazione

## Stack
- **Frontend**: HTML + JS Vanilla + Tailwind CSS
- **Backend**: PHP (REST API → Supabase)
- **Database**: Supabase (PostgreSQL + Realtime + Auth)
- **App Desktop**: comunica via `api/desktop.php` con API Key

---

## Struttura Progetto

```
mellon-forge/
├── index.html          # Landing page
├── login.html          # Login / Registrazione
├── campaigns.html      # Lista campagne, crea, entra in sessione
├── game.html           # Tavola di gioco (hex grid, chat, dadi)
│
├── css/
│   ├── input.css       # Tailwind source
│   └── style.css       # Output compilato (genera con: npm run build)
│
├── js/
│   ├── config.js       # URL Supabase + CONFIG
│   ├── auth.js         # Login/logout/guard
│   ├── campaigns.js    # Logica pagina campagne
│   └── game.js         # Hex grid canvas, realtime, dadi, chat
│
├── api/
│   ├── config.php      # ⚠️ Credenziali Supabase — non esporre pubblicamente
│   ├── auth.php        # POST: login | register | logout | GET: me
│   ├── campaigns.php   # CRUD campagne + invite/kick giocatori
│   ├── sessions.php    # Apri/chiudi sessione, crea mappe
│   ├── tokens.php      # Token sulla mappa + fog of war
│   ├── chat.php        # Messaggi sessione (IC, OOC, whisper)
│   ├── dice.php        # Tiri dadi
│   ├── characters.php  # Gestione personaggi (web)
│   └── desktop.php     # ⬡ API per app desktop (API Key auth)
│
├── tailwind.config.js
└── package.json
```

---

## Setup

### 1. Supabase
1. Crea un progetto su [supabase.com](https://supabase.com)
2. Esegui `vtt_schema.sql` nel **SQL Editor**
3. In **Database → Replication** verifica che siano attive le tabelle:
   `tokens`, `chat_messages`, `dice_rolls`, `sessions`
4. In **Storage** crea i bucket: `avatars`, `map-backgrounds`, `token-images`

### 2. Configura le credenziali

**`api/config.php`:**
```php
define('SB_URL',         'https://xxxxx.supabase.co');
define('SB_ANON_KEY',    'eyJ...');       // Settings → API → anon key
define('SB_SERVICE_KEY', 'eyJ...');       // Settings → API → service_role key
define('DESKTOP_API_KEY', 'genera-una-stringa-random-sicura');
```

**`js/config.js`:**
```js
const CONFIG = {
  SUPABASE_URL:      'https://xxxxx.supabase.co',
  SUPABASE_ANON_KEY: 'eyJ...',
  API_BASE:          '/api',
};
```

### 3. Tailwind CSS
```bash
npm install
npm run dev     # watch mode durante sviluppo
npm run build   # produzione (minified)
```

### 4. Server PHP
- **Sviluppo locale**: PHP built-in server o MAMP/Laragon
  ```bash
  php -S localhost:8080
  ```
- **PhpStorm / Junie**: configura il documento root sulla cartella `mellon-forge/`
- **Produzione**: Apache/Nginx con PHP 8.1+

---

## API Desktop — Riferimento

Endpoint: `POST /api/desktop.php`
Header richiesto: `X-Desktop-Api-Key: <DESKTOP_API_KEY>`

### Ping
```json
{ "action": "ping" }
```

### Lista campagne utente
```json
{ "action": "list_campaigns", "user_email": "player@example.com" }
```

### Crea personaggio
```json
{
  "action":      "create_character",
  "campaign_id": "uuid-campagna",
  "user_email":  "player@example.com",
  "name":        "Aragorn",
  "class":       "Ranger",
  "race":        "Umano",
  "level":       5,
  "hp_max":      52,
  "avatar_url":  "https://...",
  "stats": {
    "str": 16, "dex": 14, "con": 14,
    "int": 12, "wis": 14, "cha": 12,
    "ac": 16, "speed": 30, "initiative": 2
  },
  "inventory": [
    { "name": "Spada lunga", "qty": 1, "weight": 3 }
  ],
  "notes": "Erede di Isildur"
}
```

### Aggiorna personaggio
```json
{
  "action": "update_character",
  "id":     "uuid-personaggio",
  "level":  6,
  "hp_max": 58,
  "stats":  { "str": 18 }
}
```

### Lista personaggi
```json
{
  "action":      "list_characters",
  "campaign_id": "uuid-campagna",
  "user_email":  "player@example.com"
}
```

### Elimina personaggio
```json
{ "action": "delete_character", "id": "uuid-personaggio" }
```

---

## Note di sicurezza

- La `service_role` key di Supabase **non deve mai** arrivare al browser.
  Usarla solo in `api/config.php` lato server.
- La `DESKTOP_API_KEY` deve essere una stringa random di almeno 32 caratteri:
  ```php
  echo bin2hex(random_bytes(32));
  ```
- In produzione imposta `'secure' => true` nei cookie di sessione PHP
  e usa HTTPS obbligatorio.
- Aggiungi `api/config.php` al `.gitignore` se usi un repo pubblico.

---

## Flusso sessione di gioco

```
GM apre campaigns.html
  → click "Avvia Sessione"
  → POST api/sessions.php {action:'open', campaign_id}
  → campaigns.active = true

Giocatori vedono il badge "Live" su campaigns.html
  → click "Entra"
  → redirect game.html?campaign=X&session=Y

game.html carica:
  1. Sessione + mappa (GET sessions.php)
  2. Token mappa (GET tokens.php)
  3. Personaggi campagna (GET characters.php)
  4. Messaggi recenti (GET chat.php)
  5. Supabase Realtime subscribe → aggiornamenti live

GM chiude → POST sessions.php {action:'close'}
  → campaigns.active = false
  → tutti i client ricevono evento sessione 'ended'
```
