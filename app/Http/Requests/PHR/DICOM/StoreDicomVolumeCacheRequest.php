<?php

namespace App\Http\Requests\PHR\DICOM;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

class StoreDicomVolumeCacheRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'pipeline_version' => ['required', 'integer', Rule::in([$this->currentPipelineVersion()])],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $file = $this->file('file');
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }

            $size = $file->getSize();
            if ($size === false || $size > $this->maxBytes()) {
                $validator->errors()->add('file', 'The volume cache artifact exceeds the configured size limit.');

                return;
            }

            $magic = file_get_contents($file->getPathname(), false, null, 0, 2);
            if ($magic !== "\x1f\x8b") {
                $validator->errors()->add('file', 'The volume cache artifact must be gzip encoded.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Attach the volume cache artifact to upload.',
            'pipeline_version.in' => 'The volume cache pipeline version is not current.',
        ];
    }

    public function artifact(): UploadedFile
    {
        $file = $this->file('file');
        if (! $file instanceof UploadedFile) {
            throw new LogicException('Validated volume cache artifact is unavailable.');
        }

        return $file;
    }

    public function pipelineVersion(): int
    {
        return (int) $this->validated('pipeline_version');
    }

    private function currentPipelineVersion(): int
    {
        return (int) config('phr.volume_cache_pipeline_version', 1);
    }

    private function maxBytes(): int
    {
        return (int) config('phr.volume_cache_max_bytes', 67108864);
    }
}
