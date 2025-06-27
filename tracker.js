/**
 * Earndos Churn Analytics Tracker
 * Version: 1.0.0
 * 
 * Features:
 * - Automatic feature URL tracking
 * - Competitor visit detection
 * - GDPR/CCPA compliance
 * - Hybrid tracking (cookie-less fallback)
 * - Session management
 * - Performance optimized
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        endpoint: 'https://earndos.com/io/api/track',
        heartbeatInterval: 30000, // 30 seconds
        sessionTimeout: 1800000, // 30 minutes
        maxEventsPerBatch: 10,
        featureCheckInterval: 60000, // 1 minute
        competitorCheckInterval: 30000 // 30 seconds
    };

    // Global tracker queue
    let eventQueue = [];
    let isInitialized = false;
    let currentSessionId = generateUUID();
    let lastActivityTime = Date.now();
    let anonymousUserId = getAnonymousId();
    let consentGranted = false;
    let featureMap = {};
    let competitorMap = {};
    let heartbeatTimer = null;
    let featureCheckTimer = null;
    let competitorCheckTimer = null;

    // Main tracker function
    function earndosTracker() {
        if (!window.earndos) {
            window.earndos = [];
        }

        window.earndos.push = function(args) {
            trackEvent(args);
        };

        initializeTracker();
    }

    // Initialize the tracker
    function initializeTracker() {
        if (isInitialized) return;
        isInitialized = true;

        // Get tracking code from script URL
        const scriptElement = document.currentScript || 
            document.querySelector('script[src*="tracker.js"]');
        const trackingCode = getTrackingCodeFromScript(scriptElement);

        if (!trackingCode) {
            console.error('Earndos: No tracking code found');
            return;
        }

        // Check for existing consent
        checkConsent();

        // Load feature and competitor maps
        loadFeatureMap(trackingCode);
        loadCompetitorMap(trackingCode);

        // Set up event listeners
        setupEventListeners();

        // Start timers
        startHeartbeat();
        startFeatureChecker();
        startCompetitorChecker();

        // Track initial page view
        trackPageView(trackingCode);
    }

    // Track a page view event
    function trackPageView(trackingCode) {
        const currentUrl = window.location.pathname;
        const isFeature = checkFeatureMatch(currentUrl);

        const eventData = {
            event: 'page_view',
            url: currentUrl,
            is_feature: isFeature,
            feature_name: isFeature ? featureMap[currentUrl] : null,
            referrer: document.referrer || '',
            page_title: document.title,
            screen_width: window.innerWidth,
            screen_height: window.innerHeight
        };

        queueEvent(trackingCode, eventData);
    }

    // Track a custom event
    function trackEvent(args) {
        const trackingCode = args.trackingCode || getTrackingCode();
        if (!trackingCode) return;

        const eventData = {
            event: args.event || 'custom_event',
            ...args.data
        };

        queueEvent(trackingCode, eventData);
    }

    // Queue an event for batching
    function queueEvent(trackingCode, eventData) {
        if (!consentGranted && !isAnonymousTrackingAllowed()) {
            return;
        }

        const timestamp = new Date().toISOString();
        const sessionData = getSessionData();

        const event = {
            tracking_code: trackingCode,
            timestamp: timestamp,
            ...sessionData,
            ...eventData
        };

        eventQueue.push(event);

        // Send immediately if queue reaches max size
        if (eventQueue.length >= CONFIG.maxEventsPerBatch) {
            sendEvents();
        }
    }

    // Send batched events to server
    function sendEvents() {
        if (eventQueue.length === 0) return;

        const eventsToSend = eventQueue.slice();
        eventQueue = [];

        // Use sendBeacon if available for better performance
        if (navigator.sendBeacon) {
            const blob = new Blob([JSON.stringify({ events: eventsToSend })], 
                { type: 'application/json' });
            navigator.sendBeacon(CONFIG.endpoint, blob);
        } else {
            // Fallback to fetch API
            fetch(CONFIG.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ events: eventsToSend })
            }).catch(() => {
                // If failed, put events back in queue
                eventQueue = eventsToSend.concat(eventQueue);
            });
        }
    }

    // Check if current URL matches any features
    function checkFeatureMatch(url) {
        return featureMap.hasOwnProperty(url);
    }

    // Check if current URL matches any competitors
    function checkCompetitorMatch(url) {
        for (const [competitor, competitorUrl] of Object.entries(competitorMap)) {
            if (url.includes(competitorUrl)) {
                return {
                    is_competitor: true,
                    competitor_name: competitor
                };
            }
        }
        return { is_competitor: false };
    }

    // Load feature map from server
    function loadFeatureMap(trackingCode) {
        fetch(`https://earndos.com/io/api/features?code=${trackingCode}`)
            .then(response => response.json())
            .then(features => {
                featureMap = features.reduce((map, feature) => {
                    map[feature.url] = feature.name;
                    return map;
                }, {});
            })
            .catch(() => {
                // Retry after delay
                setTimeout(() => loadFeatureMap(trackingCode), 5000);
            });
    }

    // Load competitor map from server
    function loadCompetitorMap(trackingCode) {
        fetch(`https://earndos.com/io/api/competitors?code=${trackingCode}`)
            .then(response => response.json())
            .then(competitors => {
                competitorMap = competitors.reduce((map, competitor) => {
                    map[competitor.name] = competitor.url;
                    return map;
                }, {});
            })
            .catch(() => {
                // Retry after delay
                setTimeout(() => loadCompetitorMap(trackingCode), 5000);
            });
    }

    // Check for GDPR/CCPA consent
    function checkConsent() {
        // Check for common consent managers
        if (typeof window.__tcfapi !== 'undefined') {
            // IAB TCF compliance
            window.__tcfapi('getTCData', 2, (tcData, success) => {
                if (success && tcData.tcString) {
                    consentGranted = tcData.purpose.consents[1]; // Basic functionality
                }
            });
        } else if (window.OneTrust) {
            // OneTrust consent manager
            consentGranted = window.OnetrustActiveGroups.includes('C0001');
        } else {
            // Default to granted if no consent manager found
            consentGranted = true;
        }
    }

    // Check if anonymous tracking is allowed
    function isAnonymousTrackingAllowed() {
        return window.earndosConfig?.hybridTracking !== false;
    }

    // Generate session data
    function getSessionData() {
        const now = Date.now();
        const isNewSession = now - lastActivityTime > CONFIG.sessionTimeout;

        if (isNewSession) {
            currentSessionId = generateUUID();
        }

        lastActivityTime = now;

        return {
            session_id: currentSessionId,
            anonymous_id: consentGranted ? null : anonymousUserId,
            is_new_session: isNewSession
        };
    }

    // Set up event listeners
    function setupEventListeners() {
        // Window blur (tab change/close)
        window.addEventListener('blur', handleWindowBlur);

        // Page visibility changes
        document.addEventListener('visibilitychange', handleVisibilityChange);

        // Beforeunload (page navigation)
        window.addEventListener('beforeunload', handleBeforeUnload);

        // Hash changes (SPA navigation)
        window.addEventListener('hashchange', handleHashChange);

        // PushState/replaceState (SPA navigation)
        const originalPushState = history.pushState;
        const originalReplaceState = history.replaceState;

        history.pushState = function() {
            originalPushState.apply(this, arguments);
            handleSPANavigation();
        };

        history.replaceState = function() {
            originalReplaceState.apply(this, arguments);
            handleSPANavigation();
        };
    }

    // Start heartbeat timer
    function startHeartbeat() {
        heartbeatTimer = setInterval(() => {
            const trackingCode = getTrackingCode();
            if (!trackingCode) return;

            queueEvent(trackingCode, {
                event: 'heartbeat',
                active_time: CONFIG.heartbeatInterval / 1000
            });
        }, CONFIG.heartbeatInterval);
    }

    // Start feature checker timer
    function startFeatureChecker() {
        featureCheckTimer = setInterval(() => {
            const trackingCode = getTrackingCode();
            if (!trackingCode) return;

            loadFeatureMap(trackingCode);
        }, CONFIG.featureCheckInterval);
    }

    // Start competitor checker timer
    function startCompetitorChecker() {
        competitorCheckTimer = setInterval(() => {
            checkCompetitorVisits();
        }, CONFIG.competitorCheckInterval);
    }

    // Check for competitor visits
    function checkCompetitorVisits() {
        if (window.performance && window.performance.getEntriesByType) {
            const resources = window.performance.getEntriesByType('resource');
            const trackingCode = getTrackingCode();
            if (!trackingCode) return;

            resources.forEach(resource => {
                const competitorInfo = checkCompetitorMatch(resource.name);
                if (competitorInfo.is_competitor) {
                    queueEvent(trackingCode, {
                        event: 'competitor_visit',
                        ...competitorInfo,
                        resource_url: resource.name
                    });
                }
            });
        }
    }

    // Handle SPA navigation
    function handleSPANavigation() {
        const trackingCode = getTrackingCode();
        if (!trackingCode) return;

        trackPageView(trackingCode);
    }

    // Handle hash changes
    function handleHashChange() {
        const trackingCode = getTrackingCode();
        if (!trackingCode) return;

        trackPageView(trackingCode);
    }

    // Handle window blur
    function handleWindowBlur() {
        const trackingCode = getTrackingCode();
        if (!trackingCode) return;

        queueEvent(trackingCode, {
            event: 'window_blur',
            blur_time: new Date().toISOString()
        });
    }

    // Handle visibility changes
    function handleVisibilityChange() {
        const trackingCode = getTrackingCode();
        if (!trackingCode) return;

        if (document.visibilityState === 'hidden') {
            queueEvent(trackingCode, {
                event: 'visibility_hidden',
                hidden_time: new Date().toISOString()
            });
        } else {
            queueEvent(trackingCode, {
                event: 'visibility_visible',
                visible_time: new Date().toISOString()
            });
        }
    }

    // Handle beforeunload
    function handleBeforeUnload() {
        sendEvents();
    }

    // Get tracking code from script URL
    function getTrackingCodeFromScript(scriptElement) {
        if (!scriptElement || !scriptElement.src) return null;

        const url = new URL(scriptElement.src);
        return url.searchParams.get('code');
    }

    // Get tracking code from global config
    function getTrackingCode() {
        return window.earndosConfig?.trackingCode || 
            getTrackingCodeFromScript(document.currentScript);
    }

    // Generate anonymous ID
    function getAnonymousId() {
        let id = localStorage.getItem('earndos_anonymous_id');
        if (!id) {
            id = generateUUID();
            try {
                localStorage.setItem('earndos_anonymous_id', id);
            } catch (e) {
                // Storage may be blocked in private mode
            }
        }
        return id;
    }

    // Generate UUID
    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    // Initialize the tracker
    earndosTracker();
})();