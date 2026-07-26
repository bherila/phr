<x-mail::message>
# Import {{ $status === 'parsed' ? 'Ready for Review' : ucfirst($status) }}

Your file **{{ $filename }}** ({{ $jobType }}) has been processed.

@if($status === 'parsed')
**{{ $resultCount }}** result(s) are ready for your review.

Please log in to review and import the results.
@elseif($status === 'failed')
Unfortunately, the import failed:

> {{ $errorMessage }}

You may retry the import from the job history page.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
