@extends('layouts.app')

@section('title', $title . ' | ' . config('app.name', 'Ben Herila'))

{{-- The shell is exactly one viewport tall, so a site footer would add a page scroll. --}}
@section('hide_footer', '1')

@section('content')
  {{-- Single React shell for every PHR section and patient path. The client reads the
       view from the URL path; all navigation within PHR is client-side. --}}
  <div
    id="PhrShell"
    class="h-dvh flex flex-col overflow-hidden"
    @isset($patientId) data-patient-id="{{ $patientId }}" @endisset
    @isset($activeSection) data-active-section="{{ $activeSection }}" @endisset
    data-is-admin="{{ auth()->user()?->hasRole('admin') ? 'true' : 'false' }}"
    data-can-manage="{{ $canManage ? 'true' : 'false' }}"
  ></div>
@endsection

{{-- First-load responses (PhrPreloadBuilder), already encoded with HEX_TAG/AMP/APOS/QUOT so no
     string can close the element. The client reads it once at boot and removes it. --}}
@isset($phrPreload)
  @push('data-head')
    <script type="application/json" id="phr-preload">{!! $phrPreload !!}</script>
  @endpush
@endisset

@push('scripts')
  @vite('resources/js/phr/pages.tsx')
@endpush
