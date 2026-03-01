# telegram-bot-message-scheduler

A simple, DAU-friendly procedural PHP web UI to create and manage scheduled Telegram bot messages (JSON storage, AJAX save/load, cron-based sending, optional pre-announcements, email-only login, full audit history).

Licensed under CC-BY-SA-4.0: you can use, share, and adapt them with proper attribution, and any modifications must be shared under the same license.

## AI
This project was partially developed with the support of ChatGPT. Parts of the code, documentation, and/or structural suggestions were generated using AI and subsequently reviewed, adapted, and integrated by me. Despite careful review, the code may contain errors. Use at your own risk.


## old and new
in the old_src folder is the old minimalistic version, in src is the new one

## Features
- Scheduled Telegram group messages (bot)
- Optional pre-announcement to an admin group (X hours before)
- “Quick Msg” (send now, stored like a normal message)
- Simple list UI (Telegram-like), shows time until send date and gaps between messages
- Past unsent messages are read-only
- Append-only change history (never deleted)
- JSON-only storage with lock file + retry (no SQL)

## Requirements
- PHP 
- Apache (recommended) + cron (every 5 to 10 minutes)
- SMTP access for login codes
- Telegram bot token, bot added to the target group(s)

## Setup (short)
1) Create `config/config.json` (token, SMTP, allowed users, group IDs). Keep it out of the public web root if possible.
2) Create `data/` (writable by PHP): `msgs.json`, `sessions.json`, `history.json`, `ratelimit.json`.
3) Protect secrets via Apache:
   - deny direct access to `config/` and `data/` (for example with `.htaccess`)
4) Cron:
   - call `cron/checkMsgCron.php` every 5 to 10 minutes.

## Time format
- Use `DateTime::RFC3339` everywhere.
- In every PHP entry point:
```php
<?php
date_default_timezone_set('Europe/Vienna');
```

## Notes
- Sent state must be stored in `data/msgs.json` (for example `wasSend` and `sent_at` in RFC3339).
- Old sent messages (older than 8 days) are hidden by default, toggleable in the UI.

