<?php

error_reporting(1);

header('Content-Type: application/json');

/* -------------------------
   CONFIG
------------------------- */
define('EPP_HOST', 'epp.demoreg.net');
define('EPP_PORT', 700);

/* -------------------------
   READ JSON INPUT
------------------------- */
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid JSON']));
}

/* -------------------------
   JSON → EPP XML
------------------------- */
function buildEppXml($data)
{
    if (!isset($data['action'])) {
        throw new Exception('Action missing');
    }

    $clTRID = $data['clTRID'] ?? uniqid('TRX-');

    switch ($data['action']) {

        case 'hello':
            return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">'
            .'<command><hello/></command>'
            .'</epp>';

        case 'domain_check':
            if (empty($data['domains'])) {
                throw new Exception('Domains missing');
            }

            $domains = '';
            foreach ($data['domains'] as $d) {
                $domains .= '<domain:name>'.$d.'</domain:name>';
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">'
            .'<command>'
            .'<check>'
            .'<domain:check xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
            .$domains
            .'</domain:check>'
            .'</check>'
            .'<clTRID>'.$clTRID.'</clTRID>'
            .'</command>'
            .'</epp>';

        default:
            throw new Exception('Unsupported action');
    }
}

/* -------------------------
   SEND XML TO EPP SERVER
------------------------- */
function sendEpp($xml)
{
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $socket = stream_socket_client(
        'ssl://'.EPP_HOST.':'.EPP_PORT,
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new Exception("Connection failed: $errstr ($errno)");
    }

    // EPP framing (4-byte length header)
    $length = strlen($xml) + 4;
    fwrite($socket, pack('N', $length) . $xml);

    // Read response
    $header = fread($socket, 4);
    $size   = unpack('N', $header)[1] - 4;
    $response = '';

    while (strlen($response) < $size) {
        $response .= fread($socket, $size - strlen($response));
    }

    fclose($socket);
    return $response;
}

/* -------------------------
   BASIC XML → JSON
------------------------- */
function xmlToJson($xml)
{
    preg_match('/result code="(\d+)"/', $xml, $code);
    preg_match('/<msg>(.*?)<\/msg>/', $xml, $msg);

    return [
        'result_code' => $code[1] ?? null,
        'message'     => $msg[1] ?? null,
        'raw_xml'     => $xml
    ];
}

/* -------------------------
   EXECUTION
------------------------- */
try {
    $xmlRequest  = buildEppXml($data);
    $xmlResponse = sendEpp($xmlRequest);

    echo json_encode(xmlToJson($xmlResponse), JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage()
    ]);
}
?>
