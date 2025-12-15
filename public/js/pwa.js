class PWAManager {
    constructor() {
        this.isOnline = navigator.onLine;
        this.swRegistration = null;
        this.offlineQueue = {
            attendance: [],
            meals: [],
            payments: [],
            bazar: []
        };

        this.init();
    }

    async init() {
        // Register service worker
        await this.registerServiceWorker();

        // Initialize offline storage
        await this.initOfflineStorage();

        // Set up event listeners
        this.setupEventListeners();

        // Check for pending sync
        await this.checkPendingSync();

        // Initialize connection monitoring
        this.initConnectionMonitoring();

        console.log('PWA Manager initialized');
    }

    async registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            try {
                this.swRegistration = await navigator.serviceWorker.register('/sw.js', {
                    scope: '/'
                });

                console.log('Service Worker registered:', this.swRegistration);

                // Listen for updates
                this.swRegistration.addEventListener('updatefound', (event) => {
                    const newWorker = event.installing;
                    console.log('New service worker found:', newWorker);

                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            this.showUpdateNotification();
                        }
                    });
                });

                return this.swRegistration;
            } catch (error) {
                console.error('Service Worker registration failed:', error);
                return null;
            }
        }

        return null;
    }

    async initOfflineStorage() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('mess-manager-offline', 1);

            request.onerror = () => reject(request.error);

            request.onsuccess = (event) => {
                this.db = event.target.result;

                // Create object stores if they don't exist
                if (!this.db.objectStoreNames.contains('attendance')) {
                    this.db.createObjectStore('attendance', { keyPath: 'id', autoIncrement: true });
                }
                if (!this.db.objectStoreNames.contains('meals')) {
                    this.db.createObjectStore('meals', { keyPath: 'id', autoIncrement: true });
                }
                if (!this.db.objectStoreNames.contains('payments')) {
                    this.db.createObjectStore('payments', { keyPath: 'id', autoIncrement: true });
                }
                if (!this.db.objectStoreNames.contains('bazar')) {
                    this.db.createObjectStore('bazar', { keyPath: 'id', autoIncrement: true });
                }

                resolve();
            };
        });
    }

    setupEventListeners() {
        // Connection events
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.onConnectionChange(true);
            this.triggerSync();
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.onConnectionChange(false);
        });

        // Page visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.isOnline) {
                this.triggerSync();
            }
        });

        // Before page unload
        window.addEventListener('beforeunload', () => {
            this.saveCurrentState();
        });
    }

    initConnectionMonitoring() {
        // Monitor connection quality
        if ('connection' in navigator) {
            const connection = navigator.connection;

            connection.addEventListener('change', () => {
                console.log('Connection changed:', {
                    effectiveType: connection.effectiveType,
                    downlink: connection.downlink,
                    rtt: connection.rtt,
                    saveData: connection.saveData
                });

                this.adjustBehaviorBasedOnConnection(connection);
            });

            this.adjustBehaviorBasedOnConnection(connection);
        }
    }

    adjustBehaviorBasedOnConnection(connection) {
        // Adjust app behavior based on connection quality
        const isSlowConnection = connection.effectiveType === 'slow-2g' ||
            connection.effectiveType === '2g' ||
            connection.effectiveType === '3g';

        const isDataSaver = connection.saveData;

        // Emit custom event for other parts of the app
        window.dispatchEvent(new CustomEvent('connectionchange', {
            detail: {
                isSlow: isSlowConnection,
                isDataSaver: isDataSaver,
                downlink: connection.downlink,
                effectiveType: connection.effectiveType
            }
        }));
    }

    onConnectionChange(isOnline) {
        // Update UI elements
        const statusElements = document.querySelectorAll('[data-online-status]');
        statusElements.forEach(element => {
            if (isOnline) {
                element.textContent = element.dataset.onlineText || 'Online';
                element.classList.remove('text-red-500');
                element.classList.add('text-green-500');
            } else {
                element.textContent = element.dataset.offlineText || 'Offline';
                element.classList.remove('text-green-500');
                element.classList.add('text-red-500');
            }
        });

        // Show/hide online-only elements
        const onlineOnlyElements = document.querySelectorAll('[data-online-only]');
        onlineOnlyElements.forEach(element => {
            element.style.display = isOnline ? '' : 'none';
        });

        // Show/hide offline-only elements
        const offlineOnlyElements = document.querySelectorAll('[data-offline-only]');
        offlineOnlyElements.forEach(element => {
            element.style.display = !isOnline ? '' : 'none';
        });

        // Dispatch custom event
        window.dispatchEvent(new CustomEvent('pwa-connection-change', {
            detail: { isOnline }
        }));
    }

    async addToOfflineQueue(type, data) {
        const item = {
            id: Date.now() + Math.random(),
            timestamp: new Date().toISOString(),
            data: data,
            token: this.getAuthToken(),
            synced: false
        };

        this.offlineQueue[type].push(item);

        // Store in IndexedDB
        await this.storeOfflineData(type, item);

        // Update UI
        this.updateOfflineQueueUI();

        console.log('Added to offline queue:', type, item);
        return item;
    }

    async storeOfflineData(store, item) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([store], 'readwrite');
            const objectStore = transaction.objectStore(store);
            const request = objectStore.add(item);

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async getOfflineData(store) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([store], 'readonly');
            const objectStore = transaction.objectStore(store);
            const request = objectStore.getAll();

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async removeFromOfflineQueue(type, id) {
        // Remove from memory queue
        this.offlineQueue[type] = this.offlineQueue[type].filter(item => item.id !== id);

        // Remove from IndexedDB
        await this.removeOfflineData(type, id);

        // Update UI
        this.updateOfflineQueueUI();
    }

    async removeOfflineData(store, id) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([store], 'readwrite');
            const objectStore = transaction.objectStore(store);
            const request = objectStore.delete(id);

            request.onsuccess = () => resolve(true);
            request.onerror = () => reject(request.error);
        });
    }

    async triggerSync() {
        if (!this.isOnline || !this.swRegistration) {
            return;
        }

        console.log('Triggering background sync...');

        try {
            // Register background sync for different data types
            await this.swRegistration.sync.register('attendance-sync');
            await this.swRegistration.sync.register('meal-sync');
            await this.swRegistration.sync.register('payment-sync');
            await this.swRegistration.sync.register('bazar-sync');

            // Also try immediate sync if background sync is not supported
            if (!('sync' in window.ServiceWorkerRegistration.prototype)) {
                await this.immediateSync();
            }
        } catch (error) {
            console.error('Failed to trigger sync:', error);
        }
    }

    async immediateSync() {
        // Immediate sync for browsers that don't support background sync
        const syncPromises = [];

        Object.keys(this.offlineQueue).forEach(type => {
            if (this.offlineQueue[type].length > 0) {
                syncPromises.push(this.syncType(type));
            }
        });

        try {
            await Promise.all(syncPromises);
            this.showSyncSuccessNotification();
        } catch (error) {
            console.error('Immediate sync failed:', error);
            this.showSyncErrorNotification(error);
        }
    }

    async syncType(type) {
        const items = this.offlineQueue[type];
        if (items.length === 0) return;

        const syncPromises = items.map(item => {
            return this.syncItem(type, item);
        });

        const results = await Promise.allSettled(syncPromises);

        // Remove successfully synced items
        const successfulIds = results
            .filter(result => result.status === 'fulfilled')
            .map((result, index) => items[index].id);

        for (const id of successfulIds) {
            await this.removeFromOfflineQueue(type, id);
        }

        return results;
    }

    async syncItem(type, item) {
        const endpoints = {
            attendance: '/api/attendance',
            meals: '/api/meals',
            payments: '/api/payments',
            bazar: '/api/bazars'
        };

        try {
            const response = await fetch(endpoints[type], {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${item.token}`
                },
                body: JSON.stringify(item.data)
            });

            if (response.ok) {
                return { success: true, data: await response.json() };
            } else {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
        } catch (error) {
            return { success: false, error: error.message };
        }
    }

    async checkPendingSync() {
        Object.keys(this.offlineQueue).forEach(async type => {
            const items = await this.getOfflineData(type);
            this.offlineQueue[type] = items.filter(item => !item.synced);
        });

        this.updateOfflineQueueUI();
    }

    updateOfflineQueueUI() {
        const totalPending = Object.values(this.offlineQueue)
            .reduce((total, queue) => total + queue.length, 0);

        const badgeElements = document.querySelectorAll('[data-offline-badge]');
        badgeElements.forEach(element => {
            element.textContent = totalPending;
            element.style.display = totalPending > 0 ? '' : 'none';
        });

        const listElements = document.querySelectorAll('[data-offline-list]');
        listElements.forEach(element => {
            element.innerHTML = this.generateOfflineQueueHTML();
        });
    }

    generateOfflineQueueHTML() {
        let html = '';

        Object.entries(this.offlineQueue).forEach(([type, items]) => {
            if (items.length > 0) {
                html += `
                    <div class="mb-4">
                        <h4 class="font-semibold capitalize">${type}</h4>
                        <ul class="space-y-2">
                            ${items.map(item => `
                                <li class="flex items-center justify-between p-2 bg-gray-50 rounded">
                                    <span class="text-sm">${this.getItemDescription(type, item)}</span>
                                    <span class="text-xs text-gray-500">${new Date(item.timestamp).toLocaleTimeString()}</span>
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                `;
            }
        });

        return html || '<p class="text-gray-500">No pending items</p>';
    }

    getItemDescription(type, item) {
        const descriptions = {
            attendance: `Attendance for ${item.data.meal_type}`,
            meals: `${item.data.count} meal(s) for ${item.data.meal_date}`,
            payments: `Payment of ${item.data.amount}`,
            bazar: `Bazar: ${item.data.total_cost}`
        };

        return descriptions[type] || 'Unknown item';
    }

    getAuthToken() {
        // Try multiple storage methods for the auth token
        return localStorage.getItem('auth_token') ||
            sessionStorage.getItem('auth_token') ||
            document.cookie.split(';')
                .find(cookie => cookie.trim().startsWith('auth_token='))
                ?.split('=')[1] ||
            '';
    }

    showUpdateNotification() {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('App Update Available', {
                body: 'A new version of Mess Manager is available. Click to update.',
                icon: '/icons/icon-192x192.png',
                badge: '/icons/badge.png',
                tag: 'app-update',
                requireInteraction: true,
                actions: [
                    {
                        action: 'update',
                        title: 'Update Now'
                    },
                    {
                        action: 'later',
                        title: 'Later'
                    }
                ]
            }).onclick = (event) => {
                if (event.action === 'update') {
                    window.location.reload();
                }
            };
        }
    }

    showSyncSuccessNotification() {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('Sync Complete', {
                body: 'Your offline data has been successfully synced.',
                icon: '/icons/icon-192x192.png',
                badge: '/icons/badge.png',
                tag: 'sync-success'
            });
        }
    }

    showSyncErrorNotification(error) {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('Sync Failed', {
                body: `Failed to sync offline data: ${error}`,
                icon: '/icons/icon-192x192.png',
                badge: '/icons/badge.png',
                tag: 'sync-error'
            });
        }
    }

    saveCurrentState() {
        // Save current page state for potential restoration
        const state = {
            url: window.location.href,
            timestamp: new Date().toISOString(),
            scrollY: window.scrollY
        };

        sessionStorage.setItem('pwa-last-state', JSON.stringify(state));
    }

    async installPrompt() {
        // Show install prompt for PWA
        let deferredPrompt;

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;

            // Show custom install button
            const installButton = document.querySelector('[data-pwa-install]');
            if (installButton) {
                installButton.style.display = 'block';
                installButton.addEventListener('click', () => {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then((choiceResult) => {
                        if (choiceResult.outcome === 'accepted') {
                            console.log('PWA installed');
                            installButton.style.display = 'none';
                        }
                    });
                });
            }
        });
    }

    async requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            const permission = await Notification.requestPermission();
            return permission === 'granted';
        }

        return Notification.permission === 'granted';
    }

    shareContent(data) {
        if (navigator.share) {
            navigator.share({
                title: data.title || 'Mess Manager',
                text: data.text || '',
                url: data.url || window.location.href
            }).catch(error => {
                console.log('Share failed:', error);
            });
        } else {
            // Fallback - copy to clipboard
            if (navigator.clipboard) {
                navigator.clipboard.writeText(data.text || data.url || window.location.href);
                this.showToast('Content copied to clipboard');
            }
        }
    }

    showToast(message, type = 'info') {
        // Create or update toast notification
        const toast = document.createElement('div');
        toast.className = `fixed bottom-4 right-4 p-4 rounded-lg shadow-lg z-50 transform transition-all duration-300 ${type === 'success' ? 'bg-green-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                    type === 'warning' ? 'bg-yellow-500 text-white' :
                        'bg-blue-500 text-white'
            }`;
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
        setTimeout(() => toast.classList.add('translate-y-0'), 100);

        // Remove after 3 seconds
        setTimeout(() => {
            toast.classList.add('translate-y-full', 'opacity-0');
            setTimeout(() => document.body.removeChild(toast), 300);
        }, 3000);
    }

    // Public methods for external use
    async addToQueue(type, data) {
        return await this.addToOfflineQueue(type, data);
    }

    async syncNow() {
        return await this.triggerSync();
    }

    getConnectionStatus() {
        return {
            isOnline: this.isOnline,
            connection: navigator.connection || null
        };
    }
}

// Initialize PWA Manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.pwaManager = new PWAManager();
});

// Export for global access
window.PWAManager = PWAManager;