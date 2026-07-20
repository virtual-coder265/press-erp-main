/**
 * Reusable client-side draft persistence: IndexedDB primary + localStorage mirror + cookie pointer.
 */
(function (global) {
    'use strict';

    const DB_NAME = 'press_erp_drafts';
    const DB_VERSION = 1;
    const STORE_NAME = 'drafts';
    const SCHEMA_VERSION = 1;
    const COOKIE_NAME = 'est_draft_ptr';
    const COOKIE_MAX_AGE = 7 * 24 * 60 * 60;

    let dbPromise = null;

    function openDb() {
        if (dbPromise) {
            return dbPromise;
        }
        dbPromise = new Promise(function (resolve) {
            if (!('indexedDB' in global)) {
                resolve(null);
                return;
            }
            const request = indexedDB.open(DB_NAME, DB_VERSION);
            request.onerror = function () {
                resolve(null);
            };
            request.onsuccess = function () {
                resolve(request.result);
            };
            request.onupgradeneeded = function (event) {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    db.createObjectStore(STORE_NAME, { keyPath: 'key' });
                }
            };
        });
        return dbPromise;
    }

    function lsKey(key) {
        return 'form_draft:' + key;
    }

    function idbPut(key, snapshot) {
        return openDb().then(function (db) {
            if (!db) {
                return;
            }
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                tx.oncomplete = function () {
                    resolve();
                };
                tx.onerror = function () {
                    reject(tx.error);
                };
                tx.objectStore(STORE_NAME).put({
                    key: key,
                    snapshot: snapshot,
                    savedAt: Date.now(),
                });
            });
        });
    }

    function idbGet(key) {
        return openDb().then(function (db) {
            if (!db) {
                return null;
            }
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_NAME, 'readonly');
                const request = tx.objectStore(STORE_NAME).get(key);
                request.onsuccess = function () {
                    resolve(request.result ? request.result.snapshot : null);
                };
                request.onerror = function () {
                    reject(request.error);
                };
            });
        });
    }

    function idbDelete(key) {
        return openDb().then(function (db) {
            if (!db) {
                return;
            }
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                tx.oncomplete = function () {
                    resolve();
                };
                tx.onerror = function () {
                    reject(tx.error);
                };
                tx.objectStore(STORE_NAME).delete(key);
            });
        });
    }

    function save(key, snapshot) {
        return idbPut(key, snapshot).catch(function (err) {
            console.warn('FormDraftStore IndexedDB save failed', err);
        }).then(function () {
            writeLocalMirror(key, snapshot);
            return snapshot;
        });
    }

    function writeLocalMirror(key, snapshot) {
        try {
            localStorage.setItem(lsKey(key), JSON.stringify(snapshot));
        } catch (err) {
            console.warn('FormDraftStore localStorage save failed', err);
        }
    }

    /**
     * Synchronous mirror write for pagehide/beforeunload when async IDB may not finish.
     */
    function saveSync(key, snapshot) {
        writeLocalMirror(key, snapshot);
        idbPut(key, snapshot).catch(function () {
            /* best-effort async follow-up */
        });
        return snapshot;
    }

    function readLocalMirror(key) {
        try {
            const raw = localStorage.getItem(lsKey(key));
            return raw ? JSON.parse(raw) : null;
        } catch (err) {
            return null;
        }
    }

    function load(key) {
        return idbGet(key).catch(function (err) {
            console.warn('FormDraftStore IndexedDB load failed', err);
            return null;
        }).then(function (snapshot) {
            const local = readLocalMirror(key);
            if (!snapshot && local) {
                return local;
            }
            if (snapshot && local) {
                const idbTs = Date.parse(String((snapshot.meta && snapshot.meta.updatedAt) || '').replace(' ', 'T')) || 0;
                const localTs = Date.parse(String((local.meta && local.meta.updatedAt) || '').replace(' ', 'T')) || 0;
                return localTs > idbTs ? local : snapshot;
            }
            return snapshot || local || null;
        });
    }

    /**
     * Load the newest snapshot among candidate keys (e.g. active + est-specific).
     */
    function loadNewest(keys) {
        const uniqueKeys = keys.filter(function (k, i, arr) {
            return k && arr.indexOf(k) === i;
        });
        if (!uniqueKeys.length) {
            return Promise.resolve(null);
        }
        return Promise.all(uniqueKeys.map(function (key) {
            return load(key);
        })).then(function (snapshots) {
            let best = null;
            let bestTs = 0;
            snapshots.forEach(function (snap) {
                if (!snap) {
                    return;
                }
                const ts = Date.parse(String((snap.meta && snap.meta.updatedAt) || '').replace(' ', 'T')) || 0;
                if (!best || ts >= bestTs) {
                    best = snap;
                    bestTs = ts;
                }
            });
            return best;
        });
    }

    function remove(key) {
        return idbDelete(key).catch(function () {
            /* best-effort */
        }).then(function () {
            try {
                localStorage.removeItem(lsKey(key));
                localStorage.removeItem('estimation_draft_v4');
            } catch (err) {
                /* best-effort */
            }
        });
    }

    function setCookie(name, value, maxAge) {
        document.cookie = name + '=' + encodeURIComponent(value) +
            '; path=/; max-age=' + maxAge + '; SameSite=Lax';
    }

    function getCookie(name) {
        const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setPointer(data) {
        const payload = Object.assign({ v: 1 }, data);
        setCookie(COOKIE_NAME, JSON.stringify(payload), COOKIE_MAX_AGE);
    }

    function getPointer() {
        const raw = getCookie(COOKIE_NAME);
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (err) {
            return null;
        }
    }

    function clearPointer() {
        setCookie(COOKIE_NAME, '', 0);
    }

    global.FormDraftStore = {
        SCHEMA_VERSION: SCHEMA_VERSION,
        COOKIE_NAME: COOKIE_NAME,
        save: save,
        saveSync: saveSync,
        load: load,
        loadNewest: loadNewest,
        remove: remove,
        setPointer: setPointer,
        getPointer: getPointer,
        clearPointer: clearPointer,
    };
})(typeof window !== 'undefined' ? window : this);
