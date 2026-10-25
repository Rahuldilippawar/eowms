<?php
/**
 * Self-Contained Web Push Sender — Composer/बाह्य Library शिवाय.
 *
 * हे Class दोन गोष्टी करतं:
 *  1. VAPID (RFC 8292) — "मी अस्सल Server आहे" हे सिद्ध करणारी, Sign
 *     केलेली Authorization Header तयार करतं.
 *  2. Payload Encryption (RFC 8291, aes128gcm) — सूचनेचा मजकूर, फक्त
 *     त्या User च्या Browser लाच वाचता येईल असा Encrypt करतं (Push
 *     Service — Google/Mozilla चा Server — मजकूर वाचू शकत नाही).
 *
 * आवश्यकता: PHP 7.1+ सह OpenSSL Extension (prime256v1/P-256 EC Curve
 * Support सह — जवळपास सगळ्याच आधुनिक PHP मध्ये आधीच उपलब्ध असतं).
 */
class WebPush
{
    private $vapidPublicKey;
    private $vapidPrivateKey;
    private $vapidSubject;
    public $timeout = 10; // seconds — SOS साठी कमी ठेवता येतो

    public function __construct($vapidPublicKeyB64, $vapidPrivateKeyB64, $vapidSubject)
    {
        $this->vapidPublicKey = $vapidPublicKeyB64;
        $this->vapidPrivateKey = $vapidPrivateKeyB64;
        $this->vapidSubject = $vapidSubject;
    }

