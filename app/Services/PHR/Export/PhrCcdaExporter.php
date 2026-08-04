<?php

namespace App\Services\PHR\Export;

use App\Models\PhrPatient;
use Illuminate\Support\Str;
use XMLWriter;

class PhrCcdaExporter
{
    /**
     * C-CDA 5.0 section templates that describe the existing narrative tables.
     *
     * The eight entries-required clinical sections deliberately retain their human-readable
     * tables without claiming that those rows are structured C-CDA entries. See
     * docs/ccda-conformance.md for the interoperability boundary and validation baseline.
     *
     * @var array<string, array{root: string, extension: string, code: string}>
     */
    private const array SECTION_TEMPLATES = [
        'Results' => ['root' => '2.16.840.1.113883.10.20.22.2.3.1', 'extension' => '2015-08-01', 'code' => '30954-2'],
        'Vital Signs' => ['root' => '2.16.840.1.113883.10.20.22.2.4.1', 'extension' => '2015-08-01', 'code' => '8716-3'],
        'Problems' => ['root' => '2.16.840.1.113883.10.20.22.2.5.1', 'extension' => '2015-08-01', 'code' => '11450-4'],
        'Medications' => ['root' => '2.16.840.1.113883.10.20.22.2.1.1', 'extension' => '2014-06-09', 'code' => '10160-0'],
        'Procedures' => ['root' => '2.16.840.1.113883.10.20.22.2.7.1', 'extension' => '2014-06-09', 'code' => '47519-4'],
        'Immunizations' => ['root' => '2.16.840.1.113883.10.20.22.2.2.1', 'extension' => '2015-08-01', 'code' => '11369-6'],
        'Allergies' => ['root' => '2.16.840.1.113883.10.20.22.2.6.1', 'extension' => '2015-08-01', 'code' => '48765-2'],
        'Encounters' => ['root' => '2.16.840.1.113883.10.20.22.2.22.1', 'extension' => '2015-08-01', 'code' => '46240-8'],
        'Social History' => ['root' => '2.16.840.1.113883.10.20.22.2.17', 'extension' => '2015-08-01', 'code' => '29762-2'],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function documentXml(array $data): string
    {
        /** @var PhrPatient $patient */
        $patient = $data['patient'];

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('ClinicalDocument');
        $xml->writeAttribute('xmlns', 'urn:hl7-org:v3');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->startElement('realmCode');
        $xml->writeAttribute('code', 'US');
        $xml->endElement();
        $xml->startElement('typeId');
        $xml->writeAttribute('root', '2.16.840.1.113883.1.3');
        $xml->writeAttribute('extension', 'POCD_HD000040');
        $xml->endElement();
        // CCD is the closest C-CDA document-level contract for this longitudinal summary.
        // The patient-generated header distinguishes an app-authored PHR document from a
        // provider-authored clinical note; it is an additional header, not a document type.
        $this->templateId($xml, '2.16.840.1.113883.10.20.22.1.1', '2024-05-01');
        $this->templateId($xml, '2.16.840.1.113883.10.20.29.1', '2024-05-01');
        $this->templateId($xml, '2.16.840.1.113883.10.20.22.1.2', '2024-05-01');
        $xml->startElement('id');
        $xml->writeAttribute('root', (string) Str::uuid());
        $xml->endElement();
        $xml->startElement('code');
        $xml->writeAttribute('code', '34133-9');
        $xml->writeAttribute('codeSystem', '2.16.840.1.113883.6.1');
        $xml->writeAttribute('displayName', 'Summarization of Episode Note');
        $xml->endElement();
        $xml->writeElement('title', 'Personal Health Record Summary');
        $effectiveTime = now()->format('YmdHisO');
        $this->time($xml, 'effectiveTime', $effectiveTime);
        $xml->startElement('confidentialityCode');
        $xml->writeAttribute('code', 'N');
        $xml->writeAttribute('codeSystem', '2.16.840.1.113883.5.25');
        $xml->endElement();
        $xml->startElement('languageCode');
        $xml->writeAttribute('code', 'en-US');
        $xml->endElement();
        $this->patient($xml, $patient);
        $this->author($xml, $effectiveTime);
        $this->custodian($xml);
        $this->documentationOf($xml);
        $xml->startElement('component');
        $xml->startElement('structuredBody');

        $this->section($xml, 'Results', ['Date', 'Test', 'Value', 'Unit', 'Reference', 'Flag'], $data['lab_results']->map(fn ($lab): array => [
            $lab->result_datetime?->toDateString() ?? $lab->collection_datetime?->toDateString(),
            $lab->analyte ?? $lab->test_name,
            $lab->value ?? $lab->value_numeric,
            $lab->unit,
            $lab->reference_range_text ?? trim(($lab->range_min ?? '').' - '.($lab->range_max ?? '')),
            $lab->abnormal_flag,
        ])->all());
        $this->section($xml, 'Vital Signs', ['Date', 'Name', 'Value', 'Unit'], $data['vitals']->map(fn ($vital): array => [
            $vital->observed_at?->toDateString() ?? $vital->vital_date?->toDateString(),
            $vital->vital_name,
            $vital->vital_value ?? $vital->value_numeric,
            $vital->unit,
        ])->all());
        $this->section($xml, 'Social History', ['Category', 'Value'], []);
        $this->section($xml, 'Problems', ['Condition', 'Code', 'Status', 'Onset'], $data['conditions']->map(fn ($condition): array => [
            $condition->name,
            $condition->icd10_code,
            $condition->clinical_status,
            $condition->onset_date?->toDateString(),
        ])->all());
        $this->section($xml, 'Medications', ['Medication', 'Dose', 'Frequency', 'Status'], $data['medications']->map(fn ($medication): array => [
            $medication->name,
            trim(implode(' ', array_filter([$medication->dose, $medication->dose_unit]))),
            $medication->frequency,
            $medication->status,
        ])->all());
        $this->section($xml, 'Procedures', ['Procedure', 'Date', 'Status'], $data['procedures']->map(fn ($procedure): array => [
            $procedure->name,
            $procedure->performed_at?->toDateString() ?? $procedure->performed_on?->toDateString(),
            $procedure->status,
        ])->all());
        $this->section($xml, 'Immunizations', ['Vaccine', 'Date', 'Lot'], $data['immunizations']->map(fn ($immunization): array => [
            $immunization->vaccine_name,
            $immunization->administered_on?->toDateString(),
            $immunization->lot_number,
        ])->all());
        $this->section($xml, 'Allergies', ['Substance', 'Reaction', 'Severity'], $data['allergies']->map(fn ($allergy): array => [
            $allergy->substance,
            $allergy->reaction,
            $allergy->severity,
        ])->all());
        $this->section($xml, 'Encounters', ['Date', 'Type', 'Provider', 'Reason', 'Assessment'], $data['office_visits']->map(fn ($visit): array => [
            $visit->visit_started_at?->toDateString() ?? $visit->visit_date?->toDateString(),
            $visit->visit_type,
            $visit->provider_name,
            $visit->chief_complaint,
            $visit->assessment,
        ])->all());
        $this->section($xml, 'Portal Messages', ['Date', 'Direction', 'Subject', 'Sender', 'Recipient', 'Summary', 'Clinical Relevance'], $data['portal_messages']->map(fn ($message): array => [
            $message->message_at?->toDateString(),
            $message->direction,
            $message->subject,
            $message->sender_name,
            $message->recipient_name,
            $message->summary,
            $message->clinical_relevance,
        ])->all());
        $this->section($xml, 'Negative Assertions', ['Date', 'Type', 'Scope', 'Statement', 'Notes'], $data['negative_assertions']->map(fn ($assertion): array => [
            $assertion->observed_on?->toDateString(),
            $assertion->assertion_type,
            $assertion->scope,
            $assertion->statement,
            $assertion->notes,
        ])->all());
        $this->section($xml, 'Imaging Studies', ['Date', 'Description', 'Modality', 'UID'], $data['dicom_studies']->map(fn ($study): array => [
            $study->study_date?->toDateString(),
            $study->description,
            $study->modalities,
            $study->study_instance_uid,
        ])->all());
        $this->section($xml, 'Documents', ['Document', 'Type', 'Summary'], $data['documents']->map(fn ($document): array => [
            $document->title ?? $document->original_filename,
            $document->document_type,
            $document->summary,
        ])->all());

        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function patient(XMLWriter $xml, PhrPatient $patient): void
    {
        $xml->startElement('recordTarget');
        $xml->startElement('patientRole');
        $xml->startElement('id');
        // This application has no assigning authority suitable for an interoperable patient
        // identifier. NI is preferable to leaking a deployment-local database primary key.
        $xml->writeAttribute('nullFlavor', 'NI');
        $xml->endElement();
        $this->nullFlavor($xml, 'addr');
        $this->nullFlavor($xml, 'telecom');
        $xml->startElement('patient');
        $xml->startElement('name');
        $xml->writeElement('given', $patient->display_name ?? 'Patient');
        $this->nullFlavor($xml, 'family');
        $xml->endElement();
        $administrativeGender = match (strtolower((string) $patient->sex_at_birth)) {
            'male', 'm' => 'M',
            'female', 'f' => 'F',
            'undifferentiated', 'un' => 'UN',
            default => null,
        };
        if ($administrativeGender !== null) {
            $xml->startElement('administrativeGenderCode');
            $xml->writeAttribute('code', $administrativeGender);
            $xml->writeAttribute('codeSystem', '2.16.840.1.113883.5.1');
            $xml->endElement();
        } else {
            $this->nullFlavor($xml, 'administrativeGenderCode');
        }
        if ($patient->birth_date) {
            $this->time($xml, 'birthTime', $patient->birth_date->format('Ymd'));
        } else {
            $this->nullFlavor($xml, 'birthTime');
        }
        $this->nullFlavor($xml, 'raceCode');
        $this->nullFlavor($xml, 'ethnicGroupCode');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function section(XMLWriter $xml, string $title, array $headers, array $rows): void
    {
        $xml->startElement('component');
        $xml->startElement('section');
        $template = self::SECTION_TEMPLATES[$title] ?? null;
        if ($template !== null) {
            if ($rows === []) {
                $xml->writeAttribute('nullFlavor', 'NI');
            }
            $this->templateId($xml, $template['root'], $template['extension']);
            $xml->startElement('code');
            $xml->writeAttribute('code', $template['code']);
            $xml->writeAttribute('codeSystem', '2.16.840.1.113883.6.1');
            $xml->endElement();
        }
        $xml->writeElement('title', $title);
        $xml->startElement('text');
        if ($rows === []) {
            $xml->writeElement('paragraph', 'No information available.');
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();

            return;
        }
        $xml->startElement('table');
        $xml->startElement('thead');
        $xml->startElement('tr');
        foreach ($headers as $header) {
            $xml->writeElement('th', $header);
        }
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('tbody');
        foreach ($rows as $row) {
            $xml->startElement('tr');
            foreach ($headers as $index => $_header) {
                $xml->writeElement('td', $this->cell($row[$index] ?? null));
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    private function time(XMLWriter $xml, string $element, string $value): void
    {
        $xml->startElement($element);
        $xml->writeAttribute('value', $value);
        $xml->endElement();
    }

    private function templateId(XMLWriter $xml, string $root, string $extension): void
    {
        $xml->startElement('templateId');
        $xml->writeAttribute('root', $root);
        $xml->writeAttribute('extension', $extension);
        $xml->endElement();
    }

    private function nullFlavor(XMLWriter $xml, string $element, string $nullFlavor = 'UNK'): void
    {
        $xml->startElement($element);
        $xml->writeAttribute('nullFlavor', $nullFlavor);
        $xml->endElement();
    }

    private function author(XMLWriter $xml, string $effectiveTime): void
    {
        $xml->startElement('author');
        $this->time($xml, 'time', $effectiveTime);
        $xml->startElement('assignedAuthor');
        $this->nullFlavor($xml, 'id', 'NI');
        $this->nullFlavor($xml, 'addr');
        $this->nullFlavor($xml, 'telecom');
        $xml->startElement('assignedAuthoringDevice');
        $xml->writeElement('manufacturerModelName', 'PHR');
        $xml->writeElement('softwareName', 'PHR');
        $xml->endElement();
        $xml->startElement('representedOrganization');
        $this->nullFlavor($xml, 'id', 'NI');
        $xml->writeElement('name', 'PHR');
        $this->nullFlavor($xml, 'telecom');
        $this->nullFlavor($xml, 'addr');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    private function custodian(XMLWriter $xml): void
    {
        $xml->startElement('custodian');
        $xml->startElement('assignedCustodian');
        $xml->startElement('representedCustodianOrganization');
        $this->nullFlavor($xml, 'id', 'NI');
        $xml->writeElement('name', 'PHR');
        $this->nullFlavor($xml, 'telecom');
        $this->nullFlavor($xml, 'addr');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    private function documentationOf(XMLWriter $xml): void
    {
        $xml->startElement('documentationOf');
        $xml->startElement('serviceEvent');
        $xml->writeAttribute('classCode', 'PCPR');
        $xml->startElement('effectiveTime');
        $this->nullFlavor($xml, 'low');
        $this->nullFlavor($xml, 'high');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    private function cell(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
