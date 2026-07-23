<?php
/**
 * Sync rubrica Wildix da CSV su FTP remoto,
 * cartelle RX / RX-save.
 */

date_default_timezone_set('Europe/Rome');
require '/var/credentials/cred.php';

// --- CONFIGURAZIONE WILDIX ---
$pbxHost = "https://{$wildixdns}.wildixin.com";
$phonebookId = 19; // Leggere da Wildix
$logFile = '/tmp/rubrica_sync.log';

// --- CONFIGURAZIONE FTP ---
$ftpDirRx = '/RX';
$ftpDirRxSave = '/RX-save';
$csvFilename = 'rubrica.csv';
$localTmpFile = '/tmp/' . $csvFilename;

// nome campo contatto lato API Wildix (confermato dalla doc live).
$csvFieldMap = [
    1  => 'shortcut',      // Shortcut -> identificativo univoco del contatto
    4  => 'name',          // Name
    8  => 'mobile',        // Mobile
    12 => 'email',         // Email
    13 => 'organization',  // Orgnization (cosi' scritto nell'intestazione originale)
    19 => 'picture',       // ImageUrl
];
// Colonna usata come chiave univoca contatto.
$idColumn = 1;
$deleteMissingContacts = true; // cancella i contatti Wildix assenti dal CSV
$debug = true; // log verboso di ogni azione; mettere a false a integrazione stabile

function logMsg($msg) {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function debugLog($msg) {
    global $debug;
    if ($debug) {
        logMsg("[DEBUG] " . $msg);
    }
}

// --- CHIAMATE WILDIX ---

function wildixCall($url, $method = 'GET', $data = null) {
    global $authToken;

    $headers = ["Authorization: Bearer $authToken"];
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
    }

    if ($data !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query(['data' => $data]);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;

    debugLog("Wildix API richiesta: $method $url" . ($data !== null ? " data=" . json_encode($data) : ""));

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        logMsg("ERRORE curl su $method $url: $curlErr");
    }
    debugLog("Wildix API risposta: HTTP $httpCode - " . substr((string)$res, 0, 800));

    return [$httpCode, json_decode($res, true)];
}

// --- FTP via curl 

function ftpListDir($remoteDir) {
    global $ftpHost, $ftpUser, $ftpPass;

    $url = "ftp://$ftpHost" . rtrim($remoteDir, '/') . '/';
    debugLog("FTP (curl): elenco cartella $url");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_USERPWD => "$ftpUser:$ftpPass",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FTP_SKIP_PASV_IP => true,
        CURLOPT_DIRLISTONLY => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        logMsg("ERRORE FTP: impossibile elencare $url ($err)");
        return [];
    }
    debugLog("FTP (curl): risposta listing (HTTP/FTP code $httpCode): " . trim($res));

    return array_values(array_filter(array_map('trim', explode("\n", $res))));
}

function ftpGet($remotePath, $localPath) {
    global $ftpHost, $ftpUser, $ftpPass;

    $url = "ftp://$ftpHost" . $remotePath;
    debugLog("FTP (curl): scarico $url in $localPath");

    $fp = fopen($localPath, 'w+');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_USERPWD => "$ftpUser:$ftpPass",
        CURLOPT_FILE => $fp,
        CURLOPT_FTP_SKIP_PASV_IP => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok) {
        logMsg("ERRORE FTP GET $url: $err");
        return false;
    }
    debugLog("FTP (curl): download riuscito (" . (file_exists($localPath) ? filesize($localPath) : '?') . " byte).");
    return true;
}

function ftpPut($localPath, $remotePath) {
    global $ftpHost, $ftpUser, $ftpPass;

    $url = "ftp://$ftpHost" . $remotePath;
    debugLog("FTP (curl): carico $localPath su $url");

    $fp = fopen($localPath, 'r');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_USERPWD => "$ftpUser:$ftpPass",
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => filesize($localPath),
        CURLOPT_FTP_SKIP_PASV_IP => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok) {
        logMsg("ERRORE FTP PUT $url: $err");
        return false;
    }
    debugLog("FTP (curl): upload riuscito.");
    return true;
}

function ftpDelete($remotePath) {
    global $ftpHost, $ftpUser, $ftpPass;

    $dir = rtrim(dirname($remotePath), '/') . '/';
    $filename = basename($remotePath);
    $url = "ftp://$ftpHost" . $dir;
    debugLog("FTP (curl): DELE $filename in $dir");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_USERPWD => "$ftpUser:$ftpPass",
        CURLOPT_QUOTE => ["DELE $filename"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FTP_SKIP_PASV_IP => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$ok) {
        logMsg("ERRORE FTP DELE $filename: $err");
        return false;
    }
    debugLog("FTP (curl): '$filename' cancellato da $dir.");
    return true;
}

