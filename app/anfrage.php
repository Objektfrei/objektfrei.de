<?php
/**
 * Objektfrei – Anfrage-Endpunkt
 * Nimmt das Rechnerformular entgegen, prüft Bilder sicherheitshalber neu,
 * benachrichtigt den Betrieb und bestätigt dem Kunden.
 */
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const MAX_FOTOS      = 5;
const MAX_EINZELN    = 8  * 1024 * 1024;
const MAX_GESAMT     = 25 * 1024 * 1024;
const MAX_KANTE      = 1600;          // Pixel – danach wird verkleinert
const RATE_FENSTER   = 3600;          // Sekunden
const RATE_MAX       = 5;             // Anfragen je IP im Fenster
const ID_FENSTER     = 86400;         // Wie lange eine Anfragenummer gesperrt bleibt
const UPLOAD_DIR     = '/var/objektfrei/uploads';
const LOG_DIR        = '/var/objektfrei/log';
const ID_DIR         = '/var/objektfrei/ids';
const LOGO_PFAD      = __DIR__ . '/assets/logo-mail.png';

function ende(int $code, array $daten): never {
    http_response_code($code);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}
function env(string $k, string $std = ''): string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $std : $v;
}
function sauber(string $s, int $max = 500): string {
    $s = str_replace(["\r", "\0"], '', $s);
    $s = trim(strip_tags($s));
    return mb_substr($s, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ende(405, ['fehler' => 'Nur POST erlaubt.']);
}

/* ---------- 1. Spam-Abwehr ---------- */
if (sauber((string)($_POST['hp_website'] ?? '')) !== '') {
    ende(200, ['ok' => true]);                       // Bot: still verwerfen
}
$startzeit = (int)($_POST['startzeit'] ?? 0);
if ($startzeit > 0 && (time() * 1000 - $startzeit) < 4000) {
    ende(429, ['fehler' => 'Bitte nehmen Sie sich einen Moment und senden Sie erneut.']);
}

/* ---------- 2. Testmodus erkennen ---------- */
$devToken = env('DEV_TOKEN');
$istTest  = ($devToken !== '' && (string)($_POST['dev'] ?? '') === $devToken)
         || ((string)($_POST['testmodus'] ?? '') === 'ja');

/* ---------- 3. Doppelversand abfangen ----------
   Die Anfragenummer kommt vom Formular und ist zugleich Kundenreferenz.
   Trifft dieselbe Nummer erneut ein, antworten wir mit ok – ohne zweite Mail. */
$anfrageId = sauber((string)($_POST['anfrage_id'] ?? ''), 40);
$anfrageId = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($anfrageId)) ?: '';

if ($anfrageId !== '' && !$istTest) {
    @mkdir(ID_DIR, 0750, true);
    foreach (glob(ID_DIR . '/*.txt') ?: [] as $alt) {           // Aufräumen
        if (filemtime($alt) < time() - ID_FENSTER) { @unlink($alt); }
    }
    $marke = ID_DIR . '/' . hash('sha256', $anfrageId) . '.txt';
    $neu   = @fopen($marke, 'x');                                // schlägt fehl, wenn schon da
    if ($neu === false) {
        ende(200, ['ok' => true, 'doppelt' => true, 'anfrage_id' => $anfrageId]);
    }
    fwrite($neu, (string)time());
    fclose($neu);
}

/* ---------- 4. Rate-Limit je IP ---------- */
@mkdir(LOG_DIR, 0750, true);
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . env('RATE_SALT', 'objektfrei'));
$rateDatei = LOG_DIR . '/rate_' . substr($ipHash, 0, 16) . '.json';
$treffer = [];
if (is_file($rateDatei)) {
    $treffer = json_decode((string)file_get_contents($rateDatei), true) ?: [];
}
$treffer = array_values(array_filter($treffer, fn($t) => $t > time() - RATE_FENSTER));
if (count($treffer) >= RATE_MAX && !$istTest) {
    ende(429, ['fehler' => 'Zu viele Anfragen von diesem Anschluss. Bitte melden Sie sich per E-Mail.']);
}
$treffer[] = time();
file_put_contents($rateDatei, json_encode($treffer), LOCK_EX);

