<?php
// ─────────────────────────────────────────────────────────────
// WebAuthn (ফিঙ্গারপ্রিন্ট/পাসকি) — খাঁটি PHP, শুধু openssl।
// শুধু ES256 (alg -7, P-256) সাপোর্ট — ফোন/ল্যাপটপের প্ল্যাটফর্ম
// অথেন্টিকেটর (ফিঙ্গারপ্রিন্ট/Face/Windows Hello) প্রায় সবই এটাই ব্যবহার করে।
//
// রেজিস্ট্রেশনে (admin লগইন অবস্থায়) attestation যাচাই করা হয় না —
// শুধু পাবলিক কী তোলা হয় (ইউজার তখন এমনিতেই প্রমাণিত অ্যাডমিন)।
// আসল নিরাপত্তা লগইনের সময় assertion signature যাচাইয়ে (নিচে)।
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/functions.php';

function wa_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function wa_b64url_decode(string $str): string
{
    $str = strtr($str, '-_', '+/');
    $pad = strlen($str) % 4;
    if ($pad) { $str .= str_repeat('=', 4 - $pad); }
    return base64_decode($str, true) ?: '';
}

// Relying Party ID = হোস্ট (পোর্ট বাদ)। localhost/লাইভ দুটোতেই কাজ করে।
function wa_rp_id(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/:\d+$/', '', $host); // পোর্ট বাদ
    return $host ?: 'localhost';
}

// প্রত্যাশিত origin = scheme://host(:port) — clientDataJSON-এর origin এর সাথে মিলবে
function wa_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function wa_challenge(): string
{
    return random_bytes(32);
}

// ── ন্যূনতম CBOR ডিকোডার (uint/negint/bytes/text/array/map) ──
function wa_cbor_len(string $data, int &$off, int $ai): int
{
    if ($ai < 24) { return $ai; }
    if ($ai === 24) { return ord($data[$off++]); }
    if ($ai === 25) { $v = unpack('n', substr($data, $off, 2))[1]; $off += 2; return $v; }
    if ($ai === 26) { $v = unpack('N', substr($data, $off, 4))[1]; $off += 4; return $v; }
    if ($ai === 27) {
        $hi = unpack('N', substr($data, $off, 4))[1];
        $lo = unpack('N', substr($data, $off + 4, 4))[1];
        $off += 8;
        return $hi * 4294967296 + $lo;
    }
    throw new RuntimeException('CBOR: unsupported length');
}

function wa_cbor_decode(string $data, int &$off)
{
    $ib = ord($data[$off++]);
    $major = $ib >> 5;
    $ai = $ib & 0x1f;
    if ($major === 7) { throw new RuntimeException('CBOR: float/simple unsupported'); }
    $val = wa_cbor_len($data, $off, $ai);
    switch ($major) {
        case 0: return $val;            // unsigned int
        case 1: return -1 - $val;       // negative int
        case 2:                          // byte string
        case 3:                          // text string
            $s = substr($data, $off, $val); $off += $val; return $s;
        case 4:                          // array
            $arr = [];
            for ($i = 0; $i < $val; $i++) { $arr[] = wa_cbor_decode($data, $off); }
            return $arr;
        case 5:                          // map
            $map = [];
            for ($i = 0; $i < $val; $i++) {
                $k = wa_cbor_decode($data, $off);
                $v = wa_cbor_decode($data, $off);
                $map[$k] = $v;
            }
            return $map;
    }
    throw new RuntimeException('CBOR: unsupported major type');
}

