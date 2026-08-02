# Objektfrei – Deployment ohne WordPress

Statische Seite, PHP nur für das Anfrageformular. Kein CMS, keine Datenbank. Der Stack
läuft hinter einem bereits vorhandenen Traefik.

## Was hier drin ist

```
docker-compose.yml      nginx und PHP-FPM, angebunden an Traefik
nginx/objektfrei.conf   Routing, Sicherheits-Header, Auslieferung von site/
.env.example            Vorlage für Zugangsdaten → nach .env kopieren
app/Dockerfile          PHP 8.3 mit GD und PHPMailer
app/composer.json       einzige Abhängigkeit: PHPMailer
app/anfrage.php         Formular-Endpunkt
app/php.ini             abgesicherte PHP-Einstellungen
site/                   die Website (index.html, impressum.html, datenschutz.html)
```

## Voraussetzungen

Ein kleiner Server mit Docker – Hetzner Cloud CX22 (rund 5 €/Monat) genügt. **Klassisches
Webhosting reicht nicht**, dort läuft kein Docker. Die Domain muss per A-Record (und AAAA,
falls IPv6) auf die Server-IP zeigen, für `objektfrei.de` und `www.objektfrei.de`.

Auf dem Server muss Traefik laufen und folgendes bereitstellen:

* das externe Docker-Netzwerk `web` (`docker network create web`, falls nicht vorhanden),
* die Entrypoints `web` (Port 80) und `websecure` (Port 443),
* den Zertifikats-Resolver `letsencrypt`.

Sind die Namen bei dir anders, in `docker-compose.yml` in den Labels anpassen. Dieser
Stack veröffentlicht selbst keine Ports – Ports, TLS und Zertifikate macht Traefik.

## Einrichten

```bash
git clone <repo> /opt/objektfrei && cd /opt/objektfrei
cp .env.example .env && nano .env          # Domain und SMTP-Daten eintragen
docker compose up -d --build
docker compose logs -f web                 # Zugriffe; Zertifikat siehe Traefik-Logs
```

Danach in `site/index.html` den Endpunkt aktivieren – ganz oben im Skriptbereich:

```js
const FORM_ENDPOINT='/anfrage.php';
```

Solange dort ein leerer Text steht, wird die Anfrage nur in der Browser-Konsole angezeigt
und **nicht versendet**.

## SMTP über Google Workspace

Das normale Kontopasswort funktioniert nicht. Unter `myaccount.google.com/apppasswords`
ein App-Passwort erzeugen und in `.env` als `SMTP_PASS` eintragen. Zwei-Faktor-Anmeldung
muss dafür aktiv sein.

## Wie die Bilder abgesichert sind

Nicht durch einen Virenscanner, sondern durch die Bauweise:

1. Geprüft wird der **echte Dateiinhalt** (`getimagesize`), nicht die Endung.
2. Jedes Bild wird **neu gezeichnet** und als JPEG gespeichert. Dabei geht alles verloren,
   was kein Bildpunkt ist – eingebetteter Schadcode ebenso wie EXIF- und GPS-Daten.
3. Gespeichert wird **außerhalb des Webverzeichnisses** (`/var/objektfrei/uploads`) unter
   einem Zufallsnamen. nginx kennt dieses Verzeichnis nicht.
4. PHP wird ausschließlich für `/anfrage.php` ausgeführt, sonst für keine Datei. Das Skript
   selbst liegt im PHP-Image unter `/app` und damit ebenfalls außerhalb des Webverzeichnisses.
5. Grenzen: 5 Bilder, je 8 MB, zusammen 30 MB, maximal 60 Megapixel je Bild.

Optionaler Virenscanner: in `docker-compose.yml` den `clamav`-Block einkommentieren und
`CLAMAV_HOST=clamav` in `.env` setzen. Braucht rund 1 GB zusätzlichen Arbeitsspeicher.
Ist der Scanner nicht erreichbar, läuft die Verarbeitung weiter – die Bilder sind zu
diesem Zeitpunkt bereits neu erzeugt.

