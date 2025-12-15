// Mobile-First JavaScript for Mess Management System PWA

class MobileApp {
    constructor() {
        this.isOnline = navigator.onLine;
        this.currentPage = 'dashboard';
        this.touchStartY = 0;
        this.touchEndY = 0;
        this.swipeThreshold = 50;
        this.pullToRefreshThreshold = 80;

        this.init();
    }

    init() {
        // Initialize PWA features
        this.initServiceWorker();
        this.initTouchGestures();
        this.initPullToRefresh();
        this.initLazyLoading();
        this.initOfflineDetection();
        this.initMobileOptimizations();

        console.log('Mobile App initialized');
    }

    async initServiceWorker() {
        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.register('/sw.js');
                console.log('Service Worker registered:', registration);

                // Listen for service worker messages
                navigator.serviceWorker.addEventListener('message', (event) => {
                    this.handleServiceWorkerMessage(event);
                });

                return registration;
            } catch (error) {
                console.error('Service Worker registration failed:', error);
            }
        }
        return null;
    }

    handleServiceWorkerMessage(event) {
        const { type, data } = event.data;

        switch (type) {
            case 'CACHE_UPDATED':
                this.showNotification('Content updated', 'success');
                break;
            case 'OFFLINE_QUEUE_UPDATED':
                this.updateOfflineQueueUI(data);
                break;
            case 'CONNECTION_STATUS':
                this.updateConnectionStatus(data);
                break;
        }
    }

    initTouchGestures() {
        let touchStartX = 0;
        let touchStartY = 0;

        document.addEventListener('touchstart', (e) => {
            const touch = e.touches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;

            // Add ripple effect
            this.createRipple(touch.clientX, touch.clientY);
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            if (!touchStartX || !touchStartY) return;

            const touch = e.touches[0];
            const deltaX = touch.clientX - touchStartX;
            const deltaY = touch.clientY - touchStartY;

            // Handle swipe gestures
            if (Math.abs(deltaX) > Math.abs(deltaY)) {
                if (deltaX > 0) {
                    this.handleSwipeRight();
                } else {
                    this.handleSwipeLeft();
                }
            }
        }, { passive: true });

        document.addEventListener('touchend', (e) => {
            touchStartX = 0;
            touchStartY = 0;
        }, { passive: true });
    }

    initPullToRefresh() {
        let startY = 0;
        let isPulling = false;

        document.addEventListener('touchstart', (e) => {
            startY = e.touches[0].clientY;
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            const currentY = e.touches[0].clientY;
            const deltaY = currentY - startY;

            if (deltaY > this.pullToRefreshThreshold) {
                isPulling = true;
                this.showPullToRefresh();
            }
        }, { passive: true });

        document.addEventListener('touchend', () => {
            if (isPulling) {
                this.triggerRefresh();
            }
            isPulling = false;
        }, { passive: true });
    }

    initLazyLoading() {
        // Intersection Observer for lazy loading
        if ('IntersectionObserver' in window) {
            const lazyImageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy');
                        lazyImageObserver.unobserve(img);
                    }
                });
            });

            document.querySelectorAll('img[data-src]').forEach(img => {
                lazyImageObserver.observe(img);
            });
        }
    }

    initOfflineDetection() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.hideOfflineIndicator();
            this.syncOfflineData();
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.showOfflineIndicator();
        });

        // Initial check
        if (!this.isOnline) {
            this.showOfflineIndicator();
        }
    }

    initMobileOptimizations() {
        // Prevent double-tap zoom
        let lastTouchEnd = 0;

        document.addEventListener('touchend', (e) => {
            const now = Date.now();
            if (now - lastTouchEnd < 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        });

        // Optimize scrolling
        document.addEventListener('touchmove', (e) => {
            if (e.touches.length === 1) {
                // Smooth scrolling for single touch
                document.body.style.touchAction = 'pan-y';
            }
        }, { passive: true });

        // Handle viewport orientation changes
        window.addEventListener('orientationchange', () => {
            setTimeout(() => {
                this.adjustLayoutForOrientation();
            }, 100);
        });

        // Initialize viewport
        this.setViewport();
    }

    createRipple(x, y) {
        const ripple = document.createElement('div');
        ripple.className = 'ripple';
        ripple.style.left = x + 'px';
        ripple.style.top = y + 'px';

        document.body.appendChild(ripple);

        // Animate and remove
        setTimeout(() => {
            ripple.classList.add('animate');
            setTimeout(() => {
                document.body.removeChild(ripple);
            }, 600);
        }, 10);
    }

    showPullToRefresh() {
        const indicator = document.querySelector('#pull-to-refresh');
        if (indicator) {
            indicator.style.transform = 'translateY(0)';
            indicator.style.opacity = '1';
        }
    }

    triggerRefresh() {
        const indicator = document.querySelector('#pull-to-refresh');
        if (indicator) {
            indicator.style.transform = 'translateY(-100%)';
            indicator.style.opacity = '0';
        }

        // Trigger actual refresh
        window.location.reload();
    }

    showOfflineIndicator() {
        const indicator = document.querySelector('#offline-indicator');
        if (indicator) {
            indicator.classList.remove('hidden');
        }

        // Disable online-only features
        document.querySelectorAll('[data-online-only]').forEach(el => {
            el.style.display = 'none';
        });

        // Show offline-only features
        document.querySelectorAll('[data-offline-only]').forEach(el => {
            el.style.display = 'block';
        });
    }

    hideOfflineIndicator() {
        const indicator = document.querySelector('#offline-indicator');
        if (indicator) {
            indicator.classList.add('hidden');
        }

        // Enable online-only features
        document.querySelectorAll('[data-online-only]').forEach(el => {
            el.style.display = 'block';
        });

        // Hide offline-only features
        document.querySelectorAll('[data-offline-only]').forEach(el => {
            el.style.display = 'none';
        });
    }

    async syncOfflineData() {
        if (window.pwaManager) {
            try {
                await window.pwaManager.syncNow();
                this.showNotification('Data synced successfully', 'success');
            } catch (error) {
                this.showNotification('Sync failed: ' + error.message, 'error');
            }
        }
    }

    handleSwipeRight() {
        // Navigate to next page or action
        console.log('Swipe right detected');
        this.triggerHapticFeedback();
    }

    handleSwipeLeft() {
        // Navigate to previous page or action
        console.log('Swipe left detected');
        this.triggerHapticFeedback();
    }

    triggerHapticFeedback() {
        // Vibration feedback for touch devices
        if ('vibrate' in navigator) {
            navigator.vibrate(50);
        }
    }

    triggerHapticFeedback() {
        // More intense haptic feedback
        if ('vibrate' in navigator) {
            navigator.vibrate([100, 50, 100]);
        }
    }

    setViewport() {
        const viewport = document.querySelector('meta[name="viewport"]');
        if (viewport) {
            viewport.content = 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover';
        }
    }

    adjustLayoutForOrientation() {
        const orientation = window.orientation;
        const isLandscape = orientation === 90 || orientation === -90;

        // Adjust UI based on orientation
        document.body.classList.toggle('landscape', isLandscape);
        document.body.classList.toggle('portrait', !isLandscape);
    }

    showNotification(message, type = 'info') {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = `mobile-toast mobile-toast-${type}`;
        toast.innerHTML = `
            <div class="flex items-center space-x-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h1m-9 4h1m4 0h1m-1 4h1m-1 4v4m1-4h1m-9 4h1m4 0h1"></path>
                </svg>
                <span>${message}</span>
            </div>
        `;

        document.body.appendChild(toast);

        // Animate in
        setTimeout(() => toast.classList.add('show'), 100);

        // Remove after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => document.body.removeChild(toast), 300);
        }, 3000);
    }

    updateOfflineQueueUI(data) {
        const queueCount = document.querySelector('#offline-queue-count');
        if (queueCount) {
            queueCount.textContent = data.total || 0;
            queueCount.style.display = data.total > 0 ? 'block' : 'none';
        }
    }

    updateConnectionStatus(data) {
        const statusDot = document.querySelector('#connection-status-dot');
        const statusText = document.querySelector('#connection-status-text');

        if (statusDot && statusText) {
            if (data.isOnline) {
                statusDot.className = 'w-3 h-3 rounded-full bg-green-500';
                statusText.textContent = 'Online';
            } else {
                statusDot.className = 'w-3 h-3 rounded-full bg-red-500 animate-pulse';
                statusText.textContent = 'Offline';
            }
        }
    }

    // QR Scanner functionality
    initQRScanner() {
        const video = document.querySelector('#qr-video');
        const canvas = document.querySelector('#qr-canvas');

        if (!video || !canvas) return;

        const context = canvas.getContext('2d');
        let scanning = false;

        const startScanning = () => {
            scanning = true;
            this.scanQRCode(video, canvas, context);
        };

        const stopScanning = () => {
            scanning = false;
        };

        return { startScanning, stopScanning };
    }

    async scanQRCode(video, canvas, context) {
        if (!video.srcObject) return;

        // Draw video frame to canvas
        context.drawImage(video, 0, 0, canvas.width, canvas.height);

        // Use QR code scanning library (simplified example)
        try {
            // This would integrate with a QR scanning library like jsQR
            const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
            // const code = jsQR(imageData.data, imageData.width, imageData.height, {
            //     inversionAttempts: -1,
            // });

            // For demo purposes, simulate QR code detection
            if (Math.random() > 0.95) { // 5% chance to "find" QR code
                const simulatedQRData = {
                    type: 'meal_attendance',
                    user_id: '123',
                    meal_date: new Date().toISOString().split('T')[0],
                    meal_type: 'lunch'
                };

                this.handleQRCodeScanned(simulatedQRData);
            }
        } catch (error) {
            console.error('QR scanning error:', error);
        }

        // Continue scanning
        if (scanning) {
            requestAnimationFrame(() => this.scanQRCode(video, canvas, context));
        }
    }

    async handleQRCodeScanned(qrData) {
        try {
            // Send QR data to server
            const response = await fetch('/api/attendance/scan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    'Authorization': `Bearer ${this.getAuthToken()}`
                },
                body: JSON.stringify({
                    qr_code: JSON.stringify(qrData),
                    device_info: this.getDeviceInfo()
                })
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification('Attendance recorded successfully', 'success');
                this.triggerHapticFeedback();

                // Navigate to confirmation
                setTimeout(() => {
                    window.location.href = '/attendance/confirm/' + result.data.attendance.id;
                }, 1000);
            } else {
                this.showNotification(result.message || 'Failed to record attendance', 'error');
            }
        } catch (error) {
            console.error('QR scan error:', error);
            this.showNotification('Failed to process QR code', 'error');
        }
    }

    getDeviceInfo() {
        return {
            userAgent: navigator.userAgent,
            platform: navigator.platform,
            language: navigator.language,
            screen: {
                width: screen.width,
                height: screen.height
            },
            connection: navigator.connection ? {
                effectiveType: navigator.connection.effectiveType,
                downlink: navigator.connection.downlink,
                rtt: navigator.connection.rtt
            } : null
        };
    }

    getAuthToken() {
        return localStorage.getItem('auth_token') ||
            sessionStorage.getItem('auth_token') ||
            document.cookie.split(';')
                .find(cookie => cookie.trim().startsWith('auth_token='))
                ?.split('=')[1] ||
            '';
    }

    // Performance optimizations
    optimizeImages() {
        // Lazy load images
        document.querySelectorAll('img[data-src]').forEach(img => {
            img.loading = 'lazy';
        });

        // Optimize image sizes for mobile
        document.querySelectorAll('img').forEach(img => {
            if (img.naturalWidth > window.innerWidth) {
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
            }
        });
    }

    // Form optimizations
    optimizeForms() {
        document.querySelectorAll('form').forEach(form => {
            // Add touch-friendly submit buttons
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.style.minHeight = '44px';
                submitBtn.style.minWidth = '44px';
            }

            // Prevent zoom on input focus
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('focus', () => {
                    document.querySelector('meta[name="viewport"]').content =
                        'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no';
                });

                input.addEventListener('blur', () => {
                    document.querySelector('meta[name="viewport"]').content =
                        'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover';
                });
            });
        });
    }

    // Initialize mobile optimizations
    init() {
        this.optimizeImages();
        this.optimizeForms();

        // Add mobile-specific classes
        document.body.classList.add('mobile-optimized');

        // Handle safe area for notched devices
        if (CSS.supports('padding', 'safe')) {
            const safeArea = getComputedStyle(document.documentElement)
                .getPropertyValue('env(safe-area-inset-top)');

            if (safeArea && safeArea !== '0px') {
                document.documentElement.style.setProperty(
                    '--safe-area-inset-top',
                    safeArea
                );
            }
        }
    }
}

// Initialize mobile app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.mobileApp = new MobileApp();
});

// Export for global access
window.MobileApp = MobileApp;