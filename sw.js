// sw.js - Service Worker для фоновых уведомлений (С ПОДДЕРЖКОЙ PUSH API)
// Версия: 3.1.0 (Умный клик по уведомлению: прокрутка без перезагрузки)
const CACHE_NAME = 'msgql-cache-v2';

// ==================== НАСТРОЙКИ ЛОГИРОВАНИЯ ====================
let logLevel = 1; // 0 - выкл, 1 - только ошибки, 2 - всё

function swLog(level, message, data) {
    if (logLevel === 0) return;
    if (logLevel === 1 && level !== 'error') return;
    const prefix = level === 'error' ? '❌' : (level === 'warn' ? '⚠️' : '📢');
    const timestamp = new Date().toISOString();
    if (data !== undefined) {
        console.log(`${prefix} [SW ${timestamp}] ${message}`, data);
    } else {
        console.log(`${prefix} [SW ${timestamp}] ${message}`);
    }
}

// ==================== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ====================
self.soundEnabled = true;

// Получение названия системы
async function getSystemTitle() {
    try {
        const clients = await self.clients.matchAll({ type: 'window' });
        for (const client of clients) {
            try {
                return new Promise((resolve) => {
                    const channel = new MessageChannel();
                    channel.port1.onmessage = (event) => {
                        if (event.data && event.data.systemTitle) {
                            resolve(event.data.systemTitle);
                        } else {
                            resolve('ЗадаЧат');
                        }
                    };
                    client.postMessage({ type: 'getSystemTitle' }, [channel.port2]);
                    setTimeout(() => resolve('ЗадаЧат'), 500);
                });
            } catch(e) {
                swLog('warn', 'Error getting title from client: ' + e.message);
            }
        }
    } catch(e) {
        swLog('warn', 'Error getting clients: ' + e.message);
    }
    return 'ЗадаЧат';
}

// Функция для получения правильного пути к иконке
function getIconUrl(appBase, iconPath) {
    if (iconPath && (iconPath.startsWith('http://') || iconPath.startsWith('https://') || iconPath.startsWith('/'))) {
        return iconPath;
    }
    if (iconPath) {
        return (appBase || '') + '/' + iconPath.replace(/^\/+/, '');
    }
    return '/favicon.ico';
}

// ==================== ЗВУК И КЭШ ====================
async function getLastSoundTimeFromCache() {
    try {
        const cache = await caches.open('msgql-sound-v1');
        const response = await cache.match('/sound-data');
        if (response && response.ok) {
            const data = await response.json();
            return data.lastSoundTime || 0;
        }
    } catch(e) {
        swLog('warn', 'Failed to read sound data from cache: ' + e.message);
    }
    return 0;
}

async function saveLastSoundTimeToCache(time) {
    try {
        const cache = await caches.open('msgql-sound-v1');
        const data = { lastSoundTime: time, updatedAt: Date.now() };
        const response = new Response(JSON.stringify(data), {
            headers: { 'Content-Type': 'application/json' }
        });
        await cache.put('/sound-data', response);
        swLog('debug', 'Saved lastSoundTime to cache');
    } catch(e) {
        swLog('warn', 'Failed to save sound data to cache: ' + e.message);
    }
}

async function canPlaySoundInSW() {
    if (!self.soundEnabled) {
        swLog('debug', 'Sound disabled by user setting');
        return false;
    }
    const lastSoundTime = await getLastSoundTimeFromCache();
    const now = Date.now();
    if (now - lastSoundTime < self.soundIntervalSec * 1000) {
        swLog('debug', 'Sound interval limit');
        return false;
    }
    await saveLastSoundTimeToCache(now);
    swLog('debug', 'Sound allowed');
    return true;
}

// ==================== УСТАНОВКА И АКТИВАЦИЯ ====================
self.addEventListener('install', function(event) {
    swLog('info', 'Installing Service Worker v3.1.0');
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    swLog('info', 'Activating Service Worker v3.1.0');
    event.waitUntil(
        self.clients.claim().then(function() {
            swLog('debug', 'Clients claimed successfully');
            startHeartbeat();
            return self.clients.matchAll({ type: 'window' });
        }).then(function(clients) {
            swLog('debug', 'Controlling ' + clients.length + ' clients');
            for (var i = 0; i < clients.length; i++) {
                clients[i].postMessage({
                    type: 'sw-activated',
                    timestamp: Date.now(),
                    version: '3.1.0'
                });
            }
        })
    );
});

