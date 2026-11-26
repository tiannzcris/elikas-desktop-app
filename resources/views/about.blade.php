@extends('layouts.app')

@section('title', 'About E-LIKAS')

@section('content')
    @php
        $auth = \App\Models\LocalAuth::current();
        $webUrl = app(\App\Services\CentralApiService::class)->webUrl();
    @endphp

    <div class="page-header">
        <h1 class="page-title">About E-LIKAS</h1>
    </div>

    <div class="card card-body flex flex-col sm:flex-row gap-8 items-start">
        <img src="{{ asset('images/elikas-logo-mark.png') }}" alt="E-LIKAS logo" class="w-28 h-28 shrink-0" data-about-logo>
        <div class="min-w-0 flex-1">
            <p class="font-label font-extrabold uppercase text-[2rem] leading-none text-ink">E-LIKAS</p>
            <p class="text-base text-gray-700">Electronic Ligao Kaligtasan Sistema · Offline Companion for Windows</p>

            <dl class="dl-grid mt-6">
                <div><dt>Version</dt><dd data-app-version>{{ config('nativephp.version') }}</dd></div>
                <div><dt>Web system</dt><dd class="break-all">{{ $webUrl }}</dd></div>
                <div><dt>Signed in as</dt><dd>{{ $auth->name }} · {{ $auth->roleLabel() }}</dd></div>
                @if ($auth->barangay_name)
                    <div><dt>Barangay</dt><dd>{{ $auth->barangay_name }}</dd></div>
                @endif
                <div><dt>Office</dt><dd>City Social Welfare and Development Office, Ligao City, Albay</dd></div>
            </dl>
        </div>
        <div class="flex gap-3 shrink-0">
            <img src="{{ asset('images/ligao-city-seal.jpg') }}" alt="Seal of the City Government of Ligao" class="w-14 h-14 rounded-full object-cover ring-1 ring-gray-200">
            <img src="{{ asset('images/cswdo-ligao-logo.jpg') }}" alt="CSWDO Ligao City logo" class="w-14 h-14 rounded-full object-cover ring-1 ring-gray-200">
        </div>
    </div>
@endsection