/* ---------- 5. Pflichtfelder ---------- */
$name    = sauber((string)($_POST['name'] ?? ''), 120);
$ort     = sauber((string)($_POST['ort'] ?? ''), 120);
$mail    = sauber((string)($_POST['kontaktweg'] ?? ''), 160);
$telefon = sauber((string)($_POST['telefon'] ?? ''), 60);

if ($name === '' || $ort === '' || $mail === '') {
    ende(422, ['fehler' => 'Bitte Name, Ort und E-Mail ausfüllen.']);
}
if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
    ende(422, ['fehler' => 'Diese E-Mail-Adresse scheint nicht zu stimmen. Bitte prüfen Sie die Schreibweise.']);
}
if ($telefon !== '' && strlen(preg_replace('/\D/', '', $telefon) ?? '') < 7) {
    ende(422, ['fehler' => 'Die Telefonnummer sieht unvollständig aus. Bitte prüfen oder das Feld leer lassen.']);
}

/* ---------- 6. Übrige Felder ---------- */
$felder = [
    'anfrageart' => 'Anfrageart', 'anliegen' => 'Anliegen', 'objekt' => 'Objekt',
    'zimmer' => 'Zimmer', 'groesse' => 'Fläche', 'etage' => 'Etage', 'aufzug' => 'Aufzug',
    'ebenen' => 'Mehrere Ebenen', 'fuellgrad' => 'Füllgrad', 'kellerzusatz' => 'Keller/Dachboden',
    'zusatz' => 'Zusatzleistungen', 'endreinigung' => 'Endreinigung', 'zeit' => 'Wunschtermin',
    'verwertbar' => 'Verwertbares', 'raeumungen' => 'Räumungen pro Jahr',
    'hausverwaltung' => 'Hausverwaltung', 'preisspanne' => 'Berechnete Spanne',
    'preistreiber' => 'Preistreiber', 'msg' => 'Anmerkungen',
];
$werte = [];
foreach ($felder as $key => $label) {
    $v = sauber((string)($_POST[$key] ?? ''), $key === 'msg' ? 2000 : 300);
    if ($v !== '') { $werte[$label] = $v; }
}

$herkunft   = sauber((string)($_POST['herkunft'] ?? ''), 60);
/* Woher der Besucher kam: aus der Adresszeile und dem Verweis gelesen.
   Kein Cookie, keine Einwilligung – deshalb auch bei jedem vorhanden,
   der das Einwilligungsbanner ablehnt. */
$kanal      = sauber((string)($_POST['kanal'] ?? ''), 120);
$plzFremd   = (string)($_POST['plz_ausserhalb'] ?? '') === 'ja';