// ==================== ПЕРИОДИЧЕСКИЙ HEARTBEAT (KEEP-ALIVE) ====================
let heartbeatInterval = null;

async function startHeartbeat() {
    if (heartbeatInterval) {
        clearInterval(heartbeatInterval);
    }
    
    heartbeatInterval = setInterval(async () => {
        try {
            const clients = await self.clients.matchAll({ type: 'window' });
            const clientsCount = clients.length;
            
            if (clientsCount > 0 || Math.random() < 0.1) {
                swLog('debug', `❤️ Heartbeat - SW alive, ${clientsCount} clients connected`);
            }
            
            if (clientsCount === 0) {
                await syncWithServer();
            }
        } catch(e) {
            swLog('warn', 'Heartbeat error: ' + e.message);
        }
    }, 20000);
}

function stopHeartbeat() {
    if (heartbeatInterval) {
        clearInterval(heartbeatInterval);
        heartbeatInterval = null;
    }
}

// ==================== ОБРАБОТКА СООБЩЕНИЙ ОТ КЛИЕНТА ====================
self.addEventListener('message', function(event) {
    swLog('debug', 'Received message from client:', event.data);
    
    if (event.data && event.data.type === 'updateLogLevel') {
        const newLevel = parseInt(event.data.logLevel, 10);
        if (!isNaN(newLevel) && newLevel >= 0 && newLevel <= 2) {
            logLevel = newLevel;
            swLog('info', `Log level updated to ${logLevel}`);
        }
        if (event.ports && event.ports[0]) {
            event.ports[0].postMessage({ type: 'logLevelUpdated', logLevel: logLevel });
        }
        return;
    }

    if (event.data && event.data.type === 'getSystemTitle') {
        swLog('debug', 'getSystemTitle request received');
        if (event.ports && event.ports[0]) {
            event.ports[0].postMessage({ 
                systemTitle: event.data.systemTitle || 'ЗадаЧат',
                timestamp: Date.now()
            });
        }
        return;
    }

    if (event.data && event.data.type === 'updateSoundSettings') {
        swLog('debug', 'Updating sound settings:', event.data);
        self.soundEnabled = event.data.soundEnabled;
        self.soundIntervalSec = event.data.soundIntervalSec;
        
        if (event.data.lastSoundTime) {
            saveLastSoundTimeToCache(event.data.lastSoundTime);
        }
        
        if (event.ports && event.ports[0]) {
            event.ports[0].postMessage({ 
                type: 'soundSettingsUpdated',
                soundEnabled: self.soundEnabled,
                soundIntervalSec: self.soundIntervalSec
            });
        }
        return;
    }

    if (event.data && event.data.type === 'showNotification') {
        const appBase = event.data.appBase || '';
        const iconUrl = getIconUrl(appBase, event.data.icon || 'favicon.ico');
        const badgeUrl = getIconUrl(appBase, event.data.badge || 'favicon.ico');
        
        const options = {
            body: event.data.body || 'Новое сообщение',
            icon: iconUrl,
            badge: badgeUrl,
            vibrate: [200, 100, 200],
            tag: event.data.tag || 'msgql-notification',
            requireInteraction: false,
            data: {
                url: event.data.url || 'messages.php',
                type: event.data.notificationType || 'message',
                id: event.data.id || null,
                messageUuid: event.data.messageUuid || event.data.id,
                taskUuid: event.data.taskUuid,
                appBase: appBase
            },
            actions: [
                { action: 'open', title: 'Открыть' },
                { action: 'dismiss', title: 'Закрыть' }
            ]
        };
        
        const title = event.data.title || event.data.systemTitle || 'ЗадаЧат';
        
        event.waitUntil(
            self.registration.showNotification(title, options)
        );
    }
});

