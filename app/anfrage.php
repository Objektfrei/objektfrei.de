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
const UPLOAD_DIR     = '/var/objektfrei/uploads';
const LOG_DIR        = '/var/objektfrei/log';

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

/* ---------- 2. Rate-Limit je IP ---------- */
@mkdir(LOG_DIR, 0750, true);
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . env('RATE_SALT', 'objektfrei'));
$rateDatei = LOG_DIR . '/rate_' . substr($ipHash, 0, 16) . '.json';
$treffer = [];
if (is_file($rateDatei)) {
    $treffer = json_decode((string)file_get_contents($rateDatei), true) ?: [];
}
$treffer = array_values(array_filter($treffer, fn($t) => $t > time() - RATE_FENSTER));
if (count($treffer) >= RATE_MAX) {
    ende(429, ['fehler' => 'Zu viele Anfragen von diesem Anschluss. Bitte melden Sie sich per E-Mail.']);
}
$treffer[] = time();
file_put_contents($rateDatei, json_encode($treffer), LOCK_EX);

/* ---------- 3. Pflichtfelder ---------- */
$name = sauber((string)($_POST['name'] ?? ''), 120);
$ort  = sauber((string)($_POST['ort'] ?? ''), 120);
$weg  = sauber((string)($_POST['kontaktweg'] ?? ''), 160);
if ($name === '' || $ort === '' || $weg === '') {
    ende(422, ['fehler' => 'Bitte Name, Ort und Kontaktweg ausfüllen.']);
}
$istMail = (bool)filter_var($weg, FILTER_VALIDATE_EMAIL);

/* ---------- 4. Übrige Felder ---------- */
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

/* ---------- 5. Bilder: Inhalt prüfen und komplett neu erzeugen ---------- */
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

/* ---------- 6. Optionaler Virenscan ---------- */
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

/* ---------- 7. Mails ---------- */
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
function zeilen(array $werte): string {
    $out = '';
    foreach ($werte as $l => $v) {
        $out .= '<tr><td style="padding:5px 14px 5px 0;color:#5B6660;white-space:nowrap">'
             . htmlspecialchars($l) . '</td><td style="padding:5px 0;font-weight:600;color:#26382C">'
             . nl2br(htmlspecialchars($v)) . '</td></tr>';
    }
    return $out;
}

$betreff = ($werte['Anfrageart'] ?? '') === 'Rahmenvereinbarung'
    ? "Rahmenanfrage – $name, $ort"
    : "Neue Anfrage – $name, $ort";

try {
    /* an den Betrieb */
    $m = mailer();
    $m->addAddress(env('MAIL_TO', 'anfrage@objektfrei.de'));
    if ($istMail) { $m->addReplyTo($weg, $name); }
    $m->Subject = $betreff;
    $m->isHTML(true);
    $m->Body = '<div style="font-family:system-ui,sans-serif;font-size:14px;color:#26382C">'
        . '<h2 style="font-size:17px;margin:0 0 4px">' . htmlspecialchars($betreff) . '</h2>'
        . '<p style="margin:0 0 14px;color:#5B6660">Kontakt: <b>' . htmlspecialchars($weg) . '</b></p>'
        . '<table style="border-collapse:collapse;font-size:14px">' . zeilen($werte) . '</table>'
        . ($bilder ? '<p style="margin-top:14px">' . count($bilder) . ' Foto(s) im Anhang.</p>' : '')
        . ($fehlerBilder ? '<p style="margin-top:8px;color:#B3261E">Abgelehnt: '
            . htmlspecialchars(implode(', ', $fehlerBilder)) . '</p>' : '')
        . '</div>';
    foreach ($bilder as $b) { $m->addAttachment($b['pfad'], $b['name']); }
    $m->send();

    /* an den Kunden */
    if ($istMail) {
        $k = mailer();
        $k->addAddress($weg, $name);
        $k->addReplyTo(env('MAIL_TO', 'anfrage@objektfrei.de'), 'Objektfrei');
        $k->Subject = 'Ihre Anfrage bei Objektfrei ist angekommen';
        $k->isHTML(true);
        $k->Body = '<div style="font-family:system-ui,sans-serif;font-size:15px;color:#26382C;max-width:560px">'
            . '<p style="font-size:13px;letter-spacing:.14em;color:#A65621;margin:0 0 6px">OBJEKTFREI</p>'
            . '<h2 style="font-size:19px;margin:0 0 10px">Vielen Dank, ' . htmlspecialchars($name) . '.</h2>'
            . '<p style="line-height:1.6;margin:0 0 16px">Ihre Anfrage ist bei uns eingegangen. '
            . 'Wir melden uns noch am selben Werktag mit einem Vorschlag für Ihre kostenlose Besichtigung. '
            . 'Den verbindlichen Festpreis erhalten Sie danach schriftlich.</p>'
            . '<p style="font-size:12px;letter-spacing:.12em;color:#5B6660;margin:0 0 6px">IHRE ANGABEN</p>'
            . '<table style="border-collapse:collapse;font-size:14px;background:#F1EDE4;padding:10px">'
            . zeilen($werte) . '</table>'
            . ($bilder ? '<p style="margin-top:12px;font-size:13px;color:#5B6660">'
                . count($bilder) . ' Foto(s) haben wir erhalten.</p>' : '')
            . '<p style="margin-top:18px;line-height:1.6">Sollte etwas nicht stimmen, antworten Sie einfach auf diese E-Mail.</p>'
            . '<p style="margin-top:22px;font-size:12px;color:#5B6660;border-top:1px solid #D8D2C4;padding-top:12px">'
            . 'Objektfrei · Räumung, Reinigung &amp; Spezialdienste · Rhein-Main<br>'
            . htmlspecialchars(env('MAIL_TO', 'anfrage@objektfrei.de')) . ' · objektfrei.de</p></div>';
        $k->send();
    }
} catch (MailException $e) {
    error_log('Mailversand fehlgeschlagen: ' . $e->getMessage());
    ende(500, ['fehler' => 'Ihre Anfrage konnte nicht versendet werden. Bitte schreiben Sie uns an '
        . env('MAIL_TO', 'anfrage@objektfrei.de') . '.']);
}

ende(200, ['ok' => true, 'bestaetigung' => $istMail]);
