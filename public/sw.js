const CACHE_NAME = 'mess-manager-v1';
const RUNTIME_CACHE = 'mess-manager-runtime-v1';
const DATA_CACHE = 'mess-manager-data-v1';

// Files to cache for offline functionality
const STATIC_CACHE_URLS = [
    '/',
    '/dashboard',
    '/meals',
    '/attendance/scan',
    '/payments',
    '/bazar',
    '/inventory',
    '/announcements',
    '/settings',
    '/manifest.json',
    '/css/app.css',
    '/js/app.js',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/offline.html'
];

// API endpoints to cache for offline functionality
const API_CACHE_URLS = [
    '/api/user',
    '/api/dashboard/member',
    '/api/meals/today',
    '/api/attendance/today',
    '/api/announcements',
    '/api/settings'
];

// Install event - cache static files
self.addEventListener('install', (event) => {
    console.log('Service Worker: Installing...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('Service Worker: Caching static files');
                return cache.addAll(STATIC_CACHE_URLS);
            })
            .then(() => {
                console.log('Service Worker: Installation complete');
                return self.skipWaiting();
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('Service Worker: Activating...');

    event.waitUntil(
        Promise.all([
            // Clean up old caches
            caches.keys().then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheName !== CACHE_NAME &&
                            cacheName !== RUNTIME_CACHE &&
                            cacheName !== DATA_CACHE) {
                            console.log('Service Worker: Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
        ]).then(() => {
            console.log('Service Worker: Activation complete');
            return self.clients.claim();
        })
    );
});

// Fetch event - handle network requests
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Skip non-GET requests and external requests
    if (request.method !== 'GET' || url.origin !== location.origin) {
        return;
    }

    // Handle different request types
    if (isAPIRequest(url)) {
        event.respondWith(handleAPIRequest(request));
    } else if (isStaticRequest(url)) {
        event.respondWith(handleStaticRequest(request));
    } else {
        event.respondWith(handleNetworkRequest(request));
    }
});

// Handle API requests with offline support
function handleAPIRequest(request) {
    return caches.open(DATA_CACHE).then((cache) => {
        return cache.match(request).then((response) => {
            // Return cached response if available
            if (response) {
                // Check if cache is stale (older than 5 minutes)
                const cacheTime = response.headers.get('sw-cache-time');
                if (cacheTime && (Date.now() - parseInt(cacheTime)) < 300000) {
                    return response;
                }
            }

            // Try network first for API requests
            return fetch(request).then((networkResponse) => {
                if (networkResponse.ok) {
                    // Cache successful network response
                    const responseToCache = networkResponse.clone();
                    responseToCache.headers.set('sw-cache-time', Date.now().toString());
                    cache.put(request, responseToCache);
                    return networkResponse;
                } else {
                    // Return cached response if network fails
                    return cache.match(request);
                }
            }).catch(() => {
                // Network failed, try cache
                return cache.match(request);
            });
        });
    });
}

// Handle static file requests
function handleStaticRequest(request) {
    return caches.open(CACHE_NAME).then((cache) => {
        return cache.match(request).then((response) => {
            if (response) {
                return response;
            }

            // Try network for static files
            return fetch(request).then((networkResponse) => {
                if (networkResponse.ok) {
                    // Cache successful response
                    cache.put(request, networkResponse.clone());
                    return networkResponse;
                }

                // Return offline page for HTML requests
                if (request.destination === 'document') {
                    return caches.match('/offline.html');
                }

                return new Response('Offline', { status: 503 });
            }).catch(() => {
                // Network failed, return cached version or offline page
                if (request.destination === 'document') {
                    return caches.match('/offline.html');
                }
                return cache.match(request);
            });
        });
    });
}

// Handle general network requests
function handleNetworkRequest(request) {
    return caches.open(RUNTIME_CACHE).then((cache) => {
        return cache.match(request).then((response) => {
            if (response) {
                return response;
            }

            // Try network
            return fetch(request).then((networkResponse) => {
                if (networkResponse.ok) {
                    // Cache successful response
                    cache.put(request, networkResponse.clone());
                    return networkResponse;
                }

                return networkResponse;
            }).catch(() => {
                // Network failed, try cache
                return cache.match(request);
            });
        });
    });
}

// Check if request is for API
function isAPIRequest(url) {
    return API_CACHE_URLS.some(apiUrl => url.pathname.startsWith(apiUrl));
}

// Check if request is for static file
function isStaticRequest(url) {
    return STATIC_CACHE_URLS.some(staticUrl => url.pathname === staticUrl) ||
        url.pathname.includes('/css/') ||
        url.pathname.includes('/js/') ||
        url.pathname.includes('/icons/') ||
        url.pathname.includes('/images/');
}

// Background sync for offline actions
self.addEventListener('sync', (event) => {
    console.log('Service Worker: Background sync triggered', event.tag);

    if (event.tag === 'attendance-sync') {
        event.waitUntil(syncAttendanceData());
    } else if (event.tag === 'meal-sync') {
        event.waitUntil(syncMealData());
    } else if (event.tag === 'payment-sync') {
        event.waitUntil(syncPaymentData());
    }
});

