<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Triage and nursing notes are written and finalised by nurses; SOAP notes
 * by doctors, who also hold the signing permission.
 */
enum ClinicalNoteType: string
{
    case Triage = 'triage';
    case Nursing = 'nursing';
    case Soap = 'soap';

    public function label(): string
    {
        return match ($this) {
            self::Triage => 'Triage assessment',
            self::Nursing => 'Nursing note',
            self::Soap => 'SOAP note',
        };
    }

    public function writePermission(): Permission
    {
        return match ($this) {
            self::Triage, self::Nursing => Permission::WriteNursingNotes,
            self::Soap => Permission::WriteSoapNotes,
        };
    }

    public function signPermission(): Permission
    {
        return match ($this) {
            self::Triage, self::Nursing => Permission::WriteNursingNotes,
            self::Soap => Permission::SignClinicalNotes,
        };
    }

    /**
     * Fields that must be filled before the note can be signed.
     *
     * @return list<string>
     */
    public function requiredForSigning(): array
    {
        return match ($this) {
            self::Triage => ['chief_complaint'],
            self::Nursing => ['additional_notes'],
            self::Soap => ['chief_complaint', 'assessment', 'plan'],
        };
    }
}
