<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ResidencyImportBatch;
use App\Services\Residency\ResidencyImport;
use App\Support\Access\AccessContext;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResidencyImportController extends Controller
{
    public function __construct(private readonly ResidencyImport $import) {}

    public function template(): StreamedResponse
    {
        return Csv::download('residency-import-template.csv', ResidencyImport::COLUMNS, [
            ['Staff Quarters A', 'Room 101', 'staff', 'Rahul Pawar', 'rahul@example.com', '', '2026-01-15'],
            ['Staff Quarters A', 'Room 101', 'family', 'Seema Pawar', '', 'Wife', '2026-01-15'],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $checked = $this->import->check($this->rows($request), AccessContext::current()->organizationId());

        return response()->json(['data' => ['rows' => $checked, 'total' => count($checked), 'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count()]]);
    }

    public function run(Request $request): JsonResponse
    {
        $checked = $this->import->check($this->rows($request), AccessContext::current()->organizationId());
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }
        $batch = $this->import->import($checked, AccessContext::current()->organizationId(), $request->file('file')->getClientOriginalName(), $request->user()->id);

        return response()->json(['data' => ['imported' => $batch->occupants_created, 'batch_id' => $batch->id]], 201);
    }

    public function history(): JsonResponse
    {
        return response()->json(['data' => ResidencyImportBatch::query()->with('importer:id,name')->orderByDesc('id')->limit(50)->get()
            ->map(fn (ResidencyImportBatch $b) => [
                'id' => $b->id, 'filename' => $b->filename, 'buildings_created' => $b->buildings_created, 'rooms_created' => $b->rooms_created,
                'occupants_created' => $b->occupants_created, 'status' => $b->status, 'imported_by' => $b->importer?->name,
                'created_at' => $b->created_at?->toIso8601String(), 'rolled_back_at' => $b->rolled_back_at?->toIso8601String(),
            ])->values()]);
    }

    public function rollback(ResidencyImportBatch $batch): JsonResponse
    {
        $this->import->rollback($batch);

        return $this->history();
    }

    /** @return list<array<string, string>> */
    private function rows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), ResidencyImport::REQUIRED, 2000);
    }
}
