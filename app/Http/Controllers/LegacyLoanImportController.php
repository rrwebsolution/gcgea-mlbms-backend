<?php

namespace App\Http\Controllers;

use App\Services\LegacyLoanWorkbookImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LegacyLoanImportController extends Controller
{
    public function __construct(private readonly LegacyLoanWorkbookImportService $service) {}

    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:20480'],
            'balancePeriod' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        $token = (string) Str::uuid();
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        $path = "legacy-loan-imports/{$token}/original.{$extension}";
        Storage::disk('local')->putFileAs("legacy-loan-imports/{$token}", $request->file('file'), "original.{$extension}");
        try {
            $result = $this->service->preview(Storage::disk('local')->path($path), $data['balancePeriod']);
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory("legacy-loan-imports/{$token}");
            report($exception);

            return response()->json([
                'message' => 'The CSV/workbook could not be read: '.$exception->getMessage(),
            ], 422);
        }
        Storage::disk('local')->put("legacy-loan-imports/{$token}/meta.json", json_encode([
            'path' => $path,
            'period' => $data['balancePeriod'],
            'userId' => $request->user()->id,
            'originalFilename' => $request->file('file')->getClientOriginalName(),
        ]));

        return response()->json(['token' => $token, ...$result]);
    }

    public function commit(Request $request, string $token)
    {
        abort_unless(Str::isUuid($token) && Storage::disk('local')->exists("legacy-loan-imports/{$token}/meta.json"), 404);
        $data = $request->validate([
            'excludedRows' => ['sometimes', 'array'],
            'excludedRows.*' => ['string'],
            'resolvedMatches' => ['sometimes', 'array'],
            'resolvedMatches.*' => ['integer', 'exists:members,id'],
        ]);
        $meta = json_decode(Storage::disk('local')->get("legacy-loan-imports/{$token}/meta.json"), true);
        abort_unless((int) $meta['userId'] === (int) $request->user()->id, 403);
        $result = $this->service->commit(
            Storage::disk('local')->path($meta['path']),
            $meta['period'],
            $data['excludedRows'] ?? [],
            $data['resolvedMatches'] ?? [],
            $request->user(),
            $meta['originalFilename'] ?? null,
        );
        Storage::disk('local')->deleteDirectory("legacy-loan-imports/{$token}");

        return response()->json($result);
    }
}
