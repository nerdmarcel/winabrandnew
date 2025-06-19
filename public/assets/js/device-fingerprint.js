/**
 * File: public/assets/js/device-fingerprint.js
 * Location: public/assets/js/device-fingerprint.js
 *
 * WinABN Device Fingerprinting - Client-side Component
 *
 * Collects browser and device characteristics to create a unique
 * fingerprint for fraud prevention and user tracking.
 *
 * @package WinABN
 * @author WinABN Development Team
 * @version 1.0
 */

class DeviceFingerprint {
    constructor() {
        this.fingerprint = null;
        this.components = {};
        this.initialized = false;
    }

    /**
     * Initialize and generate device fingerprint
     *
     * @returns {Promise<string>} Device fingerprint hash
     */
    async generate() {
        if (this.initialized && this.fingerprint) {
            return this.fingerprint;
        }

        try {
            // Collect all fingerprint components
            await this.collectBasicInfo();
            await this.collectScreenInfo();
            await this.collectBrowserInfo();
            await this.collectHardwareInfo();
            await this.collectCanvasFingerprint();
            await this.collectWebGLFingerprint();
            await this.collectAudioFingerprint();
            await this.collectFontFingerprint();
            await this.collectPluginInfo();
            await this.collectTimezoneInfo();
            await this.collectLanguageInfo();
            await this.collectStorageInfo();

            // Generate hash from all components
            this.fingerprint = await this.hashComponents();
            this.initialized = true;

            return this.fingerprint;

        } catch (error) {
            console.warn('Error generating device fingerprint:', error);
            // Fallback fingerprint
            this.fingerprint = this.generateFallbackFingerprint();
            return this.fingerprint;
        }
    }

    /**
     * Collect basic browser and platform information
     */
    async collectBasicInfo() {
        this.components.userAgent = navigator.userAgent || '';
        this.components.platform = navigator.platform || '';
        this.components.cookieEnabled = navigator.cookieEnabled || false;
        this.components.doNotTrack = navigator.doNotTrack || '';
        this.components.onLine = navigator.onLine || false;
    }

    /**
     * Collect screen and display information
     */
    async collectScreenInfo() {
        const screen = window.screen;
        this.components.screenResolution = `${screen.width}x${screen.height}`;
        this.components.screenAvailSize = `${screen.availWidth}x${screen.availHeight}`;
        this.components.screenColorDepth = screen.colorDepth || 0;
        this.components.screenPixelDepth = screen.pixelDepth || 0;

        // Device pixel ratio
        this.components.devicePixelRatio = window.devicePixelRatio || 1;

        // Viewport size
        this.components.viewportSize = `${window.innerWidth}x${window.innerHeight}`;
    }

    /**
     * Collect browser-specific information
     */
    async collectBrowserInfo() {
        // Browser detection
        const isChrome = /Chrome/.test(navigator.userAgent);
        const isFirefox = /Firefox/.test(navigator.userAgent);
        const isSafari = /Safari/.test(navigator.userAgent) && !/Chrome/.test(navigator.userAgent);
        const isEdge = /Edge/.test(navigator.userAgent);

        this.components.browserName = isChrome ? 'Chrome' :
                                    isFirefox ? 'Firefox' :
                                    isSafari ? 'Safari' :
                                    isEdge ? 'Edge' : 'Unknown';

        // Browser version (simplified)
        const versionMatch = navigator.userAgent.match(/(?:Chrome|Firefox|Safari|Edge)\/(\d+)/);
        this.components.browserVersion = versionMatch ? versionMatch[1] : '';

        // Touch support
        this.components.touchSupport = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

        // WebRTC support
        this.components.webRTCSupport = !!(window.RTCPeerConnection ||
                                         window.mozRTCPeerConnection ||
                                         window.webkitRTCPeerConnection);
    }

    /**
     * Collect hardware information
     */
    async collectHardwareInfo() {
        // CPU cores
        this.components.cpuCores = navigator.hardwareConcurrency || 0;

        // Memory (if available)
        if (navigator.deviceMemory) {
            this.components.deviceMemory = navigator.deviceMemory;
        }

        // Battery API (if available)
        if ('getBattery' in navigator) {
            try {
                const battery = await navigator.getBattery();
                this.components.batteryCharging = battery.charging;
                this.components.batteryLevel = Math.round(battery.level * 100);
            } catch (e) {
                // Battery API not available or blocked
            }
        }

        // Network information (if available)
        if (navigator.connection) {
            this.components.connectionType = navigator.connection.effectiveType || '';
            this.components.connectionDownlink = navigator.connection.downlink || 0;
        }
    }

    /**
     * Generate canvas fingerprint
     */
    async collectCanvasFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');

            canvas.width = 200;
            canvas.height = 50;

