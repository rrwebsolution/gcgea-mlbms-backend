<?php

namespace App\Http\Controllers;

use App\Http\Resources\BenefitDocumentResource;
use App\Models\BenefitApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BenefitDocumentController extends Controller
{
    public function store(Request $request, BenefitApplication $benefit)
    {
        abort_unless($request->user()->hasPermission('benefits.create') || $request->user()->hasPermission('benefits.update'), 403);

        $data = $request->validate([
            'requirementLabel' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $request->file('file');
        $path = $file->store("benefits/{$benefit->id}/documents", 'public');
        $document = $benefit->documents()->create([
            'requirement_label' => $data['requirementLabel'],
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->full_name,
            'uploaded_at' => now(),
        ]);

        return new BenefitDocumentResource($document);
    }

    public function show(Request $request, BenefitApplication $benefit, string $document)
    {
        abort_unless($request->user()->hasPermission('benefits.view'), 403);
        $record = $benefit->documents()->findOrFail($document);
        abort_unless(Storage::disk('public')->exists($record->file_path), 404);

        return Storage::disk('public')->response($record->file_path, $record->file_name);
    }
}
