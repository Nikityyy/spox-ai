# SpoX+ AI Setup-Guide

Hier ist die Anleitung, wie du SpoX+ AI auf deinem Server zum Laufen bringst. Das Ganze ist für die **HAK Sport+ (BHAK & BHAS Steyr)** optimiert und sollte eigentlich recht fix gehen, wenn du dich an die Schritte hältst.

---

## Was du brauchst (Minimalanforderung)

Bevor du loslegst, check kurz ob dein Hosting das hier packt:
- **PHP 8.1 oder neuer** (ganz wichtig wegen der neuen Funktionen)
- **MySQL / MariaDB** (standard bei jedem Hoster)
- **Apache Webserver** (mit `mod_rewrite`, damit die Links funktionieren)
- **HTTPS** (ohne SSL-Zertifikat wird der Login nicht funktionieren)

---

## 1. Dateien hochladen (via FTP/FileZilla)

Der Server bei uns in der Schule hat oft strikte Regeln (`open_basedir`), deshalb habe ich alles so gebaut, dass es direkt im `httpdocs`-Ordner liegt. 

1. Lade den kompletten Inhalt in dein `httpdocs/` Verzeichnis.
2. Achte darauf, dass die `.htaccess` Dateien (in `config/` und `uploads/`) mit dabei sind – die schützen deine Daten!
3. **Ganz wichtig:** Die Datei `ROOT_htaccess_upload_this_to_httpdocs_root.txt` musst du direkt in `httpdocs/` legen und in **`.htaccess`** umbenennen. Sonst funktionieren die Routen nicht.

---

## 2. Datenbank einrichten

1. Erstell eine neue Datenbank in deinem Control Panel (z.B. Plesk oder cPanel).
2. Importier die Datei `sql/init.sql` (einfach in phpMyAdmin auf "Importieren" gehen). Damit werden alle Tabellen für Chats und User automatisch angelegt.

---

## 3. Die Config anpassen (`.env.php`)

Geh in den Ordner `config/` und öffne die `.env.php`. Hier musst du deine Zugangsdaten eintragen:

- **DB_...**: Deine Datenbank-Infos.
- **MS_...**: Die Daten für den Microsoft-Login (siehe nächster Schritt).
- **GEMINI_API_KEY**: Dein Key von Google.
- **UPLOAD_DIR**: Der absolute Pfad zu deinem `uploads/` Ordner.

---

## 4. Microsoft Login (Azure AD) einrichten

Damit sich Schüler mit ihrem schulkonto einloggen können, musst du eine "App Registration" machen:

1. Geh ins [Azure Portal](https://portal.azure.com) → **App-Registrierungen** → **Neue Registrierung**.
2. Wähl "Nur Konten in diesem Organisationsverzeichnis" (Single Tenant).
3. Als **Redirect URI** nimmst du: `https://deine-domain.at/api/auth.php?action=callback`.
4. Erstell unter "Zertifikate & Geheimnisse" ein neues **Client Secret**. Kopier den **Wert** (nicht die ID!) direkt in deine `.env.php`.
5. Bei "API-Berechtigungen" brauchst du: `email`, `openid`, `profile` und `User.Read`.

---

## 5. Google Gemini Key holen

Ohne KI kein SpoX+ AI. 
1. Hol dir einen kostenlosen API-Key bei [aistudio.google.com](https://aistudio.google.com).
2. Pack den Key in die `.env.php` bei `GEMINI_API_KEY`.

---

## 6. Server-Check & Rechte

Wenn du einen eigenen Server hast, musst du noch kurz die Rechte checken:
- Der `uploads/` Ordner muss für den Webserver beschreibbar sein (`chmod 755`).
- Die `.env.php` sollte am besten gar nicht von außen erreichbar sein (dafür sorgt die `.htaccess` im config-Ordner).

---

## 7. Letzter Check

Probier es aus:
1. Geh auf deine Domain.
2. Schau ob der Header richtig lädt.
3. Versuch dich einzuloggen.
4. Schreib eine Nachricht.
