@extends('layouts.app')

@section('title', 'Register a family')

@section('content')
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto py-10 px-4" style="background: rgba(15, 36, 71, 0.55); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);">
        @include('families._form')
    </div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Direct full-page load (e.g. bookmarked URL, refresh while on this
        // page) -- there's no page "behind" to return to, so closing just
        // goes to the dashboard like a normal link.
        window.ELIKAS.initRegisterFamilyForm(document.querySelector('[data-register-family-modal]'));
    });
</script>
@endsection
