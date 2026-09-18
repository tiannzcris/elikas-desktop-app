@php
    $isProductionApi = config('elikas.central_api_url') === config('elikas.production_api_url');
@endphp
@unless ($isProductionApi)
    {{-- Impossible-to-miss on purpose -- see config/elikas.php's own
         docblock for the exact incident this exists to prevent: a local
         test entry synced straight into the live production database
         because nothing in the app ever showed which server a session was
         actually talking to. Shown on every page, including login (the
         login attempt itself is also a real API call). --}}
    <div class="flex items-center justify-center gap-2 text-xs font-bold text-white py-1.5 px-3 text-center" style="background: repeating-linear-gradient(45deg, #B91C1C, #B91C1C 10px, #991B1B 10px, #991B1B 20px);">
        <i class="ti ti-alert-triangle" style="font-size: 14px;" aria-hidden="true"></i>
        DEV MODE -- NOT PRODUCTION -- API target: {{ config('elikas.central_api_url') }}
    </div>
@endunless