// COSE EC2 (P-256) কী → PEM SubjectPublicKeyInfo
function wa_cose_ec2_to_pem(array $cose): ?string
{
    // kty(1)=2 (EC2), alg(3)=-7 (ES256), crv(-1)=1 (P-256), x(-2), y(-3)
    if (($cose[1] ?? null) !== 2) { return null; }
    if (($cose[3] ?? null) !== -7) { return null; }
    if (($cose[-1] ?? null) !== 1) { return null; }
    $x = $cose[-2] ?? '';
    $y = $cose[-3] ?? '';
    if (strlen($x) !== 32 || strlen($y) !== 32) { return null; }
    // prime256v1 uncompressed-point SPKI DER হেডার + 0x04 + x + y
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004') . $x . $y;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// authData পার্স → [rpIdHash, flags, signCount, credId, cosePem]
function wa_parse_auth_data(string $authData): array
{
    if (strlen($authData) < 37) { throw new RuntimeException('authData too short'); }
    $rpIdHash = substr($authData, 0, 32);
    $flags = ord($authData[32]);
    $signCount = unpack('N', substr($authData, 33, 4))[1];
    $credId = null;
    $pem = null;
    if ($flags & 0x40) { // AT — attested credential data আছে
        $credIdLen = unpack('n', substr($authData, 53, 2))[1];
        $credId = substr($authData, 55, $credIdLen);
        $off = 55 + $credIdLen;
        $cose = wa_cbor_decode($authData, $off);
        $pem = is_array($cose) ? wa_cose_ec2_to_pem($cose) : null;
    }
    return [
        'rpIdHash'  => $rpIdHash,
        'flags'     => $flags,
        'signCount' => $signCount,
        'credId'    => $credId,
        'pem'       => $pem,
    ];
}

// রেজিস্ট্রেশন — attestationObject থেকে credId + পাবলিক কী তোলে (+ clientData যাচাই)
// $clientDataJSON, $attestationObject = raw বাইট (b64url ডিকোড করা)
// রিটার্ন: ['credId'=>raw, 'pem'=>string, 'signCount'=>int] অথবা RuntimeException
function wa_parse_registration(string $attestationObject, string $clientDataJSON, string $expectedChallenge): array
{
    $client = json_decode($clientDataJSON, true);
    if (!is_array($client) || ($client['type'] ?? '') !== 'webauthn.create') {
        throw new RuntimeException('ভুল clientData টাইপ');
    }
    if (!hash_equals($expectedChallenge, wa_b64url_decode($client['challenge'] ?? ''))) {
        throw new RuntimeException('challenge মিলছে না');
    }
    if (($client['origin'] ?? '') !== wa_origin()) {
        throw new RuntimeException('origin মিলছে না');
    }
    $off = 0;
    $att = wa_cbor_decode($attestationObject, $off);
    if (!is_array($att) || !isset($att['authData'])) {
        throw new RuntimeException('attestationObject ভুল');
    }
    $parsed = wa_parse_auth_data($att['authData']);
    if (!$parsed['credId'] || !$parsed['pem']) {
        throw new RuntimeException('সমর্থিত পাবলিক কী পাওয়া যায়নি (ES256 লাগবে)');
    }
    // rpIdHash যাচাই
    if (!hash_equals(hash('sha256', wa_rp_id(), true), $parsed['rpIdHash'])) {
        throw new RuntimeException('rpId মিলছে না');
    }
    return ['credId' => $parsed['credId'], 'pem' => $parsed['pem'], 'signCount' => $parsed['signCount']];
}

// লগইন — assertion signature যাচাই (এটাই আসল নিরাপত্তা)
// সব প্যারামিটার raw বাইট। রিটার্ন: ['ok'=>bool, 'signCount'=>int, 'error'=>string]
function wa_verify_assertion(string $pem, string $authData, string $clientDataJSON, string $signature, string $expectedChallenge): array
{
    $client = json_decode($clientDataJSON, true);
    if (!is_array($client) || ($client['type'] ?? '') !== 'webauthn.get') {
        return ['ok' => false, 'signCount' => 0, 'error' => 'ভুল clientData টাইপ'];
    }
    if (!hash_equals($expectedChallenge, wa_b64url_decode($client['challenge'] ?? ''))) {
        return ['ok' => false, 'signCount' => 0, 'error' => 'challenge মিলছে না'];
    }
    if (($client['origin'] ?? '') !== wa_origin()) {
        return ['ok' => false, 'signCount' => 0, 'error' => 'origin মিলছে না'];
    }
    if (strlen($authData) < 37) {
        return ['ok' => false, 'signCount' => 0, 'error' => 'authData ছোট'];
    }
    $rpIdHash = substr($authData, 0, 32);
    if (!hash_equals(hash('sha256', wa_rp_id(), true), $rpIdHash)) {
        return ['ok' => false, 'signCount' => 0, 'error' => 'rpId মিলছে না'];
    }
    $flags = ord($authData[32]);
    if (!($flags & 0x01)) { // UP — user present বিট
        return ['ok' => false, 'signCount' => 0, 'error' => 'user-present বিট নেই'];
    }
    $signCount = unpack('N', substr($authData, 33, 4))[1];
    $signedData = $authData . hash('sha256', $clientDataJSON, true);
    $res = openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256);
    return ['ok' => $res === 1, 'signCount' => $signCount, 'error' => $res === 1 ? '' : 'স্বাক্ষর যাচাই ব্যর্থ'];
}
