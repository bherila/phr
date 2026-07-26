<?php

namespace App\GenAiProcessor\Services\Prompts\Phr;

use App\GenAiProcessor\Services\Prompts\PromptTemplate;

class PhrPromptTemplate extends PromptTemplate
{
    public function __construct(private string $jobType) {}

    public function build(array $context): string
    {
        if ($this->jobType === 'phr_document') {
            return $this->buildDocumentBundlePrompt();
        }

        $schema = $this->schemaLines();
        $kind = $this->label();

        return <<<PROMPT
Analyze the provided patient health document and extract {$kind} records.
Return ONLY valid JSON or TOON. Do not include Markdown fences or explanatory text.

Use this exact top-level shape:
records: [
  {
{$schema}
  }
]

Rules:
- Preserve the source's raw text fields when available.
- Dates must use YYYY-MM-DD. Date-times must use YYYY-MM-DD HH:MM:SS when time is known.
- Numeric fields must be numbers only when the source value parses cleanly. Keep the raw printed value in the raw value field.
- Omit unknown fields or set them to null.
PROMPT;
    }

    private function buildDocumentBundlePrompt(): string
    {
        return <<<'PROMPT'
Analyze the provided patient health document and extract a complete PHR import bundle.
Return ONLY valid JSON. Do not include Markdown fences or explanatory text.

Use this exact top-level shape:
{
  "schema_version": "phr_pdf_bundle.v1",
  "source_document": {
    "record_key": "stable key for this source document",
    "title": "document title",
    "document_type": "lab_report|office_visit_note|discharge_summary|imaging_report|prescription|insurance|consent|other",
    "observed_at": "YYYY-MM-DD HH:MM:SS",
    "summary": "concise clinical summary",
    "extracted_text": "important extracted source text",
    "tags": ["tag"]
  },
  "patient_context": {
    "display_name": "patient name if present",
    "birth_date": "YYYY-MM-DD",
    "sex_at_birth": "M/F/etc"
  },
  "records": {
    "conditions": [
      {
        "record_key": "stable unique key",
        "name": "required condition/problem",
        "icd10_code": "ICD-10",
        "snomed_code": "SNOMED",
        "onset_date": "YYYY-MM-DD",
        "abated_date": "YYYY-MM-DD",
        "clinical_status": "active/resolved",
        "verification_status": "confirmed/unconfirmed",
        "severity": "severity",
        "notes": "notes",
        "source_refs": ["page/section"],
        "raw_text": "source text"
      }
    ],
    "allergies": [
      {
        "record_key": "stable unique key",
        "substance": "required allergen/substance",
        "category": "food/medication/environment/etc",
        "criticality": "low/high/unable-to-assess",
        "clinical_status": "active/resolved",
        "verification_status": "confirmed/unconfirmed",
        "reaction": "reaction",
        "severity": "severity",
        "notes": "notes",
        "source_refs": ["page/section"],
        "raw_text": "source text"
      }
    ],
    "immunizations": [
      {
        "record_key": "stable unique key",
        "vaccine_name": "required vaccine name",
        "cvx_code": "CVX code",
        "manufacturer": "manufacturer",
        "lot_number": "lot",
        "administered_on": "YYYY-MM-DD",
        "dose_number": 1,
        "series_doses": 2,
        "site": "site",
        "route": "route",
        "administered_by": "clinician",
        "facility_name": "facility",
        "notes": "notes",
        "source_refs": ["page/section"],
        "raw_text": "source text"
      }
    ],
    "medications": [
      {
        "record_key": "stable unique key",
        "name": "required medication name",
        "rxnorm_code": "RxNorm code",
        "dose": "dose",
        "dose_unit": "unit",
        "route": "route",
        "frequency": "frequency",
        "started_on": "YYYY-MM-DD",
        "ended_on": "YYYY-MM-DD",
        "status": "active/discontinued",
        "prescriber_name": "prescriber",
        "reason_for_use": "reason",
        "source_refs": ["page/section"],
        "raw_text": "source text"
      }
    ],
    "vitals": [
      {
        "record_key": "stable unique key",
        "vital_name": "required vital name",
        "vital_value": "raw value as printed",
        "value_numeric": 0.0,
        "value_numeric_secondary": 0.0,
        "unit": "unit",
        "secondary_unit": "secondary unit",
        "vital_date": "YYYY-MM-DD",
        "observed_at": "YYYY-MM-DD HH:MM:SS",
        "body_site": "site",
        "source_refs": ["page/section"],
        "notes": "notes"
      }
    ],
    "lab_results": [
      {
        "record_key": "stable unique key",
        "test_name": "panel name",
        "analyte": "required analyte name",
        "value": "raw value as printed",
        "value_numeric": 0.0,
        "unit": "unit",
        "range_min": 0.0,
        "range_max": 0.0,
        "reference_range_text": "raw reference range",
        "abnormal_flag": "H/L/A/etc",
        "collection_datetime": "YYYY-MM-DD HH:MM:SS",
        "result_datetime": "YYYY-MM-DD HH:MM:SS",
        "ordering_provider": "provider",
        "resulting_lab": "lab",
        "source_refs": ["page/section"],
        "notes": "notes"
      }
    ],
    "procedures": [
      {
        "record_key": "stable unique key",
        "name": "required procedure",
        "cpt_code": "CPT",
        "snomed_code": "SNOMED",
        "performed_at": "YYYY-MM-DD HH:MM:SS",
        "performed_on": "YYYY-MM-DD",
        "performer_name": "performer",
        "facility_name": "facility",
        "status": "completed",
        "reason": "reason",
        "outcome": "outcome",
        "source_refs": ["page/section"],
        "notes": "notes",
        "raw_text": "source text"
      }
    ],
    "encounters": [
      {
        "record_key": "stable unique key",
        "visit_date": "YYYY-MM-DD",
        "visit_started_at": "YYYY-MM-DD HH:MM:SS",
        "visit_type": "type",
        "provider_name": "provider",
        "provider_specialty": "specialty",
        "facility_name": "facility",
        "chief_complaint": "reason",
        "assessment": "assessment",
        "plan": "plan",
        "subjective": "subjective",
        "objective": "objective",
        "icd10_codes": [{"code": "A00.0", "description": "description"}],
        "cpt_codes": [{"code": "00000", "description": "description"}],
        "source_refs": ["page/section"],
        "raw_text": "important extracted source text"
      }
    ],
    "portal_messages": [
      {
        "record_key": "stable unique key",
        "message_at": "YYYY-MM-DD HH:MM:SS",
        "direction": "inbound/outbound/system",
        "subject": "message subject",
        "sender_name": "sender",
        "recipient_name": "recipient",
        "summary": "message summary",
        "clinical_relevance": "clinical significance",
        "source_refs": ["page/section"],
        "raw_text": "message text"
      }
    ],
    "negative_assertions": [
      {
        "record_key": "stable unique key",
        "assertion_type": "allergy_absence|medication_absence|condition_absence|immunization_exception|other",
        "statement": "explicit negative statement from the source",
        "scope": "what this negates",
        "observed_on": "YYYY-MM-DD",
        "notes": "notes",
        "source_refs": ["page/section"],
        "raw_text": "source text"
      }
    ]
  }
}

Rules:
- Use stable, deterministic `record_key` values for idempotent imports. Prefer source IDs; otherwise combine source document title/date, record type, date, and normalized clinical name.
- Do not duplicate the same clinical fact across repeated sections. Keep the most specific version and preserve supporting raw text.
- Extract only facts explicitly present in the document. Put explicit "none", "no known", "not documented", and provider-not-given statements in `negative_assertions` instead of inventing positive records.
- Use `portal_messages` for patient/provider messages, inbox communications, result comments addressed as messages, and portal notes that are not encounters.
- Use `source_refs` to identify where each record came from, such as page numbers, section headings, or table names.
- Dates must use YYYY-MM-DD. Date-times must use YYYY-MM-DD HH:MM:SS when time is known.
- Numeric fields must be numbers only when the source value parses cleanly. Keep the raw printed value in the raw value field.
- Include empty arrays for record groups with no records.
- Omit unknown scalar fields or set them to null.
PROMPT;
    }

