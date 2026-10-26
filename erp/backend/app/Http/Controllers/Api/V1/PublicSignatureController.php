<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LetterSignature;
use App\Services\Correspondence\LetterService;
use Illuminate\Http\JsonResponse;

/**
 * Public check of a signed file (the QR on the printout): who signed, when,
 * and whether the file is still exactly as it was signed. The subject and
 * text are not shown.
 */
class PublicSignatureController extends Controller
{
    public function show(string $code): JsonResponse
    {
        abort_unless(preg_match('/^[A-Z0-9]{12}$/', strtoupper($code)) === 1, 404);
        $signature = LetterSignature::query()->with(['letter' => fn ($q) => $q->withoutGlobalScopes()->with(['organization', 'attachments'])])
            ->where('verification_code', strtoupper($code))->firstOrFail();
        abort_unless($signature->letter?->organization?->is_active, 404);

        return response()->json(['data' => [
            'signer_name' => $signature->signer_name,
            'signer_designation' => $signature->signer_designation,
            'organization' => $signature->letter->organization->name,
            'file_number' => $signature->letter->file_number,
            'outward_no' => $signature->letter->outward_no,
            'signed_at' => $signature->signed_at->toIso8601String(),
            'method' => $signature->method,
            'intact' => LetterService::intact($signature),
        ]]);
    }
}
