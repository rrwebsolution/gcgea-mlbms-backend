<?php

namespace App\Http\Controllers;

use App\Http\Resources\LoanDocumentResource;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LoanDocumentController extends Controller
{
    public function store(Request $request, Loan $loan)
    {
        abort_unless($request->user()->hasPermission('loans.create') || $request->user()->hasPermission('loans.update'), 403);

        $data = $request->validate([
            'requirementLabel' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store("loans/{$loan->id}/documents", 'public');
        $document = $loan->documents()->create([
            'requirement_label' => $data['requirementLabel'],
            'file_name' => $file->getClientOriginalName(),
            'file_url' => Storage::disk('public')->url($path),
            'file_size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->full_name,
            'uploaded_at' => now(),
        ]);

        return new LoanDocumentResource($document);
    }
}
