{{-- Standalone full-screen 3D volume explorer. Uses the chrome-less game
     layout (full-viewport React island + csrf + theme bootstrap); all data is
     fetched client-side from the access-gated volume-manifest API. --}}
@extends('layouts.app')

@section('title', $title . ' | ' . config('app.name', 'Ben Herila'))

@section('content')
  <div
    id="explore3d-root"
    class="h-dvh w-full"
    data-patient-id="{{ $patientId }}"
    data-series-id="{{ $seriesId }}"
  ></div>
@endsection

@push('scripts')
  @vite('resources/js/phr/imaging/explore3d/standalone.tsx')
@endpush