function downloadCsvFromRx() {
    global $ftpDirRx, $csvFilename, $localTmpFile;

    $files = ftpListDir($ftpDirRx);
    debugLog("FTP: contenuto cartella RX (" . count($files) . " elementi): " .
        ($files ? implode(', ', $files) : '(vuota)'));

    if (!in_array($csvFilename, $files, true)) {
        logMsg("Nessun file '$csvFilename' trovato in $ftpDirRx (trovati: " .
            ($files ? implode(', ', $files) : 'nessun file') . ")");
        return false;
    }
    debugLog("FTP: '$csvFilename' trovato in $ftpDirRx.");

    $remotePath = rtrim($ftpDirRx, '/') . '/' . $csvFilename;
    if (!ftpGet($remotePath, $localTmpFile)) {
        return false;
    }
    return $localTmpFile;
}

function archiveCsvToRxSave($localFile) {
    global $ftpDirRx, $ftpDirRxSave, $csvFilename;

    $datedName = date('Ymd') . '_' . $csvFilename;
    $remoteDest = rtrim($ftpDirRxSave, '/') . '/' . $datedName;
    debugLog("Archiviazione: rinomino in '$datedName' e sposto in $ftpDirRxSave.");

    if (!ftpPut($localFile, $remoteDest)) {
        return false;
    }

    $remoteSrc = rtrim($ftpDirRx, '/') . '/' . $csvFilename;
    ftpDelete($remoteSrc);
    return true;
}

// --- CSV: legge il file locale e costruisce l'elenco contatti ---

function parseCsv($localFile) {
    global $csvFieldMap, $idColumn;

    $contacts = [];
    $handle = fopen($localFile, 'r');
    $header = fgetcsv($handle); // salta la riga di intestazione
    debugLog("CSV: intestazione letta: " . ($header ? implode(', ', $header) : '(vuota)'));
    while (($row = fgetcsv($handle)) !== false) {
        if (!isset($row[$idColumn]) || $row[$idColumn] === '') {
            continue;
        }
        $contactId = trim($row[$idColumn]);
        $fields = [];
        foreach ($csvFieldMap as $colIndex => $fieldName) {
            $fields[$fieldName] = isset($row[$colIndex]) ? trim($row[$colIndex]) : '';
        }
        $contacts[$contactId] = $fields;
    }
    fclose($handle);
    debugLog("CSV: " . count($contacts) . " contatti letti dal file.");
    return $contacts;
}

// --- MAIN ---

$localFile = downloadCsvFromRx();
if (!$localFile) {
    logMsg("Nessun rubrica.csv trovato in RX, niente da fare.");
    exit;
}

$csvContacts = parseCsv($localFile);

// 1. Contatti gia' presenti nella rubrica Wildix
list($httpCode, $res) = wildixCall("$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/");
if ($httpCode != 200) {
    logMsg("ERRORE: impossibile leggere i contatti esistenti (HTTP $httpCode). Interrompo per evitare duplicati.");
    exit;
}
$existingRaw = $res['result']['records'] ?? $res['result'] ?? [];
$existingContacts = [];
foreach ($existingRaw as $c) {
    $key = (string)($c['shortcut'] ?? '');
    if ($key !== '') {
        $existingContacts[$key] = $c;
    }
}
debugLog("Wildix: " . count($existingContacts) . " contatti gia' presenti in rubrica (con shortcut valorizzato).");

// 2. Crea o aggiorna i contatti presenti nel CSV
$created = 0;
$updated = 0;
$errors = 0;
foreach ($csvContacts as $contactId => $fields) {
    if (isset($existingContacts[$contactId])) {
        $existingId = $existingContacts[$contactId]['id'];
        list($httpCode, ) = wildixCall(
            "$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/$existingId/",
            'PUT',
            $fields
        );
        if ($httpCode == 200) {
            $updated++;
            debugLog("Contatto $contactId: aggiornato (id interno $existingId).");
        } else {
            $errors++;
            logMsg("ERRORE update contatto $contactId (id $existingId): HTTP $httpCode");
        }
    } else {
        list($httpCode, ) = wildixCall(
            "$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/",
            'POST',
            $fields
        );
        if ($httpCode == 200) {
            $created++;
            debugLog("Contatto $contactId: creato.");
        } else {
            $errors++;
            logMsg("ERRORE creazione contatto $contactId: HTTP $httpCode");
        }
    }
}

// 3. Cancella i contatti Wildix non piu' presenti nel CSV
$deleted = 0;
if ($deleteMissingContacts) {
    foreach ($existingContacts as $contactId => $c) {
        if (!isset($csvContacts[$contactId])) {
            list($httpCode, ) = wildixCall(
                "$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/{$c['id']}/",
                'DELETE'
            );
            if ($httpCode == 200) {
                $deleted++;
                debugLog("Contatto $contactId (id {$c['id']}): cancellato (assente dal CSV).");
            } else {
                $errors++;
                logMsg("ERRORE cancellazione contatto $contactId (id {$c['id']}): HTTP $httpCode");
            }
        }
    }
}

// 4. Archivia il file su RX-save e ripulisce il locale
archiveCsvToRxSave($localFile);
unlink($localFile);

logMsg(
    "SYNC RUBRICA: creati=$created aggiornati=$updated " .
    "cancellati=$deleted errori=$errors su " . count($csvContacts) . " righe CSV."
);
