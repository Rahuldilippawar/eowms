<?php

namespace Tests\Support;

use App\Services\Push\WebPush;

/** A software authenticator for tests: what a phone's fingerprint sensor sends, signed with a real key. */
final class FakeAuthenticator
{
    public string $credentialId;

    public int $counter = 0;

    private \OpenSSLAsymmetricKey $key;

    public function __construct(private string $rpId = 'localhost', private string $origin = 'http://localhost')
    {
        $this->credentialId = random_bytes(32);
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    public function id(): string
    {
        return WebPush::b64e($this->credentialId);
    }

    /** navigator.credentials.create() answer for the given options. */
    public function create(array $options, bool $verified = true): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = self::map([
            [self::int(1), self::int(2)], [self::int(3), self::int(-7)], [self::int(-1), self::int(1)],
            [self::int(-2), self::bytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT))], [self::int(-3), self::bytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))],
        ]);
        $authData = hash('sha256', $this->rpId, true).chr(0x41 | ($verified ? 0x04 : 0)).pack('N', $this->counter)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;
        $attestation = self::map([[self::text('fmt'), self::text('none')], [self::text('attStmt'), self::map([])], [self::text('authData'), self::bytes($authData)]]);

        return ['id' => $this->id(), 'rawId' => $this->id(), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => WebPush::b64e($this->clientData('webauthn.create', $options['challenge'])),
            'attestationObject' => WebPush::b64e($attestation), 'transports' => ['internal'],
        ]];
    }

    /** navigator.credentials.get() answer for the given options. */
    public function get(array $options, ?string $origin = null): array
    {
        $this->counter++;
        $client = $this->clientData('webauthn.get', $options['challenge'], $origin);
        $authData = hash('sha256', $this->rpId, true).chr(0x05).pack('N', $this->counter);
        openssl_sign($authData.hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return ['id' => $this->id(), 'rawId' => $this->id(), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => WebPush::b64e($client), 'authenticatorData' => WebPush::b64e($authData), 'signature' => WebPush::b64e($signature),
        ]];
    }

    private function clientData(string $type, string $challenge, ?string $origin = null): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? $this->origin, 'crossOrigin' => false]);
    }

    /** CBOR, just enough to build what an authenticator sends. @param list<array{0: string, 1: string}> $pairs encoded key / value */
    private static function map(array $pairs): string
    {
        return self::head(5, count($pairs)).implode('', array_map(fn ($p) => $p[0].$p[1], $pairs));
    }

    private static function int(int $v): string
    {
        return $v >= 0 ? self::head(0, $v) : self::head(1, -1 - $v);
    }

    private static function text(string $v): string
    {
        return self::head(3, strlen($v)).$v;
    }

    private static function bytes(string $v): string
    {
        return self::head(2, strlen($v)).$v;
    }

    private static function head(int $major, int $length): string
    {
        $m = $major << 5;

        return match (true) {
            $length < 24 => chr($m | $length),
            $length < 256 => chr($m | 24).chr($length),
            $length < 65536 => chr($m | 25).pack('n', $length),
            default => chr($m | 26).pack('N', $length),
        };
    }
}
