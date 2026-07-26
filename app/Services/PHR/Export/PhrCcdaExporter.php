<?php

namespace App\Services\PHR\Export;

use App\Models\PhrPatient;
use XMLWriter;

class PhrCcdaExporter
{
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
        $xml->startElement('templateId');
        $xml->writeAttribute('root', '2.16.840.1.113883.10.20.22.1.2');
        $xml->endElement();
        $xml->startElement('id');
        $xml->writeAttribute('root', 'urn:uuid:phr-ccda-'.$patient->id.'-'.now()->format('YmdHis'));
        $xml->endElement();
        $xml->startElement('code');
        $xml->writeAttribute('code', '34133-9');
        $xml->writeAttribute('codeSystem', '2.16.840.1.113883.6.1');
        $xml->writeAttribute('displayName', 'Summarization of Episode Note');
        $xml->endElement();
        $xml->writeElement('title', 'Personal Health Record Summary');
        $this->time($xml, 'effectiveTime', now()->format('YmdHisO'));
        $this->patient($xml, $patient);
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
        $xml->writeAttribute('root', 'urn:phr:patient');
        $xml->writeAttribute('extension', (string) $patient->id);
        $xml->endElement();
        $xml->startElement('patient');
        $xml->startElement('name');
        $xml->writeElement('given', $patient->display_name ?? 'Patient');
        $xml->endElement();
        if ($patient->sex_at_birth) {
            $xml->startElement('administrativeGenderCode');
            $xml->writeAttribute('code', strtoupper(substr($patient->sex_at_birth, 0, 1)));
            $xml->endElement();
        }
        if ($patient->birth_date) {
            $this->time($xml, 'birthTime', $patient->birth_date->format('Ymd'));
        }
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
        $xml->writeElement('title', $title);
        $xml->startElement('text');
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

    private function cell(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
