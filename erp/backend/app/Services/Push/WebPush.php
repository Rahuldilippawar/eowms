<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Web Push without extra libraries (erp/docs/NOTIFICATIONS.md): the payload is encrypted for the
 * browser as RFC 8291 (aes128gcm) and the request is signed with VAPID (RFC 8292), all with PHP's
 * openssl. Keys come from VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (base64url; `php artisan erp:vapid`).
 */
final class WebPush
{
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function configured(): bool
    {
        return (string) config('services.vapid.public_key') !== '' && (string) config('services.vapid.private_key') !== '';
    }

    /** @return 'sent'|'gone'|'failed' gone = the browser dropped the subscription (delete it) */
    public function send(PushSubscription $sub, array $payload, int $ttl = 86400): string
    {
        if (! self::configured()) {
            throw new RuntimeException('VAPID keys are not configured.');
        }
        $body = $this->encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE), self::b64d($sub->p256dh), self::b64d($sub->auth));
        $parts = parse_url($sub->endpoint);
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $response = Http::timeout(10)->withHeaders([
            'TTL' => (string) $ttl,
            'Content-Encoding' => 'aes128gcm',
            'Content-Type' => 'application/octet-stream',
            'Urgency' => 'normal',
            'Authorization' => 'vapid t='.$this->vapidToken($audience).', k='.config('services.vapid.public_key'),
        ])->withBody($body, 'application/octet-stream')->post($sub->endpoint);

        return match (true) {
            $response->successful() => 'sent',
            in_array($response->status(), [404, 410], true) => 'gone',
            default => 'failed',
        };
    }

    /** RFC 8291: one aes128gcm record for the browser's key pair and auth secret. */
    public function encrypt(string $plaintext, string $uaPublic, string $authSecret): string
    {
        $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($local)['ec'];
        $asPublic = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $shared = openssl_pkey_derive(self::publicKey($uaPublic), $local, 32);
        if ($shared === false) {
            throw new RuntimeException('Could not derive the shared secret.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    /** RFC 8292: an ES256 JWT for the push service's origin, valid 12 hours. */
    public function vapidToken(string $audience): string
    {
        $header = self::b64e(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64e(json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => config('services.vapid.subject') ?: 'mailto:'.config('mail.from.address')]));
        openssl_sign("{$header}.{$claims}", $der, self::privateKey(), OPENSSL_ALGO_SHA256);

        return "{$header}.{$claims}.".self::b64e(self::derToRaw($der));
    }

    /** A new VAPID key pair as base64url: public = uncompressed point (65 bytes), private = d (32 bytes). */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];

        return ['public' => self::b64e("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            'private' => self::b64e(str_pad($ec['d'], 32, "\0", STR_PAD_LEFT))];
    }

    public static function publicKey(string $point): \OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64)."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) ?: throw new RuntimeException('Bad public key.');
    }

    private static function privateKey(): \OpenSSLAsymmetricKey
    {
        $d = self::b64d((string) config('services.vapid.private_key'));
        $pub = self::b64d((string) config('services.vapid.public_key'));
        // SEC1 ECPrivateKey: version 1, the private scalar, the P-256 curve and the public point.
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$pub;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64)."-----END EC PRIVATE KEY-----\n";

        return openssl_pkey_get_private($pem) ?: throw new RuntimeException('Bad VAPID private key.');
    }

    /** openssl gives an ASN.1 DER signature; JWT ES256 wants r || s, 32 bytes each. */
    public static function derToRaw(string $der): string
    {
        $offset = 2;
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $len), "\0");
            $parts[] = str_pad($int, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return implode('', $parts);
    }

    public static function b64e(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64d(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