// Sync attendance data when back online
function syncAttendanceData() {
    return getOfflineData('attendance').then((attendanceData) => {
        if (!attendanceData || attendanceData.length === 0) {
            return Promise.resolve();
        }

        const syncPromises = attendanceData.map((attendance) => {
            return fetch('/api/attendance', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${attendance.token}`
                },
                body: JSON.stringify(attendance.data)
            }).then((response) => {
                if (response.ok) {
                    return removeOfflineData('attendance', attendance.id);
                }
                throw new Error('Failed to sync attendance');
            });
        });

        return Promise.all(syncPromises);
    });
}

// Sync meal data when back online
function syncMealData() {
    return getOfflineData('meals').then((mealData) => {
        if (!mealData || mealData.length === 0) {
            return Promise.resolve();
        }

        const syncPromises = mealData.map((meal) => {
            return fetch('/api/meals', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${meal.token}`
                },
                body: JSON.stringify(meal.data)
            }).then((response) => {
                if (response.ok) {
                    return removeOfflineData('meals', meal.id);
                }
                throw new Error('Failed to sync meal');
            });
        });

        return Promise.all(syncPromises);
    });
}

// Sync payment data when back online
function syncPaymentData() {
    return getOfflineData('payments').then((paymentData) => {
        if (!paymentData || paymentData.length === 0) {
            return Promise.resolve();
        }

        const syncPromises = paymentData.map((payment) => {
            return fetch('/api/payments', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${payment.token}`
                },
                body: JSON.stringify(payment.data)
            }).then((response) => {
                if (response.ok) {
                    return removeOfflineData('payments', payment.id);
                }
                throw new Error('Failed to sync payment');
            });
        });

        return Promise.all(syncPromises);
    });
}

// Push notification handling
self.addEventListener('push', (event) => {
    console.log('Service Worker: Push notification received', event);

    const options = {
        body: event.data ? event.data.text() : 'New notification',
        icon: '/icons/icon-192x192.png',
        badge: '/icons/badge.png',
        vibrate: [200, 100, 200],
        data: {
            url: event.data ? event.data.text() : '/dashboard'
        },
        actions: [
            {
                action: 'view',
                title: 'View'
            },
            {
                action: 'dismiss',
                title: 'Dismiss'
            }
        ]
    };

    event.waitUntil(
        self.registration.showNotification('Mess Manager', options)
    );
});

// Notification click handling
self.addEventListener('notificationclick', (event) => {
    console.log('Service Worker: Notification clicked', event);

    event.notification.close();

    if (event.action === 'view') {
        event.waitUntil(
            clients.openWindow(event.notification.data.url || '/dashboard')
        );
    } else if (event.action === 'dismiss') {
        // Just close the notification
    } else {
        // Default action - open the app
        event.waitUntil(
            clients.openWindow('/dashboard')
        );
    }
});

// Periodic background sync for data updates
self.addEventListener('periodicsync', (event) => {
    console.log('Service Worker: Periodic sync triggered', event.tag);

    if (event.tag === 'data-refresh') {
        event.waitUntil(refreshCachedData());
    }
});

// Refresh cached data periodically
function refreshCachedData() {
    const refreshPromises = API_CACHE_URLS.map(apiUrl => {
        return fetch(apiUrl, {
            headers: {
                'Authorization': `Bearer ${getAuthToken()}`
            }
        }).then((response) => {
            if (response.ok) {
                return caches.open(DATA_CACHE).then((cache) => {
                    const responseToCache = response.clone();
                    responseToCache.headers.set('sw-cache-time', Date.now().toString());
                    return cache.put(new Request(apiUrl), responseToCache);
                });
            }
        }).catch((error) => {
            console.log('Failed to refresh', apiUrl, error);
        });
    });

    return Promise.all(refreshPromises);
}

// Helper functions for offline storage
function getOfflineData(store) {
    return new Promise((resolve) => {
        const request = indexedDB.open('mess-manager-offline', 1);

        request.onsuccess = (event) => {
            const db = event.target.result;
            const transaction = db.transaction([store], 'readonly');
            const objectStore = transaction.objectStore(store);
            const getRequest = objectStore.getAll();

            getRequest.onsuccess = () => resolve(getRequest.result);
            getRequest.onerror = () => resolve([]);
        };

        request.onerror = () => resolve([]);
    });
}

function removeOfflineData(store, id) {
    return new Promise((resolve) => {
        const request = indexedDB.open('mess-manager-offline', 1);

        request.onsuccess = (event) => {
            const db = event.target.result;
            const transaction = db.transaction([store], 'readwrite');
            const objectStore = transaction.objectStore(store);
            const deleteRequest = objectStore.delete(id);

            deleteRequest.onsuccess = () => resolve(true);
            deleteRequest.onerror = () => resolve(false);
        };

        request.onerror = () => resolve(false);
    });
}

function getAuthToken() {
    // This should be implemented based on your auth storage strategy
    return localStorage.getItem('auth_token') || '';
}

// Message handling from main app
self.addEventListener('message', (event) => {
    console.log('Service Worker: Message received', event.data);

    if (event.data.type === 'CACHE_URLS') {
        // Cache specific URLs requested by app
        event.waitUntil(
            caches.open(CACHE_NAME).then((cache) => {
                return cache.addAll(event.data.urls);
            })
        );
    } else if (event.data.type === 'SKIP_WAITING') {
        // Force the new service worker to become active
        self.skipWaiting();
    }
});

console.log('Service Worker: Loaded and ready');