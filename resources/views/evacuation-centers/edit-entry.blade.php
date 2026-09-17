@extends('layouts.app')

@section('title', 'Edit pending evacuee entry')

@section('content')
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto py-10 px-4" style="background: rgba(15, 36, 71, 0.55); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);">
        @include('evacuation-centers._entry_form')
    </div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Direct full-page load (bookmarked URL, refresh while on this
        // page) -- no page "behind" to return to, closing just goes to
        // the center's page like a normal link, same as families/create.
        window.ELIKAS.initEcBoardEntryForm(document.querySelector('[data-ec-board-entry-modal]'));
    });
</script>
@endsection
