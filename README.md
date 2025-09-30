# DEV Access & Environment Tools

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![Version](https://img.shields.io/badge/Version-1.2.2-orange.svg)](https://github.com/zb-marc/dev-access/releases)

Ein umfassendes WordPress-Plugin zur automatischen Erkennung von Entwicklungsumgebungen, visuellen Kennzeichnung, Zugriffsbeschränkung und Sicherheits-Audit-Logging mit erweiterten Sicherheitsfunktionen.

## 🌟 Features

### Kern-Funktionalitäten
- **Automatische Umgebungserkennung**: Unterscheidung zwischen DEV/STAGING/PRODUCTION basierend auf URLs
- **Visuelle DEV-Kennzeichnung**: Farbige Admin-Bar und Login-Seiten-Branding
- **Zugriffskontrolle**: IP-basierte und User-Agent-basierte Zugriffsbeschränkung
- **Security Audit Log**: Umfassendes Logging aller sicherheitsrelevanten Ereignisse
- **Login-Beschränkungen**: Rollenbasierte Login-Kontrolle für verschiedene Umgebungen

### Erweiterte Sicherheitsfeatures
- **Rate Limiting**: Schutz vor Brute-Force-Angriffen (5 Versuche pro Stunde)
- **Honeypot-Schutz**: Unsichtbare Felder zur Bot-Erkennung
- **IP-Validierung**: IPv4 und IPv6 Unterstützung mit Proxy-Header-Support
- **Audit-Retention**: Automatische Bereinigung alter Logs
- **Multi-Faktor-Validierung**: Kombinierte Sicherheitsmechanismen

## 📦 Installation

### Voraussetzungen
- WordPress 5.8 oder höher
- PHP 7.4 oder höher
- MySQL 5.6 oder höher
- Schreibrechte im wp-content Verzeichnis

### Installation via WordPress Admin
1. ZIP-Datei des Plugins herunterladen
2. Im WordPress Admin zu **Plugins → Installieren → Plugin hochladen** navigieren
3. ZIP-Datei auswählen und hochladen
4. Plugin aktivieren

### Manuelle Installation
```bash
# In das WordPress Plugin-Verzeichnis wechseln
cd wp-content/plugins/

# Plugin-Dateien kopieren
cp -r /path/to/dev-access ./

# Berechtigungen setzen
chmod 755 dev-access
chmod 644 dev-access/*.php
```

### Aktivierungs-Setup
Bei der Aktivierung werden automatisch:
- Audit-Log-Tabelle erstellt
- Standard-Einstellungen konfiguriert
- Sicherheitsfeatures aktiviert

## ⚙️ Konfiguration

### 1. Umgebungserkennung
```
Admin → Einstellungen → Environment Tools → Environment Detection
```

**Development URLs**: 
- Beispiele: `dev.example.com`, `staging.example.com`, `localhost`
- Kommaseparierte Liste von Hostnamen (ohne https://)

**Production URLs**:
- Beispiele: `example.com`, `www.example.com`
- Produktiv-Domains für spezielle Behandlung

**Fallback Mode**:
- ✓ Aktivieren = Unbekannte URLs als DEV behandeln
- ✗ Deaktivieren = Unbekannte URLs als PRODUCTION behandeln

### 2. Visuelle Anpassungen

**Admin Bar Styling**:
- Farbe der Admin-Bar in DEV-Umgebung anpassen
- Standard: Rot (#d63638)

**Login Page Customization**:
- DEV-Warnung auf Login-Seite
- Anpassbare Texte und Farben
- Logo-Ersetzung durch Environment-Hinweis

### 3. Zugriffsbeschränkung

#### IP-Whitelist
```php
// Erlaubte IPs (eine pro Zeile oder kommasepariert)
192.168.1.100
10.0.0.5
2001:db8::1  // IPv6 unterstützt
```

#### User-Agent Whitelist
Vordefinierte Services:
- Google PageSpeed (Googlebot, Lighthouse)
- GTmetrix
- Screaming Frog SEO Spider
- Google Gemini Research

Custom User-Agents:
```
Mozilla/5.0 CustomBot
MyMonitoringService/1.0
```

### 4. Login-Beschränkungen

**Rollenbasierte Kontrolle**:
- DEV-Umgebung: Minimum-Rolle festlegen (z.B. nur Editoren+)
- LIVE-Umgebung: Separate Kontrolle (z.B. nur Administratoren)

Verfügbare Rollen-Stufen:
1. Administrator (höchste)
2. Editor
3. Author
4. Contributor
5. Subscriber (niedrigste)

### 5. Sicherheitseinstellungen

**Trust Proxy Headers**:
```php
// Nur aktivieren bei:
- Cloudflare
- Load Balancers
- Reverse Proxies
```

**Rate Limiting**:
- 5 Login-Versuche pro Stunde pro IP
- Automatisches Blockieren bei Überschreitung
- Transient-basierte Speicherung

**Honeypot-Schutz**:
- Unsichtbares Feld `daet_email_confirm`
- Bots füllen es aus = Blockierung

## 🔍 Verwendung

### Umgebungskonstanten

Nach Plugin-Aktivierung steht global zur Verfügung:
```php
// In Theme oder Plugin nutzen
if (defined('WP_ENV')) {
    if (WP_ENV === 'development') {
        // DEV-spezifischer Code
        error_reporting(E_ALL);
    } elseif (WP_ENV === 'production') {
        // PRODUCTION-spezifischer Code
        error_reporting(0);
    }
}
```

### Debug-Einstellungen

In DEV-Umgebung automatisch gesetzt:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Security Audit Log

Zugriff über: **Tools → Security Audit Log**

Geloggte Events:
- `login_success` - Erfolgreiche Anmeldungen
- `login_failed` - Fehlgeschlagene Login-Versuche
- `login_role_denied` - Rollenbasierte Ablehnung
- `rate_limit_exceeded` - Rate-Limit überschritten
- `honeypot_triggered` - Bot-Erkennung
- `settings_changed` - Plugin-Einstellungen geändert
- `access_denied` - Zugriffsverweigerung
- `audit_log_cleared` - Log-Löschung

## 🛠️ Entwickler-Dokumentation

### Hooks & Filter

#### Actions
```php
// Nach Plugin-Aktivierung
do_action('daet_activation');

// Nach Umgebungserkennung
do_action('daet_environment_detected', WP_ENV);

// Nach Audit-Log-Eintrag
do_action('daet_audit_logged', $event_type, $severity, $details);
```

#### Filter
```php
// Umgebung überschreiben
add_filter('daet_environment', function($env) {
    return 'custom_environment';
});

// Audit-Log-Retention anpassen
add_filter('daet_audit_retention_days', function($days) {
    return 60; // 60 Tage statt 30
});

// IP-Adresse modifizieren
add_filter('daet_client_ip', function($ip) {
    // Custom IP-Logik
    return $ip;
});
```

### Datenbank-Struktur

#### Tabelle: `wp_daet_audit_log`
```sql
CREATE TABLE wp_daet_audit_log (
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(50) NOT NULL,
    event_severity VARCHAR(20) DEFAULT 'info',
    user_id BIGINT(20) DEFAULT 0,
    username VARCHAR(100) DEFAULT '',
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    event_details LONGTEXT,
    event_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY event_type (event_type),
    KEY event_time (event_time),
    KEY ip_address (ip_address)
);
```

### Programmatische Nutzung

#### Audit-Log schreiben
```php
// Eigenen Event loggen
daet_audit_log(
    'custom_event',           // Event-Type
    'warning',                // Severity: info|warning|error|critical
    array(                    // Details
        'action' => 'delete',
        'item_id' => 123
    )
);
```

#### Client-IP ermitteln
```php
// Sichere IP-Ermittlung
$ip = daet_get_client_ip();
```

#### Umgebung prüfen
```php
// Ist DEV-Umgebung?
if (defined('WP_ENV') && WP_ENV === 'development') {
    // DEV-spezifische Aktionen
}
```

### WP-CLI Commands

```bash
# Audit-Log anzeigen (zukünftig)
wp daet audit list --limit=50

# Umgebung prüfen
wp eval "echo WP_ENV;"

# Debug-Status
wp eval "var_dump(WP_DEBUG, WP_DEBUG_LOG);"
```

## 📊 Performance-Optimierung

### Empfohlene Einstellungen

1. **Audit-Retention**: 30 Tage (Standard) - bei viel Traffic auf 7-14 Tage reduzieren
2. **Rate-Limiting**: Aktiviert lassen zum Schutz vor Brute-Force
3. **Honeypot**: Aktiviert für Bot-Schutz ohne Performance-Einbußen
4. **Debug-Log**: In PRODUCTION deaktivieren

### Cron-Jobs

Automatisch registriert:
```bash
# Alte Audit-Logs bereinigen (täglich)
wp cron event run daet_cleanup_audit_logs

# Status prüfen
wp cron event list | grep daet
```

### Datenbank-Optimierung

```sql
-- Index-Optimierung für große Audit-Logs
ALTER TABLE wp_daet_audit_log 
ADD INDEX idx_severity_time (event_severity, event_time);

-- Manuelles Cleanup (älter als 30 Tage)
DELETE FROM wp_daet_audit_log 
WHERE event_time < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

## 🐛 Debugging

### Debug-Modus
```php
// In wp-config.php für erweiterte Logs:
define('DAET_DEBUG', true);
```

### Log-Analyse
```bash
# Fehlerhafte Logins
SELECT * FROM wp_daet_audit_log 
WHERE event_type = 'login_failed' 
ORDER BY event_time DESC LIMIT 20;

# Rate-Limits
SELECT ip_address, COUNT(*) as attempts 
FROM wp_daet_audit_log 
WHERE event_type = 'rate_limit_exceeded' 
GROUP BY ip_address;
```

### Häufige Probleme

#### Audit-Tabelle nicht erstellt
```php
// Manuell erstellen via:
// Admin → Tools → Security Audit Log
// Oder Code ausführen:
daet_create_audit_table();
```

#### Falsche Umgebungserkennung
1. URLs in Einstellungen prüfen (ohne https://)
2. Fallback-Mode Status überprüfen
3. `WP_ENV` Konstante debuggen

#### IP-Adresse immer 0.0.0.0
1. Trust Proxy Headers aktivieren (bei Cloudflare etc.)
2. Server-Konfiguration prüfen
3. `$_SERVER['REMOTE_ADDR']` verfügbar?

## 📈 Changelog

### Version 1.2.2 (Aktuell)
- **Audit-Log**: Vollständiges Security-Audit-System
- **Rate-Limiting**: Brute-Force-Schutz implementiert
- **Honeypot**: Bot-Schutz hinzugefügt
- **IP-Validierung**: IPv4/IPv6 mit Proxy-Support
- **UI-Verbesserungen**: Bessere Admin-Interface

### Version 1.2.0
- Login-Beschränkungen nach Rollen
- Erweiterte User-Agent-Erkennung
- Performance-Optimierungen

### Version 1.1.0
- Multi-URL-Support
- Fallback-Modus
- Debug-Log-Integration

### Version 1.0.0
- Initiale Veröffentlichung
- Basis-Umgebungserkennung
- Admin-Bar-Styling

## 🤝 Support & Beitrag

### Support
- **Website**: [https://akkusys.de](https://akkusys.de)
- **Entwickler**: Marc Mirschel
- **Entwickler-Website**: [https://mirschel.biz](https://mirschel.biz)

### Fehler melden
Bitte erstellen Sie detaillierte Fehlerberichte mit:
- WordPress-Version
- PHP-Version
- Aktive Plugins
- Debug-Log-Auszüge
- Schritte zur Reproduktion

### Sicherheitslücken
**WICHTIG**: Sicherheitslücken bitte NICHT öffentlich melden!
Senden Sie Security-Issues direkt an den Entwickler.

### Beitragen
Pull Requests sind willkommen! Bitte beachten Sie:
- WordPress Coding Standards einhalten
- PHPDoc für alle Funktionen
- Sicherheits-Best-Practices befolgen
- Keine Debug-Ausgaben in Production-Code

## 📄 Lizenz

Dieses Plugin ist unter der MIT-Lizenz veröffentlicht. Siehe [LICENSE](LICENSE) für Details.

## 🙏 Credits

- **Entwicklung**: Marc Mirschel
- **Sponsor**: AKKUSYS GmbH
- **Framework**: WordPress
- **Icons**: Dashicons (WordPress Core)
- **Testing**: Community-Beiträge

## 🔒 Sicherheitshinweise

1. **Produktiv-Einsatz**: Fallback-Mode in Produktion IMMER deaktivieren
2. **Trust Proxy**: Nur bei vertrauenswürdigen Proxies aktivieren
3. **Audit-Logs**: Regelmäßig prüfen auf verdächtige Aktivitäten
4. **IP-Whitelist**: Nur vertrauenswürdige IPs hinzufügen
5. **API-Keys**: Niemals im Code hart kodieren

---

*DEV Access & Environment Tools - Professionelle Umgebungsverwaltung und Sicherheit für WordPress*
