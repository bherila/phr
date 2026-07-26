<?php

namespace App\Services\PHR\DICOM;

class DicomMetadataParser
{
    public const MAX_PARSE_BYTES = 4194304;

    /**
     * @var array<string, array{name: string, type: string}>
     */
    private const TAGS = [
        '00020010' => ['name' => 'TransferSyntaxUID', 'type' => 'string'],
        '00080008' => ['name' => 'ImageType', 'type' => 'string_list'],
        '00080016' => ['name' => 'SOPClassUID', 'type' => 'string'],
        '00080018' => ['name' => 'SOPInstanceUID', 'type' => 'string'],
        '00080020' => ['name' => 'StudyDate', 'type' => 'string'],
        '00080021' => ['name' => 'SeriesDate', 'type' => 'string'],
        '00080030' => ['name' => 'StudyTime', 'type' => 'string'],
        '00080050' => ['name' => 'AccessionNumber', 'type' => 'string'],
        '00080060' => ['name' => 'Modality', 'type' => 'string'],
        '00081030' => ['name' => 'StudyDescription', 'type' => 'string'],
        '0008103E' => ['name' => 'SeriesDescription', 'type' => 'string'],
        '00100010' => ['name' => 'PatientName', 'type' => 'string'],
        '00100020' => ['name' => 'PatientID', 'type' => 'string'],
        '00100030' => ['name' => 'PatientBirthDate', 'type' => 'string'],
        '00100040' => ['name' => 'PatientSex', 'type' => 'string'],
        '00101010' => ['name' => 'PatientAge', 'type' => 'string'],
        '00180015' => ['name' => 'BodyPartExamined', 'type' => 'string'],
        '00180050' => ['name' => 'SliceThickness', 'type' => 'decimal'],
        '0020000D' => ['name' => 'StudyInstanceUID', 'type' => 'string'],
        '0020000E' => ['name' => 'SeriesInstanceUID', 'type' => 'string'],
        '00200010' => ['name' => 'StudyID', 'type' => 'string'],
        '00200011' => ['name' => 'SeriesNumber', 'type' => 'integer'],
        '00200013' => ['name' => 'InstanceNumber', 'type' => 'integer'],
        '00200032' => ['name' => 'ImagePositionPatient', 'type' => 'decimal_list'],
        '00200037' => ['name' => 'ImageOrientationPatient', 'type' => 'decimal_list'],
        '00200052' => ['name' => 'FrameOfReferenceUID', 'type' => 'string'],
        '00280002' => ['name' => 'SamplesPerPixel', 'type' => 'integer'],
        '00280004' => ['name' => 'PhotometricInterpretation', 'type' => 'string'],
        '00280008' => ['name' => 'NumberOfFrames', 'type' => 'integer'],
        '00280010' => ['name' => 'Rows', 'type' => 'integer'],
        '00280011' => ['name' => 'Columns', 'type' => 'integer'],
        '00280030' => ['name' => 'PixelSpacing', 'type' => 'decimal_list'],
        '00280100' => ['name' => 'BitsAllocated', 'type' => 'integer'],
        '00280101' => ['name' => 'BitsStored', 'type' => 'integer'],
        '00280102' => ['name' => 'HighBit', 'type' => 'integer'],
        '00280103' => ['name' => 'PixelRepresentation', 'type' => 'integer'],
        '00281050' => ['name' => 'WindowCenter', 'type' => 'decimal_or_list'],
        '00281051' => ['name' => 'WindowWidth', 'type' => 'decimal_or_list'],
        '00281052' => ['name' => 'RescaleIntercept', 'type' => 'decimal'],
        '00281053' => ['name' => 'RescaleSlope', 'type' => 'decimal'],
    ];

    /**
     * @var array<string, true>
     */
    private const LONG_VRS = [
        'OB' => true,
        'OD' => true,
        'OF' => true,
        'OL' => true,
        'OV' => true,
        'OW' => true,
        'SQ' => true,
        'UC' => true,
        'UR' => true,
        'UT' => true,
        'UN' => true,
    ];