    /** ---------------- Base64-URL Helpers ---------------- */
    private static function b64urlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    private static function b64urlDecode($data)
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) { $data .= str_repeat('=', 4 - $pad); }
        return base64_decode($data);
    }

    /**
     * VAPID Private Key (Base64-URL, Raw 32-byte Scalar) पासून OpenSSL
     * ला समजेल असा, वापरण्यायोग्य EC Private Key Resource बनवतो.
     */
    private function vapidPrivateKeyResource()
    {
        $rawPriv = self::b64urlDecode($this->vapidPrivateKey);
        $rawPub = self::b64urlDecode($this->vapidPublicKey); // 65 bytes, 0x04 + X(32) + Y(32)

        $der = $this->buildEcPrivateKeyDer($rawPriv, $rawPub);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        return openssl_pkey_get_private($pem);
    }

    /** कच्च्या (Raw) EC Key Bytes पासून — OpenSSL ला हवं तसं DER-Encoded EC Private Key तयार करतं (RFC 5915). */
    private function buildEcPrivateKeyDer($privRaw, $pubRaw)
    {
        $version = "\x02\x01\x01";
        $privKeyOctet = $this->derOctetString($privRaw);
        $curveOid = "\xA0\x0A\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07"; // prime256v1 OID
        $pubBitString = "\xA1" . $this->derLength(strlen($pubRaw) + 2) . "\x03" . $this->derLength(strlen($pubRaw) + 1) . "\x00" . $pubRaw;
        $body = $version . $privKeyOctet . $curveOid . $pubBitString;
        return "\x30" . $this->derLength(strlen($body)) . $body;
    }
    private function derOctetString($raw)
    {
        return "\x04" . $this->derLength(strlen($raw)) . $raw;
    }
    private function derLength($len)
    {
        if ($len < 128) { return chr($len); }
        $bytes = ltrim(pack('N', $len), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** ---------------- VAPID JWT (RFC 8292) ---------------- */
    private function buildVapidHeader($endpoint)
    {
        $urlParts = parse_url($endpoint);
        $aud = $urlParts['scheme'] . '://' . $urlParts['host'];

        $header = self::b64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64urlEncode(json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $this->vapidSubject,
        ]));
        $unsigned = $header . '.' . $payload;

        $privKey = $this->vapidPrivateKeyResource();
        openssl_sign($unsigned, $derSignature, $privKey, OPENSSL_ALGO_SHA256);

        // OpenSSL DER-स्वरूपात Signature देतं — JWT ला मात्र सरळ, R+S
        // जोडलेलं (64 bytes) हवं असतं — इथे ते रूपांतर करतो.
        $rawSignature = $this->derSignatureToRaw($derSignature);
        $jwt = $unsigned . '.' . self::b64urlEncode($rawSignature);

        return [
            'Authorization: vapid t=' . $jwt . ', k=' . $this->vapidPublicKey,
        ];
    }

    private function derSignatureToRaw($der)
    {
        $offset = 2; // skip SEQUENCE tag+length
        $offset++; // skip INTEGER tag for r
        $rLen = ord($der[$offset]); $offset++;
        $r = substr($der, $offset, $rLen); $offset += $rLen;
        $offset++; // skip INTEGER tag for s
        $sLen = ord($der[$offset]); $offset++;
        $s = substr($der, $offset, $sLen);

        $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    /** ---------------- Payload Encryption (RFC 8291, aes128gcm) ---------------- */
    private function encryptPayload($payload, $p256dhB64, $authB64)
    {
        $userPublicKeyRaw = self::b64urlDecode($p256dhB64); // 65 bytes uncompressed EC point
        $authSecret = self::b64urlDecode($authB64); // 16 bytes

        // सर्व्हरची स्वतःची, या एका Message पुरतीच वापरली जाणारी तात्पुरती Key-जोडी
        $ephemeralKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ephemeralDetails = openssl_pkey_get_details($ephemeralKey);
        $serverPublicKeyRaw = "\x04" . $ephemeralDetails['ec']['x'] . $ephemeralDetails['ec']['y'];

        // ECDH — User च्या Public Key आणि आपल्या तात्पुरत्या Private Key वापरून समान गुपित काढतो
        $userPemPublic = $this->rawEcPublicToPem($userPublicKeyRaw);
        $sharedSecret = openssl_pkey_derive($userPemPublic, $ephemeralKey, 256);
        if (strlen($sharedSecret) < 32) { $sharedSecret = str_pad($sharedSecret, 32, "\x00", STR_PAD_LEFT); }

        $salt = openssl_random_pseudo_bytes(16);

        $ikmInfo = "WebPush: info\x00" . $userPublicKeyRaw . $serverPublicKeyRaw;
        $ikm = $this->hkdf($authSecret, $sharedSecret, $ikmInfo, 32);

        $cek = $this->hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $this->hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        // Payload च्या शेवटी 0x02 (Padding-Delimiter) जोडतो (RFC 8291 आवश्यकता)
        $paddedPayload = $payload . "\x02";
        $ciphertext = openssl_encrypt($paddedPayload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        $ciphertext .= $tag;

        $recordSize = pack('N', 4096);
        $keyIdLen = chr(strlen($serverPublicKeyRaw));
        $header = $salt . $recordSize . $keyIdLen . $serverPublicKeyRaw;

        return $header . $ciphertext;
    }

    /** HKDF (RFC 5869) — फक्त एका 32-byte Block पुरतं (आपल्याला याहून जास्त कधीच लागत नाही) */
    private function hkdf($salt, $ikm, $info, $length)
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $t = hash_hmac('sha256', $info . "\x01", $prk, true);
        return substr($t, 0, $length);
    }

    private function rawEcPublicToPem($rawPublic)
    {
        $der = "\x30\x59\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07\x03\x42\x00" . $rawPublic;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * एका Subscription ला प्रत्यक्ष सूचना पाठवतो.
     * @return array ['success' => bool, 'http_code' => int, 'should_delete' => bool]
     */
    public function send($endpoint, $p256dh, $auth, $payloadArray)
    {
        $payload = json_encode($payloadArray, JSON_UNESCAPED_UNICODE);
        $encryptedBody = $this->encryptPayload($payload, $p256dh, $auth);
        $vapidHeaders = $this->buildVapidHeader($endpoint);

        $headers = array_merge($vapidHeaders, [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 2419200',
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encryptedBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 404/410 म्हणजे — त्या Browser ने Subscription रद्द केली आहे
        // (App Uninstall केलं, वेगळा Browser वापरतोय, इ.) — अशा जुन्या
        // नोंदी Database मधून काढून टाकाव्या लागतात.
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'should_delete' => in_array($httpCode, [404, 410], true),
        ];
    }

    /** VAPID Key-जोडी तयार करतं — Setup च्या वेळी एकदाच वापरायचं Function. */
    public static function generateVapidKeys()
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key);
        $publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        $privateRaw = $details['ec']['d'];
        return [
            'publicKey' => self::b64urlEncode($publicRaw),
            'privateKey' => self::b64urlEncode($privateRaw),
        ];
    }
}