// ==================== PUSH-СОБЫТИЯ ====================
self.addEventListener('push', function(event) {
    swLog('debug', '========== PUSH EVENT RECEIVED ==========');
    
    event.waitUntil(
        (async function() {
            let data = {
                title: 'Новое уведомление',
                body: 'У вас новое сообщение',
                icon: '/favicon.ico',
                badge: '/favicon.ico',
                url: 'messages.php',
                tag: 'msgql-push-' + Date.now(),
                notificationType: 'message',
                id: null,
                taskUuid: null,
                messageUuid: null,
                appBase: ''
            };
            
            try {
                if (event.data) {
                    let parsedData;
                    try {
                        parsedData = await event.data.json();
                    } catch(e) {
                        const text = await event.data.text();
                        swLog('debug', 'Push data as text:', text);
                        try {
                            parsedData = JSON.parse(text);
                        } catch(e2) {
                            parsedData = { body: text };
                        }
                    }
                    swLog('debug', 'Parsed push data:', parsedData);
                    data = { ...data, ...parsedData };
                }
            } catch(e) {
                swLog('warn', 'Could not parse push data:', e.message);
            }
             
            const systemTitle = await getSystemTitle();
            const shouldPlaySound = await canPlaySoundInSW();
            
            const appBase = data.appBase || ''; 
            const iconUrl = getIconUrl(appBase, data.icon);
            const badgeUrl = getIconUrl(appBase, data.badge);
            
            swLog('debug', 'AppBase:', appBase);
            swLog('debug', 'Icon URL:', iconUrl);
            swLog('debug', 'Badge URL:', badgeUrl);
            
            const options = {
                body: data.body || 'Новое сообщение',
                icon: iconUrl,
                badge: badgeUrl,
                vibrate: [200, 100, 200],
                tag: data.tag || 'msgql-push',
                requireInteraction: false,
                renotify: true,
                silent: false,
                data: {
                    url: data.url || 'messages.php',
                    type: data.notificationType || 'message',
                    id: data.id || data.messageUuid,
                    messageUuid: data.messageUuid,
                    taskUuid: data.taskUuid,
                    appBase: appBase
                },
                actions: [
                    { action: 'open', title: '📖 Открыть' },
                    { action: 'dismiss', title: '❌ Закрыть' }
                ]
            };
            
            const title = data.title || systemTitle;
            swLog('debug', 'Showing notification - title:', title);
            
            return self.registration.showNotification(title, options);
        })()
    );
});

