@extends('layouts.app')

@section('title', $title . ' | ' . config('app.name', 'Ben Herila'))

@section('content')
  {{-- Single hash-routed React shell. pages.tsx seeds the initial column from the
       active section (or patient) data attributes; all PHR navigation is client-side. --}}
  <div
    id="PhrShell"
    class="h-dvh flex flex-col overflow-hidden"
    @isset($patientId) data-patient-id="{{ $patientId }}" @endisset
    @isset($activeSection) data-active-section="{{ $activeSection }}" @endisset
    data-can-manage="{{ $canManage ? 'true' : 'false' }}"
  ></div>
@endsection

@push('scripts')
  @vite('resources/js/phr/pages.tsx')
@endpush
