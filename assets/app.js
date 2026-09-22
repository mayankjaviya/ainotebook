(function () {
    var form = document.getElementById('chat-form');
    if (!form) { return; }

    var input = document.getElementById('chat-input');
    var log   = document.getElementById('chat-log');
    var sendBtn = form.querySelector('.composer-send');
    var busy  = false;
    var currentAbortController = null;
    var currentThinking = null;

    var conversationId = log.dataset.conversation || 'main';

    var pageContext = null;
    var screenshotPermission = null; // null = unprompted for current page, true = allowed, false = denied
    var lastContextUrl = null;
    var framed = window.parent !== window;

    var attachBtn  = document.getElementById('chat-attach-btn');
    var fileInput  = document.getElementById('chat-image-input');
    var previewBar = document.getElementById('image-preview-bar');

    var pendingImages = [];
    var screenshotWaiters = [];

    window.addEventListener('message', function (e) {
        if (!framed || !/^(chrome-extension|moz-extension|edge-extension):/i.test(e.origin)) { return; }
        var d = e.data;
        if (!d) { return; }
        if (d.type === 'mya:context') {
            var newUrl = typeof d.url === 'string' ? d.url : '';
            var newTitle = typeof d.title === 'string' ? d.title : '';
            if (lastContextUrl !== newUrl) {
                lastContextUrl = newUrl;
                screenshotPermission = null; // Reset permission state whenever active page/URL changes
            }
            pageContext = {
                url:   newUrl,
                title: newTitle
            };
        } else if (d.type === 'mya:screenshot_data') {
            var dataUrl = (typeof d.dataUrl === 'string' && d.dataUrl) ? d.dataUrl : null;
            while (screenshotWaiters.length > 0) {
                var cb = screenshotWaiters.shift();
                cb(dataUrl);
            }
        } else if (d.type === 'mya:browser_action_result') {
            var reqId = d.reqId;
            if (reqId && browserActionWaiters[reqId]) {
                var actionCb = browserActionWaiters[reqId];
                delete browserActionWaiters[reqId];
                actionCb(d.ok ? d.result : { error: d.error || 'Action failed' });
            }
        }
    });

    var browserActionWaiters = {};

    function executeBrowserAction(action, params, callback) {
        if (!framed) {
            callback({ error: 'Browser actions are only available in the Chrome extension side panel.' });
            return;
        }

        var reqId = 'act_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 6);
        browserActionWaiters[reqId] = callback;

        window.parent.postMessage({
            type: 'mya:browser_action',
            reqId: reqId,
            action: action,
            params: params || {}
        }, '*');
    }

    if (framed) {
        window.parent.postMessage({ type: 'mya:ready' }, '*');
    }

    function renderPreviewBar() {
        if (!previewBar) { return; }
        if (pendingImages.length === 0) {
            previewBar.classList.add('hidden');
            previewBar.innerHTML = '';
            return;
        }
        previewBar.classList.remove('hidden');
        var html = '';
        for (var i = 0; i < pendingImages.length; i++) {
            html += '<div class="preview-chip">'
                + '<img src="' + pendingImages[i] + '" alt="Upload preview">'
                + '<button type="button" class="btn-remove-chip" data-idx="' + i + '" title="Remove image">&times;</button>'
                + '</div>';
        }
        previewBar.innerHTML = html;
    }

    if (previewBar) {
        previewBar.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-remove-chip');
            if (!btn) { return; }
            var idx = parseInt(btn.getAttribute('data-idx'), 10);
            if (!isNaN(idx) && idx >= 0 && idx < pendingImages.length) {
                pendingImages.splice(idx, 1);
                renderPreviewBar();
            }
        });
    }

    function handleFiles(files) {
        if (!files || !files.length) { return; }
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            if (!file.type.match(/^image\//i)) { continue; }
            var reader = new FileReader();
            reader.onload = (function (f) {
                return function (e) {
                    if (e.target && e.target.result) {
                        pendingImages.push(e.target.result);
                        renderPreviewBar();
                    }
                };
            })(file);
            reader.readAsDataURL(file);
        }
    }

    if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            handleFiles(this.files);
            this.value = '';
        });
    }

    input.addEventListener('paste', function (e) {
        var items = (e.clipboardData || e.originalEvent.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') === 0) {
                var blob = items[i].getAsFile();
                if (blob) {
                    handleFiles([blob]);
                }
            }
        }
    });

    form.addEventListener('dragover', function (e) { e.preventDefault(); });
    form.addEventListener('drop', function (e) {
        e.preventDefault();
        if (e.dataTransfer && e.dataTransfer.files) {
            handleFiles(e.dataTransfer.files);
        }
    });

    function escapeHtml(str) {
        return (str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatMarkdown(text) {
        if (!text) { return ''; }

        // Strip reasoning <think>...</think> tags emitted by R1 models
        text = text.replace(/<think>[\s\S]*?<\/think>/gi, '').replace(/<\/think>/gi, '');

        // Extract <artifact title="..." type="...">content</artifact> blocks
        var artifacts = [];
        text = text.replace(/<artifact\b[^>]*?\btitle=["']?([^"'>]+)["']?[^>]*?>([\s\S]*?)<\/artifact>/gi, function (m, title, code) {
            var key = '%%ARTIFACT_BLOCK_' + artifacts.length + '%%';
            title = escapeHtml(title.trim());
            var type = 'html';
            var b64 = '';
            try { b64 = btoa(unescape(encodeURIComponent(code.trim()))); } catch (e) { b64 = ''; }

            artifacts.push(
                '<div class="artifact-card" data-type="' + type + '">'
                + '<div class="artifact-card-icon">&#127912;</div>'
                + '<div class="artifact-card-info">'
                + '<div class="artifact-card-title">' + title + '</div>'
                + '<div class="artifact-card-sub">Interactive Visual Preview</div>'
                + '</div>'
                + '<button class="btn btn-secondary btn-open-artifact" type="button" data-title="' + title + '" data-type="' + type + '">Open Preview &#8599;</button>'
                + '<script class="artifact-code-data" type="text/plain">' + b64 + '</script>'
                + '</div>'
            );
            return key;
        });

        var safe = escapeHtml(text);

        // Code blocks
        var codeBlocks = [];
        safe = safe.replace(/```(?:[a-z0-9_-]+)?\r?\n([\s\S]*?)\r?\n```/g, function (m, p1) {
            var raw = p1.trim();
            if (/^(<!DOCTYPE html|<html|<svg)/i.test(raw)) {
                var title = 'Visual Preview';
                var type = 'html';
                var b64 = '';
                try { b64 = btoa(unescape(encodeURIComponent(raw))); } catch (e) { b64 = ''; }
                var key = '%%ARTIFACT_BLOCK_' + artifacts.length + '%%';
                artifacts.push(
                    '<div class="artifact-card" data-type="' + type + '">'
                    + '<div class="artifact-card-icon">&#127912;</div>'
                    + '<div class="artifact-card-info">'
                    + '<div class="artifact-card-title">' + title + '</div>'
                    + '<div class="artifact-card-sub">Interactive Visual Preview</div>'
                    + '</div>'
                    + '<button class="btn btn-secondary btn-open-artifact" type="button" data-title="' + title + '" data-type="' + type + '">Open Preview &#8599;</button>'
                    + '<script class="artifact-code-data" type="text/plain">' + b64 + '</script>'
                    + '</div>'
                );
                return key;
            }
            var cKey = '%%CODE_BLOCK_' + codeBlocks.length + '%%';
            codeBlocks.push('<pre><code>' + p1 + '</code></pre>');
            return cKey;
        });

        // Inline code
        var inlineCode = [];
        safe = safe.replace(/`([^`]+)`/g, function (m, p1) {
            var key = '%%INLINE_CODE_' + inlineCode.length + '%%';
            inlineCode.push('<code>' + p1 + '</code>');
            return key;
        });

        // Headings
        safe = safe.replace(/^### (.*?)$/gm, '<h3>$1</h3>');
        safe = safe.replace(/^## (.*?)$/gm, '<h2>$1</h2>');
        safe = safe.replace(/^# (.*?)$/gm, '<h1>$1</h1>');

        // Bold and italic
        safe = safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/__(.*?)__/g, '<strong>$1</strong>');
        safe = safe.replace(/(?<!\*)\*(?!\*)(.*?)\*/g, '<em>$1</em>');

        // Markdown images ![alt](url)
        safe = safe.replace(/!\[([^\]]*)\]\((data:image\/[^\)]+|https?:\/\/[^\)]+)\)/gi, function (m, alt, url) {
            url = url.trim();
            return '<div class="msg-image-wrapper"><img src="' + url + '" alt="' + escapeHtml(alt) + '" class="msg-image-thumb" onclick="window.myaOpenImageModal(this.src)"></div>';
        });

        // Links [label](url)
        safe = safe.replace(/(?<!\!)\[([^\]]+)\]\(([^)]+)\)/g, function (m, label, url) {
            url = url.trim();
            if (/^(https?:\/\/|chrome-extension:\/\/|moz-extension:\/\/)/i.test(url)) {
                return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            }
            return label + ' (' + url + ')';
        });

        // Split inline bullet items after punctuation/colons onto newlines
        safe = safe.replace(/([:\.\!\?])\s+[\-\*]\s+/g, '$1\n- ');

        // Lists
        var lines = safe.split('\n');
        var inList = false;
        var listType = null;
        var outLines = [];

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var trimmed = line.trim();
            var ulMatch = trimmed.match(/^[\-\*]\s+(.*)$/);
            var olMatch = trimmed.match(/^\d+\.\s+(.*)$/);

            if (ulMatch) {
                if (!inList || listType !== 'ul') {
                    if (inList) { outLines.push('</' + listType + '>\n'); }
                    outLines.push('\n<ul>');
                    inList = true;
                    listType = 'ul';
                }
                outLines.push('<li>' + ulMatch[1] + '</li>');
            } else if (olMatch) {
                if (!inList || listType !== 'ol') {
                    if (inList) { outLines.push('</' + listType + '>\n'); }
                    outLines.push('\n<ol>');
                    inList = true;
                    listType = 'ol';
                }
                outLines.push('<li>' + olMatch[1] + '</li>');
            } else {
                if (inList) {
                    outLines.push('</' + listType + '>\n');
                    inList = false;
                    listType = null;
                }
                outLines.push(line);
            }
        }
        if (inList) {
            outLines.push('</' + listType + '>\n');
        }

        safe = outLines.join('\n');

        // Paragraphs
        var blocks = safe.split(/\n{2,}/);
        var formattedBlocks = [];
        for (var j = 0; j < blocks.length; j++) {
            var b = blocks[j].trim();
            if (!b) { continue; }
            if (/^<(ul|ol|h1|h2|h3|pre|div)/i.test(b)) {
                formattedBlocks.push(b);
            } else {
                formattedBlocks.push('<p>' + b.replace(/\n/g, '<br>') + '</p>');
            }
        }

        var result = formattedBlocks.join('\n');

        // Restore code
        for (var k = 0; k < inlineCode.length; k++) {
            result = result.replace('%%INLINE_CODE_' + k + '%%', inlineCode[k]);
        }
        for (var l = 0; l < codeBlocks.length; l++) {
            result = result.replace('%%CODE_BLOCK_' + l + '%%', codeBlocks[l]);
        }
        for (var a = 0; a < artifacts.length; a++) {
            result = result.replace('%%ARTIFACT_BLOCK_' + a + '%%', artifacts[a]);
        }

        return result;
    }

    function addMessage(cls, text) {
        var empty = log.querySelector('.chat-empty');
        if (empty) { empty.remove(); }

        var el = document.createElement('div');
        el.className = 'msg ' + cls;
        if ((cls === 'msg-assistant' || cls === 'msg-user') && text) {
            el.innerHTML = formatMarkdown(text);
        } else {
            el.textContent = text || '';
        }
        log.appendChild(el);
        el.scrollIntoView({ block: 'end' });
        return el;
    }

    // Shows what the server is actually doing right now, polled from progress.php,
    // so a slow turn tells you which step it is stuck on.
    function addThinking(turnId) {
        var el = addMessage('msg-step', '');
        el.innerHTML = '<span class="dots"><span></span><span></span><span></span></span>'
            + '<span class="step-text">Thinking</span><span class="step-time"></span>';

        var label   = el.querySelector('.step-text');
        var time    = el.querySelector('.step-time');
        var started = Date.now();

        function tick() {
            var secs = Math.round((Date.now() - started) / 1000);
            time.textContent = secs >= 2 ? '  ' + secs + 's' : '';
        }

        var clock = setInterval(tick, 1000);

        var poll = setInterval(function () {
            fetch('progress.php?turn=' + encodeURIComponent(turnId), { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (p) { if (p.step) { label.textContent = p.step; } })
                .catch(function () { /* a missed poll is not worth reporting */ });
        }, 400);

        el.stop = function () {
            clearInterval(poll);
            clearInterval(clock);
            el.remove();
        };

        tick();
        return el;
    }

    function reveal(el, text) {
        el.textContent = '';
        var i = 0;
        var step = Math.max(1, Math.round(text.length / 300));

        (function tick() {
            i += step;
            if (i < text.length) {
                el.textContent = text.slice(0, i);
                el.scrollIntoView({ block: 'end' });
                setTimeout(tick, 15);
            } else {
                el.innerHTML = formatMarkdown(text);
                el.scrollIntoView({ block: 'end' });
            }
        })();
    }

    function autoGrow() {
        input.style.height = 'auto';
        var wanted = input.scrollHeight;
        input.style.height = Math.min(wanted, 180) + 'px';
        input.style.overflowY = wanted > 180 ? 'auto' : 'hidden';
    }

    input.addEventListener('input', autoGrow);
    autoGrow();

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    function captureScreenshotOnDemand(callback) {
        if (!framed) {
            callback(null);
            return;
        }
        var timeoutId = setTimeout(function () {
            var idx = screenshotWaiters.indexOf(done);
            if (idx !== -1) {
                screenshotWaiters.splice(idx, 1);
            }
            callback(null);
        }, 2500);

        function done(dataUrl) {
            clearTimeout(timeoutId);
            callback(dataUrl);
        }

        screenshotWaiters.push(done);
        window.parent.postMessage({ type: 'mya:capture_screenshot' }, '*');
    }

    function confirmScreenshotModal(onDecision) {
        var existing = document.getElementById('screenshot-confirm-modal');
        if (existing) { existing.remove(); }

        var modal = document.createElement('div');
        modal.id = 'screenshot-confirm-modal';
        modal.className = 'mya-confirm-modal-overlay';
        modal.innerHTML = ''
            + '<div class="mya-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="screenshot-modal-title">'
            + '  <div class="mya-confirm-icon">&#128247;</div>'
            + '  <h3 class="mya-confirm-title" id="screenshot-modal-title">Allow Screenshot Capture?</h3>'
            + '  <p class="mya-confirm-desc">Notebook wants to capture a screenshot of your active browser tab to share visual context with AI.</p>'
            + '  <div class="mya-confirm-actions">'
            + '    <button type="button" class="btn btn-quiet mya-confirm-btn-deny" id="btn-screenshot-deny">Don\'t Allow</button>'
            + '    <button type="button" class="btn btn-primary mya-confirm-btn-allow" id="btn-screenshot-allow">Allow</button>'
            + '  </div>'
            + '</div>';

        document.body.appendChild(modal);

        function cleanup(allowed) {
            window.removeEventListener('keydown', keyHandler);
            if (modal.parentNode) {
                modal.parentNode.removeChild(modal);
            }
            onDecision(allowed);
        }

        function keyHandler(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                cleanup(false);
            }
        }

        window.addEventListener('keydown', keyHandler);

        document.getElementById('btn-screenshot-deny').addEventListener('click', function () {
            cleanup(false);
        });

        document.getElementById('btn-screenshot-allow').addEventListener('click', function () {
            cleanup(true);
        });

        var allowBtn = document.getElementById('btn-screenshot-allow');
        if (allowBtn) { allowBtn.focus(); }
    }

    function setBusy(isBusy) {
        busy = isBusy;
        if (sendBtn) {
            if (isBusy) {
                sendBtn.classList.add('is-busy');
                sendBtn.setAttribute('title', 'Stop generation');
                sendBtn.setAttribute('aria-label', 'Stop');
                sendBtn.innerHTML = '<span class="composer-stop-icon"></span>';
            } else {
                sendBtn.classList.remove('is-busy');
                sendBtn.setAttribute('title', 'Send');
                sendBtn.setAttribute('aria-label', 'Send');
                sendBtn.innerHTML = '&#8593;';
            }
        }
    }

    function stopGeneration() {
        if (!busy) { return; }
        if (currentAbortController) {
            try { currentAbortController.abort(); } catch (e) {}
            currentAbortController = null;
        }
        if (currentThinking && currentThinking.stop) {
            currentThinking.stop();
            currentThinking = null;
        }
        addMessage('msg-tool', '⏹ Stopped generation.');
        setBusy(false);
        input.focus();
    }

    function doSubmitMessage(text, screenshotToSend) {
        setBusy(true);

        var userText = text;
        var imagesToSend = pendingImages.slice();

        if (imagesToSend.length > 0) {
            for (var i = 0; i < imagesToSend.length; i++) {
                userText += (userText ? '\n\n' : '') + '![Uploaded image](' + imagesToSend[i] + ')';
            }
        }
        if (screenshotToSend) {
            userText += (userText ? '\n\n' : '') + '*(📸 Screenshot captured)*';
        }

        addMessage('msg-user', userText);

        input.value = '';
        pendingImages = [];
        renderPreviewBar();
        autoGrow();

        var turnId   = 't-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
        currentThinking = addThinking(turnId);
        currentAbortController = new AbortController();

        function handleTurnResponse(data) {
            if (!data.ok) {
                if (currentThinking) { currentThinking.stop(); currentThinking = null; }
                addMessage('msg-error', data.error || 'Something went wrong.');
                setBusy(false);
                input.focus();
                return;
            }

            // Check if model requires browser action in active tab
            if (data.requires_browser_action) {
                if (data.tool_summary) {
                    addMessage('msg-tool', '🌐 ' + data.tool_summary);
                }

                executeBrowserAction(data.action, data.params, function (actionResult) {
                    if (!busy) { return; } // cancelled while executing action
                    fetch('api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        signal: currentAbortController ? currentAbortController.signal : undefined,
                        body: JSON.stringify({
                            action: 'resume_browser_action',
                            conversation_id: conversationId,
                            tool_call_id: data.tool_call_id,
                            tool_name: data.tool_name,
                            tool_result: actionResult,
                            history: data.history || [],
                            turn_id: turnId
                        })
                    })
                    .then(function (r) { return r.json(); })
                    .then(handleTurnResponse)
                    .catch(function (err) {
                        if (err.name === 'AbortError') { return; }
                        if (currentThinking) { currentThinking.stop(); currentThinking = null; }
                        addMessage('msg-error', 'Could not resume browser action: ' + err.message);
                        setBusy(false);
                        input.focus();
                    });
                });
                return;
            }

            if (currentThinking) { currentThinking.stop(); currentThinking = null; }
            (data.tools || []).forEach(function (t) {
                addMessage('msg-tool', t.summary);
            });
            var msgEl = addMessage(data.degraded ? 'msg-error' : 'msg-assistant', '');
            reveal(msgEl, data.reply);
            setBusy(false);
            currentAbortController = null;
            input.focus();
        }

        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            signal: currentAbortController.signal,
            body: JSON.stringify({
                message: text,
                conversation_id: conversationId,
                page_context: pageContext,
                turn_id: turnId,
                images: imagesToSend,
                page_screenshot: screenshotToSend,
                allow_browser_control: framed
            })
        })
        .then(function (r) { return r.json(); })
        .then(handleTurnResponse)
        .catch(function (err) {
            if (err.name === 'AbortError') { return; }
            if (currentThinking) { currentThinking.stop(); currentThinking = null; }
            addMessage('msg-error', 'Could not reach the app: ' + err.message);
            setBusy(false);
            currentAbortController = null;
            input.focus();
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) {
            stopGeneration();
            return;
        }

        var text = input.value.trim();
        if (!text && pendingImages.length === 0) { return; }

        if (framed) {
            if (screenshotPermission === true) {
                // Already allowed on this page: capture directly without asking again
                captureScreenshotOnDemand(function (screenshotData) {
                    doSubmitMessage(text, screenshotData);
                });
            } else if (screenshotPermission === false) {
                // Already denied on this page: continue without screenshot
                doSubmitMessage(text, null);
            } else {
                // First message on this page: show confirm box
                confirmScreenshotModal(function (allowScreenshot) {
                    screenshotPermission = allowScreenshot;
                    if (allowScreenshot) {
                        captureScreenshotOnDemand(function (screenshotData) {
                            doSubmitMessage(text, screenshotData);
                        });
                    } else {
                        doSubmitMessage(text, null);
                    }
                });
            }
        } else {
            doSubmitMessage(text, null);
        }
    });

    input.focus();
})();

// Global Artifact Drawer & Modal Controllers
window.myaCurrentArtifact = { title: '', code: '', type: 'html' };

window.myaOpenArtifact = function (title, code, type) {
    window.myaCurrentArtifact = { title: title || 'Visual Preview', code: code || '', type: type || 'html' };

    var drawer = document.getElementById('artifact-drawer');
    var container = document.getElementById('chat-container');
    var modal = document.getElementById('artifact-modal');

    // On Chat page: open 50/50 side-by-side drawer
    if (drawer && container) {
        var titleEl = document.getElementById('artifact-drawer-title');
        var iframe = document.getElementById('artifact-iframe');
        var codeDisplay = document.getElementById('artifact-code-display');

        if (titleEl) titleEl.textContent = window.myaCurrentArtifact.title;
        if (codeDisplay) codeDisplay.textContent = window.myaCurrentArtifact.code;
        if (iframe) iframe.srcdoc = window.myaCurrentArtifact.code;

        drawer.classList.remove('hidden');
        container.classList.add('has-drawer');
        window.myaSwitchArtifactTab('preview');
        return;
    }

    // On Creations or other pages: open full-screen overlay modal
    if (modal) {
        var titleElM = document.getElementById('artifact-modal-title');
        var iframeM = document.getElementById('artifact-modal-iframe');
        var codeDisplayM = document.getElementById('artifact-modal-code-display');

        if (titleElM) titleElM.textContent = window.myaCurrentArtifact.title;
        if (codeDisplayM) codeDisplayM.textContent = window.myaCurrentArtifact.code;
        if (iframeM) iframeM.srcdoc = window.myaCurrentArtifact.code;

        modal.classList.remove('hidden');
        window.myaSwitchModalTab('preview');
    }
};

window.myaCloseArtifact = function () {
    var drawer = document.getElementById('artifact-drawer');
    var container = document.getElementById('chat-container');
    if (drawer) { drawer.classList.add('hidden'); }
    if (container) { container.classList.remove('has-drawer'); }
};

window.myaCloseArtifactModal = function () {
    var modal = document.getElementById('artifact-modal');
    if (modal) { modal.classList.add('hidden'); }
};

window.myaSwitchArtifactTab = function (tab) {
    var prevPane = document.getElementById('artifact-view-preview');
    var codePane = document.getElementById('artifact-view-code');
    var prevTab = document.getElementById('art-tab-preview');
    var codeTab = document.getElementById('art-tab-code');

    if (tab === 'code') {
        if (prevPane) prevPane.classList.remove('active');
        if (codePane) codePane.classList.add('active');
        if (prevTab) prevTab.classList.remove('active');
        if (codeTab) codeTab.classList.add('active');
    } else {
        if (codePane) codePane.classList.remove('active');
        if (prevPane) prevPane.classList.add('active');
        if (codeTab) codeTab.classList.remove('active');
        if (prevTab) prevTab.classList.add('active');
    }
};

window.myaSwitchModalTab = function (tab) {
    var prevPane = document.getElementById('artifact-modal-view-preview');
    var codePane = document.getElementById('artifact-modal-view-code');
    var prevTab = document.getElementById('art-modal-tab-preview');
    var codeTab = document.getElementById('art-modal-tab-code');

    if (tab === 'code') {
        if (prevPane) prevPane.classList.remove('active');
        if (codePane) codePane.classList.add('active');
        if (prevTab) prevTab.classList.remove('active');
        if (codeTab) codeTab.classList.add('active');
    } else {
        if (codePane) codePane.classList.remove('active');
        if (prevPane) prevPane.classList.add('active');
        if (codeTab) codeTab.classList.remove('active');
        if (prevTab) prevTab.classList.add('active');
    }
};

window.myaCopyArtifactCode = function () {
    var code = window.myaCurrentArtifact.code;
    if (navigator.clipboard && code) {
        navigator.clipboard.writeText(code).then(function () {
            alert('Artifact code copied to clipboard!');
        });
    }
};

window.myaDownloadArtifact = function () {
    var code = window.myaCurrentArtifact.code;
    var title = window.myaCurrentArtifact.title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'artifact';
    if (!code) return;

    var blob = new Blob([code], { type: 'text/html;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = title + '.html';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
};

// Event delegation for Open Preview button click (works on Chat & Creations pages)
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-open-artifact');
    if (!btn) return;
    var containerBox = btn.closest('.artifact-card') || btn.closest('.creation-card-actions') || btn.closest('.creation-card');
    if (!containerBox) return;

    var scriptTag = containerBox.querySelector('.artifact-code-data');
    if (!scriptTag) return;

    var title = btn.getAttribute('data-title') || 'Visual Preview';
    var type = btn.getAttribute('data-type') || 'html';
    var rawB64 = scriptTag.textContent.trim();
    var code = '';
    try {
        code = decodeURIComponent(escape(atob(rawB64)));
    } catch (err) {
        code = rawB64;
    }

    window.myaOpenArtifact(title, code, type);
});

// Image Modal Lightbox Controller
window.myaOpenImageModal = function (url) {
    if (!url) return;
    var overlay = document.getElementById('image-lightbox-modal');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'image-lightbox-modal';
        overlay.className = 'image-lightbox-modal';
        overlay.innerHTML = '<div class="lightbox-content">'
            + '<button class="lightbox-close" onclick="window.myaCloseImageModal()">&times;</button>'
            + '<img id="lightbox-img" src="" alt="Full view">'
            + '</div>';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) window.myaCloseImageModal();
        });
    }
    document.getElementById('lightbox-img').src = url;
    overlay.classList.add('active');
};

window.myaCloseImageModal = function () {
    var overlay = document.getElementById('image-lightbox-modal');
    if (overlay) overlay.classList.remove('active');
};

// Web Search Toggle Controller
var searchToggleBtn = document.getElementById('web-search-toggle');
if (searchToggleBtn) {
    searchToggleBtn.addEventListener('click', function () {
        searchToggleBtn.disabled = true;
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'toggle_web_search' })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            searchToggleBtn.disabled = false;
            if (data && data.ok) {
                var statusEl = document.getElementById('web-search-status');
                if (data.enabled) {
                    searchToggleBtn.classList.add('is-active');
                    if (statusEl) statusEl.textContent = 'Web Search: ON';
                } else {
                    searchToggleBtn.classList.remove('is-active');
                    if (statusEl) statusEl.textContent = 'Web Search: OFF';
                }
            }
        })
        .catch(function () {
            searchToggleBtn.disabled = false;
        });
    });
}
