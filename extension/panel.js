(function () {
    var APP_ORIGIN = 'http://localhost';
    var READY_TIMEOUT_MS = 2500;

    var frame    = document.getElementById('app');
    var chip     = document.getElementById('chip');
    var chipText = document.getElementById('chip-text');
    var offline  = document.getElementById('offline');
    var loading  = document.getElementById('loading');

    var ready = false;
    var timer = null;
    var context = { url: '', title: '' };

    function showOffline() {
        if (ready) { return; }
        loading.hidden = true;
        offline.hidden = false;
    }

    frame.addEventListener('load', function () { loading.hidden = true; });

    function watchForReady() {
        clearTimeout(timer);
        timer = setTimeout(showOffline, READY_TIMEOUT_MS);
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== APP_ORIGIN || !e.data) { return; }

        if (e.data.type === 'mya:ready') {
            ready = true;
            clearTimeout(timer);
            offline.hidden = true;
            loading.hidden = true;
            sendContext();
        } else if (e.data.type === 'mya:capture_screenshot') {
            captureTabScreenshot();
        } else if (e.data.type === 'mya:browser_action') {
            handleBrowserAction(e.data);
        }
    });

    function getActiveTab(callback) {
        if (typeof chrome === 'undefined' || !chrome.tabs) {
            callback(null);
            return;
        }
        chrome.tabs.query({ active: true, lastFocusedWindow: true }, function (tabs) {
            if (tabs && tabs[0] && usable(tabs[0].url)) {
                callback(tabs[0]);
            } else {
                chrome.tabs.query({ active: true, currentWindow: true }, function (t2) {
                    callback(t2 && t2[0] ? t2[0] : null);
                });
            }
        });
    }

    function sendActionResult(reqId, ok, result, error) {
        if (frame && frame.contentWindow) {
            frame.contentWindow.postMessage({
                type: 'mya:browser_action_result',
                reqId: reqId,
                ok: ok,
                result: result || null,
                error: error || null
            }, APP_ORIGIN);
        }
    }

    function handleBrowserAction(data) {
        var reqId  = data.reqId || ('req_' + Date.now());
        var action = data.action || '';
        var params = data.params || {};

        getActiveTab(function (tab) {
            if (!tab) {
                sendActionResult(reqId, false, null, 'No active browser tab found.');
                return;
            }

            var tabId = tab.id;

            if (action === 'navigate') {
                var targetUrl = (params.url || '').trim();
                if (!targetUrl) {
                    sendActionResult(reqId, false, null, 'No URL provided for navigation.');
                    return;
                }
                if (!/^https?:\/\//i.test(targetUrl)) {
                    targetUrl = 'https://' + targetUrl;
                }

                chrome.tabs.update(tabId, { url: targetUrl }, function (updatedTab) {
                    if (chrome.runtime.lastError) {
                        sendActionResult(reqId, false, null, chrome.runtime.lastError.message);
                        return;
                    }

                    // Wait for page to finish loading (or 6s max timeout)
                    var finished = false;
                    function onTabUpdate(updatedTabId, info) {
                        if (updatedTabId === tabId && info.status === 'complete' && !finished) {
                            finished = true;
                            chrome.tabs.onUpdated.removeListener(onTabUpdate);
                            clearTimeout(navTimeout);
                            setTimeout(function () {
                                sendActionResult(reqId, true, { status: 'navigated', url: targetUrl, title: updatedTab.title || targetUrl });
                            }, 500);
                        }
                    }
                    var navTimeout = setTimeout(function () {
                        if (!finished) {
                            finished = true;
                            chrome.tabs.onUpdated.removeListener(onTabUpdate);
                            sendActionResult(reqId, true, { status: 'navigated_timeout', url: targetUrl });
                        }
                    }, 6000);

                    chrome.tabs.onUpdated.addListener(onTabUpdate);
                });
                return;
            }

            // Script-based actions on the active tab
            if (typeof chrome.scripting === 'undefined' || !chrome.scripting.executeScript) {
                sendActionResult(reqId, false, null, 'chrome.scripting API not available.');
                return;
            }

            if (action === 'read_page') {
                chrome.scripting.executeScript({
                    target: { tabId: tabId },
                    func: injectedReadPageDOM,
                    args: [params.focus_query || '']
                }, function (results) {
                    if (chrome.runtime.lastError) {
                        sendActionResult(reqId, false, null, chrome.runtime.lastError.message);
                        return;
                    }
                    var res = (results && results[0] && results[0].result) ? results[0].result : { elements: 'No interactive elements found.', text: '' };
                    sendActionResult(reqId, true, res);
                });
            } else if (action === 'click') {
                chrome.scripting.executeScript({
                    target: { tabId: tabId },
                    func: injectedClickElement,
                    args: [params.element_id || null, params.selector || '']
                }, function (results) {
                    if (chrome.runtime.lastError) {
                        sendActionResult(reqId, false, null, chrome.runtime.lastError.message);
                        return;
                    }
                    var res = (results && results[0] && results[0].result) ? results[0].result : { status: 'clicked' };
                    sendActionResult(reqId, !res.error, res, res.error);
                });
            } else if (action === 'type') {
                chrome.scripting.executeScript({
                    target: { tabId: tabId },
                    func: injectedTypeElement,
                    args: [params.element_id || null, params.text || '', params.press_enter || false, params.selector || '']
                }, function (results) {
                    if (chrome.runtime.lastError) {
                        sendActionResult(reqId, false, null, chrome.runtime.lastError.message);
                        return;
                    }
                    var res = (results && results[0] && results[0].result) ? results[0].result : { status: 'typed' };
                    sendActionResult(reqId, !res.error, res, res.error);
                });
            } else if (action === 'scroll') {
                chrome.scripting.executeScript({
                    target: { tabId: tabId },
                    func: injectedScroll,
                    args: [params.direction || 'down', params.amount || 500]
                }, function (results) {
                    if (chrome.runtime.lastError) {
                        sendActionResult(reqId, false, null, chrome.runtime.lastError.message);
                        return;
                    }
                    var res = (results && results[0] && results[0].result) ? results[0].result : { status: 'scrolled' };
                    sendActionResult(reqId, true, res);
                });
            } else {
                sendActionResult(reqId, false, null, 'Unknown action: ' + action);
            }
        });
    }

    // Injected DOM Reading & PII Redacting Engine (runs in active webpage context)
    function injectedReadPageDOM(focusQuery) {
        function scrub(text) {
            if (!text || typeof text !== 'string') { return ''; }
            return text
                .replace(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g, '[EMAIL_REDACTED]')
                .replace(/(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/g, '[PHONE_REDACTED]')
                .replace(/\b(?:\d{4}[ -]?){3}\d{4}\b/g, '[CARD_REDACTED]')
                .replace(/\b(?:eyJ[a-zA-Z0-9_-]{10,}\.[a-zA-Z0-9._-]{10,}|sk-[a-zA-Z0-9]{20,})\b/g, '[TOKEN_REDACTED]')
                .replace(/\b\d{3}-\d{2}-\d{4}\b/g, '[SSN_REDACTED]')
                .replace(/\s+/g, ' ')
                .trim();
        }

        var elements = [];
        var selectors = [
            'a[href]', 'button', 'input:not([type="hidden"])', 'textarea', 'select',
            '[role="button"]', '[role="link"]', '[role="textbox"]', '[role="searchbox"]',
            '[role="checkbox"]', '[role="tab"]', '[role="menuitem"]', '[onclick]', '[contenteditable="true"]'
        ];

        var rawList = document.querySelectorAll(selectors.join(','));
        var idCounter = 1;

        for (var i = 0; i < rawList.length; i++) {
            var el = rawList[i];

            // Visibility & Dimension checks
            var style = window.getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
                continue;
            }
            var rect = el.getBoundingClientRect();
            if (rect.width === 0 && rect.height === 0) {
                continue;
            }

            var tag = el.tagName.toLowerCase();
            var agentId = idCounter++;
            el.setAttribute('data-mya-agent-id', String(agentId));

            var desc = '[' + agentId + '] ';

            if (tag === 'input') {
                var type = (el.getAttribute('type') || 'text').toLowerCase();
                if (type === 'password') {
                    desc += 'input[password] (placeholder="' + scrub(el.placeholder || '') + '")';
                } else {
                    var label = scrub(el.getAttribute('aria-label') || el.placeholder || el.name || el.title || '');
                    var val = scrub(el.value || '');
                    desc += 'input (type="' + type + '", label="' + label + '", value="' + val + '")';
                }
            } else if (tag === 'textarea') {
                var tLabel = scrub(el.getAttribute('aria-label') || el.placeholder || el.name || '');
                desc += 'textarea (label="' + tLabel + '", value="' + scrub(el.value || '') + '")';
            } else if (tag === 'button' || el.getAttribute('role') === 'button') {
                var bText = scrub(el.innerText || el.getAttribute('aria-label') || el.title || el.value || 'button');
                desc += 'button "' + (bText.length > 50 ? bText.slice(0, 47) + '…' : bText) + '"';
            } else if (tag === 'a' || el.getAttribute('role') === 'link') {
                var aText = scrub(el.innerText || el.getAttribute('aria-label') || el.title || 'link');
                var href = el.getAttribute('href') || '';
                desc += 'link "' + (aText.length > 50 ? aText.slice(0, 47) + '…' : aText) + '"';
                if (href && !href.startsWith('javascript:')) {
                    desc += ' (href="' + (href.length > 60 ? href.slice(0, 57) + '…' : href) + '")';
                }
            } else if (tag === 'select') {
                var sLabel = scrub(el.getAttribute('aria-label') || el.name || 'select');
                desc += 'select (label="' + sLabel + '")';
            } else {
                var otherText = scrub(el.innerText || el.getAttribute('aria-label') || '');
                desc += tag + ' "' + (otherText.length > 40 ? otherText.slice(0, 37) + '…' : otherText) + '"';
            }

            elements.push(desc);
            if (elements.length >= 60) {
                break; // Cap at 60 items to guarantee ultra-low token count
            }
        }

        // Get sanitized main page heading / key summary text
        var bodyClone = document.body ? document.body.cloneNode(true) : null;
        var pageSummaryText = '';
        if (bodyClone) {
            var removeTags = bodyClone.querySelectorAll('script, style, noscript, svg');
            for (var r = 0; r < removeTags.length; r++) { removeTags[r].remove(); }
            pageSummaryText = scrub(bodyClone.innerText || '').slice(0, 800);
        }

        return {
            url: window.location.href,
            title: document.title,
            interactive_elements: elements.join('\n'),
            page_text: pageSummaryText
        };
    }

    function injectedClickElement(elementId, selector) {
        var el = null;
        if (elementId) {
            el = document.querySelector('[data-mya-agent-id="' + elementId + '"]');
        }
        if (!el && selector) {
            try { el = document.querySelector(selector); } catch (e) {}
        }
        if (!el) {
            return { error: 'Element ' + (elementId ? ('#' + elementId) : selector) + ' not found on the page.' };
        }

        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.focus();

        var evtOpts = { bubbles: true, cancelable: true, view: window };
        el.dispatchEvent(new MouseEvent('mousedown', evtOpts));
        el.dispatchEvent(new MouseEvent('mouseup', evtOpts));
        el.dispatchEvent(new MouseEvent('click', evtOpts));

        return { status: 'clicked', tag: el.tagName.toLowerCase() };
    }

    function injectedTypeElement(elementId, text, pressEnter, selector) {
        var el = null;
        if (elementId) {
            el = document.querySelector('[data-mya-agent-id="' + elementId + '"]');
        }
        if (!el && selector) {
            try { el = document.querySelector(selector); } catch (e) {}
        }
        if (!el) {
            return { error: 'Input element ' + (elementId ? ('#' + elementId) : selector) + ' not found.' };
        }

        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.focus();

        if ('value' in el) {
            el.value = text;
        } else {
            el.innerText = text;
        }

        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));

        if (pressEnter) {
            var enterOpts = { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true, cancelable: true };
            el.dispatchEvent(new KeyboardEvent('keydown', enterOpts));
            el.dispatchEvent(new KeyboardEvent('keypress', enterOpts));
            el.dispatchEvent(new KeyboardEvent('keyup', enterOpts));

            if (el.form && typeof el.form.requestSubmit === 'function') {
                try { el.form.requestSubmit(); } catch (e) { el.form.submit(); }
            }
        }

        return { status: 'typed', text: text, pressed_enter: !!pressEnter };
    }

    function injectedScroll(direction, amount) {
        var y = (direction === 'up' ? -1 : 1) * (parseInt(amount, 10) || 500);
        window.scrollBy({ top: y, behavior: 'smooth' });
        return { status: 'scrolled', scrolled_y: y };
    }

    function captureTabScreenshot() {
        if (typeof chrome === 'undefined' || !chrome.tabs || !chrome.tabs.captureVisibleTab) {
            if (frame && frame.contentWindow) {
                frame.contentWindow.postMessage({
                    type:    'mya:screenshot_data',
                    dataUrl: null
                }, APP_ORIGIN);
            }
            return;
        }

        function doCapture(winId) {
            chrome.tabs.captureVisibleTab(winId, { format: 'png' }, function (dataUrl) {
                if (chrome.runtime.lastError) {
                    console.warn('Notebook capture error:', chrome.runtime.lastError.message);
                }
                var finalUrl = (!chrome.runtime.lastError && dataUrl) ? dataUrl : null;
                if (frame && frame.contentWindow) {
                    frame.contentWindow.postMessage({
                        type:    'mya:screenshot_data',
                        dataUrl: finalUrl
                    }, APP_ORIGIN);
                }
            });
        }

        if (chrome.windows && chrome.windows.getCurrent) {
            chrome.windows.getCurrent(function (win) {
                doCapture(win ? win.id : null);
            });
        } else {
            doCapture(null);
        }
    }

    function sendContext() {
        if (!ready) { return; }
        frame.contentWindow.postMessage({
            type:  'mya:context',
            url:   context.url,
            title: context.title
        }, APP_ORIGIN);
    }

    function usable(url) {
        if (typeof url !== 'string') { return false; }
        if (typeof chrome !== 'undefined' && chrome.runtime && typeof chrome.runtime.getURL === 'function') {
            if (url === chrome.runtime.getURL('panel.html')) { return false; }
        }
        return /^(https?|chrome-extension|moz-extension|edge-extension|extension|file):/i.test(url);
    }

    function setContext(tab) {
        if (!tab || !usable(tab.url)) {
            context = { url: '', title: '' };
            chip.classList.remove('has-page');
            chipText.textContent = 'No page';
            chip.title = '';
        } else {
            context = { url: tab.url, title: tab.title || tab.url };
            chip.classList.add('has-page');
            chipText.textContent = context.title;
            chip.title = context.url;
        }
        sendContext();
    }

    function refresh() {
        chrome.tabs.query({ active: true, lastFocusedWindow: true }, function (tabs) {
            setContext(tabs && tabs[0]);
        });
    }

    chrome.tabs.onActivated.addListener(refresh);
    chrome.tabs.onUpdated.addListener(function (id, change, tab) {
        if (tab.active && (change.url || change.title)) { refresh(); }
    });
    chrome.windows.onFocusChanged.addListener(refresh);

    document.getElementById('retry').addEventListener('click', function () {
        offline.hidden = true;
        loading.hidden = false;
        frame.src = frame.src;
        watchForReady();
    });

    refresh();
    watchForReady();
})();
