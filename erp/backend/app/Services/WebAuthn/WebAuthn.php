<?php

namespace App\Services\WebAuthn;

use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Push\WebPush;
use Illuminate\Contracts\Session\Session;
use RuntimeException;

/**
 * Fingerprint / face / device-PIN sign-in and identity checks with WebAuthn (erp/docs/WEBAUTHN.md),
 * verified with PHP's openssl: ES256 (P-256) and RS256 keys, "none" attestation, user verification
 * required. Challenges live in the session, one per purpose, for five minutes, and are used once.
 */
final class WebAuthn
{
    public const PURPOSES = ['register', 'login', 'confirm'];

    private const TIMEOUT = 300;

    public function __construct(private readonly Session $session) {}

    public function rpId(): string
    {
        return (string) (config('services.webauthn.rp_id') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }

    /** @return list<string> origins a response may come from (scheme://host[:port]) */
    public function origins(): array
    {
        $configured = array_filter(array_map('trim', explode(',', (string) config('services.webauthn.origins'))));
        $app = parse_url((string) config('app.url'));
        $own = isset($app['scheme'], $app['host']) ? $app['scheme'].'://'.$app['host'].(isset($app['port']) ? ':'.$app['port'] : '') : null;

        return array_values(array_unique(array_filter([...$configured, $own])));
    }

    public function challenge(string $purpose, ?int $userId = null): string
    {
        $challenge = random_bytes(32);
        $this->session->put("webauthn.{$purpose}", ['c' => WebPush::b64e($challenge), 'u' => $userId, 't' => time()]);

        return WebPush::b64e($challenge);
    }

    /** @return array<string, mixed> PublicKeyCredentialCreationOptions (binary values as base64url) */
    public function registrationOptions(User $user): array
    {
        return [
            'challenge' => $this->challenge('register', $user->id),
            'rp' => ['id' => $this->rpId(), 'name' => (string) config('app.name')],
            'user' => ['id' => WebPush::b64e(self::userHandle($user)), 'name' => $user->email, 'displayName' => $user->name],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['userVerification' => 'required', 'residentKey' => 'preferred', 'requireResidentKey' => false],
            'excludeCredentials' => $user->webauthnCredentials()->pluck('credential_id')->map(fn ($id) => ['type' => 'public-key', 'id' => $id])->values()->all(),
            'attestation' => 'none',
            'timeout' => self::TIMEOUT * 1000,
        ];
    }

    /** @return array<string, mixed> PublicKeyCredentialRequestOptions; with $user, only that person's keys */
    public function requestOptions(string $purpose, ?User $user = null): array
    {
        return [
            'challenge' => $this->challenge($purpose, $user?->id),
            'rpId' => $this->rpId(),
            'userVerification' => 'required',
            'allowCredentials' => $user ? $user->webauthnCredentials()->pluck('credential_id')->map(fn ($id) => ['type' => 'public-key', 'id' => $id])->values()->all() : [],
            'timeout' => self::TIMEOUT * 1000,
        ];
    }

    /**
     * Checks a new credential (navigator.credentials.create) and stores it for $user.
     *
     * @param  array{id: string, response: array{clientDataJSON: string, attestationObject: string, transports?: list<string>}}  $credential
     */
    public function register(User $user, array $credential, string $name): WebauthnCredential
    {
        $clientData = $this->clientData($credential['response']['clientDataJSON'], 'webauthn.create', 'register', $user->id);
        unset($clientData);
        $attestation = Cbor::decode(WebPush::b64d($credential['response']['attestationObject']));
        if (! is_array($attestation) || ! isset($attestation['authData']) || ! is_string($attestation['authData'])) {
            throw new RuntimeException('bad_attestation');
        }
        $auth = $this->authData($attestation['authData'], true);
        if (WebPush::b64e($auth['credential_id']) !== $credential['id']) {
            throw new RuntimeException('bad_attestation');
        }
        [$pem, $alg] = self::coseToPem($auth['cose']);
        if (WebauthnCredential::query()->where('credential_id', $credential['id'])->exists()) {
            throw new RuntimeException('already_registered');
        }

        return $user->webauthnCredentials()->create([
            'credential_id' => $credential['id'], 'public_key' => $pem, 'algorithm' => $alg, 'sign_count' => $auth['sign_count'],
            'name' => $name, 'transports' => array_values(array_filter((array) ($credential['response']['transports'] ?? []), 'is_string')),
        ]);
    }

    /**
     * Checks an assertion (navigator.credentials.get) for the purpose's challenge and returns the
     * credential used; for "confirm", the credential must belong to $user.
     *
     * @param  array{id: string, response: array{clientDataJSON: string, authenticatorData: string, signature: string}}  $assertion
     */
    public function verify(string $purpose, array $assertion, ?User $user = null): WebauthnCredential
    {
        $stored = WebauthnCredential::query()->where('credential_id', (string) ($assertion['id'] ?? ''))->first();
        if ($stored === null || ($user !== null && $stored->user_id !== $user->id)) {
            throw new RuntimeException('unknown_credential');
        }
        $clientJson = WebPush::b64d($assertion['response']['clientDataJSON']);
        $this->clientData($assertion['response']['clientDataJSON'], 'webauthn.get', $purpose, $user?->id);
        $authData = WebPush::b64d($assertion['response']['authenticatorData']);
        $auth = $this->authData($authData, false);
        $ok = openssl_verify($authData.hash('sha256', $clientJson, true), WebPush::b64d($assertion['response']['signature']), $stored->public_key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('bad_signature');
        }
        // A counter that does not go up means a copied authenticator (counters of 0 are not kept).
        if ($auth['sign_count'] > 0 || $stored->sign_count > 0) {
            if ($auth['sign_count'] <= $stored->sign_count) {
                throw new RuntimeException('counter');
            }
        }
        $stored->forceFill(['sign_count' => $auth['sign_count'], 'last_used_at' => now()])->save();

        return $stored;
    }

    /** A stable, opaque handle for the person (not their id or e-mail). */
    public static function userHandle(User $user): string
    {
        return substr(hash_hmac('sha256', 'webauthn-user:'.$user->id, (string) config('app.key'), true), 0, 32);
    }

    /** @return array<string, mixed> */
    private function clientData(string $b64, string $type, string $purpose, ?int $userId): array
    {
        $saved = $this->session->pull("webauthn.{$purpose}");
        $data = json_decode(WebPush::b64d($b64), true);
        if (! is_array($saved) || time() - (int) $saved['t'] > self::TIMEOUT || ($saved['u'] ?? null) !== $userId && $purpose !== 'login') {
            throw new RuntimeException('expired');
        }
        if (! is_array($data) || ($data['type'] ?? null) !== $type || ! hash_equals((string) $saved['c'], (string) ($data['challenge'] ?? ''))) {
            throw new RuntimeException('bad_client_data');
        }
        if (! in_array($data['origin'] ?? '', $this->origins(), true)) {
            throw new RuntimeException('bad_origin');
        }

        return $data;
    }

    /** @return array{sign_count: int, credential_id?: string, cose?: array<int, mixed>} */
    private function authData(string $data, bool $withCredential): array
    {
        if (strlen($data) < 37 || ! hash_equals(hash('sha256', $this->rpId(), true), substr($data, 0, 32))) {
            throw new RuntimeException('bad_rp');
        }
        $flags = ord($data[32]);
        // User present (0x01) and user verified (0x04): the person used their fingerprint / face / PIN.
        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
            throw new RuntimeException('not_verified');
        }
        $out = ['sign_count' => unpack('N', substr($data, 33, 4))[1]];
        if ($withCredential) {
            if (($flags & 0x40) === 0 || strlen($data) < 55) {
                throw new RuntimeException('bad_attestation');
            }
            $idLength = unpack('n', substr($data, 53, 2))[1];
            $out['credential_id'] = substr($data, 55, $idLength);
            $offset = 55 + $idLength;
            $cose = Cbor::decode($data, $offset);
            if (! is_array($cose)) {
                throw new RuntimeException('bad_attestation');
            }
            $out['cose'] = $cose;
        }

        return $out;
    }

    /** @return array{0: string, 1: int} PEM public key and COSE algorithm */
    public static function coseToPem(array $cose): array
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === -7 && ($cose[-1] ?? null) === 1 && strlen((string) ($cose[-2] ?? '')) === 32 && strlen((string) ($cose[-3] ?? '')) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$cose[-2].$cose[-3];
        } elseif ($kty === 3 && $alg === -257 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null)) {
            $der = self::rsaSpki($cose[-1], $cose[-2]);
        } else {
            throw new RuntimeException('unsupported_key');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64)."-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            throw new RuntimeException('unsupported_key');
        }

        return [$pem, $alg];
    }

    private static function rsaSpki(string $n, string $e): string
    {
        $int = fn (string $v) => "\x02".self::derLength(strlen($v = (ord($v[0]) > 127 ? "\0" : '').$v)).$v;
        $rsaKey = $int($n).$int($e);
        $rsaKey = "\x30".self::derLength(strlen($rsaKey)).$rsaKey;
        $bitString = "\x03".self::derLength(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $algo = hex2bin('300d06092a864886f70d0101010500');
        $body = $algo.$bitString;

        return "\x30".self::derLength(strlen($body)).$body;
    }

    private static function derLength(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
