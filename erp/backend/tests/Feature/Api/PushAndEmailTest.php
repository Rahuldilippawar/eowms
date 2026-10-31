<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifier;
use App\Services\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PushAndEmailTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    /** @var array{0: \OpenSSLAsymmetricKey, 1: string, 2: string} the "browser": private key, public point, auth secret */
    private array $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create(['name' => 'Shivaji Trust']);
        $keys = WebPush::generateKeys();
        config(['services.vapid.public_key' => $keys['public'], 'services.vapid.private_key' => $keys['private'], 'services.vapid.subject' => 'mailto:it@example.com']);
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $this->browser = [$key, "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT), random_bytes(16)];
    }

    /** What the browser does with a push body (RFC 8291). */
    private function decrypt(string $body): array
    {
        [$key, $uaPublic, $auth] = $this->browser;
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $rest = substr($body, 21 + $idLen);
        $shared = openssl_pkey_derive(WebPush::publicKey($asPublic), $key, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($rest, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rest, -16));
        $this->assertNotFalse($plain, 'The browser could not decrypt the push message.');
        $this->assertSame("\x02", substr($plain, -1));

        return json_decode(substr($plain, 0, -1), true);
    }

    private function subscribe(User $user, string $endpoint = 'https://push.example.net/send/abc'): void
    {
        $this->actingAs($user);
        $this->spa()->postJson('/api/v1/me/push-subscriptions', ['endpoint' => $endpoint,
            'keys' => ['p256dh' => WebPush::b64e($this->browser[1]), 'auth' => WebPush::b64e($this->browser[2])]])->assertOk()->assertJsonCount(1, 'data.devices');
    }

    public function test_new_notifications_reach_devices_encrypted_signed_and_in_the_readers_language(): void
    {
        $user = $this->userWithRole($this->org, ['departments.view']);
        $user->forceFill(['locale' => 'en'])->save();
        $this->subscribe($user);
        $this->spa()->postJson('/api/v1/me/push-subscriptions', ['endpoint' => 'https://push.example.net/x', 'keys' => ['p256dh' => 'AAAA', 'auth' => 'BBBB']])->assertStatus(422);
        $this->spa()->putJson('/api/v1/me/notification-settings', ['push' => ['tasks' => false, 'nonsense' => false]])->assertOk()
            ->assertJsonPath('data.push.tasks', false)->assertJsonPath('data.push.renewals', true)->assertJsonPath('data.email.renewals', true)->assertJsonMissingPath('data.push.nonsense');

        Notifier::user($user->id, $this->org->id, 'renewal.due', ['item' => 'Fire NOC', 'days' => 7, 'expiry_date' => '2026-07-17'], '/renewals/5');
        Notifier::user($user->id, $this->org->id, 'task.overdue', ['code' => 'T-1', 'title' => 'Paint']);
        // First delivered; then the push service no longer knows the device.
        Http::fake(['push.example.net/*' => Http::sequence()->push('', 201)->push('', 410)]);
        $this->artisan('erp:deliver-push')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) {
            $this->assertSame('aes128gcm', $r->header('Content-Encoding')[0]);
            [$token, $k] = sscanf($r->header('Authorization')[0], 'vapid t=%[^,], k=%s');
            $this->assertSame(config('services.vapid.public_key'), $k);
            [$h, $c, $sig] = explode('.', $token);
            $this->assertSame('https://push.example.net', json_decode(WebPush::b64d($c), true)['aud']);
            $raw = WebPush::b64d($sig);
            $der = fn (string $i) => "\x02".chr(strlen($i = (ord($i[0]) > 127 ? "\0" : '').ltrim($i, "\0") ?: "\0")).$i;
            $seq = $der(substr($raw, 0, 32)).$der(substr($raw, 32));
            $this->assertSame(1, openssl_verify("{$h}.{$c}", "\x30".chr(strlen($seq)).$seq, WebPush::publicKey(WebPush::b64d($k)), OPENSSL_ALGO_SHA256));
            $this->assertSame(['title' => 'Shivaji Trust', 'body' => 'Fire NOC expires in 7 days (17 Jul 2026).', 'url' => '/renewals/5'],
                array_intersect_key($this->decrypt($r->body()), array_flip(['title', 'body', 'url'])));

            return true;
        });
        $this->assertSame(0, UserNotification::withoutGlobalScopes()->whereNull('pushed_at')->count());
        $this->artisan('erp:deliver-push')->assertSuccessful();
        Http::assertSentCount(1);

        // A device the push service no longer knows (410) is removed.
        Notifier::user($user->id, $this->org->id, 'renewal.today', ['item' => 'Lift licence'], '/renewals/6');
        $this->artisan('erp:deliver-push')->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_one_summary_email_per_person_with_the_groups_they_chose(): void
    {
        config(['mail.default' => 'array']);
        $asha = $this->userWithRole($this->org, ['departments.view']);
        $asha->forceFill(['locale' => 'mr'])->save();
        $ravi = $this->userWithRole($this->org, ['departments.view']);
        $this->actingAs($ravi);
        $this->spa()->putJson('/api/v1/me/notification-settings', ['email' => ['renewals' => false], 'locale' => 'en'])->assertOk()->assertJsonPath('data.locale', 'en');
        foreach ([$asha, $ravi] as $u) {
            Notifier::user($u->id, $this->org->id, 'renewal.today', ['item' => 'Fire NOC'], '/renewals/5');
            Notifier::user($u->id, $this->org->id, 'task.overdue', ['code' => 'T-1', 'title' => 'Paint']);
        }
        auth()->forgetGuards();
        $this->artisan('erp:email-notifications')->assertSuccessful();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertSame($asha->email, $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('1 नवीन सूचना', $email->getSubject());
        $this->assertStringContainsString('Fire NOC', $email->getTextBody());
        $this->assertStringContainsString('/renewals/5', $email->getTextBody());
        $this->assertStringNotContainsString('Paint', $email->getTextBody());
        $this->assertSame(0, UserNotification::withoutGlobalScopes()->whereNull('emailed_at')->count());
    }
}
