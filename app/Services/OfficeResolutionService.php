<?php

namespace App\Services;

use App\Models\MemberImportRow;
use App\Models\Office;
use App\Models\OfficeAlias;
use App\Models\User;

/**
 * Resolves a raw "Name of Present Office" spreadsheet value (e.g. "CEED",
 * "City Environment and Natural Resources Management Office") to a real
 * Office record, learning aliases so the same spelling resolves
 * automatically on future imports without asking again.
 */
class OfficeResolutionService
{
    public function resolve(string $rawText): ?Office
    {
        $normalized = $this->normalize($rawText);
        if ($normalized === '') {
            return null;
        }

        $alias = OfficeAlias::where('alias_text', $normalized)->first();
        if ($alias) {
            return $alias->office;
        }

        $offices = Office::all(['id', 'name', 'code']);

        foreach ($offices as $office) {
            if ($this->normalize($office->name) === $normalized || $this->normalize($office->code) === $normalized) {
                return $office;
            }
        }

        foreach ($offices as $office) {
            $normalizedName = $this->normalize($office->name);
            if ($normalizedName !== '' && (str_contains($normalizedName, $normalized) || str_contains($normalized, $normalizedName))) {
                return $office;
            }
        }

        return null;
    }

    public function saveAlias(string $rawText, int $officeId, ?User $user = null): OfficeAlias
    {
        $normalized = $this->normalize($rawText);

        $alias = OfficeAlias::where('alias_text', $normalized)->first();
        if ($alias) {
            $alias->update(['office_id' => $officeId, 'usage_count' => $alias->usage_count + 1]);

            return $alias;
        }

        return OfficeAlias::create([
            'alias_text' => $normalized,
            'office_id' => $officeId,
            'usage_count' => 1,
            'created_by_user_id' => $user?->id,
        ]);
    }

    /**
     * One entry per distinct unresolved raw value, with every affected row
     * number — Step 6/7 resolves a group in one decision, not per row.
     *
     * @return array<int, array{rawText: string, normalizedText: string, rowCount: int, rowNumbers: array<int,int>}>
     */
    public function groupUnresolved(int $batchId): array
    {
        $rows = MemberImportRow::where('batch_id', $batchId)
            ->whereNotNull('unresolved_office_text')
            ->get(['row_number', 'unresolved_office_text']);

        $groups = [];
        foreach ($rows as $row) {
            $normalized = $this->normalize($row->unresolved_office_text);
            if (! isset($groups[$normalized])) {
                $groups[$normalized] = [
                    'rawText' => $row->unresolved_office_text,
                    'normalizedText' => $normalized,
                    'rowNumbers' => [],
                ];
            }
            $groups[$normalized]['rowNumbers'][] = $row->row_number;
        }

        return array_values(array_map(fn ($g) => [...$g, 'rowCount' => count($g['rowNumbers'])], $groups));
    }

    /**
     * Re-resolves every row in the batch whose unresolved_office_text
     * normalizes to the same value, in one query.
     */
    public function applyResolutionToBatch(int $batchId, string $rawText, int $officeId): int
    {
        $normalized = $this->normalize($rawText);

        return MemberImportRow::where('batch_id', $batchId)
            ->whereNotNull('unresolved_office_text')
            ->get(['id', 'unresolved_office_text'])
            ->filter(fn ($row) => $this->normalize($row->unresolved_office_text) === $normalized)
            ->each(fn ($row) => $row->update(['resolved_office_id' => $officeId, 'unresolved_office_text' => null]))
            ->count();
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower($value)) ?? '');
    }
}