### AI prompts 
hey, ich hab eine projekt-idee, kannst du mir kurz helfen es Software Engineering technisch durch zu denken? Technisch soll es möglichst simpel sein, also mit php (ohne Objekt Orientierung) und einer einfachen smtp Option, via konfigurierbarem server, Konto, und absendeadresse. Es geht um eine Website zur Steuerung eines Telegramm Bots. Ziel soll es sein auf der Website für einen Telegramm Bot Geplante Nachrichten zu erstellen und zu organisieren, die "von" diesem dann an einem bestimmten Tag zu einer Bestimmten Zeit in eine Telegramm Gruppe sendet, optional auch X Stunden davor in eine andere "Admins" Gruppe. (es wird einen cron job geben der alle 5-10 minuten eine php datei aufruft, z.B. checkMsgCron.php) Es soll wie eine lange Liste aufgebaut sein (nach datum sortiert) in der es für jede Nachricht ein div gibt. Darin kann man jeweils (optisch ähnlich einer TG Nachricht) den Inhalt der Nachrichten in einer Text Area eingeben und das Datum auswählen wann es versandt werden soll. Und man soll einen "speichern" button im header haben (position: fixed-> scrollt mit). Zwischen den Nachrichten steht (wird von JS erstellt) wieviel Zeit zwischen Nachrichten liegt z.B. "2 Wochen, 2 Tage, 3 Stunden" Serverseitig soll geloggt werden was beim speichern verändert wurde (auch wann und von wem), was sich auch user anzeigen können lassen sollen. Im Header gibt es also "Nachrichten", "Verlauf", "User"(für Einstellungen des Kontos, bzw die Option sich überall abzumelden -> sucht Session des Users und beendet diese), "speichern", "logout". Das laden und das speichern der nachrichten soll via ajax passieren, inkl "loading" Indikator. Es soll ein normales und einfaches (auf Dau bedachtes) User Interface geben. Der Login soll nur via e-mail möglich sein, also man gibt eine mail Adresse ein, dann bekommt man einen 8 stelligen code zugesendet und damit kann man sich authentifizieren. (um es möglichst einfach zu machen, wird der code serverseitig erstellt, per mail versandt und in der $Session hinterlegt um nicht unnötig viele Files verwalten zu müssen, mit maximaler Gültigkeit von 2 Stunden). Und im Hintergrund eine Konfigurations- Datei (als json) die vom Administrator konfiguriert wird(direkt am server ohne Oberfläche), unter anderem ist darin der Telegram-Bot-Token, aber auch die möglichen ziel Gruppen (also das Default Ziel mit Gruppen ID + Label und die Vor-Ankündigungs Ziel Gruppen-ID + Label. Meinst du hier sind alle Aspekte gut beschrieben, was würdest du detaillierter Beschreiben, wo fehlen Informationen?

Nachgedacht für 24s
März 2026
Du hast schon sehr viel sauber beschrieben (Ziel, UI-Idee, Cron, Auth per Mailcode, Konfig per JSON). Was ich noch detaillierter spezifizieren würde (weil es später sonst an genau diesen Stellen knirscht):
1) Persistenz und Datenmodell (entscheidet fast alles)
Du beschreibst UI und Cron, aber noch nicht, wo Nachrichten und Verlauf dauerhaft liegen sollen.
Speicher (empfohlen für “auf Dauer”): SQLite oder MySQL (statt JSON-Dateien), weil du sonst schnell Probleme mit Concurrent Writes, Backup, Filtern und History bekommst.
Für jede geplante Nachricht brauchst du mindestens:
id
text (inkl. ggf. ParseMode wie HTML/Markdown)
send_at (Datum, Uhrzeit, Zeitzone)
target_chat_id (Gruppe)
optional: preannounce_enabled, preannounce_hours_before, preannounce_chat_id
status (pending, sent, failed, cancelled)
last_error, retry_count
created_by, updated_by, created_at, updated_at
Für “Verlauf” (Audit Log) brauchst du: message_id, user_id(email), timestamp, action, before_json, after_json (oder diff), plus optional ip, user_agent.
2) Zeit, Zeitzone, Sommerzeit (kritisch bei Terminversand)
Du sagst “bestimmter Tag und Zeit”, aber nicht, in welcher Zeitzone das gilt.
Entscheidung: Alle Zeiten werden als “Europe/Vienna” eingegeben und gespeichert (und in UTC umgerechnet), oder alles rein in UTC.
Sommerzeit-Umstellung: Es gibt Uhrzeiten, die es 1x im Jahr nicht gibt, und andere, die es doppelt gibt. Definiere, wie UI das handhabt.
Cron alle 5 bis 10 Minuten: Definiere das Sendefenster (zum Beispiel “alles senden, was send_at <= now und noch pending ist”).
3) Idempotenz und Doppelversand (Cron-Sicherheit)
Damit nicht zweimal gesendet wird (z.B. zwei Cron-Läufe nah beieinander, oder ein Lauf braucht länger):
Beim “fällig” Finden musst du atomar locken: erst Status von pending auf sending setzen (mit WHERE status=pending), dann senden, dann auf sent.
Falls Senden fehlschlägt: Retry-Regeln (wie oft, in welchen Abständen), und wann ein Eintrag endgültig “failed” ist.
Pre-Announcement: eigenes Statusfeld (preannounce_sent_at, preannounce_status), sonst kommt auch da Doppelversand.
4) Telegram Details (Permissions, API, Format)
Hier fehlen noch ein paar harte Fakten, die die Umsetzung beeinflussen:
Bot muss in Zielgruppe sein und Schreibrechte haben (und ggf. Admin, je nach Gruppeinstellungen).
Unterstützte Message-Features: nur Text, oder auch Emojis, Links, Markdown/HTML, Silent Messages, Replies, Attachments.
Fehlerfälle: Bot kicked, Chat-ID falsch, rate limits. Was sieht der User dann im UI (Status, Fehlermeldung).
5) Auth per E-Mail Code (Sicherheit und UX)
“Ich speichere den Code in $_SESSION” ist simpel, hat aber Grenzen:
Wenn User Browser wechselt oder Session weg ist, ist der Code “weg” (trotz gültiger Mail).
Rate limiting ist wichtig (pro Mail, pro IP, pro Zeitfenster), sonst Code-Spam und Bruteforce.
Code nicht im Klartext speichern (mindestens hash + expiry).
“Logout überall” ist nur sauber möglich, wenn du Sessions serverseitig trackst (z.B. session_id in DB mit user_id und “revoked_at”), sonst kannst du nicht zuverlässig “alle Sessions” finden und killen.
6) Speichern via AJAX (Konflikte, Validierung, Versionierung)
Bei “lange Liste” und mehreren Nutzern oder mehreren Tabs:
Konfliktstrategie: last write wins, oder optimistic locking (message.version mitsenden, wenn Version nicht passt, UI warnt).
Validierung serverseitig: leere Nachrichten, zu lange Texte, ungültige Uhrzeit, Zielgruppe nicht erlaubt (Whitelist aus JSON).
CSRF-Schutz (auch bei AJAX), sonst kann eine fremde Seite im eingeloggten Zustand speichern.
7) Konfig JSON (Ablage, Secrets, Reload)
Token und SMTP Zugangsdaten dürfen nicht im Webroot liegen.
Was passiert bei Konfig-Änderung zur Laufzeit (neuladen pro Request, oder cached).
Struktur definieren (Beispiel): smtp(host, port, user, pass, from), telegram(token), groups(list of {id,label,type}), defaults.
8) UI Details, die später teuer werden, wenn unklar
Pagination oder Lazy Loading (bei “lange Liste” wird es sonst zäh).
Autosave vs. manuell Speichern (du willst “Speichern” fixed, aber was ist bei ungespeicherten Änderungen beim Logout oder Tab schließen).
“Zeit zwischen Nachrichten” wird von JS berechnet (ok), aber was ist, wenn zwei Nachrichten identische send_at haben.
9) Betrieb und Wartung
Backup-Plan (DB sichern, Config sichern).
Log-Retention (wie lange Verlauf aufheben).
Monitoring: Cron-Lauf protokollieren (letzter Lauf, wie viele gesendet, Fehlercount).
Wenn du das noch ergänzst, ist die Spezifikation “rund”
Entscheidung für Speicher (SQLite empfehlenswert) + grobes Schema
Zeitzonen-Regel (UI-Zeitzone, Speicherung UTC oder lokal)
Cron-Idempotenz (status workflow)
Auth-Sicherheit (rate limit, hash, logout überall Mechanik)
Konfliktstrategie beim Speichern
Wenn du magst, kann ich dir daraus direkt eine sehr konkrete Mini-Spezifikation machen (Endpoints, DB Tabellen, Status-Maschine, und ein Dateilayout für prozedurales PHP).


