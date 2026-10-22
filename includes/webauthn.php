<?php
/**
 * WebAuthn (Fingerprint/Face ID) — Core Helper Functions
 * ---------------------------------------------------------------
 * हे संपूर्ण PHP च्या built-in OpenSSL cryptography वापरून बनवलेलं आहे —
 * कुठलाही बाह्य library/Composer package लागत नाही. फक्त ES256
 * (ECDSA P-256) अल्गोरिदम support करतो — जो जवळपास सगळे आधुनिक
 * Fingerprint/Face ID Authenticators (Windows Hello, Touch ID, Android
 * Biometric) डिफॉल्ट म्हणून वापरतात.
 *
 * महत्त्वाचं: तुमचा प्रत्यक्ष फिंगरप्रिंट कधीही Server कडे पाठवला जात
 * नाही — फक्त डिव्हाइसवर तयार झालेली सार्वजनिक किल्ली (Public Key) आणि
 * cryptographic स्वाक्षरी (Signature) इथे साठवली/पडताळली जाते.
 */

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) { $data .= str_repeat('=', 4 - $pad); }
    return base64_decode($data);
}

/**
 * एक अगदी छोटा, WebAuthn साठी पुरेसा CBOR Decoder — फक्त आवश्यक असलेले
 * data types (unsigned/negative int, byte string, text string, array,
 * map, bool) डीकोड करतो. सुरुवातीला (offset 0) पासून एकच value वाचतो.
 */
function cbor_decode_item($data, &$offset) {
    $firstByte = ord($data[$offset]);
    $majorType = $firstByte >> 5;
    $addInfo = $firstByte & 0x1F;
    $offset++;

    $readLength = function () use ($data, &$offset, $addInfo) {
        if ($addInfo < 24) { return $addInfo; }
        if ($addInfo === 24) { $v = ord($data[$offset]); $offset += 1; return $v; }
        if ($addInfo === 25) { $v = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; return $v; }
        if ($addInfo === 26) { $v = unpack('N', substr($data, $offset, 4))[1]; $offset += 4; return $v; }
        if ($addInfo === 27) {
            $hi = unpack('N', substr($data, $offset, 4))[1];
            $lo = unpack('N', substr($data, $offset + 4, 4))[1];
            $offset += 8;
            return ($hi << 32) | $lo;
        }
        throw new Exception('Unsupported CBOR length encoding');
    };

    switch ($majorType) {
        case 0: // unsigned int
            return $readLength();
        case 1: // negative int
            return -1 - $readLength();
        case 2: // byte string
            $len = $readLength();
            $val = substr($data, $offset, $len);
            $offset += $len;
            return $val;
        case 3: // text string
            $len = $readLength();
            $val = substr($data, $offset, $len);
            $offset += $len;
            return $val;
        case 4: // array
            $len = $readLength();
            $arr = [];
            for ($i = 0; $i < $len; $i++) { $arr[] = cbor_decode_item($data, $offset); }
            return $arr;
        case 5: // map
            $len = $readLength();
            $map = [];
            for ($i = 0; $i < $len; $i++) {
                $key = cbor_decode_item($data, $offset);
                $val = cbor_decode_item($data, $offset);
                $map[$key] = $val;
            }
            return $map;
        case 7: // simple/float/bool
            if ($addInfo === 20) { return false; }
            if ($addInfo === 21) { return true; }
            if ($addInfo === 22) { return null; }
            throw new Exception('Unsupported CBOR simple type');
        default:
            throw new Exception('Unsupported CBOR major type: ' . $majorType);
    }
}

function cbor_decode($data) {
    $offset = 0;
    return cbor_decode_item($data, $offset);
}

/**
 * authData ही WebAuthn मधली एक raw-binary रचना आहे (CBOR नाही) —
 * rpIdHash(32) + flags(1) + signCount(4) + [attestedCredentialData]
 */
function webauthn_parse_auth_data($authData) {
    $rpIdHash = substr($authData, 0, 32);
    $flags = ord($authData[32]);
    $signCount = unpack('N', substr($authData, 33, 4))[1];

    $result = ['rpIdHash' => $rpIdHash, 'flags' => $flags, 'signCount' => $signCount, 'credentialId' => null, 'coseKey' => null];

    $hasAttestedCredData = ($flags & 0x40) !== 0;
    if ($hasAttestedCredData) {
        $offset = 37; // after rpIdHash+flags+signCount
        $offset += 16; // aaguid
        $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;
        $credentialId = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;

        // उरलेला भाग म्हणजे COSE_Key, CBOR स्वरूपात — decode करा
        $remaining = substr($authData, $offset);
        $coseKey = cbor_decode($remaining);

        $result['credentialId'] = $credentialId;
        $result['coseKey'] = $coseKey;
    }
    return $result;
}

/**
 * COSE EC2 Key (ES256/P-256) ला PEM public key मध्ये रूपांतरित करते —
 * जेणेकरून PHP चं openssl_verify() वापरून स्वाक्षरी पडताळता येईल.
 */
function webauthn_cose_key_to_pem($coseKey) {
    // COSE_Key map keys: 1=kty, 3=alg, -1=crv, -2=x, -3=y
    if (($coseKey[1] ?? null) !== 2) { throw new Exception('फक्त EC2 (P-256) की समर्थित आहे'); }
    $x = $coseKey[-2] ?? null;
    $y = $coseKey[-3] ?? null;
    if (!$x || !$y || strlen($x) !== 32 || strlen($y) !== 32) {
        throw new Exception('चुकीची Public Key रचना');
    }

    // Standard SPKI DER prefix for an EC P-256 (prime256v1) public key
    $derPrefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $derPrefix . "\x04" . $x . $y; // 0x04 = uncompressed point marker

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Registration/Login साठी वापरलं जाणारं random, न-अंदाज-करता-येणारं Challenge */
function webauthn_generate_challenge() {
    return random_bytes(32);
}

/**
 * Server ने पाठवलेलं challenge आणि Browser ने पाठवलेल्या clientDataJSON
 * मधलं challenge सारखंच आहे का, आणि origin बरोबर आहे का हे तपासते.
 */
function webauthn_verify_client_data($clientDataJSON, $expectedChallenge, $expectedType, $expectedOrigin) {
    $clientData = json_decode($clientDataJSON, true);
    if (!$clientData) { return false; }
    if (($clientData['type'] ?? '') !== $expectedType) { return false; }
    if (!hash_equals($expectedChallenge, base64url_decode($clientData['challenge'] ?? ''))) { return false; }
    if (($clientData['origin'] ?? '') !== $expectedOrigin) { return false; }
    return true;
}

/** सध्याच्या request वरून बरोबर Origin (scheme://host) मिळवते */
function webauthn_current_origin() {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'];
}

/** सध्याच्या request वरून Relying Party ID (फक्त host, port/scheme शिवाय) मिळवते */
function webauthn_rp_id() {
    $host = $_SERVER['HTTP_HOST'];
    // पोर्ट असेल (उदा. localhost:8080) तर काढून टाका — RP ID मध्ये पोर्ट चालत नाही
    return preg_replace('/:\d+$/', '', $host);
}
