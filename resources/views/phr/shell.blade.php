@extends('layouts.app')

@section('title', $title . ' | ' . config('app.name', 'Ben Herila'))

@section('content')
  {{-- Single React shell for every PHR section and patient path. The client reads the
       view from the URL path; all navigation within PHR is client-side. --}}
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
