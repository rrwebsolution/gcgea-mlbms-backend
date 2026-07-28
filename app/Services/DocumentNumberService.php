<?php

namespace App\Services;

use App\Models\SystemSetting;
use Carbon\CarbonInterface;

class DocumentNumberService
{
    private const DEFAULTS = [
        'member' => ['prefix' => 'GCGEA-MEM', 'includeYear' => false, 'yearFormat' => 'YYYY', 'separator' => '-', 'sequenceLength' => 6, 'startingNumber' => 1],
        'loan' => ['prefix' => 'GCGEA-LN', 'includeYear' => true, 'yearFormat' => 'YYYY', 'separator' => '-', 'sequenceLength' => 6, 'startingNumber' => 1],
        'loanPayment' => ['prefix' => 'GCGEA-PAY', 'includeYear' => true, 'yearFormat' => 'YYYY', 'separator' => '-', 'sequenceLength' => 6, 'startingNumber' => 1],
        'contribution' => ['prefix' => 'GCGEA-CON', 'includeYear' => true, 'yearFormat' => 'YYYY', 'separator' => '-', 'sequenceLength' => 6, 'startingNumber' => 1],
        'benefit' => ['prefix' => 'GCGEA-BEN', 'includeYear' => true, 'yearFormat' => 'YYYY', 'separator' => '-', 'sequenceLength' => 6, 'startingNumber' => 1],
        'benefitRelease' => ['prefix' => 'GCGEA-BENREL', 'includeYear' => true, 'yearFormat' => 'YYYY', 'separator' => '-', 'sequenceLength' => 6, 'startingNumber' => 1],
    ];

    public function generate(string $type, int $recordSequence, ?CarbonInterface $date = null): string
    {
        $default = self::DEFAULTS[$type] ?? throw new \InvalidArgumentException("Unknown numbering type [{$type}].");
        $saved = SystemSetting::query()->where('section', 'numbering')->first()?->value ?? [];
        $config = array_merge($default, $saved[$type] ?? []);
        $sequence = max(0, (int) $config['startingNumber'] + $recordSequence - 1);
        $separator = (string) $config['separator'];
        $parts = [(string) $config['prefix']];

        if ($config['includeYear']) {
            $year = ($date ?? now())->year;
            $parts[] = $config['yearFormat'] === 'YY'
                ? substr((string) $year, -2)
                : (string) $year;
        }

        $parts[] = str_pad((string) $sequence, (int) $config['sequenceLength'], '0', STR_PAD_LEFT);

        return implode($separator, $parts);
    }
}