## Spam-Abwehr

Unsichtbares Zusatzfeld (Bots füllen es aus), Mindestdauer von vier Sekunden zwischen
Seitenaufruf und Absenden, sowie maximal fünf Anfragen je Stunde und IP-Adresse. Die
IP wird dafür nur als Prüfsumme gespeichert, nicht im Klartext.

## Wartung

```bash
git pull && docker compose up -d --build        # Aktualisierung
docker compose logs php --tail=50               # Fehlersuche
```

Reine Textänderungen in `site/` brauchen keinen Neustart – nginx liest das Verzeichnis
direkt. Nach Änderungen an `app/` oder `nginx/objektfrei.conf` ist ein
`docker compose up -d --build` nötig.

Backup: das Volume `objektfrei_data` enthält die hochgeladenen Fotos. Die
SSL-Zertifikate liegen bei Traefik und werden dort gesichert. Die Website selbst liegt
im Git-Repository.

Alte Uploads regelmäßig löschen – sie enthalten Kundendaten und dürfen nach der
Auftragsabwicklung nicht unbegrenzt liegen bleiben:

```bash
docker compose exec php find /var/objektfrei/uploads -type d -mtime +90 -exec rm -rf {} +
```

## Datenschutz – vor dem Livegang zu klären

Die Datenschutzerklärung muss ergänzt werden um: Fotoübermittlung im Anfrageformular,
Speicherdauer der Bilder, Versand über Google Workspace und die Server-Logs bei Hetzner.
Ein Auftragsverarbeitungsvertrag ist mit Hetzner und mit Google abzuschließen.

## Grenzen dieser Lösung

Es gibt kein Redaktionssystem. Jede Textänderung erfolgt in `site/index.html` und wird
per Git eingespielt. Für einen One-Pager mit wenigen Stadtseiten ist das der schnellste
und sicherste Weg. Sobald regelmäßig Inhalte veröffentlicht werden sollen, empfiehlt sich
ein Umstieg auf einen statischen Generator mit Redaktionsoberfläche – die Seite bleibt
dabei statisch und schnell.

## Rechtsseiten im Paket

```
site/impressum.html    Pflichtangaben nach § 5 DDG
site/datenschutz.html  Datenschutzerklärung, deckt Formular, Fotos, Hosting und Mailversand ab
site/widerruf.html     Widerrufsbelehrung mit Muster-Formular
site/agb.html          Allgemeine Geschäftsbedingungen (Entwurf)
```

Alle rot umrandeten Platzhalter müssen vor dem Livegang gefüllt werden. AGB und
Widerrufsbelehrung sind inhaltliche Vorlagen und **müssen anwaltlich geprüft werden** –
fehlerhafte Klauseln gegenüber Verbrauchern sind unwirksam und abmahnfähig.

Wichtig: Die Widerrufsbelehrung muss dem Kunden **vor Vertragsschluss in Textform**
vorliegen, nicht nur auf der Website. Sie gehört als Anlage in jedes Angebot. Soll vor
Ablauf der 14 Tage geräumt werden, braucht es zusätzlich die ausdrückliche Aufforderung
des Kunden zum vorzeitigen Beginn – ebenfalls in Textform.

## Schriftarten lokal ausliefern (empfohlen)

Solange die Schriften von Google geladen werden, wird die IP-Adresse jedes Besuchers an
Google übertragen; die Datenschutzerklärung weist darauf hin. Besser ist die lokale
Einbindung:

```bash
# Auf einem Rechner mit Internetzugang:
npx google-font-installer download "Archivo" "Instrument Sans" "IBM Plex Mono"
# Dateien nach site/fonts/ legen, in index.html den <link> auf fonts.googleapis.com
# durch @font-face-Regeln ersetzen.
```

Danach in der Datenschutzerklärung Abschnitt 4 durch den dort genannten Ersatzsatz
austauschen.