    /**
     * @return array{
     *     is_dicom: bool,
     *     has_preamble: bool,
     *     metadata: array<string, mixed>,
     *     normalized: array<string, mixed>,
     *     is_image_instance: bool
     * }
     */
    public function parse(string $path): array
    {
        $data = file_get_contents($path, false, null, 0, self::MAX_PARSE_BYTES);
        if ($data === false) {
            return $this->emptyResult();
        }

        return $this->parseBytes($data);
    }

    /**
     * @return array{
     *     is_dicom: bool,
     *     has_preamble: bool,
     *     metadata: array<string, mixed>,
     *     normalized: array<string, mixed>,
     *     is_image_instance: bool
     * }
     */
    public function parseBytes(string $data): array
    {
        if (strlen($data) < 8) {
            return $this->emptyResult();
        }

        $hasPreamble = strlen($data) >= 132 && substr($data, 128, 4) === 'DICM';
        $metadata = [];

        if ($hasPreamble) {
            $datasetOffset = $this->parseGroup($data, 132, $metadata, true, 0x0002);
            $transferSyntaxUid = $this->stringValue($metadata['TransferSyntaxUID'] ?? null);
            $this->parseElements($data, $datasetOffset, $metadata, $transferSyntaxUid !== '1.2.840.10008.1.2');
        } else {
            $explicitMetadata = [];
            $this->parseElements($data, 0, $explicitMetadata, true);
            $implicitMetadata = [];
            $this->parseElements($data, 0, $implicitMetadata, false);
            $metadata = count($explicitMetadata) >= count($implicitMetadata) ? $explicitMetadata : $implicitMetadata;
        }

        $normalized = $this->normalizedMetadata($metadata);
        $isDicom = $hasPreamble
            || $normalized['study_instance_uid'] !== null
            || $normalized['series_instance_uid'] !== null
            || $normalized['sop_instance_uid'] !== null;

        return [
            'is_dicom' => $isDicom,
            'has_preamble' => $hasPreamble,
            'metadata' => $metadata,
            'normalized' => $normalized,
            'is_image_instance' => $normalized['study_instance_uid'] !== null
                && $normalized['series_instance_uid'] !== null
                && $normalized['sop_instance_uid'] !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function parseGroup(string $data, int $offset, array &$metadata, bool $explicitVr, int $group): int
    {
        $length = strlen($data);

        while ($offset + 8 <= $length) {
            $elementOffset = $offset;
            $currentGroup = $this->readUInt16($data, $offset);
            if ($currentGroup !== $group) {
                return $elementOffset;
            }

            $nextOffset = $this->parseElement($data, $offset, $metadata, $explicitVr);
            if ($nextOffset <= $offset) {
                return $elementOffset;
            }

            $offset = $nextOffset;
        }

        return $offset;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function parseElements(string $data, int $offset, array &$metadata, bool $explicitVr): void
    {
        $length = strlen($data);
        $parsed = 0;

        while ($offset + 8 <= $length && $parsed < 2500) {
            $group = $this->readUInt16($data, $offset);
            $element = $this->readUInt16($data, $offset + 2);

            if ($group === 0x7FE0 && $element === 0x0010) {
                return;
            }

            $nextOffset = $this->parseElement($data, $offset, $metadata, $explicitVr);
            if ($nextOffset <= $offset) {
                return;
            }

            $offset = $nextOffset;
            $parsed++;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function parseElement(string $data, int $offset, array &$metadata, bool $explicitVr): int
    {
        $dataLength = strlen($data);
        $group = $this->readUInt16($data, $offset);
        $element = $this->readUInt16($data, $offset + 2);
        $vr = '';

        if ($explicitVr) {
            if ($offset + 8 > $dataLength) {
                return $offset;
            }

            $vr = substr($data, $offset + 4, 2);
            if (preg_match('/^[A-Z]{2}$/', $vr) !== 1) {
                return $offset;
            }

            if (isset(self::LONG_VRS[$vr])) {
                if ($offset + 12 > $dataLength) {
                    return $offset;
                }

                $valueLength = $this->readUInt32($data, $offset + 8);
                $valueOffset = $offset + 12;
            } else {
                $valueLength = $this->readUInt16($data, $offset + 6);
                $valueOffset = $offset + 8;
            }
        } else {
            if ($offset + 8 > $dataLength) {
                return $offset;
            }

            $valueLength = $this->readUInt32($data, $offset + 4);
            $valueOffset = $offset + 8;
        }

        if ($valueLength === 0xFFFFFFFF) {
            return $this->skipUndefinedLengthElement($data, $valueOffset, $explicitVr) ?? $offset;
        }

        if ($valueOffset + $valueLength > $dataLength) {
            return $offset;
        }

        $tag = sprintf('%04X%04X', $group, $element);
        if (isset(self::TAGS[$tag])) {
            $definition = self::TAGS[$tag];
            $metadata[$definition['name']] = $this->decodeValue(substr($data, $valueOffset, $valueLength), $vr, $definition['type']);
        }

        return $valueOffset + $valueLength;
    }

    private function skipUndefinedLengthElement(string $data, int $valueOffset, bool $explicitVr): ?int
    {
        return $this->skipUndefinedLengthSequence($data, $valueOffset, $explicitVr);
    }

    private function skipUndefinedLengthSequence(string $data, int $offset, bool $explicitVr): ?int
    {
        $length = strlen($data);
        $cursor = $offset;

        while ($cursor + 8 <= $length) {
            $group = $this->readUInt16($data, $cursor);
            $element = $this->readUInt16($data, $cursor + 2);
            $itemLength = $this->readUInt32($data, $cursor + 4);

            if ($group !== 0xFFFE) {
                return null;
            }

            if ($element === 0xE0DD) {
                return $cursor + 8;
            }

            if ($element !== 0xE000) {
                return null;
            }

            $itemValueOffset = $cursor + 8;
            if ($itemLength === 0xFFFFFFFF) {
                $itemEnd = $this->skipUndefinedLengthItem($data, $itemValueOffset, $explicitVr);
                if ($itemEnd === null) {
                    return null;
                }

                $cursor = $itemEnd;

                continue;
            }

            if ($itemValueOffset + $itemLength > $length) {
                return null;
            }

            $cursor = $itemValueOffset + $itemLength;
        }

        return null;
    }

    private function skipUndefinedLengthItem(string $data, int $offset, bool $explicitVr): ?int
    {
        $length = strlen($data);
        $cursor = $offset;
        $metadata = [];

        while ($cursor + 8 <= $length) {
            $group = $this->readUInt16($data, $cursor);
            $element = $this->readUInt16($data, $cursor + 2);

            if ($group === 0xFFFE && $element === 0xE00D) {
                return $cursor + 8;
            }

            if ($group === 0xFFFE && $element === 0xE0DD) {
                return $cursor;
            }

            $nextOffset = $this->parseElement($data, $cursor, $metadata, $explicitVr);
            if ($nextOffset <= $cursor) {
                return null;
            }

            $cursor = $nextOffset;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function normalizedMetadata(array $metadata): array
    {
        return [
            'study_instance_uid' => $this->stringValue($metadata['StudyInstanceUID'] ?? null),
            'series_instance_uid' => $this->stringValue($metadata['SeriesInstanceUID'] ?? null),
            'sop_instance_uid' => $this->stringValue($metadata['SOPInstanceUID'] ?? null),
            'sop_class_uid' => $this->stringValue($metadata['SOPClassUID'] ?? null),
            'transfer_syntax_uid' => $this->stringValue($metadata['TransferSyntaxUID'] ?? null),
            'study_date' => $this->dateValue($metadata['StudyDate'] ?? null),
            'study_date_raw' => $this->stringValue($metadata['StudyDate'] ?? null),
            'study_time' => $this->timeValue($metadata['StudyTime'] ?? null),
            'study_time_raw' => $this->stringValue($metadata['StudyTime'] ?? null),
            'accession_number' => $this->stringValue($metadata['AccessionNumber'] ?? null),
            'study_description' => $this->stringValue($metadata['StudyDescription'] ?? null),
            'series_description' => $this->stringValue($metadata['SeriesDescription'] ?? null),
            'modality' => $this->stringValue($metadata['Modality'] ?? null),
            'body_part' => $this->stringValue($metadata['BodyPartExamined'] ?? null),
            'series_number' => $this->intValue($metadata['SeriesNumber'] ?? null),
            'instance_number' => $this->intValue($metadata['InstanceNumber'] ?? null),
            'rows' => $this->intValue($metadata['Rows'] ?? null),
            'columns' => $this->intValue($metadata['Columns'] ?? null),
            'number_of_frames' => $this->intValue($metadata['NumberOfFrames'] ?? null),
        ];
    }

    private function decodeValue(string $value, string $vr, string $type): mixed
    {
        $trimmed = trim($value, " \t\r\n\0");

        return match ($type) {
            'integer' => $this->decodeInteger($value, $vr),
            'decimal' => $this->floatValue($trimmed),
            'decimal_list' => $this->floatListValue($trimmed),
            'decimal_or_list' => $this->decimalOrListValue($trimmed),
            'string_list' => $this->stringListValue($trimmed),
            default => $trimmed === '' ? null : $trimmed,
        };
    }

    private function decodeInteger(string $value, string $vr): ?int
    {
        if (($vr === 'US' || $vr === 'SS') && strlen($value) >= 2) {
            $unsigned = $this->readUInt16($value, 0);

            return $vr === 'SS' && $unsigned > 32767 ? $unsigned - 65536 : $unsigned;
        }

        if (($vr === 'UL' || $vr === 'SL') && strlen($value) >= 4) {
            $unsigned = $this->readUInt32($value, 0);

            return $vr === 'SL' && $unsigned > 2147483647 ? $unsigned - 4294967296 : $unsigned;
        }

        $trimmed = trim($value, " \t\r\n\0");

        return preg_match('/^-?\d+$/', $trimmed) === 1 ? (int) $trimmed : null;
    }

    /**
     * @return float|list<float>|null
     */
    private function decimalOrListValue(string $value): float|array|null
    {
        $values = $this->floatListValue($value);

        if ($values === null) {
            return null;
        }

        return count($values) === 1 ? $values[0] : $values;
    }

    /**
     * @return list<float>|null
     */
    private function floatListValue(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        return array_values(array_map(
            fn (string $part): float => (float) trim($part),
            array_filter(explode('\\', $value), fn (string $part): bool => trim($part) !== ''),
        ));
    }

    /**
     * @return list<string>|null
     */
    private function stringListValue(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        return array_values(array_filter(
            array_map(fn (string $part): string => trim($part), explode('\\', $value)),
            fn (string $part): bool => $part !== '',
        ));
    }

    private function floatValue(string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function dateValue(mixed $value): ?string
    {
        $date = $this->stringValue($value);
        if ($date === null || preg_match('/^\d{8}$/', $date) !== 1) {
            return null;
        }

        return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
    }

    private function timeValue(mixed $value): ?string
    {
        $time = $this->stringValue($value);
        if ($time === null || preg_match('/^\d{2}(\d{2})?(\d{2})?(\.\d+)?$/', $time) !== 1) {
            return null;
        }

        $compact = str_pad(preg_replace('/\.\d+$/', '', $time) ?? '', 6, '0');

        return substr($compact, 0, 2).':'.substr($compact, 2, 2).':'.substr($compact, 4, 2);
    }

    private function readUInt16(string $data, int $offset): int
    {
        $value = unpack('v', substr($data, $offset, 2));

        return (int) ($value[1] ?? 0);
    }

    private function readUInt32(string $data, int $offset): int
    {
        $value = unpack('V', substr($data, $offset, 4));

        return (int) ($value[1] ?? 0);
    }

    /**
     * @return array{
     *     is_dicom: bool,
     *     has_preamble: bool,
     *     metadata: array<string, mixed>,
     *     normalized: array<string, mixed>,
     *     is_image_instance: bool
     * }
     */
    private function emptyResult(): array
    {
        return [
            'is_dicom' => false,
            'has_preamble' => false,
            'metadata' => [],
            'normalized' => $this->normalizedMetadata([]),
            'is_image_instance' => false,
        ];
    }
}
