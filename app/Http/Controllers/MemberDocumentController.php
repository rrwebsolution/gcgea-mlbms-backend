<?php

namespace App\Http\Controllers;

use App\Http\Resources\MemberDocumentResource;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MemberDocumentController extends Controller
{
    public function storePhoto(Request $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);

        if ($member->profile_photo_url) {
            Storage::disk('public')->delete($this->pathFromUrl($member->profile_photo_url));
        }

        $path = $request->file('photo')->store("members/{$member->id}/photo", 'public');
        $member->update(['profile_photo_url' => Storage::disk('public')->url($path)]);

        return new MemberResource($member->load(['office', 'beneficiaries', 'documents']));
    }

    public function destroyPhoto(Request $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        if ($member->profile_photo_url) {
            Storage::disk('public')->delete($this->pathFromUrl($member->profile_photo_url));
            $member->update(['profile_photo_url' => null]);
        }

        return new MemberResource($member->load(['office', 'beneficiaries', 'documents']));
    }

    public function storeDocument(Request $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'category' => ['required', 'string', Rule::in([
                'Valid ID', 'Appointment Document', 'Payslip', 'Membership Form', 'Other Supporting Document',
            ])],
        ]);

        $file = $request->file('file');
        $path = $file->store("members/{$member->id}/documents", 'public');

        $document = $member->documents()->create([
            'category' => $request->string('category')->toString(),
            'file_name' => $file->getClientOriginalName(),
            'file_url' => Storage::disk('public')->url($path),
            'file_size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->full_name,
            'uploaded_at' => now(),
        ]);

        return new MemberDocumentResource($document);
    }

    public function destroyDocument(Request $request, Member $member, string $document)
    {
        if (! $request->user()->hasPermission('members.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $doc = $member->documents()->findOrFail($document);
        if ($doc->category === 'Payslip'
            && $member->net_pay !== null
            && (float) $member->net_pay > 0
            && $member->documents()->where('category', 'Payslip')->count() <= 1) {
            return response()->json([
                'message' => 'Payslip cannot be removed while Monthly Net Pay is recorded.',
            ], 422);
        }
        Storage::disk('public')->delete($this->pathFromUrl($doc->file_url));
        $doc->delete();

        return response()->json(['message' => 'Document removed.']);
    }

    /**
     * Streams a member document through the authenticated API. This avoids
     * exposing deployment-specific /storage URLs and works behind the
     * frontend's Vercel /api proxy even when Railway has no public symlink.
     */
    public function showDocument(Request $request, Member $member, string $document)
    {
        abort_unless(
            $request->user()->hasPermission('members.view')
                || $request->user()->hasPermission('members.update')
                || $request->user()->hasPermission('loans.view')
                || $request->user()->hasPermission('loans.create'),
            403
        );

        $doc = $member->documents()->findOrFail($document);
        $path = $this->pathFromUrl($doc->file_url);
        abort_unless(Storage::disk('public')->exists($path), 404, 'The uploaded document file could not be found.');

        return Storage::disk('public')->response($path, $doc->file_name, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function pathFromUrl(string $url): string
    {
        $storageUrl = Storage::disk('public')->url('');

        return ltrim(str_replace($storageUrl, '', $url), '/');
    }
}
