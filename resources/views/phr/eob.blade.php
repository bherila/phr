@extends('layouts.app')

@section('title', 'Explanation of Benefits | ' . config('app.name', 'Personal Health Record'))

@section('content')
  <article class="mx-auto w-full max-w-3xl space-y-6 px-4 py-10 text-foreground sm:px-6">
    <header class="space-y-2">
      <a class="text-sm underline underline-offset-4" href="{{ route('phr.patient', ['patient' => $patient->id]) }}">Back to patient</a>
      <h1 class="text-3xl font-semibold">Explanation of Benefits</h1>
    </header>

    <dl class="grid gap-4 rounded-lg border border-border bg-card p-5 text-sm text-card-foreground sm:grid-cols-2">
      @foreach (['provider_name' => 'Provider', 'claim_number' => 'Claim number', 'claim_type' => 'Claim type'] as $field => $label)
        <div>
          <dt class="text-muted-foreground">{{ $label }}</dt>
          <dd>{{ $eob->getAttribute($field) ?? 'Not recorded' }}</dd>
        </div>
      @endforeach
      <div>
        <dt class="text-muted-foreground">Processed date</dt>
        <dd>{{ $eob->processed_date?->toDateString() ?? 'Not recorded' }}</dd>
      </div>
      @foreach (['total_charges' => 'Charges', 'total_accepted_fee' => 'Accepted fee', 'total_plan_payment' => 'Plan payment', 'total_deductible_applied' => 'Deductible applied', 'total_copay_applied' => 'Copay applied', 'total_patient_responsibility' => 'Patient responsibility'] as $field => $label)
        <div>
          <dt class="text-muted-foreground">{{ $label }}</dt>
          <dd>{{ $eob->getAttribute($field) ?? 'Not recorded' }}</dd>
        </div>
      @endforeach
    </dl>

    @if ($sourceDocument)
      <a class="underline underline-offset-4" href="{{ route('phr.patients.documents.file', ['patient' => $patient->id, 'document' => $sourceDocument->id]) }}">View source document</a>
    @else
      <p class="text-sm text-muted-foreground">No source document attached.</p>
    @endif
  </article>
@endsection
