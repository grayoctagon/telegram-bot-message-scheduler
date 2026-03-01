# Telegram Bot Scheduler (V1)

Prozedurales PHP Projekt für geplante Telegram Nachrichten mit JSON Persistenz.

## Voraussetzungen
- PHP 8.x mit curl
- Webserver mit .htaccess (Apache)
- Cron der alle 5 bis 10 Minuten `checkMsgCron.php` aufruft (CLI oder per URL mit key)

## Setup
1) `config/config.json` bearbeiten:
- bot_token
- public_base_url (z.B. https://example.com)
- allowed_emails
- groups + preannounce_groups
- cron.secret (zufälliger String)
- smtp (mail oder smtp)

2) Schreibrechte für `data/` geben.

3) Cron:
- CLI: `php /path/to/checkMsgCron.php`
- Web: `https://example.com/checkMsgCron.php?key=DEIN_SECRET`

## Hinweise
- Zeitformat: RFC3339 (`DateTime::RFC3339`)
- Zeitzone: Europe/Vienna (gesetzt in jedem Entry-Point)
- Vergangene Nachrichten, die nicht gesendet wurden, sind gesperrt (locked).
- Alte gesendete Nachrichten werden standardmäßig nach 8 Tagen ausgeblendet, können aber angezeigt werden.

## Dateien
- `data/msgs.json` (Messages)
- `data/audit.json` (Verlauf)
- `data/sessions.json` (Login Sessions)
- `data/ratelimit.json` (Login Rate Limit)
