<?php

namespace App\Services;

/**
 * Detects which spreadsheet column corresponds to each target field the
 * member importer understands, tolerant of the workbook's 22-column and
 * 26-column variants, extra/unused columns, and header wording that isn't
 * byte-for-byte identical across sheets. Same longest-alias-wins matching
 * algorithm as PayrollColumnMapper — duplicated rather than shared since the
 * two field sets are unrelated and sharing an abstract base for two call
 * sites isn't worth the indirection.
 */
class MemberColumnMapper
{
    /**
     * AGE, "No. of Years in the Government Service", and "Length of
     * Membership" are display-only comparison fields — the system always
     * recomputes these from birthdate/appointment date/membership date and
     * never stores the sheet's value as authoritative.
     */
    public const TARGET_FIELDS = [
        'source_submitted_at' => 'Timestamp',
        'email' => 'Email Address',
        'membership_type_raw' => 'Check One! (New/Old Member)',
        'last_name' => 'Surname',
        'first_name' => 'First Name',
        'middle_name' => 'Middle Name',
        'office_name_raw' => 'Name of Present Office',
        'position' => 'Occupation/Position',
        'permanent_address' => 'Permanent Address',
        'birthdate' => 'Birthdate',
        'age_display' => 'AGE (display only — recomputed from Birthdate)',
        'date_of_regular_appointment' => 'Date of Regular Appointment',
        'years_in_service_display' => 'No. of Years in the Government Service (display only — recomputed)',
        'beneficiary_1' => '1. Name of Dependent/Beneficiary',
        'beneficiary_2' => '2. Name of Dependent/Beneficiary',
        'cellphone_number' => 'Cellphone Number',
        'sex' => 'Sex',
        'name_of_spouse' => '(If Married) Name of Spouse',
        'membership_date' => 'Date as GCGEA Member',
        'length_of_membership_display' => 'Length of Membership (display only — recomputed)',
        'retiree_status_raw' => 'Retirees',
        'cash_pabaon' => 'CASH PABAON',
        'loan_start' => 'Loan Start',
        'solidarity_assistance_loan' => 'Solidarity Assistance Loan',
        'no_of_months' => 'No. of Months',
        'monthly_amort' => 'Monthly Amort',
        'legacy_notes' => '2015 Resolution',
    ];

    public const REQUIRED_FIELDS = ['last_name', 'first_name', 'birthdate'];

    /** These 3 columns hold Excel dates and need per-cell number-format capture. */
    public const DATE_FIELDS = ['birthdate', 'date_of_regular_appointment', 'membership_date'];

    private const ALIASES = [
        'source_submitted_at' => ['timestamp'],
        'email' => ['emailaddress', 'email'],
        'membership_type_raw' => ['checkone'],
        'last_name' => ['surname'],
        'first_name' => ['firstname'],
        'middle_name' => ['middlename'],
        'office_name_raw' => ['nameofpresentoffice', 'presentoffice'],
        'position' => ['occupationposition', 'occupation', 'position'],
        'permanent_address' => ['permanentaddress'],
        'age_display' => ['age'],
        'date_of_regular_appointment' => ['dateofregularappointment'],
        'years_in_service_display' => ['noofyearsinthegovernmentservice', 'yearsingovernmentservice'],
        'beneficiary_1' => ['1nameofdependentsandbeneficiaryies', '1nameofdependentandbeneficiary'],
        'beneficiary_2' => ['2nameofdependentsandbeneficiaryies', '2nameofdependentandbeneficiary'],
        'cellphone_number' => ['cellphonenumber', 'cellphone', 'mobilenumber'],
        'name_of_spouse' => ['ifmarriednameofspouse', 'nameofspouse'],
        'membership_date' => ['dateasgcgeamember'],
        'length_of_membership_display' => ['lengthofmembership'],
        'retiree_status_raw' => ['retirees', 'retiree'],
        'cash_pabaon' => ['cashpabaon'],
        'loan_start' => ['loanstart'],
        'solidarity_assistance_loan' => ['solidarityassistanceloan'],
        'no_of_months' => ['noofmonths'],
        'monthly_amort' => ['monthlyamort', 'monthlyamortization'],
        'legacy_notes' => ['2015resolution'],
        // Deliberately generic/short — checked against this exact 26-header
        // set for false-positive substring collisions before being added.
        'birthdate' => ['birthdate'],
        'sex' => ['sex'],
    ];

    /**
     * @param  array<int, string>  $headers
     * @return array{mapping: array<string, string|null>, unmatched: array<int, string>}
     */
    public function detect(array $headers): array
    {
        $pool = [];
        foreach ($headers as $header) {
            $pool[$header] = $this->normalize($header);
        }

        $flatAliases = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $flatAliases[] = [$field, $alias];
            }
        }
        usort($flatAliases, fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));

        $mapping = array_fill_keys(array_keys(self::TARGET_FIELDS), null);

        foreach ($flatAliases as [$field, $alias]) {
            if ($mapping[$field] !== null) {
                continue;
            }
            foreach ($pool as $header => $normalized) {
                if ($normalized !== '' && str_contains($normalized, $alias)) {
                    $mapping[$field] = $header;
                    unset($pool[$header]);
                    break;
                }
            }
        }

        return [
            'mapping' => $mapping,
            'unmatched' => array_keys($pool),
        ];
    }

    public static function looksLikeKnownHeader(string $value): bool
    {
        $normalized = (new self)->normalize($value);
        if ($normalized === '') {
            return false;
        }

        foreach (self::ALIASES as $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($normalized, $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }
}
