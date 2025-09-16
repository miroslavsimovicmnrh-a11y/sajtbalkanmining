<?php
// chat.php — siguran proxy do xAI Grok API-ja (bez izlaganja ključa u HTML/JS)
// Postavi GROK_API_KEY kao environment var (preporučeno) ili u posebnom config fajlu izvan web root-a.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// 1) UČITAJ KLJUČ
$apiKey = getenv('GROK_API_KEY');
if (!$apiKey) {
  // Opciona rezerva: config.php vrati ['GROK_API_KEY' => '...']
  $configPath = __DIR__ . '/config.php';
  if (file_exists($configPath)) {
    $cfg = include $configPath;
    if (isset($cfg['GROK_API_KEY'])) $apiKey = $cfg['GROK_API_KEY'];
  }
}
if (!$apiKey) {
  http_response_code(500);
  echo json_encode(['error' => 'Nedostaje GROK_API_KEY. Postavi ga kao env var ili u config.php (koji nije javno vidljiv).']);
  exit;
}

// 2) UČITAJ PORUKU
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$userMsg = isset($payload['message']) ? trim($payload['message']) : '';
if ($userMsg === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Prazna poruka.']);
  exit;
}

// 3) UČITAJ LOKALNO ZNANJE (podaci.txt) — opciono
$kbText = '';
$kbPath = __DIR__ . '/podaci.txt';
if (file_exists($kbPath)) {
  $kbText = file_get_contents($kbPath);
  // Limit da ne šaljemo previše teksta odjednom (po potrebi podesi)
  if (strlen($kbText) > 150000) {
    $kbText = substr($kbText, 0, 150000);
  }
}

// 4) PRIPREMI ZAHTEV KA xAI (Grok) — OpenAI-kompatibilan chat.completions
$endpoint = 'https://api.x.ai/v1/chat/completions';
$model = 'grok-2-latest'; // prilagodi ako koristiš drugi naziv modela

$messages = [
  [
    'role' => 'system',
    'content' => "Budi kratak, jasan i odgovaraj na srpskom. Ako pitanje traži info iz baze znanja, koristi 'PODACI.TXT' ispod kao primarni izvor.\n\nPODACI.TXT:\n" . $kbText
  ],
  ['role' => 'user', 'content' => $userMsg],
];

$body = json_encode([
  'model' => $model,
  'messages' => $messages,
  'temperature' => 0.3,
  'stream' => false
]);

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Content-Type: application/json',
  'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($res === false) {
  http_response_code(500);
  echo json_encode(['error' => "cURL greška: $err"]);
  exit;
}

$data = json_decode($res, true);

if ($httpCode >= 400 || !isset($data['choices'][0]['message']['content'])) {
  http_response_code(500);
  $msg = isset($data['error']['message']) ? $data['error']['message'] : ('Neuspešan odgovor API-ja (HTTP ' . $httpCode . ').');
  echo json_encode(['error' => $msg, 'raw' => $data]);
  exit;
}

$answer = $data['choices'][0]['message']['content'];
echo json_encode(['answer' => $answer]);