// ==================== КЛИК ПО УВЕДОМЛЕНИЮ (УМНЫЙ КЛИК v2.0) ====================
self.addEventListener('notificationclick', function(event) {
    swLog('debug', '========== SMART NOTIFICATION CLICK ==========');
    event.notification.close();

    const notificationData = event.notification.data || {};
    const action = event.action;
    const messageUuid = notificationData.messageUuid;
    const taskUuid = notificationData.taskUuid;
    const appBase = notificationData.appBase || '';

    if (action === 'dismiss') {
        swLog('debug', 'Dismiss action, closing');
        return;
    }

    swLog('debug', 'Notification data:', {
        taskUuid: taskUuid,
        messageUuid: messageUuid,
        type: notificationData.type,
        appBase: appBase
    });

    // Формируем целевой URL
    let targetUrl = notificationData.url || 'messages.php';
    if (notificationData.type === 'message' && messageUuid) {
        targetUrl = `messages.php?message=${encodeURIComponent(messageUuid)}`;
    } else if (notificationData.type === 'task' && taskUuid) {
        targetUrl = `projects.php?task=${encodeURIComponent(taskUuid)}`;
    } else if (notificationData.type === 'file' && notificationData.id) {
        targetUrl = `file_preview.php?uuid=${encodeURIComponent(notificationData.id)}`;
    } else if (taskUuid) {
        targetUrl = `messages.php?task=${encodeURIComponent(taskUuid)}`;
    } else if (messageUuid) {
        targetUrl = `messages.php?message=${encodeURIComponent(messageUuid)}`;
    }

    const finalUrl = appBase ? appBase + '/' + targetUrl.replace(/^\//, '') : targetUrl;
    swLog('debug', 'Target URL: ' + finalUrl);

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(async function(clientList) {
                swLog('debug', 'Found ' + clientList.length + ' clients');
                
                // ========== ПОИСК КЛИЕНТА С ОТКРЫТЫМ ЧАТОМ ЭТОЙ ЖЕ ЗАДАЧИ ==========
                let existingChatClient = null;
                
                for (let i = 0; i < clientList.length; i++) {
                    const client = clientList[i];
                    const clientUrl = client.url;
                    
                    // Проверяем, открыта ли страница messages.php с этой же задачей
                    if (clientUrl && clientUrl.includes('/messages.php') && taskUuid && clientUrl.includes('task=' + taskUuid)) {
                        existingChatClient = client;
                        swLog('debug', 'Found existing chat client for task: ' + taskUuid);
                        break;
                    }
                }
                
                if (existingChatClient) {
                    // ✅ Чат уже открыт — отправляем команду на прокрутку
                    swLog('debug', 'Chat already open, sending scroll-to-bottom command');
                    
                    try {
                        existingChatClient.postMessage({
                            type: 'scroll-to-bottom',
                            taskUuid: taskUuid,
                            messageUuid: messageUuid,
                            url: finalUrl,
                            timestamp: Date.now()
                        });
                        swLog('debug', '✅ Scroll command sent');
                    } catch(e) {
                        swLog('warn', 'Failed to send scroll command: ' + e.message);
                        // Fallback: перенаправляем
                        existingChatClient.navigate(finalUrl);
                    }
                    
                    existingChatClient.focus();
                    return;
                }
                
                // ========== ЧАТ НЕ ОТКРЫТ — ищем любой клиент ==========
                for (let i = 0; i < clientList.length; i++) {
                    const client = clientList[i];
                    try {
                        client.postMessage({
                            type: 'notification-click',
                            url: finalUrl,
                            taskUuid: taskUuid,
                            messageUuid: messageUuid,
                            timestamp: Date.now()
                        });
                        swLog('debug', 'PostMessage sent to client ' + i);
                        
                        if (client.focus) {
                            client.focus();
                            return;
                        }
                    } catch(e) {
                        swLog('warn', 'PostMessage failed: ' + e.message);
                    }
                }
                
                // ========== НЕТ НИ ОДНОГО КЛИЕНТА — ОТКРЫВАЕМ НОВОЕ ОКНО ==========
                if (self.clients.openWindow) {
                    swLog('debug', 'Opening new window with URL: ' + finalUrl);
                    return self.clients.openWindow(finalUrl);
                }
            })
    );
});

self.addEventListener('notificationclose', function(event) {
    swLog('debug', 'Notification closed');
});

// ==================== ФОНОВАЯ СИНХРОНИЗАЦИЯ ====================
self.addEventListener('sync', function(event) {
    swLog('debug', 'Background sync: ' + event.tag);
    if (event.tag === 'msgql-sync') {
        event.waitUntil(syncWithServer());
    }
});

async function syncWithServer() {
    try {
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
            client.postMessage({
                type: 'sync-complete',
                timestamp: Date.now()
            });
        });
        swLog('debug', 'Sync completed');
    } catch(e) {
        swLog('error', 'Sync error: ' + e.message);
    }
}

// ==================== КЭШИРОВАНИЕ ДЛЯ ОФЛАЙН-РЕЖИМА ====================
self.addEventListener('fetch', function(event) {
    const url = event.request.url;
    if (url.includes('sse.php') ||
        url.includes('dashboard_data.php') ||
        url.includes('polling_data.php')) {
        return;
    }
    event.respondWith(
        fetch(event.request).catch(function() {
            return caches.match(event.request).then(function(response) {
                if (response) {
                    return response;
                }
                if (event.request.mode === 'navigate') {
                    return caches.match('offline.html');
                }
                return new Response('Нет соединения', {
                    status: 503,
                    statusText: 'Service Unavailable'
                });
            });
        })
    );
});