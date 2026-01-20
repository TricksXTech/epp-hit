<?php
header('Content-Type: application/json');

/* ===============================
   CONFIG (CHANGE THESE)
================================ */
define('EPP_HOST', 'epp.demoreg.net');
define('EPP_PORT', 700);

define('EPP_USER', 'YOUR_EPP_USERNAME');
define('EPP_PASS', 'YOUR_EPP_PASSWORD');

/* ===============================
   HELPER: clTRID
================================ */
function clTRID() {
    return 'CL-' . date('YmdHis') . '-' . random_int(1000,9999);
}

/* ===============================
   SOCKET CONNECT
================================ */
function eppConnect() {
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $sock = stream_socket_client(
        "ssl://" . EPP_HOST . ":" . EPP_PORT,
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $ctx
    );

    if (!$sock) {
        throw new Exception("EPP connect failed: $errstr ($errno)");
    }

    // Read greeting
    readEpp($sock);
    return $sock;
}

/* ===============================
   SEND + RECEIVE EPP
================================ */
function sendEpp($sock, $xml) {
    $frame = pack('N', strlen($xml) + 4) . $xml;
    fwrite($sock, $frame);
    return readEpp($sock);
}

function readEpp($sock) {
    $hdr = fread($sock, 4);
    if (!$hdr) throw new Exception("No response from EPP");

    $len = unpack('N', $hdr)[1] - 4;
    $data = '';

    while (strlen($data) < $len) {
        $data .= fread($sock, $len - strlen($data));
    }
    return $data;
}

/* ===============================
   EPP LOGIN
================================ */
function eppLogin($sock) {
    $xml =
'<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
 <command>
  <login>
   <clID>'.EPP_USER.'</clID>
   <pw>'.EPP_PASS.'</pw>
   <options>
    <version>1.0</version>
    <lang>en</lang>
   </options>
   <svcs>
    <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
   </svcs>
  </login>
  <clTRID>'.clTRID().'</clTRID>
 </command>
</epp>';

    $res = sendEpp($sock, $xml);
    if (!preg_match('/code="1000"/', $res)) {
        throw new Exception("Login failed: $res");
    }
}

/* ===============================
   DOMAIN CHECK
================================ */
function eppDomainCheck($sock, array $domains) {
    $names = '';
    foreach ($domains as $d) {
        $names .= '<domain:name>'.$d.'</domain:name>';
    }

    $xml =
'<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
 <command>
  <check>
   <domain:check xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
    '.$names.'
   </domain:check>
  </check>
  <clTRID>'.clTRID().'</clTRID>
 </command>
</epp>';

    return sendEpp($sock, $xml);
}

/* ===============================
   LOGOUT
================================ */
function eppLogout($sock) {
    $xml =
'<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
 <command>
  <logout/>
  <clTRID>'.clTRID().'</clTRID>
 </command>
</epp>';
    sendEpp($sock, $xml);
}

/* ===============================
   XML → JSON (BASIC)
================================ */
function parseAvailability($xml) {
    preg_match_all(
        '/<domain:name avail="([01])">(.*?)<\/domain:name>/',
        $xml,
        $m,
        PREG_SET_ORDER
    );

    $out = [];
    foreach ($m as $x) {
        $out[$x[2]] = ($x[1] === '1') ? 'available' : 'unavailable';
    }

    return $out;
}

/* ===============================
   MAIN EXECUTION
================================ */
try {

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['domains'])) {
        throw new Exception("JSON with domains[] required");
    }

    $sock = eppConnect();        // connect + greeting
    eppLogin($sock);             // LOGIN
    $xml = eppDomainCheck($sock, $input['domains']); // CHECK
    eppLogout($sock);            // LOGOUT
    fclose($sock);

    echo json_encode([
        'success' => true,
        'availability' => parseAvailability($xml),
        'raw_xml' => $xml
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
