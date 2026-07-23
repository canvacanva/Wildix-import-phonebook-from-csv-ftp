<?php

date_default_timezone_set('Europe/Rome');
require '/var/credentials/cred.php';

$pbxHost = "https://{$wildixdns}.wildixin.com";

function call($url, $method = 'GET', $data = null) {
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
        // Formato confermato dalla doc: campi annidati sotto "data",
        // form-urlencoded (non JSON).
        $options[CURLOPT_POSTFIELDS] = http_build_query(['data' => $data]);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init();
    curl_setopt_array($ch, $options);

    echo "\n>>> $method $url\n";
    if ($data !== null) {
        echo "    body (form, annidato in data[]): " . http_build_query(['data' => $data]) . "\n";
    }

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    echo "<<< HTTP $httpCode" . ($err ? " curl_error=$err" : "") . "\n";
    echo $res . "\n";

    return [$httpCode, json_decode($res, true)];
}

$phonebookId = 19;

echo "=========================================\n";
echo "1. Contenuto attuale del Phonebook $phonebookId\n";
echo "=========================================\n";
call("$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/");

echo "\n=========================================\n";
echo "2. Creazione contatto di test con formato data[] confermato dalla doc\n";
echo "=========================================\n";
call("$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/", 'POST', [
    'shortcut' => '999993',
    'name' => 'TEST CONTATTO DEBUG FINAL',
    'mobile' => '+390000000000',
    'email' => 'test.debug.final@example.com',
    'organization' => 'TEST DEBUG',
    'picture' => 'data/images/default_avatar.png',
]);

echo "\n=========================================\n";
echo "3. Rilettura Phonebook $phonebookId per verificare che il contatto sia stato creato\n";
echo "=========================================\n";
call("$pbxHost/api/v1/Phonebooks/$phonebookId/Contacts/");

echo "\n=========================================\n";
echo "FINE. Incolla tutto questo output.\n";
echo "Se il passo 2 da' HTTP 200 con un 'id', il formato e' confermato: si\n";
echo "puo' passare al sync completo. Il contatto di test (shortcut 999993 e\n";
echo "gli eventuali precedenti 999994-999999) andra' cancellato a mano.\n";