ja date+time muss jedenfalls in DateTime::RFC3339 sein. Ich will aber definitiv json, es wird nur von einer Hand voll leute betrieben, da reicht das mit lock file + retry. Kein SQL! auch eine .htaccess wäre gut, damit konfig nicht ausgelesen werden kann was noch gut wäre, wäre beim sende datum in der UI (via js) anzuteigen wie lange es noch dauert bis dahin. Und ja, in der msgs.json muss auch gespeichert werden ob(/wann) eine nachricht gesendet wurde was auch praktisch wäre, wäre ein "quick msg" wo mann schnell und sofort eine nachricht via bot senden kann -> soll natürlich gleich behandelt werden wie eine normale neu angelegte Nachricht, aber eben mit Zeit=jetzt und einfach nur einer eingabe Box. Aja, die eingabe soll nicht fancy sien, wie gesagt DAU kompatibel ;) einfacher Text, mit utf-8 für emojies, soll man einfügen können, aber einfach via copy paste, bzw der system tastatur(also in der Anwendung braucht es dazu kein eigenes feature). Und ganz wichtig: vergangene Nachrichten (wasSend==false) sollen nicht bearbeitet werden können. w.g. login.. ok, machen wir eine sessions.json will sich jemand anmelden, also hat die Mail Adresse eingegeben und ist diese in der konfig vorhanden (ja rate limit ist sinnvoll, sagen wir max einmal alle 5 minuten und max 5 mal am tag) dann wird ein loginkey estellt, der wird via password_hash in der sessions.json eingetragen (inkl valid_to (DateTime::RFC3339), mailadresse, ip, user_agent, etc) und in der mail ist dann der code zum eintippen aber auch eine url mit der die session aktiviert werden kann. nur die session in der der login angefragt wurde, nicht die in der die url aufgerufen wird! der nachrichten verlauf wird nie gelöscht, bereits gesendete nachrichten werden in der UI ausgeblendet wenn es mehr als 8 tage her ist, aber es gibt auch eine checkbox im header "alte nachrichten anzeigen" dann werden alle alten nachrichten angezeigt. Zum scope: es wird etwa 5 user geben und es wird etwa 20 nachrichten im jahr geben, json reicht also vollkommen aus gib bitte immer "date_default_timezone_set('Europe/Vienna');" an Kannst du mir bitte nochmal alle bis jetzt von mir definierten anforderungen / Beschreibungen zusammenfassen?



