<?php
date_default_timezone_set('Europe/Rome');
require '/var/credentials/cred.php';

$pbxHost = "https://{$wildixdns}.wildixin.com";

// ID dei gruppi
$tecniciGroupId   = 6; // gruppo sorgente "Tecnici"
$assistentiGroupId = 7; // gruppo destinazione "Assistenti"

$tecniciTitle   = "Tecnici";
$assistentiTitle = "Assistenti";

$keyword = 'assistenza';

// File di log
$logFile = '/tmp/assistenza_sync.log';
function logMsg($msg) {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $msg . "\n";
}

//---------------------------------------------------------
// 1. Recupera membri del gruppo Tecnici
//---------------------------------------------------------
$groupsUrl = "$pbxHost/api/v1/Dialplan/CallGroups/";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $groupsUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        "Authorization: Bearer $authToken"
    ]
]);

$response = curl_exec($ch);
$data = json_decode($response, true);

$records = $data['result']['records'] ?? [];
$tecniciMembersRaw = [];
$assistentiSettings = [];

foreach ($records as $group) {
    if ($group['id'] == $tecniciGroupId || $group['title'] === $tecniciTitle) {
        $tecniciMembersRaw = $group['members'] ?? [];
    }
    if ($group['id'] == $assistentiGroupId || $group['title'] === $assistentiTitle) {
        $assistentiSettings = $group['settings'] ?? [];
    }
}

$tecniciExtensions = array_map(function($member) {
    return explode('@', $member)[0];
}, $tecniciMembersRaw);

if (empty($tecniciExtensions)) {
    logMsg("LOG: Nessun membro trovato nel gruppo '$tecniciTitle'.");
    exit;
}

//---------------------------------------------------------
// 2. Recupera Colleghi per ottenere email
//---------------------------------------------------------
$colleaguesUrl = "$pbxHost/api/v1/Colleagues/";
curl_setopt($ch, CURLOPT_URL, $colleaguesUrl);
$colleaguesResponse = curl_exec($ch);
$colleaguesData = json_decode($colleaguesResponse, true);
curl_close($ch);

$allUsers = $colleaguesData['result']['records'] ?? [];

$tecniciVerifica = [];
foreach ($allUsers as $user) {
    $ext = (string)$user['extension'];
    if (in_array($ext, $tecniciExtensions) && !empty($user['email'])) {
        $tecniciVerifica[$ext] = $user['email'];
    }
}

logMsg("Tecnici da controllare su Outlook: " . json_encode($tecniciVerifica));

//---------------------------------------------------------
// 3. Otteniamo token Microsoft Graph
//---------------------------------------------------------
$tokenUrl = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";
$postToken = [
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'scope' => 'https://graph.microsoft.com/.default',
    'grant_type' => 'client_credentials'
];
$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postToken));
$tokenRes = json_decode(curl_exec($ch), true);
$accessToken = $tokenRes['access_token'];
curl_close($ch);

//---------------------------------------------------------
// 4. Finestra temporale
//---------------------------------------------------------
$start = gmdate('Y-m-d\TH:i:s\Z', strtotime('-2 minutes'));
$end   = gmdate('Y-m-d\TH:i:s\Z', strtotime('+2 minutes'));

//---------------------------------------------------------
// 5. Verifica calendario e costruisci lista attivi
//---------------------------------------------------------
$attivi = [];

foreach ($tecniciVerifica as $ext => $email) {
    $graphUrl = "https://graph.microsoft.com/v1.0/users/" . urlencode($email) . "/calendarView";
    $graphUrl .= "?startDateTime=" . urlencode($start);
    $graphUrl .= "&endDateTime=" . urlencode($end);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $graphUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $accessToken",
            "Accept: application/json",
            "Prefer: outlook.timezone=\"Europe/Rome\""
        ]
    ]);

    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);

    if (isset($data['error'])) {
        logMsg("ERRORE GRAPH per $email: " . $data['error']['message']);
        continue;
    }

    $eventi = $data['value'] ?? [];
    $isAttivo = false;

    foreach ($eventi as $evento) {
        $subject = $evento['subject'] ?? '';
        $showAs  = strtolower($evento['showAs'] ?? '');
        $isCanceled = stripos($subject, 'Canceled:') === 0 || $showAs === 'free';

        if (!$isCanceled && stripos($subject, $keyword) !== false) {
            $isAttivo = true;
            break;
        }
    }

    if ($isAttivo) {
        $attivi[] = $ext . "@internalcalls";
        logMsg("OK: Tecnico $ext ($email) attivo");
    } else {
        logMsg("KO: Tecnico $ext ($email) non attivo");
    }
}

//---------------------------------------------------------
// 6. Aggiorna il gruppo Assistenti con i membri attivi
//---------------------------------------------------------
$updateUrl = "$pbxHost/api/v1/Dialplan/CallGroups/$assistentiGroupId/";
$payload = [
    "title"    => $assistentiTitle,
    "members"  => $attivi,
    "settings" => $assistentiSettings
];

$ch = curl_init($updateUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        "Authorization: Bearer $authToken",
        'Content-Type: application/json'
    ]
]);

$updateResponse = curl_exec($ch);
curl_close($ch);

logMsg("Risultato aggiornamento gruppo Assistenti: $updateResponse");
?>