    private function label(): string
    {
        return match ($this->jobType) {
            'phr_lab_result' => 'lab result',
            'phr_vital' => 'vital sign',
            'phr_office_visit' => 'office visit',
            'phr_medication' => 'medication',
            'phr_immunization' => 'immunization',
            'phr_problem_list' => 'condition/problem list',
            'phr_procedure' => 'procedure',
            'phr_allergy' => 'allergy',
            'phr_portal_message' => 'portal message',
            'phr_negative_assertion' => 'negative assertion',
            'phr_document' => 'generic document summary',
            default => 'PHR',
        };
    }

    private function schemaLines(): string
    {
        return match ($this->jobType) {
            'phr_lab_result' => <<<'SCHEMA'
    "test_name": "panel name",
    "analyte": "required analyte name",
    "value": "raw value as printed",
    "value_numeric": 0.0,
    "unit": "unit",
    "range_min": 0.0,
    "range_max": 0.0,
    "reference_range_text": "raw reference range",
    "abnormal_flag": "H/L/A/etc",
    "observed_at": "YYYY-MM-DD HH:MM:SS",
    "result_datetime": "YYYY-MM-DD HH:MM:SS",
    "ordering_provider": "provider",
    "resulting_lab": "lab",
    "notes": "notes"
SCHEMA,
            'phr_vital' => <<<'SCHEMA'
    "vital_name": "required vital name",
    "vital_value": "raw value as printed",
    "value_numeric": 0.0,
    "value_numeric_secondary": 0.0,
    "unit": "unit",
    "secondary_unit": "secondary unit",
    "vital_date": "YYYY-MM-DD",
    "observed_at": "YYYY-MM-DD HH:MM:SS",
    "body_site": "site",
    "notes": "notes"
SCHEMA,
            'phr_office_visit' => <<<'SCHEMA'
    "visit_date": "YYYY-MM-DD",
    "visit_started_at": "YYYY-MM-DD HH:MM:SS",
    "visit_type": "type",
    "provider_name": "provider",
    "provider_specialty": "specialty",
    "facility_name": "facility",
    "chief_complaint": "reason",
    "assessment": "assessment",
    "plan": "plan",
    "subjective": "subjective",
    "objective": "objective",
    "icd10_codes": [{"code": "A00.0", "description": "description"}],
    "raw_text": "important extracted source text"
SCHEMA,
            'phr_medication' => <<<'SCHEMA'
    "name": "required medication name",
    "rxnorm_code": "RxNorm code",
    "dose": "dose",
    "dose_unit": "unit",
    "route": "route",
    "frequency": "frequency",
    "started_on": "YYYY-MM-DD",
    "ended_on": "YYYY-MM-DD",
    "status": "active/discontinued",
    "prescriber_name": "prescriber",
    "reason_for_use": "reason",
    "raw_text": "source text"
SCHEMA,
            'phr_immunization' => <<<'SCHEMA'
    "vaccine_name": "required vaccine name",
    "cvx_code": "CVX code",
    "manufacturer": "manufacturer",
    "lot_number": "lot",
    "administered_on": "YYYY-MM-DD",
    "dose_number": 1,
    "series_doses": 2,
    "site": "site",
    "route": "route",
    "administered_by": "clinician",
    "facility_name": "facility",
    "notes": "notes"
SCHEMA,
            'phr_problem_list' => <<<'SCHEMA'
    "name": "required condition/problem",
    "icd10_code": "ICD-10",
    "snomed_code": "SNOMED",
    "onset_date": "YYYY-MM-DD",
    "abated_date": "YYYY-MM-DD",
    "clinical_status": "active/resolved",
    "verification_status": "confirmed/unconfirmed",
    "severity": "severity",
    "notes": "notes",
    "raw_text": "source text"
SCHEMA,
            'phr_procedure' => <<<'SCHEMA'
    "name": "required procedure",
    "cpt_code": "CPT",
    "snomed_code": "SNOMED",
    "performed_at": "YYYY-MM-DD HH:MM:SS",
    "performed_on": "YYYY-MM-DD",
    "performer_name": "performer",
    "facility_name": "facility",
    "status": "completed",
    "reason": "reason",
    "outcome": "outcome",
    "notes": "notes",
    "raw_text": "source text"
SCHEMA,
            'phr_allergy' => <<<'SCHEMA'
    "substance": "required allergen/substance",
    "rxnorm_code": "RxNorm",
    "snomed_code": "SNOMED",
    "category": "food/medication/environment/etc",
    "criticality": "low/high/unable-to-assess",
    "clinical_status": "active/resolved",
    "verification_status": "confirmed/unconfirmed",
    "reaction": "reaction",
    "severity": "severity",
    "notes": "notes",
    "raw_text": "source text"
SCHEMA,
            'phr_portal_message' => <<<'SCHEMA'
    "record_key": "stable unique key",
    "message_at": "YYYY-MM-DD HH:MM:SS",
    "direction": "inbound/outbound/system",
    "subject": "message subject",
    "sender_name": "sender",
    "recipient_name": "recipient",
    "summary": "message summary",
    "clinical_relevance": "clinical significance",
    "source_refs": ["page/section"],
    "raw_text": "message text"
SCHEMA,
            'phr_negative_assertion' => <<<'SCHEMA'
    "record_key": "stable unique key",
    "assertion_type": "allergy_absence|medication_absence|condition_absence|immunization_exception|other",
    "statement": "required explicit negative statement from the source",
    "scope": "what this negates",
    "observed_on": "YYYY-MM-DD",
    "notes": "notes",
    "source_refs": ["page/section"],
    "raw_text": "source text"
SCHEMA,
            'phr_document' => <<<'SCHEMA'
    "title": "document title",
    "document_type": "lab_report/office_visit_note/discharge_summary/imaging_report/prescription/insurance/consent/other",
    "summary": "concise clinical summary",
    "extracted_text": "important extracted text"
SCHEMA,
            default => '    "notes": "source text"',
        };
    }
}
