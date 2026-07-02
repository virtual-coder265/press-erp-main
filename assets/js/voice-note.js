/**
 * Telegram/WhatsApp-style mic toggle: first tap records, second tap stops and attaches audio.
 * Requires MediaRecorder + getUserMedia (HTTPS or localhost).
 */
(function (global) {
    'use strict';

    function pickMimeType() {
        if (!global.MediaRecorder || !global.MediaRecorder.isTypeSupported) {
            return '';
        }
        var candidates = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/mp4',
        ];
        var i;
        for (i = 0; i < candidates.length; i++) {
            if (global.MediaRecorder.isTypeSupported(candidates[i])) {
                return candidates[i];
            }
        }
        return '';
    }

    function extensionForMime(mime) {
        mime = (mime || '').toLowerCase();
        if (mime.indexOf('ogg') !== -1) {
            return 'ogg';
        }
        if (mime.indexOf('mp4') !== -1 || mime.indexOf('mpeg') !== -1) {
            return 'm4a';
        }
        return 'webm';
    }

    function mergeIntoFileInput(input, file) {
        if (!input || !global.DataTransfer) {
            return;
        }
        var dt = new global.DataTransfer();
        var j;
        if (input.files && input.files.length) {
            for (j = 0; j < input.files.length; j++) {
                dt.items.add(input.files[j]);
            }
        }
        dt.items.add(file);
        input.files = dt.files;
    }

    /**
     * @param {object} opts
     * @param {Element|string} opts.button
     * @param {Element|string} [opts.fileInput]
     * @param {Element|string} [opts.hiddenVoiceInput]  value set to '1' on success
     * @param {Element|string} [opts.statusEl]
     * @param {number} [opts.maxSeconds=180]
     * @param {function(File, HTMLInputElement|null)} [opts.onFile] overrides default merge into fileInput
     * @param {function(File)} [opts.afterRecord]
     */
    function bindToggle(opts) {
        var button = typeof opts.button === 'string' ? global.document.querySelector(opts.button) : opts.button;
        if (!button || !global.navigator.mediaDevices || !global.navigator.mediaDevices.getUserMedia) {
            if (button) {
                button.setAttribute('title', 'Voice notes are not supported in this browser.');
                button.setAttribute('disabled', 'disabled');
            }
            return;
        }

        var fileInput = opts.fileInput
            ? (typeof opts.fileInput === 'string' ? global.document.querySelector(opts.fileInput) : opts.fileInput)
            : null;
        var hiddenVoice = opts.hiddenVoiceInput
            ? (typeof opts.hiddenVoiceInput === 'string'
                ? global.document.querySelector(opts.hiddenVoiceInput)
                : opts.hiddenVoiceInput)
            : null;
        var maxMs = (opts.maxSeconds != null ? opts.maxSeconds : 180) * 1000;
        var statusEl = opts.statusEl
            ? (typeof opts.statusEl === 'string' ? global.document.querySelector(opts.statusEl) : opts.statusEl)
            : null;

        var isRecording = false;
        var starting = false;
        var mediaRecorder = null;
        var chunks = [];
        var stream = null;
        var tickInterval = null;
        var limitTimeout = null;
        var startTs = 0;

        function formatDur(ms) {
            var sec = Math.floor(ms / 1000);
            var m = Math.floor(sec / 60);
            var s = sec % 60;
            return m + ':' + (s < 10 ? '0' : '') + s;
        }

        function clearTimers() {
            if (tickInterval) {
                global.clearInterval(tickInterval);
                tickInterval = null;
            }
            if (limitTimeout) {
                global.clearTimeout(limitTimeout);
                limitTimeout = null;
            }
        }

        function stopStream() {
            if (stream) {
                stream.getTracks().forEach(function (t) {
                    t.stop();
                });
                stream = null;
            }
        }

        function updateRecordingUi(elapsed) {
            button.classList.add('press-voice-btn--recording');
            button.setAttribute('aria-pressed', 'true');
            if (statusEl) {
                statusEl.textContent = 'Recording ' + formatDur(elapsed) + ' — tap mic to stop';
            }
        }

        if (!button.getAttribute('type')) {
            button.setAttribute('type', 'button');
        }
        button.setAttribute('aria-label', button.getAttribute('aria-label') || 'Record voice note');

        button.addEventListener('click', function (e) {
            e.preventDefault();
            if (starting) {
                return;
            }

            if (!isRecording) {
                starting = true;
                chunks = [];
                var mime = pickMimeType();

                global.navigator.mediaDevices
                    .getUserMedia({
                        audio: {
                            echoCancellation: true,
                            noiseSuppression: true,
                        },
                    })
                    .then(function (s) {
                        stream = s;
                        var recorder;
                        try {
                            recorder = mime
                                ? new global.MediaRecorder(s, { mimeType: mime })
                                : new global.MediaRecorder(s);
                        } catch (err) {
                            recorder = new global.MediaRecorder(s);
                        }

                        recorder.ondataavailable = function (ev) {
                            if (ev.data && ev.data.size) {
                                chunks.push(ev.data);
                            }
                        };

                        recorder.onstop = function () {
                            clearTimers();

                            var mimeType = recorder.mimeType || mime || 'audio/webm';
                            var blob = new global.Blob(chunks, { type: mimeType });
                            chunks = [];
                            mediaRecorder = null;
                            isRecording = false;

                            button.classList.remove('press-voice-btn--recording');
                            button.setAttribute('aria-pressed', 'false');
                            stopStream();

                            if (!blob.size || blob.size < 80) {
                                if (statusEl) {
                                    statusEl.textContent = '';
                                }
                                return;
                            }

                            var ext = extensionForMime(blob.type);
                            var fileName = 'voice-' + Date.now() + '.' + ext;
                            var file = new global.File([blob], fileName, { type: blob.type || mimeType });

                            if (hiddenVoice) {
                                hiddenVoice.value = '1';
                            }

                            if (typeof opts.onFile === 'function') {
                                opts.onFile(file, fileInput);
                            } else if (fileInput) {
                                mergeIntoFileInput(fileInput, file);
                            }

                            if (statusEl) {
                                statusEl.textContent = 'Voice note ready — send message';
                            }

                            if (typeof opts.afterRecord === 'function') {
                                opts.afterRecord(file);
                            }

                            if (typeof global.refreshAppShellIcons === 'function') {
                                global.refreshAppShellIcons();
                            }
                        };

                        mediaRecorder = recorder;
                        mediaRecorder.start();
                        startTs = global.Date.now();
                        isRecording = true;
                        starting = false;
                        updateRecordingUi(0);

                        tickInterval = global.setInterval(function () {
                            updateRecordingUi(global.Date.now() - startTs);
                        }, 300);

                        limitTimeout = global.setTimeout(function () {
                            if (mediaRecorder && mediaRecorder.state === 'recording') {
                                mediaRecorder.stop();
                            }
                        }, maxMs);
                    })
                    .catch(function () {
                        starting = false;
                        isRecording = false;
                        if (typeof global.showToast === 'function') {
                            global.showToast('Microphone permission is needed for voice notes.', 'error');
                        } else {
                            global.alert('Microphone permission is needed for voice notes.');
                        }
                    });
            } else if (mediaRecorder && mediaRecorder.state === 'recording') {
                clearTimers();
                mediaRecorder.stop();
            }
        });
    }

    global.PressVoiceNote = {
        mergeIntoFileInput: mergeIntoFileInput,
        bindToggle: bindToggle,
    };
})(typeof window !== 'undefined' ? window : globalThis);
