@if (config('ecopa.client_id'))
    <div class="ak-ecopa-login-wrap">
        <div class="ak-ecopa-divider">
            <span>atau</span>
        </div>
        <a href="{{ route('ecopa.login') }}" class="ak-ecopa-btn">
            <span class="ak-ecopa-btn-mark" aria-hidden="true">
                <svg viewBox="0 0 32 32" fill="none">
                    <rect width="32" height="32" rx="7" fill="url(#ak-ecopa-grad)"/>
                    <path d="M9 22 L13 11 L17 22" stroke="white" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                    <line x1="10.4" y1="18.5" x2="15.6" y2="18.5" stroke="white" stroke-width="2.4" stroke-linecap="round"/>
                    <circle cx="22" cy="11" r="2.2" fill="white"/>
                    <line x1="22" y1="14.2" x2="22" y2="22" stroke="white" stroke-width="2.4" stroke-linecap="round"/>
                    <defs>
                        <linearGradient id="ak-ecopa-grad" x1="0" y1="0" x2="32" y2="32">
                            <stop offset="0" stop-color="#1B84FF"/>
                            <stop offset="1" stop-color="#056EE9"/>
                        </linearGradient>
                    </defs>
                </svg>
            </span>
            <span class="ak-ecopa-btn-text">
                <span class="ak-ecopa-btn-title">Lanjut dengan Ecopa</span>
                <span class="ak-ecopa-btn-sub">Single sign-on Akunta ecosystem</span>
            </span>
            <svg class="ak-ecopa-btn-arrow" viewBox="0 0 16 16" fill="none">
                <path d="M5 4l4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
    </div>
@endif
