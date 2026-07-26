<x-mail::message>
# Import Deferred

Your file **{{ $filename }}** ({{ $jobType }}) has been deferred because the daily AI processing limit has been reached.

Your file will be processed on **{{ $scheduledFor }}** when the quota resets.

You do not need to re-upload the file. We will notify you once processing is complete.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
