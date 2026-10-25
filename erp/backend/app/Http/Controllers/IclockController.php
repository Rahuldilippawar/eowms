<?php

namespace App\Http\Controllers;

use App\Models\BiometricDevice;
use App\Services\AttendanceRules;
use App\Services\BiometricService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * ZKTeco "ADMS" push protocol (/iclock/*). Machines have no credentials, so
 * only serial numbers registered and active in the ERP are accepted; anything
 * else gets the same plain "OK" and is ignored (nothing to probe).
 */
class IclockController extends Controller
{
    public function cdata(Request $request, BiometricService $service): Response
    {
        $device = $this->device($request);
        if ($device === null) {
            return $this->text('OK');
        }
        if ($request->isMethod('get')) {
            // Handshake: tell the machine to push attendance logs in real time.
            $offset = AttendanceRules::for($device->organization)->now()->utcOffset() / 60;

            return $this->text(implode("\n", [
                "GET OPTION FROM: {$device->serial_number}",
                'ATTLOGStamp=None', 'OPERLOGStamp=9999', 'ATTPHOTOStamp=None', 'ErrorDelay=30', 'Delay=10',
                'TransTimes=00:00;14:05', 'TransInterval=1', 'TransFlag=TransData AttLog', "TimeZone={$offset}", 'Realtime=1', 'Encrypt=None',
            ]));
        }
        if (strtoupper((string) $request->query('table')) !== 'ATTLOG') {
            return $this->text('OK');
        }

        return $this->text('OK: '.$service->ingest($device, (string) $request->getContent()));
    }

    /** No commands are queued for machines. */
    public function getrequest(Request $request): Response
    {
        $this->device($request);

        return $this->text('OK');
    }

    public function devicecmd(): Response
    {
        return $this->text('OK');
    }

    private function device(Request $request): ?BiometricDevice
    {
        $serial = (string) $request->query('SN', '');
        $device = preg_match('/^[A-Za-z0-9_-]{4,50}$/', $serial) === 1
            ? BiometricDevice::withoutGlobalScopes()->where(['serial_number' => $serial, 'is_active' => true])->first()
            : null;
        if ($device === null) {
            Log::info('iclock: unregistered device', ['sn' => mb_substr($serial, 0, 50), 'ip' => $request->ip()]);

            return null;
        }
        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();

        return $device;
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
