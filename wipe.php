<?php
/**
 * Svuota TUTTI i contatti della rubrica definita ID
 * Lancialo con: php wipe_phonebook.php
 */

date_default_timezone_set('Europe/Rome');
require '/var/credentials/cred.php';

$pbxHost = "https://{$wildixdns}.wildixin.com";
$phonebookId = 19;

//Funzione WIPE
function call($url, $method = 'GET') {
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
    $options[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    echo "\n>>> $method $url\n";
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo "<<< HTTP $httpCode" . ($err ? " curl_error=$err" : "") . "\n";
    echo $res . "\n";
    return [$httpCode, json_decode($res, true)];
}

echo "=========================================\n";
echo "1. Contatti presenti PRIMA della cancellazione\n";
echo "=========================================\n";
call("$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/");

echo "\n=========================================\n";
echo "2. Cancellazione di TUTTI i contatti del Phonebook $phonebookId\n";
echo "=========================================\n";
call("$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/", 'DELETE');

echo "\n=========================================\n";
echo "3. Verifica: contatti presenti DOPO la cancellazione (deve essere 0)\n";
echo "=========================================\n";
call("$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/");

echo "\n=========================================\n";
echo "FINE. Se il passo 3 mostra total:0, la rubrica e' pulita:\n";