März 2026
Hier ist eine konsolidierte Zusammenfassung aller von dir bisher definierten Anforderungen und Beschreibungen (inkl. deiner neuen Ergänzungen):
0) Grundsatz / Tech-Stack
Implementierung so simpel wie möglich in PHP prozedural (ohne OOP).
In jedem PHP Entry-Point wird gesetzt:
date_default_timezone_set('Europe/Vienna');
Persistenz ausschließlich über JSON-Dateien (kein SQL).
Concurrency-Schutz beim Schreiben über Lock-File + Retry.
UTF-8 Text (inkl. Emojis via Copy/Paste oder System-Tastatur), keine “fancy” Eingabe-Features notwendig.
1) Zweck der Anwendung
Website dient zur Steuerung eines Telegram Bots.
User erstellen und verwalten geplante Nachrichten, die der Bot zu einem definierten Zeitpunkt an eine Telegram-Gruppe sendet.
Optional kann derselbe Eintrag X Stunden vorher eine Ankündigung an eine definierte “Admins”-Gruppe senden.
2) Telegram Versand-Logik
Es gibt einen Cronjob, der alle 5 bis 10 Minuten eine PHP Datei aufruft (z.B. checkMsgCron.php).
Cron prüft fällige Nachrichten und sendet sie via Telegram Bot.
In der Nachrichten-Datei (z.B. msgs.json) wird gespeichert:
ob eine Nachricht bereits gesendet wurde
und/oder wann sie gesendet wurde (Zeitformat siehe unten)
Nachrichten, die in der Vergangenheit liegen und nicht gesendet wurden (wasSend == false), dürfen nicht bearbeitet werden.
3) Zeitformat und Zeitzone
Datum/Zeit werden im gesamten System im Format DateTime::RFC3339 geführt.
Server-Zeitzone ist fix: Europe/Vienna (per date_default_timezone_set).
4) Konfiguration (Admin-only, ohne UI)
Eine Konfigurationsdatei als JSON, wird vom Administrator direkt am Server gepflegt (ohne Web-Oberfläche).
Enthält unter anderem:
Telegram Bot Token
SMTP Konfiguration (Server, Konto, Absenderadresse)
Whitelist der erlaubten User-Mailadressen (Login nur wenn Mail in Config vorhanden)
Zielgruppen-Konfiguration:
Default Zielgruppe (Gruppen-ID + Label)
Pre-Announcement/Admin Gruppe(n) (Gruppen-ID + Label)
Zugriffsschutz: .htaccess soll verhindern, dass sensible JSON-Dateien (Config) ausgelesen werden können.
5) UI / Bedienkonzept (DAU-kompatibel)
“Normales, einfaches UI” (auf Dauer wartbar, keine Spielereien).
Hauptansicht ist eine lange Liste, nach Datum sortiert.
Für jede Nachricht gibt es ein eigenes DIV, optisch ähnlich einer Telegram-Nachricht.
Pro Nachricht:
Text in einer Textarea
Versanddatum/-zeit auswählbar
Anzeige, wie lange es noch dauert bis zum Versandzeitpunkt (per JS)
Zwischen den Nachrichten zeigt JS die Zeitdifferenz an (z.B. „2 Wochen, 2 Tage, 3 Stunden“).
Im Header (fixed, scrollt mit) gibt es Navigation/Buttons:
Nachrichten
Verlauf
User (Einstellungen + “überall abmelden”)
Speichern
Logout
Zusätzlich im Header:
Checkbox „alte Nachrichten anzeigen“:
Standard: bereits gesendete Nachrichten, die älter als 8 Tage sind, werden ausgeblendet
Mit Checkbox: alle alten Nachrichten werden angezeigt
“Quick Msg” Funktion:
Eine schnelle Eingabebox um sofort eine Nachricht via Bot zu senden
Wird intern wie eine normale Nachricht behandelt, nur mit Zeit = jetzt
Wird also ebenfalls in msgs.json erfasst inkl. Sendestatus/Zeitpunkt
6) Laden/Speichern (AJAX)
Nachrichten werden per AJAX geladen und gespeichert.
UI zeigt dabei einen Loading-Indikator.
Es gibt einen zentralen Speichern-Button im fixed Header (kein pro Nachricht “Speichern” als Pflicht-Mechanik; Speichern ist global gedacht).
7) Änderungsprotokoll / Verlauf (Audit)
Serverseitiges Logging: beim Speichern wird protokolliert:
was sich geändert hat
wann es geändert wurde
von wem (User-Mail)
Dieser Verlauf ist im UI unter „Verlauf“ einsehbar.
Verlauf wird nie gelöscht.
8) Login / Session-Konzept (E-Mail only, Code + Link)
Login ausschließlich per E-Mail (kein Passwort).
Ablauf:
User gibt Mailadresse ein.
Nur wenn Mail in Config erlaubt ist, wird Login gestartet.
Rate Limit:
max 1 Anfrage alle 5 Minuten
max 5 Anfragen pro Tag
Server erstellt einen Login-Key/Code:
wird in sessions.json gespeichert (gehasht mit password_hash)
Session-Daten enthalten mindestens:
valid_to (RFC3339, max 2h)
mailadresse
ip
user_agent
Status/Flags (implizit: angefragt/aktiviert)
Mail enthält:
den 8-stelligen Code zum Eintippen
und zusätzlich eine URL zur Aktivierung
Wichtige Sonderregel:
Die Aktivierung (per URL) soll nur die Session aktivieren, in der der Login angefragt wurde, nicht die Session/Browser-Session, in der die Aktivierungs-URL geöffnet wird (also keine “Login in neuem Browser durch Klick”, sondern Bindung an die ursprüngliche Anfrage-Session).
9) Logout überall
In “User” gibt es eine Funktion „überall abmelden“:
sucht alle Sessions des Users und beendet diese (über sessions.json).
Wenn du willst, kann ich als nächsten Schritt aus dieser Zusammenfassung direkt ein “Mini-Pflichtenheft” machen: Dateistruktur, JSON-Schemas (config.json, msgs.json, sessions.json, audit.json), Endpoints für AJAX, und die Statusfelder (pending/sent/failed, preannounce status), alles in deinem prozeduralen PHP-Stil.



bitte programmiere mir direkt Version1 als zip



Nachgedacht für 7m 48s
Hier ist Version 1 als ZIP:
Download tg_bot_scheduler_v1.zip
Kurze Setup-Notizen:
config/config.json anpassen (Bot Token, public_base_url, allowed_emails, Gruppen, cron.secret, SMTP)
Schreibrechte für data/ sicherstellen
Cron:
CLI: php /pfad/zum/checkMsgCron.php
Web: /checkMsgCron.php?key=DEIN_SECRET (Secret steht in config.json)
.htaccess schützt config/, data/ und alle .json Dateien vor direktem Zugriff (Apache 2.4)



## License: 
Attribution-ShareAlike 4.0 International CC-BY-SA 

(details see LICENSE.txt file)

[![CC-BY-SA](https://i.creativecommons.org/l/by-sa/4.0/88x31.png)](#license)

