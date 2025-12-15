<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="description" content="Complete mess and hostel management system">
    <meta name="theme-color" content="#3b82f6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Mess Manager">
    <meta name="application-name" content="Mess Manager">
    <meta name="msapplication-TileColor" content="#3b82f6">
    <meta name="msapplication-TileImage" content="/icons/icon-144x144.png">

    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json">

    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" sizes="57x57" href="/icons/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="/icons/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="/icons/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="/icons/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="/icons/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="/icons/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="/icons/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/icons/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-icon-180x180.png">

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192x192.png">

    <!-- Preload critical resources -->
    <link rel="preload" href="{{ mix('css/mobile.css') }}" as="style">
    <link rel="preload" href="{{ mix('js/mobile.js') }}" as="script">

    <!-- Styles -->
    <link href="{{ mix('css/mobile.css') }}" rel="stylesheet">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @stack('scripts')
</head>

<body class="bg-gray-50 min-h-screen touch-manipulation">
    <!-- Mobile Navigation -->
    @include('partials.mobile-navigation')

    <!-- Main Content -->
    <main class="pb-16">
        @yield('content')
    </main>

    <!-- Mobile Bottom Navigation -->
    @include('partials.mobile-bottom-nav')

    <!-- PWA Install Banner -->
    <div id="pwa-install-banner" class="hidden fixed top-0 left-0 right-0 bg-blue-600 text-white p-3 z-50 transform transition-transform duration-300">
        <div class="flex items-center justify-between max-w-md mx-auto">
            <div class="flex items-center space-x-3">
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 2L2 7v10c0 1.1.9 2 2h16c1.1 0 2-.9 2-2V9c0-1.1-.9-2-2-2h-4l4-5z" />
                    <path d="M12 2v6m0 4v2" />
                </svg>
                <div>
                    <p class="font-semibold">Install Mess Manager</p>
                    <p class="text-sm opacity-90">Get offline access and better experience</p>
                </div>
            </div>
            <button onclick="window.pwaManager.installPrompt()" class="bg-white text-blue-600 px-4 py-2 rounded-lg font-semibold">
                Install
            </button>
        </div>
    </div>

    <!-- Connection Status Indicator -->
    <div id="connection-status" class="fixed top-4 right-4 z-40">
        <div class="bg-white rounded-full shadow-lg p-2 flex items-center space-x-2">
            <div id="status-dot" class="w-3 h-3 rounded-full bg-green-500"></div>
            <span id="status-text" class="text-sm font-medium">Online</span>
        </div>
    </div>

    <!-- Offline Queue Indicator -->
    <div id="offline-queue" class="fixed bottom-20 right-4 z-40 hidden">
        <div class="bg-red-500 text-white rounded-lg shadow-lg p-3 flex items-center space-x-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0m-9 2a2 2 0 002-2h4a2 2 0 002-2m-4 0v6m0-6a2 2 0 00-2 2h4a2 2 0 002 2v6a2 2 0 01-2 2h-4a2 2 0 01-2-2v-6z" />
            </svg>
            <div>
                <p class="font-semibold">Offline Queue</p>
                <p class="text-sm" id="queue-count">0 items</p>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-20 left-4 right-4 z-50 pointer-events-none">
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg p-6 flex flex-col items-center space-y-4">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
            <p class="text-gray-600">Loading...</p>
        </div>
    </div>

    <!-- Scripts -->
    <script src="{{ mix('js/mobile.js') }}"></script>
    <script>
        // Initialize PWA features
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then(registration => {
                        console.log('SW registered:', registration);

                        // Show install banner if applicable
                        if (registration.installing) {
                            showInstallBanner();
                        }

                        // Listen for updates
                        registration.addEventListener('updatefound', () => {
                            showUpdateNotification();
                        });
                    })
                    .catch(error => {
                        console.error('SW registration failed:', error);
                    });
            });
        }

        // Show install banner for standalone detection
        function showInstallBanner() {
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);

            if (!isStandalone && !isIOS) {
                setTimeout(() => {
                    document.getElementById('pwa-install-banner').classList.remove('hidden');
                }, 3000);
            }
        }

        // Update notification
        function showUpdateNotification() {
            const banner = document.createElement('div');
            banner.className = 'fixed top-16 left-4 right-4 bg-green-500 text-white p-4 rounded-lg shadow-lg z-50 max-w-sm';
            banner.innerHTML = `
                <div class="flex items-center space-x-3">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M7 7h10l2 2m-2-2v6m0 4l-2-2m2 0h-4m4 0v6m0-6a2 2 0 00-2 2h4a2 2 0 002 2v6a2 2 0 01-2 2h-4a2 2 0 01-2-2v-6z"/>
                    </svg>
                    <div>
                        <p class="font-semibold">App Update Available</p>
                        <p class="text-sm">Refresh to get the latest features</p>
                    </div>
                    <button onclick="window.location.reload()" class="bg-white text-green-600 px-3 py-1 rounded font-semibold">
                        Refresh
                    </button>
                </div>
            `;

            document.body.appendChild(banner);

            setTimeout(() => {
                banner.remove();
            }, 10000);
        }

        // Connection status updates
        window.addEventListener('online', () => {
            updateConnectionStatus(true);
        });

        window.addEventListener('offline', () => {
            updateConnectionStatus(false);
        });

        function updateConnectionStatus(isOnline) {
            const dot = document.getElementById('status-dot');
            const text = document.getElementById('status-text');

            if (isOnline) {
                dot.className = 'w-3 h-3 rounded-full bg-green-500';
                text.textContent = 'Online';
            } else {
                dot.className = 'w-3 h-3 rounded-full bg-red-500 animate-pulse';
                text.textContent = 'Offline';
            }
        }

        // Touch optimizations
        document.addEventListener('touchstart', function(e) {
            // Add active state for touch feedback
            e.target.classList.add('touch-active');
        }, {
            passive: true
        });

        document.addEventListener('touchend', function(e) {
            // Remove active state
            e.target.classList.remove('touch-active');
        }, {
            passive: true
        });

        // Prevent double-tap zoom
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            const touchDuration = now - lastTouchEnd;

            if (touchDuration < 300) {
                e.preventDefault();
            }

            lastTouchEnd = now;
        }, false);

        // Safe area handling for notched screens
        function setSafeAreaInsets() {
            const safeArea = getComputedStyle(document.documentElement)
                .getPropertyValue('--safe-area-inset-top');

            if (safeArea && safeArea !== '0px') {
                document.documentElement.style.setProperty(
                    '--safe-area-inset-top',
                    safeArea
                );
            }
        }

        setSafeAreaInsets();
    </script>
</body>

</html>