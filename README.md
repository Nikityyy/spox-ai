# SpoX+ AI — Deployment Guide

**HAK Sport+ · BHAK & BHAS Steyr**

> KI-Assistent für das Sport+ Programm. Powered by Google Gemini.

---

## Voraussetzungen

| Anforderung | Minimum |
|---|---|
| PHP | 8.1+ |
| MySQL | 5.7+ / MariaDB 10.4+ |
| Webserver | Apache mit `mod_rewrite` |
| HTTPS | Pflicht (Let's Encrypt empfohlen) |
| Hosting | FTP-Zugang genügt |

---

## Schritt 1: Datei-Upload (FileZilla)

Wegen Server-Sicherheitsregeln (`open_basedir`) müssen alle Ordner **innerhalb** von `httpdocs` liegen. Keine Sorge – ich habe extra Schutz-Dateien (`.htaccess`) hinzugefügt, damit niemand deine Passwörter lesen kann.

### Fertige Struktur in `httpdocs/`:
Lade einfach alle Ordner in dein `httpdocs/` Verzeichnis hoch. Es muss am Ende so aussehen:

- 📁 `httpdocs/`
  - 📄 `.htaccess`
  - 📁 `api/`
  - 📁 `docs/`
  - 📁 `public/` (Deine Website-Dateien)
  - 📁 **`config/`** (Neu: Hier drin liegen `.env.php` und `.htaccess`)
  - 📁 **`uploads/`** (Neu: Hier liegen die hochgeladenen PDFs)

**Wichtig für die Sicherheit:**
Ich habe in den Ordnern `config/` und `uploads/` jeweils eine `.htaccess` Datei erstellt. Diese **MUSS** mit hochgeladen werden. Sie blockiert den Zugriff von außen, sodass nur das System selbst die Dateien lesen kann.

---

**Routing-Datei:**
Nicht vergessen: Die Datei `ROOT_htaccess_upload_this_to_httpdocs_root.txt` muss direkt in `httpdocs/` liegen und in **`.htaccess`** umbenannt werden.


---

## Schritt 2: Datenbank anlegen

1. Erstelle eine neue MySQL-Datenbank und einen Datenbanknutzer.
2. Importiere das Schema:
   ```bash
   mysql -u USERNAME -p DATENBANKNAME < sql/init.sql
   ```
   Oder über phpMyAdmin: **Importieren** → `sql/init.sql` auswählen.

---

## Schritt 3: Konfiguration

Bearbeite `config/.env.php` und fülle alle Werte aus:

```php
// Datenbank
define('DB_HOST',     'localhost');
define('DB_NAME',     'spoxai');
define('DB_USER',     'spoxai_user');
define('DB_PASS',     'SICHERES_PASSWORT');

// Microsoft Entra ID (Azure AD)
define('MS_CLIENT_ID',     'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
define('MS_CLIENT_SECRET', 'dein-client-secret');
define('MS_TENANT_ID',     'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
define('MS_REDIRECT_URI',  'https://spox.hak-steyr.at/api/auth.php?action=callback');
define('MS_ALLOWED_DOMAIN','hak-steyr.at');

// Google Gemini
define('GEMINI_API_KEY', 'AIza...');

// Upload-Verzeichnis (außerhalb des Webroots!)
define('UPLOAD_DIR', '/var/www/spox-ai/uploads/');
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'SICHERES_ADMIN_PASSWORT');
define('ADMIN_IPS',  ['127.0.0.1', '10.0.0.0/8']); // Schulnetzwerk-IPs
```

---

## Schritt 4: Azure App Registration

Siehe [`docs/setup_azure.md`](docs/setup_azure.md) für die vollständige Anleitung.

**Kurzfassung:**
1. [portal.azure.com](https://portal.azure.com) → Azure AD → App registrations → New
2. Name: `SpoX+ AI`, Single Tenant
3. Redirect URI: `https://spox.hak-steyr.at/api/auth.php?action=callback`
4. Client Secret erstellen → in `.env.php` eintragen
5. API Permissions: `openid`, `profile`, `email`, `User.Read`

---

## Schritt 5: Google Gemini API Key

1. Gehe zu [aistudio.google.com](https://aistudio.google.com)
2. **Get API Key** → neuen Key erstellen
3. In `config/.env.php` eintragen: `define('GEMINI_API_KEY', 'AIza...');`

---

## Schritt 6: Apache VirtualHost

```apache
<VirtualHost *:443>
    ServerName spox.hak-steyr.at
    DocumentRoot /var/www/spox-ai/public

    <Directory /var/www/spox-ai/public>
        AllowOverride All
        Require all granted
    </Directory>

    # SSL
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/spox.hak-steyr.at/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/spox.hak-steyr.at/privkey.pem

    <Directory /var/www/spox-ai/api>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

> **FTP-Hosting ohne SSH:** Viele Shared-Hosting-Anbieter erlauben die Konfiguration des Document Root über das Control Panel (cPanel, Plesk). Setze das Document Root auf `public/`.

---

## Schritt 7: Berechtigungen prüfen

```bash
# Upload-Verzeichnis beschreibbar machen
chmod 755 /var/www/spox-ai/uploads/
chown www-data:www-data /var/www/spox-ai/uploads/

# Config schützen
chmod 640 /var/www/spox-ai/config/.env.php
```

---

## Schritt 8: Erster Test

1. Öffne `https://spox.hak-steyr.at` im Browser
2. Du siehst den Cookie-Banner → "OK" klicken
3. Klicke "Log in" → Microsoft-Login mit `@hak-steyr.at`-Konto
4. Nach Login: Initiale im Profil-Button sichtbar
5. Schreibe eine Test-Nachricht → Gemini antwortet per Streaming

---

---

## Sicherheits-Checkliste

- [ ] `config/.env.php` ist **nicht** öffentlich erreichbar
- [ ] `uploads/` ist **nicht** öffentlich erreichbar
- [ ] HTTPS ist aktiv (HSTS-Header gesetzt)
- [ ] Gemini API Key hat kein Budget-Limit (oder Rate Limit konfiguriert)
- [ ] Datenbanknutzer hat nur `SELECT, INSERT, UPDATE, DELETE` (kein `DROP`, `CREATE`)

---

## Datenschutz / DSGVO

- Datenschutzerklärung: `/docs/privacy.html`
- Impressum: `/docs/imprint.html`
- Daten-Export: Einstellungen → "Daten exportieren"
- Konto-Löschung: Einstellungen → "Konto löschen"

> ⚠️ Die Datenschutzerklärung ist eine technische Vorlage und muss vor dem Produktivbetrieb rechtlich geprüft werden.

---

**Logs löschen:** (Manuell in der Datenbank oder über API-Endpoint)

**Datenbank-Backup:**
```bash
mysqldump -u USERNAME -p DATENBANKNAME > backup_$(date +%Y%m%d).sql
```

**PHP-Session-Cleanup** (optional, Cron täglich):
```bash
0 3 * * * find /tmp -name 'sess_*' -mtime +1 -delete
```

---

## Technischer Stack

| Komponente | Technologie |
|---|---|
| Frontend | HTML5, Tailwind CSS (CDN), Vanilla JS |
| Backend | PHP 8.1+ |
| Datenbank | MySQL / MariaDB |
| KI | Google Gemini 1.5 Flash (Streaming SSE) |
| Auth | Microsoft Entra ID (OAuth2) |
| Hosting | Apache + FTP |

---

---

*SpoX+ AI — HAK Sport+ · BHAK & BHAS Steyr · 2026*

**Entwickelt von [Nikita Berger](https://nikityyy.github.io/)**