            // Draw various shapes and text
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);

            ctx.fillStyle = '#069';
            ctx.fillText('WinABN Fingerprint 🎯', 2, 15);

            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('Device fingerprint test', 4, 32);

            // Add some geometric shapes
            ctx.globalCompositeOperation = 'multiply';
            ctx.fillStyle = 'rgb(255,0,255)';
            ctx.beginPath();
            ctx.arc(50, 25, 20, 0, Math.PI * 2, true);
            ctx.closePath();
            ctx.fill();

            this.components.canvasFingerprint = canvas.toDataURL();

        } catch (error) {
            this.components.canvasFingerprint = 'canvas_error';
        }
    }

    /**
     * Generate WebGL fingerprint
     */
    async collectWebGLFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');

            if (!gl) {
                this.components.webglFingerprint = 'webgl_not_supported';
                return;
            }

            const webglInfo = {
                vendor: gl.getParameter(gl.VENDOR),
                renderer: gl.getParameter(gl.RENDERER),
                version: gl.getParameter(gl.VERSION),
                shadingLanguageVersion: gl.getParameter(gl.SHADING_LANGUAGE_VERSION),
                maxVertexAttribs: gl.getParameter(gl.MAX_VERTEX_ATTRIBS),
                maxTextureSize: gl.getParameter(gl.MAX_TEXTURE_SIZE),
                maxRenderbufferSize: gl.getParameter(gl.MAX_RENDERBUFFER_SIZE),
                maxViewportDims: gl.getParameter(gl.MAX_VIEWPORT_DIMS)
            };

            // Get WebGL extensions
            const extensions = gl.getSupportedExtensions() || [];
            webglInfo.extensions = extensions.sort();

            this.components.webglFingerprint = JSON.stringify(webglInfo);

        } catch (error) {
            this.components.webglFingerprint = 'webgl_error';
        }
    }

    /**
     * Generate audio context fingerprint
     */
    async collectAudioFingerprint() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) {
                this.components.audioFingerprint = 'audio_not_supported';
                return;
            }

            const audioCtx = new AudioContext();
            const oscillator = audioCtx.createOscillator();
            const analyser = audioCtx.createAnalyser();
            const gain = audioCtx.createGain();
            const scriptProcessor = audioCtx.createScriptProcessor(4096, 1, 1);

            gain.gain.value = 0; // Mute the sound
            oscillator.type = 'triangle';
            oscillator.frequency.value = 10000;

            oscillator.connect(analyser);
            analyser.connect(scriptProcessor);
            scriptProcessor.connect(gain);
            gain.connect(audioCtx.destination);

            oscillator.start(0);

            // Generate fingerprint from audio context properties
            const audioFingerprint = {
                sampleRate: audioCtx.sampleRate,
                maxChannelCount: audioCtx.destination.maxChannelCount,
                numberOfInputs: audioCtx.destination.numberOfInputs,
                numberOfOutputs: audioCtx.destination.numberOfOutputs,
                channelCount: audioCtx.destination.channelCount,
                channelCountMode: audioCtx.destination.channelCountMode,
                channelInterpretation: audioCtx.destination.channelInterpretation
            };

            this.components.audioFingerprint = JSON.stringify(audioFingerprint);

            // Clean up
            oscillator.stop();
            audioCtx.close();

        } catch (error) {
            this.components.audioFingerprint = 'audio_error';
        }
    }

    /**
     * Detect available fonts
     */
    async collectFontFingerprint() {
        const baseFonts = ['monospace', 'sans-serif', 'serif'];
        const testFonts = [
            'Arial', 'Arial Black', 'Arial Narrow', 'Arial Rounded MT Bold',
            'Bookman Old Style', 'Bradley Hand ITC', 'Century', 'Century Gothic',
            'Comic Sans MS', 'Courier', 'Courier New', 'Georgia', 'Gentium',
            'Helvetica', 'Helvetica Neue', 'Impact', 'King', 'Lucida Console',
            'Lalit', 'Modena', 'Monotype Corsiva', 'Papyrus', 'Tahoma', 'TeX',
            'Times', 'Times New Roman', 'Trebuchet MS', 'Verdana', 'Verona'
        ];

        const testString = 'mmmmmmmmmmlli';
        const testSize = '72px';
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');

        context.textBaseline = 'top';
        context.font = testSize + ' monospace';

        const baselines = {};
        for (const baseFont of baseFonts) {
            context.font = testSize + ' ' + baseFont;
            baselines[baseFont] = context.measureText(testString).width;
        }

        const availableFonts = [];
        for (const testFont of testFonts) {
            let detected = false;
            for (const baseFont of baseFonts) {
                context.font = testSize + ' ' + testFont + ', ' + baseFont;
                const width = context.measureText(testString).width;
                if (width !== baselines[baseFont]) {
                    detected = true;
                    break;
                }
            }
            if (detected) {
                availableFonts.push(testFont);
            }
        }

        this.components.availableFonts = availableFonts.sort();
    }

    /**
     * Collect browser plugin information
     */
    async collectPluginInfo() {
        const plugins = [];

        if (navigator.plugins) {
            for (let i = 0; i < navigator.plugins.length; i++) {
                const plugin = navigator.plugins[i];
                plugins.push({
                    name: plugin.name,
                    description: plugin.description,
                    filename: plugin.filename
                });
            }
        }

        this.components.plugins = plugins.sort((a, b) => a.name.localeCompare(b.name));
    }

    /**
     * Collect timezone information
     */
    async collectTimezoneInfo() {
        this.components.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        this.components.timezoneOffset = new Date().getTimezoneOffset();

        // Test for timezone spoofing
        const january = new Date(2024, 0, 1);
        const july = new Date(2024, 6, 1);
        this.components.timezoneJanuary = january.getTimezoneOffset();
        this.components.timezoneJuly = july.getTimezoneOffset();
    }

    /**
     * Collect language and localization information
     */
    async collectLanguageInfo() {
        this.components.language = navigator.language || '';
        this.components.languages = navigator.languages ? navigator.languages.slice() : [];

        if (Intl.DateTimeFormat) {
            this.components.dateTimeFormat = Intl.DateTimeFormat().resolvedOptions();
        }

        if (Intl.NumberFormat) {
            this.components.numberFormat = Intl.NumberFormat().resolvedOptions();
        }
    }

    /**
     * Collect storage information
     */
    async collectStorageInfo() {
        // LocalStorage support
        try {
            localStorage.setItem('test', 'test');
            localStorage.removeItem('test');
            this.components.localStorageSupport = true;
        } catch (e) {
            this.components.localStorageSupport = false;
        }

        // SessionStorage support
        try {
            sessionStorage.setItem('test', 'test');
            sessionStorage.removeItem('test');
            this.components.sessionStorageSupport = true;
        } catch (e) {
            this.components.sessionStorageSupport = false;
        }

        // IndexedDB support
        this.components.indexedDBSupport = !!window.indexedDB;

        // WebSQL support (deprecated but still fingerprint-able)
        this.components.webSQLSupport = !!window.openDatabase;

        // Storage quota (if available)
        if (navigator.storage && navigator.storage.estimate) {
            try {
                const estimate = await navigator.storage.estimate();
                this.components.storageQuota = estimate.quota;
                this.components.storageUsage = estimate.usage;
            } catch (e) {
                // Storage estimate not available
            }
        }
    }

    /**
     * Hash all collected components into final fingerprint
     *
     * @returns {Promise<string>} SHA-256 hash of fingerprint components
     */
    async hashComponents() {
        const fingerprintString = JSON.stringify(this.components, Object.keys(this.components).sort());

        // Use Web Crypto API if available
        if (window.crypto && window.crypto.subtle) {
            try {
                const encoder = new TextEncoder();
                const data = encoder.encode(fingerprintString);
                const hashBuffer = await window.crypto.subtle.digest('SHA-256', data);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
                return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            } catch (e) {
                // Fallback to simple hash
            }
        }

        // Fallback: simple hash function
        return this.simpleHash(fingerprintString);
    }

    /**
     * Simple hash function fallback
     *
     * @param {string} str String to hash
     * @returns {string} Hash string
     */
    simpleHash(str) {
        let hash = 0;
        if (str.length === 0) return hash.toString();

        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32-bit integer
        }

        return Math.abs(hash).toString(16);
    }

    /**
     * Generate fallback fingerprint if main generation fails
     *
     * @returns {string} Fallback fingerprint
     */
    generateFallbackFingerprint() {
        const fallbackData = {
            userAgent: navigator.userAgent || '',
            screenResolution: `${screen.width}x${screen.height}`,
            timezone: new Date().getTimezoneOffset(),
            language: navigator.language || '',
            platform: navigator.platform || '',
            timestamp: Date.now()
        };

        return this.simpleHash(JSON.stringify(fallbackData));
    }

    /**
     * Get all collected components (for debugging)
     *
     * @returns {Object} All fingerprint components
     */
    getComponents() {
        return { ...this.components };
    }

    /**
     * Get fingerprint without regenerating
     *
     * @returns {string|null} Current fingerprint or null if not generated
     */
    getFingerprint() {
        return this.fingerprint;
    }

    /**
     * Validate that fingerprint generation is working
     *
     * @returns {boolean} True if fingerprint seems valid
     */
    isValid() {
        return this.fingerprint &&
               this.fingerprint.length > 10 &&
               this.fingerprint !== 'canvas_error' &&
               this.fingerprint !== 'webgl_error' &&
               this.fingerprint !== 'audio_error';
    }
}

// Global instance
window.DeviceFingerprint = DeviceFingerprint;

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.winABN === 'undefined') {
        window.winABN = {};
    }

    window.winABN.deviceFingerprint = new DeviceFingerprint();

    // Generate fingerprint automatically for forms
    const gameForm = document.getElementById('game-form');
    if (gameForm) {
        window.winABN.deviceFingerprint.generate().then(fingerprint => {
            // Add fingerprint to form data
            let fingerprintInput = document.getElementById('device_fingerprint');
            if (!fingerprintInput) {
                fingerprintInput = document.createElement('input');
                fingerprintInput.type = 'hidden';
                fingerprintInput.name = 'device_fingerprint';
                fingerprintInput.id = 'device_fingerprint';
                gameForm.appendChild(fingerprintInput);
            }
            fingerprintInput.value = fingerprint;
        }).catch(error => {
            console.warn('Failed to generate device fingerprint:', error);
        });
    }
});

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DeviceFingerprint;
}