/* ---------- 7. Bilder: Inhalt prüfen und komplett neu erzeugen ---------- */
$bilder = [];
$fehlerBilder = [];
if (!empty($_FILES['fotos']['name'][0])) {
    @mkdir(UPLOAD_DIR, 0750, true);
    $ordner = UPLOAD_DIR . '/' . date('Y-m-d') . '_' . bin2hex(random_bytes(6));
    @mkdir($ordner, 0750, true);

    $anzahl = min(count($_FILES['fotos']['name']), MAX_FOTOS);
    $gesamt = 0;

    for ($i = 0; $i < $anzahl; $i++) {
        if ((int)$_FILES['fotos']['error'][$i] !== UPLOAD_ERR_OK) { continue; }
        $tmp   = $_FILES['fotos']['tmp_name'][$i];
        $size  = (int)$_FILES['fotos']['size'][$i];
        $orig  = sauber(basename((string)$_FILES['fotos']['name'][$i]), 80);

        if (!is_uploaded_file($tmp))            { $fehlerBilder[] = "$orig: ungültig"; continue; }
        if ($size > MAX_EINZELN)                { $fehlerBilder[] = "$orig: über 8 MB";  continue; }
        if ($gesamt + $size > MAX_GESAMT)       { $fehlerBilder[] = "$orig: Gesamtlimit"; continue; }

        // Entscheidend: nicht die Endung, sondern den echten Inhalt prüfen
        $info = @getimagesize($tmp);
        if ($info === false) { $fehlerBilder[] = "$orig: keine gültige Bilddatei"; continue; }
        [$w, $h, $typ] = $info;
        if (!in_array($typ, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $fehlerBilder[] = "$orig: Format nicht erlaubt"; continue;
        }
        if ($w * $h > 60_000_000) { $fehlerBilder[] = "$orig: Auflösung zu groß"; continue; }

        $quelle = match ($typ) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
        };
        if (!$quelle) { $fehlerBilder[] = "$orig: nicht lesbar"; continue; }

        // Neu zeichnen: dabei verschwindet jeder eingebettete Fremdinhalt samt EXIF/GPS
        $faktor = min(1, MAX_KANTE / max($w, $h));
        $nw = max(1, (int)round($w * $faktor));
        $nh = max(1, (int)round($h * $faktor));
        $ziel = imagecreatetruecolor($nw, $nh);
        imagefill($ziel, 0, 0, imagecolorallocate($ziel, 255, 255, 255));
        imagecopyresampled($ziel, $quelle, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($quelle);

        $datei = $ordner . '/' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg($ziel, $datei, 82);
        imagedestroy($ziel);
        @chmod($datei, 0640);

        $bilder[] = ['pfad' => $datei, 'name' => 'Foto-' . (count($bilder) + 1) . '.jpg'];
        $gesamt += $size;
    }
}

/* ---------- 8. Optionaler Virenscan ---------- */
if (env('CLAMAV_HOST') !== '' && $bilder !== []) {
    foreach ($bilder as $b) {
        $sock = @fsockopen(env('CLAMAV_HOST'), (int)env('CLAMAV_PORT', '3310'), $e, $s, 5);
        if (!$sock) { break; }                       // Scanner nicht erreichbar: Bilder sind bereits neu erzeugt
        fwrite($sock, "zINSTREAM\0");
        $fh = fopen($b['pfad'], 'rb');
        while (!feof($fh)) {
            $chunk = fread($fh, 8192);
            fwrite($sock, pack('N', strlen($chunk)) . $chunk);
        }
        fclose($fh);
        fwrite($sock, pack('N', 0));
        $antwort = (string)fgets($sock);
        fclose($sock);
        if (str_contains($antwort, 'FOUND')) {
            @unlink($b['pfad']);
            ende(422, ['fehler' => 'Eine der Dateien wurde abgelehnt. Bitte senden Sie andere Aufnahmen.']);
        }
    }
}

/* ---------- 9. Mails ---------- */
function mailer(): PHPMailer {
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host       = env('SMTP_HOST', 'smtp.gmail.com');
    $m->Port       = (int)env('SMTP_PORT', '587');
    $m->SMTPAuth   = true;
    $m->Username   = env('SMTP_USER');
    $m->Password   = env('SMTP_PASS');
    $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $m->CharSet    = 'UTF-8';
    $m->setFrom(env('MAIL_FROM', 'anfrage@objektfrei.de'), 'Objektfrei');
    return $m;
}

/** Zeilen für die interne Benachrichtigung */
function zeilen(array $werte): string {
    $out = '';
    foreach ($werte as $l => $v) {
        $out .= '<tr><td style="padding:5px 14px 5px 0;color:#5B6660;white-space:nowrap">'
             . htmlspecialchars($l) . '</td><td style="padding:5px 0;font-weight:600;color:#26382C">'
             . nl2br(htmlspecialchars($v)) . '</td></tr>';
    }
    return $out;
}

/**
 * Zeilen für die Kundenbestätigung.
 * Feste Farben je Zelle, damit Dark-Mode-Clients nichts umfärben.
 */
function kundenZeilen(array $werte): string {
    $out = ''; $i = 0;
    foreach ($werte as $l => $v) {
        $bg = ($i++ % 2 === 0) ? '#FFFFFF' : '#F7F4EE';
        $out .= '<tr>'
             . '<td style="padding:11px 16px;background:' . $bg . ';color:#5B6660;'
             . 'font-size:14px;line-height:1.4;white-space:nowrap;border-bottom:1px solid #E8E2D6">'
             . htmlspecialchars($l) . '</td>'
             . '<td style="padding:11px 16px;background:' . $bg . ';color:#26382C;'
             . 'font-size:14px;line-height:1.4;font-weight:600;border-bottom:1px solid #E8E2D6">'
             . nl2br(htmlspecialchars($v)) . '</td>'
             . '</tr>';
    }
    return $out;
}

/** Überschrift über einer Gruppe von Angaben */
function kundenBlock(string $titel, array $werte): string {
    if ($werte === []) { return ''; }
    return '<tr><td style="padding:24px 28px 0">'
         . '<div style="font-size:12px;letter-spacing:.12em;color:#5B6660;text-transform:uppercase;'
         . 'padding-bottom:10px">' . htmlspecialchars($titel) . '</div>'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
         . ' style="border-collapse:collapse;border:1px solid #E8E2D6;border-radius:8px;overflow:hidden">'
         . kundenZeilen($werte)
         . '</table></td></tr>';
}

/** Angaben in zwei Gruppen aufteilen, Preis und Preistreiber getrennt behandeln */
function gruppiere(array $werte): array {
    $objektFelder = ['Anliegen', 'Objekt', 'Zimmer', 'Fläche', 'Etage', 'Aufzug', 'Mehrere Ebenen'];
    $umfangFelder = ['Füllgrad', 'Keller/Dachboden', 'Zusatzleistungen', 'Endreinigung',
                     'Wunschtermin', 'Verwertbares', 'Räumungen pro Jahr'];
    $objekt = $umfang = $rest = [];
    foreach ($werte as $l => $v) {
        if (in_array($l, ['Berechnete Spanne', 'Preistreiber'], true)) { continue; }
        if     (in_array($l, $objektFelder, true)) { $objekt[$l] = $v; }
        elseif (in_array($l, $umfangFelder, true)) { $umfang[$l] = $v; }
        else                                       { $rest[$l]   = $v; }
    }
    return [$objekt, $umfang, $rest];
}

/** Kopf der Kundenmail – das Wortzeichen steht auch ohne Bilddatei */
function mailKopf(string $logo): string {
    return '<tr><td style="background:#26382C;padding:22px 28px">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
         . ($logo ? '<td style="padding-right:14px;vertical-align:middle">' . $logo . '</td>' : '')
         . '<td style="vertical-align:middle">'
         . '<div style="font-size:19px;font-weight:700;letter-spacing:.12em;line-height:1.1">'
         . '<span style="color:#F1EDE4">OBJEKT</span> <span style="color:#D08650">FREI</span></div>'
         . '<div style="color:#A8B5AC;font-size:12.5px;padding-top:5px;letter-spacing:.04em">'
         . 'Räumung, Reinigung &amp; Spezialdienste</div>'
         . '</td></tr></table></td></tr>';
}

/** Die drei Schritte nach der Anfrage */
function mailSchritte(): string {
    $schritte = [
        ['Wir melden uns', 'Werktags innerhalb von 4 Stunden – mit einem Vorschlag für den Besichtigungstermin.'],
        ['Kostenlose Besichtigung', 'Wir sehen uns das Objekt vor Ort an. Unverbindlich und ohne versteckte Bedingungen.'],
        ['Festpreis schriftlich', 'Danach erhalten Sie Ihren verbindlichen Festpreis – Werte im Objekt werden angerechnet.'],
    ];
    $out = '<tr><td style="padding:26px 28px 0">'
         . '<div style="font-size:12px;letter-spacing:.12em;color:#5B6660;text-transform:uppercase;'
         . 'padding-bottom:12px">So geht es weiter</div>'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
    $n = 1;
    foreach ($schritte as [$titel, $text]) {
        $out .= '<tr>'
              . '<td width="34" valign="top" style="padding:0 12px 16px 0">'
              . '<div style="width:26px;height:26px;background:#26382C;border-radius:99px;'
              . 'color:#F1EDE4;font-size:13px;font-weight:700;text-align:center;line-height:26px">'
              . $n++ . '</div></td>'
              . '<td valign="top" style="padding:0 0 16px">'
              . '<div style="font-size:15px;font-weight:700;color:#26382C;line-height:1.35">'
              . htmlspecialchars($titel) . '</div>'
              . '<div style="font-size:13.5px;color:#5B6660;line-height:1.55;padding-top:3px">'
              . htmlspecialchars($text) . '</div></td></tr>';
    }
    return $out . '</table></td></tr>';
}

$betreff = ($werte['Anfrageart'] ?? '') === 'Rahmenvereinbarung'
    ? "Rahmenanfrage – $name, $ort"
    : "Neue Anfrage – $name, $ort";
if ($anfrageId !== '') { $betreff .= " [$anfrageId]"; }
if ($istTest)          { $betreff  = '[TEST] ' . $betreff; }

try {
    /* ---- an den Betrieb ---- */
    $m = mailer();
    $m->addAddress(env('MAIL_TO', 'anfrage@objektfrei.de'));
    $m->addReplyTo($mail, $name);
    $m->Subject = $betreff;
    $m->isHTML(true);

    $kopf = 'Kontakt: <b>' . htmlspecialchars($mail) . '</b>';
    if ($telefon !== '') { $kopf .= ' · Telefon: <b>' . htmlspecialchars($telefon) . '</b>'; }
    if ($anfrageId !== '') { $kopf .= '<br>Nummer: <b>' . htmlspecialchars($anfrageId) . '</b>'; }
    if ($herkunft !== '') { $kopf .= '<br>Herkunft: <b>' . htmlspecialchars($herkunft) . '</b>'; }
    if ($kanal !== '')    { $kopf .= '<br>Kanal: <b>' . htmlspecialchars($kanal) . '</b>'; }

    $m->Body = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="color-scheme" content="light only">'
        . '<meta name="supported-color-schemes" content="light only">'
        . '<meta name="format-detection" content="telephone=no,date=no,address=no">'
        . '</head><body style="margin:0;background:#FFFFFF">'
        . '<div style="font-family:system-ui,sans-serif;font-size:14px;color:#26382C">'
        . ($istTest ? '<p style="margin:0 0 12px;padding:8px 12px;background:#B3261E;color:#fff;'
            . 'font-weight:700;letter-spacing:.08em">TESTANFRAGE – kein echter Kunde</p>' : '')
        . '<h2 style="font-size:17px;margin:0 0 4px">' . htmlspecialchars($betreff) . '</h2>'
        . '<p style="margin:0 0 14px;color:#5B6660">' . $kopf . '</p>'
        . ($plzFremd ? '<p style="margin:0 0 14px;padding:8px 12px;background:#FBEEDC;'
            . 'color:#7A5B3A;font-weight:600">Objekt liegt außerhalb des Kerngebiets – Anfahrt prüfen.</p>' : '')
        . '<table style="border-collapse:collapse;font-size:14px">' . zeilen($werte) . '</table>'
        . ($bilder ? '<p style="margin-top:14px">' . count($bilder) . ' Foto(s) im Anhang.</p>' : '')
        . ($fehlerBilder ? '<p style="margin-top:8px;color:#B3261E">Abgelehnt: '
            . htmlspecialchars(implode(', ', $fehlerBilder)) . '</p>' : '')
        . '</div></body></html>';
    foreach ($bilder as $b) { $m->addAttachment($b['pfad'], $b['name']); }
    $m->send();

    /* ---- an den Kunden ---- */
    $k = mailer();
    $k->addAddress($mail, $name);
    $k->addReplyTo(env('MAIL_TO', 'anfrage@objektfrei.de'), 'Objektfrei');
    $k->Subject = ($istTest ? '[TEST] ' : '') . 'Ihre Anfrage bei Objektfrei ist angekommen';
    $k->isHTML(true);

    // Logo als eingebetteter Anhang – kein Hotlink, laedt auch ohne Bilderfreigabe nach
    $logo = '';
    if (is_file(LOGO_PFAD) && $k->addEmbeddedImage(LOGO_PFAD, 'oflogo', 'objektfrei.png')) {
        $logo = '<img src="cid:oflogo" width="46" height="46" alt=""'
              . ' style="display:block;border:0;outline:none;width:46px;height:46px">';
    }

    $vorname = trim(explode(' ', $name)[0]);
    [$objekt, $umfang, $rest] = gruppiere($werte);
    $spanne      = $werte['Berechnete Spanne'] ?? '';
    $preistreiber = $werte['Preistreiber'] ?? '';

    $k->Body =
    '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  // Ohne diese beiden Zeilen dreht der Dunkelmodus vieler Mail-Programme
  // alle Farben um – die Mail wirkt dann grau und leblos.
  . '<meta name="color-scheme" content="light only">'
  . '<meta name="supported-color-schemes" content="light only">'
  // Verhindert, dass Handys aus der Preisspanne eine Telefonnummer machen
  . '<meta name="format-detection" content="telephone=no,date=no,address=no,email=no">'
  . '<style>:root{color-scheme:light only;supported-color-schemes:light only}'
  . 'body{margin:0;padding:0;background:#F1EDE4}'
  . 'a{color:#A65621}'
  . '@media (prefers-color-scheme:dark){body,table,td,div,p,span{color-scheme:light only}}'
  . '</style><title>Ihre Anfrage bei Objektfrei</title></head>'
  . '<body style="margin:0;padding:0;background:#F1EDE4">'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
  . ' style="background:#F1EDE4;padding:28px 12px">'
  . '<tr><td align="center">'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
  . ' style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden;'
  . 'font-family:system-ui,-apple-system,Segoe UI,sans-serif">'

  . mailKopf($logo)

  . ($istTest ? '<tr><td style="background:#B3261E;color:#FFFFFF;padding:9px 28px;font-size:13px;'
      . 'font-weight:700;letter-spacing:.06em">TESTVERSAND</td></tr>' : '')

  // Anrede und Zusage
  . '<tr><td style="padding:30px 28px 0">'
  . '<div style="font-size:21px;font-weight:700;color:#26382C;line-height:1.3">'
  . 'Vielen Dank, ' . htmlspecialchars($vorname) . '.</div>'
  . '<p style="margin:14px 0 0;font-size:15px;line-height:1.65;color:#3C4A41">'
  . 'Ihre Anfrage ist bei uns eingegangen. <b style="color:#26382C">Werktags melden wir uns '
  . 'innerhalb von 4 Stunden</b> mit einem Vorschlag für Ihre kostenlose Besichtigung. '
  . 'Den verbindlichen Festpreis erhalten Sie danach schriftlich.</p>'
  . '</td></tr>'

  // Preisspanne: das, worauf der Kunde schaut
  . ($spanne !== ''
      ? '<tr><td style="padding:24px 28px 0">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
      . ' style="background:#26382C;border-radius:10px">'
      . '<tr><td style="padding:18px 20px">'
      . '<div style="font-size:11.5px;letter-spacing:.16em;color:#A8B5AC;text-transform:uppercase">'
      . 'Ihre grobe Preisspanne</div>'
      . '<div style="font-size:25px;font-weight:700;color:#FFFFFF;padding-top:7px;line-height:1.15">'
      . htmlspecialchars($spanne) . '</div>'
      . ($preistreiber !== ''
          ? '<div style="font-size:12.5px;color:#A8B5AC;padding-top:9px;line-height:1.5">'
          . htmlspecialchars($preistreiber) . '</div>' : '')
      . '<div style="font-size:12.5px;color:#D08650;padding-top:10px;line-height:1.5">'
      . 'Unverbindliche Schätzung. Den festen Preis nennen wir nach der Besichtigung.</div>'
      . '</td></tr></table></td></tr>'
      : '')

  // Anfragenummer
  . ($anfrageId !== ''
      ? '<tr><td style="padding:20px 28px 0">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
      . ' style="background:#F7F4EE;border-left:3px solid #A65621;border-radius:6px">'
      . '<tr><td style="padding:14px 18px">'
      . '<div style="font-size:12px;letter-spacing:.12em;color:#5B6660;text-transform:uppercase">Ihre Anfragenummer</div>'
      . '<div style="font-size:19px;font-weight:700;color:#26382C;padding-top:5px;letter-spacing:.06em">'
      . htmlspecialchars($anfrageId) . '</div>'
      . '<div style="font-size:13px;color:#5B6660;padding-top:6px;line-height:1.5">'
      . 'Bitte bei Rückfragen angeben – dann finden wir Ihren Vorgang sofort.</div>'
      . '</td></tr></table></td></tr>'
      : '')

  // Angaben, in Gruppen statt einer langen Liste
  . kundenBlock('Ihr Objekt', $objekt)
  . kundenBlock('Umfang & Termin', $umfang)
  . kundenBlock('Weitere Angaben', $rest)

  . ($bilder
      ? '<tr><td style="padding:14px 28px 0;font-size:13.5px;color:#5B6660">'
      . count($bilder) . ' Foto(s) haben wir erhalten.</td></tr>'
      : '')

  . mailSchritte()

  // Hinweis
  . '<tr><td style="padding:8px 28px 28px">'
  . '<p style="margin:0;font-size:14.5px;line-height:1.6;color:#3C4A41">'
  . 'Sollte etwas nicht stimmen, antworten Sie einfach auf diese E-Mail.</p>'
  . '</td></tr>'

  // Fuss
  . '<tr><td style="background:#F7F4EE;padding:20px 28px;border-top:1px solid #E8E2D6">'
  . '<div style="font-size:14px;font-weight:700;color:#26382C;letter-spacing:.1em">'
  . '<span style="color:#26382C">OBJEKT</span> <span style="color:#A65621">FREI</span></div>'
  . '<div style="font-size:13.5px;color:#5B6660;padding-top:5px;line-height:1.6">'
  . 'Räumung, Reinigung &amp; Spezialdienste · Rhein-Main<br>'
  . '<a href="tel:+4961116889631" style="color:#A65621;text-decoration:none">0611 16889631</a>'
  . ' · <a href="mailto:' . htmlspecialchars(env('MAIL_TO', 'anfrage@objektfrei.de'))
  . '" style="color:#A65621;text-decoration:none">'
  . htmlspecialchars(env('MAIL_TO', 'anfrage@objektfrei.de')) . '</a>'
  . ' · <a href="https://objektfrei.de" style="color:#A65621;text-decoration:none">objektfrei.de</a>'
  . '</div></td></tr>'

  . '</table></td></tr></table></body></html>';

    $k->send();

} catch (MailException $e) {
    error_log('Mailversand fehlgeschlagen: ' . $e->getMessage());
    // Nummer wieder freigeben, damit der Kunde es erneut versuchen kann
    if ($anfrageId !== '' && !$istTest) {
        @unlink(ID_DIR . '/' . hash('sha256', $anfrageId) . '.txt');
    }
    ende(500, ['fehler' => 'Ihre Anfrage konnte nicht versendet werden. Bitte schreiben Sie uns an '
        . env('MAIL_TO', 'anfrage@objektfrei.de') . '.']);
}

ende(200, ['ok' => true, 'bestaetigung' => true, 'anfrage_id' => $anfrageId]);
