/**
 * "Почта": the letter list (open in the right-side panel, select, mark read,
 * "Показать ещё", hotkeys), the open letter (images, quick reply) and the
 * letter editor (recipients with suggestions, formatting, sending).
 * Handlers are delegated to document, so they survive Pjax reloads and work
 * both on the list page and inside the letter panel.
 */
(function ($) {
    'use strict';

    var root = '[data-sx-mail]';

    function csrf(data) {
        data = data || {};
        data[yii.getCsrfParam()] = yii.getCsrfToken();
        return data;
    }

    function notify(type, text) {
        var w = mainWindow();
        if (w.sx && w.sx.notify && w.sx.notify[type]) {
            w.sx.notify[type](text);
        }
    }

    /** The window that shows the letter list (this one or an opener of the panel). */
    function mainWindow() {
        var candidates = [window, window.parent, window.top];
        for (var i = 0; i < candidates.length; i++) {
            try {
                if (candidates[i] && candidates[i].sxMail && candidates[i].sxMail.hasList()) {
                    return candidates[i];
                }
            } catch (e) {
            }
        }
        // No list open: the window holding the panel stack.
        for (var j = 1; j < candidates.length; j++) {
            try {
                if (candidates[j] && candidates[j] !== window && candidates[j].sx && candidates[j].sx.BackendWindowStack) {
                    return candidates[j];
                }
            } catch (e) {
            }
        }
        return window;
    }

    /** The backend panel stack of the main window. */
    function windowStack() {
        var w = mainWindow();
        try {
            return w.sx && w.sx.BackendWindowStack ? w.sx.BackendWindowStack : null;
        } catch (e) {
            return null;
        }
    }

    /** Closes the backend panel this page is shown in. */
    function closeOwnPanel() {
        var stack = windowStack();
        if (!stack) {
            return false;
        }
        for (var i = stack.entries.length - 1; i >= 0; i--) {
            if (stack.entries[i].iframe.contentWindow === window) {
                stack.close(stack.entries[i].widget);
                return true;
            }
        }
        return false;
    }

    function isTyping(target) {
        return $(target).closest('input, textarea, select, [contenteditable="true"]').length > 0;
    }

    /* ------------------------------------------------------------------
     * The letter frame
     * ------------------------------------------------------------------ */

    /**
     * The letter frame takes the full height of the letter, so the panel
     * scrolls as one page. Images and fonts load later: keep measuring.
     */
    window.sxMailFitFrame = function (frame) {
        var doc;
        try {
            doc = frame.contentWindow.document;
        } catch (e) {
            return;
        }
        // Measured early (text parsed) and again on load: one set of watchers per document.
        if (frame.sxMailFitDoc === doc) {
            frame.sxMailFit();
            return;
        }
        var fit = function () {
            var html = doc.documentElement ? doc.documentElement.scrollHeight : 0;
            var body = doc.body ? doc.body.scrollHeight : 0;
            // The html is at least as tall as the frame: when nothing overflows it, the body tells
            // the real height, so the frame also shrinks (a folded quote, a short letter).
            var height = html > frame.clientHeight ? Math.max(html, body) : (body || html);
            if (height > 0) {
                // The minimum height only keeps the place while the letter loads: a short letter is short.
                frame.style.minHeight = '0';
                frame.style.height = (height + 8) + 'px';
            }
        };
        frame.sxMailFitDoc = doc;
        frame.sxMailFit = fit;
        fit();
        if (window.ResizeObserver && doc.body) {
            new ResizeObserver(fit).observe(doc.body);
        }
        Array.prototype.forEach.call(doc.images || [], function (img) {
            if (!img.complete) {
                img.addEventListener('load', fit);
                img.addEventListener('error', fit);
            }
        });
        setTimeout(fit, 500);
        setTimeout(fit, 2000);
    };

    /**
     * The frame "load" waits for every image of the letter, and a slow
     * sender server would keep the letter cut to the frame's minimum height.
     * So the frame is fitted as soon as its text is parsed, and a note says
     * that images from the internet are still loading.
     */
    function setFrameDoc(frame, doc) {
        var loaded = false;
        var $note = null;
        var waitText = function () {
            if (loaded) {
                return;
            }
            var current;
            try {
                current = frame.contentDocument;
            } catch (e) {
                return;
            }
            if (!current || current.URL !== 'about:srcdoc' || current.readyState === 'loading') {
                setTimeout(waitText, 50);
                return;
            }
            syncQuote(frame);
            window.sxMailFitFrame(frame);
            var pending = Array.prototype.some.call(current.images || [], function (img) {
                return !img.complete && /^https?:/i.test(img.currentSrc || img.src);
            });
            if (pending) {
                $note = $('<div class="sx-letter__images sx-letter__fetching" data-sx-letter-remote-loading>'
                    + '<span class="sx-letter__spinner" aria-hidden="true"></span>'
                    + '<span>Загружаются картинки с сайта отправителя — текст письма уже можно читать.</span></div>');
                $(frame).before($note);
            }
        };
        frame.addEventListener('load', function onLoad() {
            frame.removeEventListener('load', onLoad);
            loaded = true;
            if ($note) {
                $note.remove();
            }
            syncQuote(frame);
            window.sxMailFitFrame(frame);
        });
        frame.srcdoc = doc;
        setTimeout(waitText, 0);
    }

    /**
     * The quoted history under the own text is folded on the server
     * (MailText::foldQuotedHtml marks the frame html): "Показать переписку"
     * under the frame shows it. The choice survives a reload of the frame
     * (images shown).
     */
    function syncQuote(frame) {
        var doc;
        try {
            doc = frame.contentDocument;
        } catch (e) {
            return;
        }
        if (!doc || !doc.documentElement) {
            return;
        }
        var hasQuote = doc.documentElement.getAttribute('data-sx-mail-quote') === '1';
        doc.documentElement.classList.toggle('sx-mail-quote-open', !!frame.sxQuoteOpen);
        $(frame).closest('.sx-letter__body').find('[data-sx-letter-quote-toggle]').prop('hidden', !hasQuote);
    }

    $(document).on('click', '[data-sx-letter-quote-toggle]', function () {
        var $toggle = $(this);
        var $body = $toggle.closest('.sx-letter__body');
        var open = $toggle.attr('aria-expanded') !== 'true';
        $toggle.attr('aria-expanded', open ? 'true' : 'false')
            .find('[data-sx-letter-quote-label]').text(open ? 'Скрыть переписку' : 'Показать переписку');
        $body.find('[data-sx-letter-text-quote]').prop('hidden', !open);
        var frame = $body.find('[data-sx-letter-frame]').get(0);
        if (!frame) {
            return;
        }
        frame.sxQuoteOpen = open;
        syncQuote(frame);
        var doc = frame.contentDocument;
        if (!open && doc && doc.body) {
            // The fit only grows the frame (its html is as tall as the frame): fit the folded letter here.
            var scrollY = window.scrollY;
            frame.style.height = (doc.body.scrollHeight + 8) + 'px';
            window.scrollTo(window.scrollX, scrollY);
        }
        if (frame.sxMailFit) {
            frame.sxMailFit();
        }
    });

    // The letter is put into its frame only after the page has loaded, so the
    // panel never waits for the letter's own resources (images included).
    function loadLetters() {
        $('[data-sx-letter-frame][data-srcdoc]').each(function () {
            var doc = this.getAttribute('data-srcdoc');
            this.removeAttribute('data-srcdoc');
            setFrameDoc(this, doc);
        });
        fetchImages();
    }

    if (document.readyState === 'complete') {
        setTimeout(loadLetters, 0);
    } else {
        window.addEventListener('load', function () {
            setTimeout(loadLetters, 0);
        });
    }

    /* ------------------------------------------------------------------
     * The letter list
     * ------------------------------------------------------------------ */

    function $mail() {
        return $(root);
    }

    var baseTitle = null;

    /** Folder counters and the unread count in the browser tab title. */
    function applyCounters(counters) {
        if (!counters) {
            return;
        }
        $.each(counters, function (key, value) {
            $mail().find('[data-sx-mail-count="' + key + '"]').text(value > 0 ? value : '');
        });
        if (counters.all !== undefined) {
            $mail().attr('data-unread-total', counters.all);
        }
        updateTitle();
    }

    function updateTitle() {
        if ($mail().length === 0) {
            return;
        }
        if (baseTitle === null) {
            baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
        }
        var total = parseInt($mail().attr('data-unread-total'), 10) || 0;
        document.title = (total > 0 ? '(' + total + ') ' : '') + baseTitle;
    }

    function postMark(ids, read) {
        return $.post($mail().data('mark-url'), csrf({ids: ids, read: read ? 1 : 0})).done(function (response) {
            applyCounters(response.counters);
        });
    }

    function setRowsRead(ids, read) {
        $.each(ids, function (i, id) {
            var $row = $mail().find('.sx-mail__row[data-id="' + id + '"]');
            if ($row.find('[data-sx-mail-toggle-read]').prop('disabled')) {
                return;
            }
            $row.toggleClass('is-unread', !read);
            $row.find('[data-sx-mail-toggle-read]').attr('title', read ? 'Отметить непрочитанным' : 'Отметить прочитанным');
        });
    }

    function letterPanels() {
        var stack = windowStack();
        if (!stack) {
            return [];
        }
        return stack.entries.filter(function (entry) {
            return entry.widget && entry.widget.get && entry.widget.get('sxMailLetter');
        });
    }

    function openLetter($row) {
        if (!$row.length) {
            return;
        }
        setCursor($row);
        $mail().find('.sx-mail__row.is-open').removeClass('is-open');
        $row.addClass('is-open');
        if ($row.hasClass('is-unread')) {
            setRowsRead([$row.data('id')], true);
            postMark([$row.data('id')], true);
        }
        // One letter panel at a time: the next letter replaces the open one.
        var stack = windowStack();
        letterPanels().forEach(function (entry) {
            stack.close(entry.widget);
        });
        // A record created from the letter marks its panel changed: closing it then refreshes
        // only the list, not the whole page (the standard reaction).
        var action = new sx.classes.backend.widgets.Action({isOpenNewWindow: true, url: $row.data('url'), updateSuccessCallback: function () {
            reloadList();
        }});
        if (action.getWindow()) {
            action.getWindow().set('sxMailLetter', true);
        }
        action.go();
    }

    function rows() {
        return $mail().find('.sx-mail__row');
    }

    function cursorRow() {
        return $mail().find('.sx-mail__row.is-cursor');
    }

    function setCursor($row) {
        rows().removeClass('is-cursor');
        $row.addClass('is-cursor');
        var el = $row.get(0);
        if (el) {
            // Below the sticky head as well: scroll with a margin.
            var rect = el.getBoundingClientRect();
            var head = $mail().find('.sx-mail__head').get(0);
            var top = head ? head.getBoundingClientRect().bottom : 0;
            if (rect.top < top) {
                window.scrollBy(0, rect.top - top - 8);
            } else if (rect.bottom > window.innerHeight) {
                window.scrollBy(0, rect.bottom - window.innerHeight + 8);
            }
        }
    }

    /** Moves the cursor by one row; with an open letter, opens the next one. */
    function step(delta) {
        var $rows = rows();
        if (!$rows.length) {
            return;
        }
        var $current = cursorRow();
        if (!$current.length) {
            $current = $mail().find('.sx-mail__row.is-open');
        }
        var index = $current.length ? $rows.index($current) + delta : (delta > 0 ? 0 : $rows.length - 1);
        if (index < 0 || index >= $rows.length) {
            if (index >= $rows.length && $mail().find('[data-sx-mail-more-wrap]').is(':visible')) {
                loadMore().done(function () {
                    step(delta);
                });
            }
            return;
        }
        var $next = $rows.eq(index);
        if (letterPanels().length) {
            openLetter($next);
        } else {
            setCursor($next);
        }
    }

    // The letter panel moves through the list with j / k; panels update the folder counters.
    window.sxMail = {
        step: step,
        applyCounters: applyCounters,
        hasList: function () {
            return $(root).length > 0;
        },
        folder: function () {
            return String($(root).find('.sx-mail__folder.is-active').data('folder') || '');
        }
    };

    /** Counters changed in a panel (drafts saved or removed): into the list behind it. */
    function applyMainCounters(counters) {
        var w = mainWindow();
        try {
            if (counters && w.sxMail && w.sxMail.hasList()) {
                w.sxMail.applyCounters(counters);
            }
        } catch (e) {
        }
    }

    // "Черновики": × removes a draft with its files.
    $(document).on('click', root + ' [data-sx-mail-draft-delete]', function (e) {
        e.stopPropagation();
        var $row = $(this).closest('.sx-mail__row');
        if (!window.confirm('Удалить черновик?')) {
            return;
        }
        $.post($mail().data('draft-delete-url'), csrf({id: $(this).data('sx-mail-draft-delete')})).done(function (response) {
            $row.remove();
            applyCounters(response.counters);
        }).fail(function () {
            notify('error', 'Не удалось удалить черновик.');
        });
    });

    function selectedIds() {
        return $mail().find('[data-sx-mail-select]:checked').map(function () {
            return this.value;
        }).get();
    }

    /** "Выбрать все письма папки": the action goes to every letter of the folder, not only the shown ones. */
    var folderChosen = false;

    function refreshSelection() {
        var ids = selectedIds();
        var $rows = rows();
        $rows.each(function () {
            $(this).toggleClass('is-selected', $(this).find('[data-sx-mail-select]').is(':checked'));
        });
        $mail().find('.sx-mail__toolbar').find('[data-sx-mail-mark], [data-sx-mail-move], [data-sx-mail-folder-menu], [data-sx-mail-labels-menu]')
            .prop('disabled', ids.length === 0);
        var $all = $mail().find('[data-sx-mail-all]');
        var everyRow = ids.length > 0 && ids.length === $rows.length;
        $all.prop('checked', everyRow);
        $all.prop('indeterminate', ids.length > 0 && ids.length < $rows.length);
        if (!everyRow) {
            folderChosen = false;
        }

        var total = parseInt($mail().data('total'), 10) || 0;
        var $bar = $mail().find('[data-sx-mail-selection]');
        var $folderLink = $bar.find('[data-sx-mail-select-folder]');
        if (ids.length) {
            $bar.find('[data-sx-mail-selected]').text(folderChosen ? 'Выбраны все письма папки: ' + total : plural(ids.length));
            // More letters in the folder than on the screen: offer all of them.
            $folderLink.prop('hidden', !everyRow || total <= ids.length)
                .text(folderChosen ? 'Только показанные' : 'Выбрать все письма папки: ' + total);
            $bar.prop('hidden', false);
        } else {
            $bar.prop('hidden', true);
        }
    }

    $(document).on('click', root + ' [data-sx-mail-select-folder]', function () {
        folderChosen = !folderChosen;
        refreshSelection();
    });

    /** The letters an action applies to: the chosen rows or the whole folder with its filters. */
    function chosen() {
        if (folderChosen) {
            return {all: 1, list: $mail().data('list-filter') || {}};
        }
        var ids = selectedIds();
        if (!ids.length && cursorRow().length) {
            ids = [String(cursorRow().data('id'))];
        }
        return ids.length ? {ids: ids} : null;
    }

    function plural(n) {
        var mod10 = n % 10, mod100 = n % 100;
        var word = (mod10 === 1 && mod100 !== 11) ? 'письмо'
            : (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) ? 'письма' : 'писем';
        return 'Выбрано ' + n + ' ' + word;
    }

    function markSelected(read) {
        var target = chosen();
        if (!target) {
            return;
        }
        if (target.all) {
            $.post($mail().data('mark-url'), csrf($.extend({read: read ? 1 : 0}, target))).done(function (response) {
                applyCounters(response.counters);
                $.pjax.reload({container: '#sx-mail-pjax', timeout: 15000});
            }).fail(function () {
                notify('error', 'Не удалось изменить отметки писем.');
            });
            return;
        }
        var ids = target.ids;
        setRowsRead(ids, read);
        postMark(ids, read).fail(function () {
            setRowsRead(ids, !read);
            notify('error', 'Не удалось изменить отметки писем.');
        });
    }

    var loading = null;

    /** "Показать ещё": the next letters older than the last shown one. */
    function loadMore() {
        if (loading) {
            return loading;
        }
        var $last = rows().last();
        var $wrap = $mail().find('[data-sx-mail-more-wrap]');
        var $button = $wrap.find('[data-sx-mail-more]').prop('disabled', true).text('Загрузка…');
        var url = $mail().data('list-url');
        var params = {portion: 2, before: $last.data('at'), before_id: $last.data('id')};
        loading = $.get(url, params).done(function (response) {
            var known = {};
            rows().each(function () {
                known[$(this).data('id')] = true;
            });
            var $new = $('<div>').html(response.html).children('.sx-mail__row').filter(function () {
                return !known[$(this).data('id')];
            });
            $mail().find('[data-sx-mail-rows]').append($new);
            $wrap.prop('hidden', !response.hasMore);
            refreshSelection();
        }).fail(function () {
            notify('error', 'Не удалось загрузить письма.');
        }).always(function () {
            $button.prop('disabled', false).text('Показать ещё');
            loading = null;
        });
        return loading;
    }

    var lastChecked = null;

    $(document).on('click', root + ' .sx-mail__row', function (e) {
        if ($(e.target).closest('.sx-mail__check, a, button').length) {
            return;
        }
        openLetter($(this));
    });

    // Shift + click selects every letter between the last checked one and this one.
    $(document).on('click', root + ' [data-sx-mail-select]', function (e) {
        var $rows = rows();
        var $row = $(this).closest('.sx-mail__row');
        if (e.shiftKey && lastChecked && lastChecked.closest('body').length) {
            var from = $rows.index(lastChecked), to = $rows.index($row);
            var checked = this.checked;
            $rows.slice(Math.min(from, to), Math.max(from, to) + 1).find('[data-sx-mail-select]').prop('checked', checked);
        }
        lastChecked = $row;
        setCursor($row);
    });

    $(document).on('change', root + ' [data-sx-mail-select]', refreshSelection);

    $(document).on('change', root + ' [data-sx-mail-all]', function () {
        rows().find('[data-sx-mail-select]').prop('checked', this.checked);
        refreshSelection();
    });

    $(document).on('click', root + ' [data-sx-mail-clear]', function () {
        $mail().find('[data-sx-mail-select], [data-sx-mail-all]').prop('checked', false);
        refreshSelection();
    });

    $(document).on('click', root + ' [data-sx-mail-mark]', function () {
        markSelected(String($(this).data('sx-mail-mark')) === '1');
    });

    // The dot of a row toggles its read mark without opening it.
    $(document).on('click', root + ' [data-sx-mail-toggle-read]', function (e) {
        e.stopPropagation();
        var $row = $(this).closest('.sx-mail__row');
        var read = $row.hasClass('is-unread');
        setRowsRead([$row.data('id')], read);
        postMark([$row.data('id')], read);
    });

    $(document).on('click', root + ' [data-sx-mail-more]', function () {
        loadMore();
    });

    // Receive new letters now, then reload the list.
    $(document).on('click', root + ' [data-sx-mail-refresh]', function () {
        var $button = $(this);
        if ($button.hasClass('is-busy')) {
            return;
        }
        $button.addClass('is-busy');
        $.post($mail().data('refresh-url'), csrf()).always(function () {
            setTimeout(function () {
                $.pjax.reload({container: '#sx-mail-pjax', timeout: 15000});
            }, 6000);
        });
    });

    $(document).on('click', root + ' [data-sx-mail-hotkeys]', function () {
        $mail().find('[data-sx-mail-hotkeys-dialog]').prop('hidden', false);
    });

    $(document).on('click', root + ' [data-sx-mail-hotkeys-close], ' + root + ' [data-sx-mail-hotkeys-dialog]', function (e) {
        if (e.target === this || $(this).is('[data-sx-mail-hotkeys-close]')) {
            $mail().find('[data-sx-mail-hotkeys-dialog]').prop('hidden', true);
        }
    });

    $(document).on('pjax:end', function () {
        lastChecked = null;
        folderChosen = false;
        updateTitle();
    });
    $(updateTitle);

    // List hotkeys; e.code keeps them working with any keyboard layout.
    $(document).on('keydown', function (e) {
        if (!$mail().length || e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) {
            return;
        }
        var $dialog = $mail().find('[data-sx-mail-hotkeys-dialog]');
        switch (e.code) {
            case 'ArrowDown':
            case 'KeyJ':
                e.preventDefault();
                step(1);
                break;
            case 'ArrowUp':
            case 'KeyK':
                e.preventDefault();
                step(-1);
                break;
            case 'Enter':
            case 'KeyO':
                if (cursorRow().length) {
                    e.preventDefault();
                    openLetter(cursorRow());
                }
                break;
            case 'KeyX':
                if (cursorRow().length) {
                    var $check = cursorRow().find('[data-sx-mail-select]');
                    $check.prop('checked', !$check.prop('checked'));
                    lastChecked = cursorRow();
                    refreshSelection();
                }
                break;
            case 'KeyI':
            case 'KeyU':
                if (e.shiftKey) {
                    markSelected(e.code === 'KeyI');
                }
                break;
            case 'Delete':
            case 'Digit3':
            case 'KeyE':
            case 'Digit1':
            case 'KeyV':
            case 'KeyM':
                if (listHotkey(e)) {
                    e.preventDefault();
                }
                break;
            case 'KeyC':
                var compose = $mail().find('[data-sx-mail-compose]').get(0);
                if (compose) {
                    e.preventDefault();
                    compose.click();
                }
                break;
            case 'Slash':
                e.preventDefault();
                if (e.shiftKey) {
                    $dialog.prop('hidden', false);
                } else {
                    $mail().find('[data-sx-mail-search]').trigger('focus');
                }
                break;
            case 'Escape':
                if (!$dialog.prop('hidden')) {
                    $dialog.prop('hidden', true);
                } else if (selectedIds().length) {
                    $mail().find('[data-sx-mail-clear]').trigger('click');
                }
                break;
        }
    });

    /* ------------------------------------------------------------------
     * The open letter (inside the panel)
     * ------------------------------------------------------------------ */

    function $letter() {
        return $('[data-sx-letter]');
    }

    /** Refreshes the list behind the panel, if it is open there (only the given folder, when set). */
    function reloadList(onlyFolder) {
        var w = mainWindow();
        try {
            if (onlyFolder && !(w.sxMail && w.sxMail.hasList() && w.sxMail.folder() === onlyFolder)) {
                return;
            }
            if (w.jQuery && w.jQuery('#sx-mail-pjax').length) {
                w.jQuery.pjax.reload({container: '#sx-mail-pjax', timeout: 15000});
            }
        } catch (e) {
        }
    }

    $(document).on('click', '[data-sx-letter] [data-sx-letter-unread]', function () {
        var $letterEl = $(this).closest('[data-sx-letter]');
        var $button = $(this).prop('disabled', true);
        $.post($letterEl.data('mark-url'), csrf({ids: [$letterEl.data('id')], read: 0})).done(function () {
            $button.find('.sx-mail__tool-text').text('Отмечено непрочитанным');
            reloadList();
        }).fail(function () {
            $button.prop('disabled', false);
        });
    });

    function loadFrame(images) {
        var $letterEl = $letter();
        var frame = $letterEl.find('[data-sx-letter-frame]').get(0);
        if (!frame) {
            return $.Deferred().resolve().promise();
        }
        if (images === undefined) {
            images = $letterEl.data('shownImages');
        } else if (images) {
            $letterEl.data('shownImages', true);
        }
        // Not chosen in this letter: the server follows the employee's setting.
        var params = images === undefined ? {} : {images: images ? 1 : 0};
        return $.get($letterEl.data('frame-url'), params).done(function (response) {
            setFrameDoc(frame, response.doc);
        });
    }

    /* Files not kept in the CRM: fetched from the mailbox by a job while the letter is open */

    /**
     * Asks for a fetch and waits until the wanted files have a CRM copy or an
     * error. done(files) gets the latest state of every file of the letter.
     */
    function fetchFromMailbox(kind, fileId, isDone, $source) {
        // $source: an element with data-fetch-url / data-fetch-status-url (the open letter by default).
        var $letterEl = $source || $letter();
        var deferred = $.Deferred();
        var started = Date.now();
        var poll = function () {
            $.get($letterEl.data('fetch-status-url')).done(function (response) {
                // Ready, or the job has finished: show what there is.
                if (isDone(response.files || {}) || response.pending === false) {
                    deferred.resolve(response.files || {});
                } else if (Date.now() - started > 120000) {
                    deferred.reject('Почтовый сервер долго не отвечает. Попробуйте открыть файл ещё раз.');
                } else {
                    setTimeout(poll, 1500);
                }
            }).fail(function () {
                deferred.reject('Не удалось узнать, загрузился ли файл.');
            });
        };
        $.post($letterEl.data('fetch-url'), csrf({kind: kind, fileId: fileId || ''})).done(function (response) {
            if (isDone(response.files || {})) {
                deferred.resolve(response.files || {});
            } else {
                setTimeout(poll, 1200);
            }
        }).fail(function () {
            deferred.reject('Не удалось запросить файл из почтового ящика.');
        });

        return deferred.promise();
    }

    function settled(file) {
        return !file || file.stored || !!file.error;
    }

    // Images of the text: right after the letter opens.
    function fetchImages() {
        var $bar = $letter().find('[data-sx-letter-fetch-images]');
        if (!$bar.length || $bar.data('started')) {
            return;
        }
        $bar.data('started', true);
        fetchFromMailbox('images', null, function (files) {
            // A letter without file rows gets them from the fetch itself.
            var inline = $.map(files, function (file) {
                return file.inline ? file : null;
            });
            return inline.length > 0 && inline.every(settled);
        }).done(function (files) {
            var failed = $.map(files, function (file) {
                return file.inline && !file.stored ? file : null;
            });
            loadFrame().always(function () {
                if (failed.length) {
                    $bar.removeClass('sx-letter__fetching').find('.sx-letter__spinner').remove();
                    $bar.find('[data-sx-letter-fetch-text]').text('Не все картинки удалось загрузить: ' + failed[0].error);
                } else {
                    $bar.remove();
                }
            });
        }).fail(function (error) {
            $bar.removeClass('sx-letter__fetching').find('.sx-letter__spinner').remove();
            $bar.find('[data-sx-letter-fetch-text]').text(error);
        });
    }

    // An attachment: on click, then it is downloaded.
    $(document).on('click', '[data-sx-letter] [data-sx-letter-fetch-file]', function () {
        var $card = $(this);
        if ($card.hasClass('is-loading')) {
            return;
        }
        var id = String($card.data('sx-letter-fetch-file'));
        var $state = $card.find('[data-sx-letter-file-state]');
        $card.addClass('is-loading');
        $state.text('Загружается из почтового ящика…');
        fetchFromMailbox('file', id, function (files) {
            return settled(files[id]);
        }).done(function (files) {
            var file = files[id] || {};
            $card.removeClass('is-loading');
            if (!file.stored) {
                $state.text(file.error || 'Файл не удалось загрузить.');
                return;
            }
            $state.text('Загружено');
            var $link = $('<a class="sx-letter__file" download data-pjax="0"></a>').attr('href', file.url).append($card.children());
            $card.replaceWith($link);
            $link.get(0).click();
        }).fail(function (error) {
            $card.removeClass('is-loading');
            $state.text(error);
        });
    });

    // A letter received before files were kept: describe and fetch all its files, then show them.
    $(document).on('click', '[data-sx-letter] [data-sx-letter-fetch-all]', function () {
        var $cards = $letter().find('[data-sx-letter-fetch-all]');
        if ($cards.hasClass('is-loading')) {
            return;
        }
        $cards.addClass('is-loading').find('[data-sx-letter-file-state]').text('Загружается из почтового ящика…');
        fetchFromMailbox('all', null, function (files) {
            var list = $.map(files, function (file) {
                return file;
            });
            return list.length > 0 && list.every(settled);
        }).done(function () {
            window.location.reload();
        }).fail(function (error) {
            $cards.removeClass('is-loading').find('[data-sx-letter-file-state]').text(error);
        });
    });

    function imagesBar(state) {
        var $bar = $letter().find('[data-sx-letter-images]');
        $bar.find('[data-sx-letter-images-show]').prop('hidden', !state.blocked);
        $bar.find('[data-sx-letter-images-always="1"]').prop('hidden', state.always);
        $bar.find('[data-sx-letter-images-always="0"]').prop('hidden', !state.always);
        $bar.find('[data-sx-letter-images-text]').text(state.blocked
            ? 'Картинки из интернета скрыты: так отправитель не узнает, что письмо открыто.'
            : (state.always ? 'Картинки показываются сразу.' : 'Картинки показаны.'));
        $bar.prop('hidden', false);
    }

    $(document).on('click', '[data-sx-letter] [data-sx-letter-images-show]', function () {
        var always = $letter().find('[data-sx-letter-images-always="1"]').prop('hidden');
        loadFrame(true).done(function () {
            imagesBar({blocked: false, always: always});
        });
    });

    // "Показывать всегда" / "Не показывать сразу": the employee's own setting, changed right here.
    $(document).on('click', '[data-sx-letter] [data-sx-letter-images-always]', function () {
        var always = String($(this).data('sx-letter-images-always')) === '1';
        $.post($letter().data('settings-url'), csrf({showImages: always ? 1 : 0})).done(function (response) {
            if (!response.success) {
                notify('error', 'Не удалось сохранить настройку.');
                return;
            }
            if (always) {
                loadFrame(true).done(function () {
                    imagesBar({blocked: false, always: true});
                });
            } else {
                imagesBar({blocked: false, always: false});
                notify('success', 'Картинки в новых письмах будут скрыты до нажатия «Показать картинки».');
            }
        });
    });

    /** "Ответить", "Ответить всем", "Переслать": the editor right under the letter. */
    function openInlineCompose(url) {
        var $letterEl = $letter();
        var $box = $letterEl.find('[data-sx-letter-compose-box]');
        var $existing = $box.find('[data-sx-compose]');
        if ($existing.length && $existing.data('url') === url) {
            focusCompose($existing);
            return;
        }
        if ($existing.length && composeHasText($existing) && !window.confirm('Заменить начатое письмо?')) {
            return;
        }
        $letterEl.find('[data-sx-letter-reply-bar]').prop('hidden', true);
        $letterEl.find('[data-sx-letter-sent]').prop('hidden', true);
        $box.html('<div class="sx-compose__loading">Загрузка…</div>');
        $.get(url).done(function (html) {
            $box.html(html);
            var $form = $box.find('[data-sx-compose]').data('url', url);
            initCompose($form);
            $box.get(0).scrollIntoView({behavior: 'smooth', block: 'start'});
            focusCompose($form);
        }).fail(function () {
            $box.empty();
            $letterEl.find('[data-sx-letter-reply-bar]').prop('hidden', false);
            notify('error', 'Не удалось открыть форму письма.');
        });
    }

    $(document).on('click', '[data-sx-letter] [data-sx-letter-compose]', function () {
        openInlineCompose($(this).data('sx-letter-compose'));
    });

    // Letter hotkeys inside the panel.
    $(document).on('keydown', function (e) {
        if (!$letter().length || $(root).length || e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) {
            return;
        }
        var click = function (selector) {
            var el = $letter().find(selector).get(0);
            if (el) {
                e.preventDefault();
                el.click();
            }
        };
        switch (e.code) {
            case 'KeyR':
                click('.sx-letter__toolbar [data-sx-letter-compose]:first');
                break;
            case 'KeyA':
                click('[data-sx-letter-reply-all]');
                break;
            case 'KeyF':
                click('[data-sx-letter-forward]');
                break;
            case 'KeyL':
                click('[data-sx-letter-link]');
                break;
            case 'KeyU':
                if (e.shiftKey) {
                    click('[data-sx-letter-unread]');
                }
                break;
            case 'Delete':
                click('[data-sx-letter-move="trash"]');
                break;
            case 'Digit3':
                if (e.shiftKey) {
                    click('[data-sx-letter-move="trash"]');
                }
                break;
            case 'KeyE':
                click('[data-sx-letter-move="archive"]');
                break;
            case 'Digit1':
                if (e.shiftKey) {
                    click('[data-sx-letter-move="junk"], [data-sx-letter-move="restore"]:last');
                }
                break;
            case 'KeyV':
                click('[data-sx-letter-folder-menu]');
                break;
            case 'KeyM':
                click('[data-sx-letter-labels-menu]');
                break;
            case 'KeyJ':
            case 'KeyK':
                var w = mainWindow();
                if (w !== window && w.sxMail) {
                    e.preventDefault();
                    w.sxMail.step(e.code === 'KeyJ' ? 1 : -1);
                }
                break;
            case 'Escape':
                if (closeOwnPanel()) {
                    e.preventDefault();
                }
                break;
        }
    });

    /* ------------------------------------------------------------------
     * The letter editor
     * ------------------------------------------------------------------ */

    var EMAIL = /^[^\s@<>,;]+@[^\s@<>,;]+\.[^\s@<>,;]+$/;

    /** The text editor of a letter form (skeeks/cms RichTextInputWidget). */
    function composeEditor($form) {
        return $form.find('[data-sx-compose-rte]');
    }

    function composeHasText($form) {
        var $rte = composeEditor($form);
        return $rte.length > 0 && !sx.RichText.isEmpty($rte);
    }

    function focusCompose($form) {
        var $to = $form.find('[data-sx-compose-row="to"] [data-sx-compose-chips-input]');
        if (!$form.find('[data-sx-compose-row="to"] [data-sx-compose-chip]').length) {
            $to.trigger('focus');
            return;
        }
        // Caret at the start: the reply is written above the quote.
        sx.RichText.init(composeEditor($form));
        sx.RichText.focus(composeEditor($form), true);
    }

    /* Recipients as chips with suggestions */

    function chipsValue($chips) {
        return $chips.find('[data-sx-compose-chip]').map(function () {
            return $(this).data('email');
        }).get();
    }

    function syncChips($chips) {
        $chips.find('[data-sx-compose-value]').val(chipsValue($chips).join(', '));
        // The recipients changed: the old complaint about them no longer applies.
        $chips.closest('.sx-compose__row').removeClass('has-error').find('[data-sx-compose-error]').text('');
        markDirty($chips.closest('[data-sx-compose]'));
        checkOptOut($chips.closest('[data-sx-compose]'));
    }

    /* Recipients on the stop list of mailings: the letter or SMS still goes,
     * the employee sees a warning (a personal letter is not advertising). */

    function showOptOut($notice, items) {
        if (!items || !items.length) {
            $notice.prop('hidden', true).empty();
            return;
        }
        var text = items.map(function (item) {
            return item.value + ' — ' + String(item.reason).toLowerCase();
        }).join('; ');
        $notice.empty()
            .append($('<span></span>').text((items.length > 1 ? 'Получатели отписались от рассылок: ' : 'Получатель отписался от рассылок: ')
                + text + '. Личное письмо уйдёт, но не отправляйте рекламу.'))
            .prop('hidden', false);
    }

    function requestOptOut($owner, $notice, params) {
        var url = $owner.data('optout-url');
        if (!url || !$notice.length) {
            return;
        }
        clearTimeout($owner.data('optoutTimer'));
        $owner.data('optoutTimer', setTimeout(function () {
            var key = $.param(params);
            if ($owner.data('optoutKey') === key) {
                return;
            }
            $owner.data('optoutKey', key);
            if (!params.emails && !params.phones) {
                showOptOut($notice, []);
                return;
            }
            $.getJSON(url, params).done(function (data) {
                // Only the answer about the current recipients counts.
                if ($owner.data('optoutKey') === key) {
                    showOptOut($notice, data && data.items);
                }
            });
        }, 250));
    }

    function checkOptOut($form) {
        if (!$form.length) {
            return;
        }
        var emails = [];
        $form.find('[data-sx-compose-chips]').each(function () {
            emails = emails.concat(chipsValue($(this)));
        });
        requestOptOut($form, $form.find('[data-sx-compose-optout]'), emails.length ? {emails: emails} : {});
    }

    function addChip($chips, email, name) {
        email = $.trim(String(email)).replace(/^[<"']+|[>"']+$/g, '').toLowerCase();
        if (!email || chipsValue($chips).indexOf(email) !== -1) {
            return;
        }
        var $chip = $('<span class="sx-compose__chip" data-sx-compose-chip tabindex="-1"></span>')
            .data('email', email)
            .attr('title', email)
            .toggleClass('is-invalid', !EMAIL.test(email));
        $('<span class="sx-compose__chip-text"></span>').text(name ? name : email).appendTo($chip);
        $('<button type="button" class="sx-compose__chip-remove" aria-label="Убрать">×</button>').appendTo($chip);
        $chip.insertBefore($chips.find('[data-sx-compose-chips-input]'));
        syncChips($chips);
    }

    /** Typed text → chips: "a@b.ru, Иван <c@d.ru>; e@f.ru". */
    function commitTyped($chips) {
        var $input = $chips.find('[data-sx-compose-chips-input]');
        var text = $.trim($input.val());
        if (!text) {
            return;
        }
        var re = /(?:"([^"]*)"|([^"<>,;]*))\s*<([^<>\s]+@[^<>\s]+)>|([^\s,;<>"]+)/g, m;
        while ((m = re.exec(text)) !== null) {
            if (m[3]) {
                addChip($chips, m[3], $.trim(m[1] || m[2] || ''));
            } else if (m[4]) {
                addChip($chips, m[4]);
            }
        }
        $input.val('');
        hideSuggest($chips);
    }

    function hideSuggest($chips) {
        $chips.find('.sx-compose__suggest').remove();
    }

    var suggestTimer = null;

    function suggest($chips) {
        var $input = $chips.find('[data-sx-compose-chips-input]');
        var q = $.trim($input.val());
        clearTimeout(suggestTimer);
        if (q.length < 2) {
            hideSuggest($chips);
            return;
        }
        suggestTimer = setTimeout(function () {
            var url = $chips.closest('[data-sx-compose]').data('contacts-url');
            $.get(url, {q: q}).done(function (response) {
                if ($.trim($input.val()) !== q) {
                    return;
                }
                hideSuggest($chips);
                var taken = chipsValue($chips);
                var items = (response.items || []).filter(function (item) {
                    return taken.indexOf(item.email) === -1;
                });
                if (!items.length) {
                    return;
                }
                var $list = $('<div class="sx-compose__suggest" role="listbox"></div>');
                items.forEach(function (item, i) {
                    var $item = $('<button type="button" class="sx-compose__suggest-item" role="option"></button>')
                        .data('item', item).toggleClass('is-active', i === 0);
                    $('<span class="sx-compose__suggest-name"></span>').text(item.name || item.email).appendTo($item);
                    if (item.name) {
                        $('<span class="sx-compose__suggest-email"></span>').text(item.email).appendTo($item);
                    }
                    $('<span class="sx-compose__suggest-type"></span>').text(item.type).appendTo($item);
                    $list.append($item);
                });
                $chips.append($list);
            });
        }, 200);
    }

    function pickSuggest($chips, $item) {
        var item = $item.data('item');
        addChip($chips, item.email, item.name);
        $chips.find('[data-sx-compose-chips-input]').val('').trigger('focus');
        hideSuggest($chips);
    }

    $(document).on('input', '[data-sx-compose-chips-input]', function () {
        var $chips = $(this).closest('[data-sx-compose-chips]');
        if (/[,;]/.test(this.value)) {
            commitTyped($chips);
            return;
        }
        suggest($chips);
    });

    $(document).on('keydown', '[data-sx-compose-chips-input]', function (e) {
        var $chips = $(this).closest('[data-sx-compose-chips]');
        var $items = $chips.find('.sx-compose__suggest-item');
        var $active = $items.filter('.is-active');
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && $items.length) {
            e.preventDefault();
            var index = $items.index($active) + (e.key === 'ArrowDown' ? 1 : -1);
            $items.removeClass('is-active').eq((index + $items.length) % $items.length).addClass('is-active');
            return;
        }
        if (e.key === 'Enter' && !(e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            if ($active.length) {
                pickSuggest($chips, $active);
            } else {
                commitTyped($chips);
            }
            return;
        }
        if (e.key === 'Tab' && $active.length && $.trim(this.value)) {
            e.preventDefault();
            pickSuggest($chips, $active);
            return;
        }
        if (e.key === 'Escape' && $items.length) {
            e.stopPropagation();
            hideSuggest($chips);
            return;
        }
        if (e.key === 'Backspace' && this.value === '') {
            $chips.find('[data-sx-compose-chip]').last().remove();
            syncChips($chips);
        }
    });

    $(document).on('blur', '[data-sx-compose-chips-input]', function () {
        var $chips = $(this).closest('[data-sx-compose-chips]');
        // Let a click on a suggestion happen first.
        setTimeout(function () {
            if (!$chips.find(':focus').length) {
                commitTyped($chips);
            }
        }, 150);
    });

    $(document).on('paste', '[data-sx-compose-chips-input]', function () {
        var $chips = $(this).closest('[data-sx-compose-chips]');
        setTimeout(function () {
            commitTyped($chips);
        }, 0);
    });

    $(document).on('mousedown', '.sx-compose__suggest-item', function (e) {
        e.preventDefault();
        pickSuggest($(this).closest('[data-sx-compose-chips]'), $(this));
    });

    $(document).on('click', '.sx-compose__chip-remove', function () {
        var $chips = $(this).closest('[data-sx-compose-chips]');
        $(this).closest('[data-sx-compose-chip]').remove();
        syncChips($chips);
        $chips.find('[data-sx-compose-chips-input]').trigger('focus');
    });

    $(document).on('click', '[data-sx-compose-chips]', function (e) {
        if (e.target === this) {
            $(this).find('[data-sx-compose-chips-input]').trigger('focus');
        }
    });

    /* Rows shown on demand: "Копия", "Скрытая", "От кого" */

    $(document).on('click', '[data-sx-compose-show]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        var key = $(this).data('sx-compose-show');
        var $row = $form.find('[data-sx-compose-row="' + key + '"]').prop('hidden', false);
        if (key !== 'cms_comm_account_id') {
            $(this).prop('hidden', true);
        }
        $row.find('[data-sx-compose-chips-input], select').first().trigger('focus');
    });

    $(document).on('click', '[data-sx-compose-quote-toggle]', function () {
        var $quote = $(this).closest('.sx-compose__quote').find('[data-sx-compose-quote]');
        $quote.prop('hidden', !$quote.prop('hidden'));
        $(this).text($quote.prop('hidden') ? 'Показать' : 'Скрыть');
    });

    /* Files: attachments belong to the shared field (skeeks/cms AttachmentsInputWidget,
     * sx.Attachments): the button, drag and drop onto the letter, paste, the editor's
     * "Прикрепить файлом" zone. Formatting, links, paste and pictures inside the text
     * belong to the shared editor (RichTextInputWidget, sx.RichText). */

    $(document).on('sx-attach:change', '[data-sx-compose] [data-sx-attach]', function () {
        markDirty($(this).closest('[data-sx-compose]'));
    });

    function isUploading($form) {
        return sx.Attachments.isBusy($form) || sx.RichText.isBusy(composeEditor($form));
    }

    /* Sending */

    function initCompose($form) {
        // Filling the form is not a change of the letter: no draft for it.
        $form.data('quiet', true);
        $form.find('[data-sx-compose-chips]').each(function () {
            var $chips = $(this);
            var value = $chips.find('[data-sx-compose-value]').val();
            $chips.find('[data-sx-compose-chips-input]').val(value);
            commitTyped($chips);
        });
        sx.RichText.init(composeEditor($form));
        // A draft has its signature in the text already.
        if (!draftId($form)) {
            setSignature($form, currentAccount($form));
        }
        $form.data('quiet', false);
        fetchSourceFiles($form);
    }

    /* Signatures and quick reply templates */

    var SIGNATURE = 'data-sx-mail-signature';

    function currentAccount($form) {
        return parseInt($form.find('[name$="[cms_comm_account_id]"]').val(), 10) || 0;
    }

    function editorElement($form) {
        return composeEditor($form).find('[data-sx-rte-editor]').get(0);
    }

    /** The edited text into the form value, as typing does (the shared editor syncs on "input"). */
    function editorChanged($form) {
        var editor = editorElement($form);
        if (editor) {
            $(editor).trigger('input');
        }
    }

    /**
     * The employee's signature of the mailbox below the text (the quote is
     * added on sending, so the signature stays above it). Another mailbox
     * replaces it; a mailbox without a signature removes it.
     */
    function setSignature($form, accountId) {
        var editor = editorElement($form);
        if (!editor) {
            return;
        }
        var html = ($form.data('signatures') || {})[accountId] || '';
        var $old = $(editor).find('[' + SIGNATURE + ']');
        var block = html ? '<div ' + SIGNATURE + '="1"><div>--&nbsp;</div>' + html + '</div>' : '';
        if ($old.length) {
            if (block) {
                $old.first().replaceWith(block);
            } else {
                $old.remove();
            }
        } else if (block) {
            // An empty line above it: the letter is typed there.
            $(editor).append('<div><br></div>' + block);
        } else {
            return;
        }
        editorChanged($form);
    }

    $(document).on('change', '[data-sx-compose] select[name$="[cms_comm_account_id]"]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        setSignature($form, currentAccount($form));
        $form.find('[data-sx-compose-templates-menu]').prop('hidden', true);
    });

    /** Inserts a template where the caret is, otherwise at the start of the text (above the signature). */
    function insertTemplate($form, html) {
        var editor = editorElement($form);
        if (!editor) {
            return;
        }
        var selection = window.getSelection();
        if (!selection.rangeCount || !editor.contains(selection.anchorNode)) {
            editor.focus();
            var range = document.createRange();
            var first = editor.firstChild;
            // An empty first line (a reply over its signature) is replaced by the template.
            if (first && first.nodeType === 1 && !first.hasAttribute(SIGNATURE) && $.trim(first.textContent) === '' && !$(first).find('img').length) {
                range.selectNodeContents(first);
            } else if (first) {
                range.setStartBefore(first);
                range.collapse(true);
            } else {
                range.selectNodeContents(editor);
            }
            selection.removeAllRanges();
            selection.addRange(range);
        }
        document.execCommand('insertHTML', false, html);
        editorChanged($form);
    }

    // The caret stays in the text while the menu is used.
    $(document).on('mousedown', '[data-sx-compose-templates-toggle], [data-sx-compose-template]', function (e) {
        e.preventDefault();
    });

    $(document).on('click', '[data-sx-compose-templates-toggle]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        var $menu = $form.find('[data-sx-compose-templates-menu]');
        if (!$menu.prop('hidden')) {
            $menu.prop('hidden', true);
            return;
        }
        var account = currentAccount($form);
        var list = ($form.data('templates') || []).filter(function (t) {
            return !t.account || t.account === account;
        });
        $menu.empty();
        if (!list.length) {
            $('<div class="sx-compose__templates-empty"></div>').text('Шаблонов для этого ящика пока нет.').appendTo($menu);
        }
        list.forEach(function (t) {
            $('<button type="button" class="sx-compose__templates-item" role="menuitem" data-sx-compose-template></button>')
                .text(t.name).data('html', t.html).appendTo($menu);
        });
        $('<button type="button" class="sx-compose__templates-item is-manage" role="menuitem" data-sx-compose-templates-manage></button>')
            .text('Шаблоны и подпись…').appendTo($menu);
        $menu.prop('hidden', false);
    });

    $(document).on('click', '[data-sx-compose-template]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        $form.find('[data-sx-compose-templates-menu]').prop('hidden', true);
        insertTemplate($form, $(this).data('html'));
    });

    $(document).on('click', '[data-sx-compose-templates-manage]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        $form.find('[data-sx-compose-templates-menu]').prop('hidden', true);
        new sx.classes.backend.widgets.Action({isOpenNewWindow: true, url: $form.data('personal-url')}).go();
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('[data-sx-compose-templates]').length) {
            $('[data-sx-compose-templates-menu]').prop('hidden', true);
        }
    });

    /* Drafts: the form saves itself while the employee writes */

    function draftId($form) {
        return parseInt($form.find('[data-sx-compose-draft-id]').val(), 10) || 0;
    }

    function markDirty($form) {
        // The window "Отправить клиенту" keeps no draft.
        if (!$form.length || $form.data('quiet') || $form.data('sent') || $form.data('no-drafts')) {
            return;
        }
        $form.data('dirty', true);
        clearTimeout($form.data('draftTimer'));
        $form.data('draftTimer', setTimeout(function () {
            saveDraft($form);
        }, 2000));
    }

    function saveDraft($form) {
        if ($form.data('sent') || !$form.data('dirty')) {
            return $.Deferred().resolve().promise();
        }
        // One save at a time: a second one waits, so a new draft is never created twice.
        if ($form.data('draftXhr')) {
            $form.data('draftAgain', true);
            return $form.data('draftXhr');
        }
        if (isUploading($form)) {
            clearTimeout($form.data('draftTimer'));
            $form.data('draftTimer', setTimeout(function () {
                saveDraft($form);
            }, 1500));
            return $.Deferred().resolve().promise();
        }
        $form.data('dirty', false);
        var created = !draftId($form);
        var xhr = $.post($form.data('draft-url'), $form.serialize()).done(function (response) {
            if (response.success) {
                $form.find('[data-sx-compose-draft-id]').val(response.id);
                $form.find('[data-sx-compose-draft-status]').text('Черновик сохранён в ' + response.savedAt);
                $form.find('[data-sx-compose-draft-delete]').prop('hidden', false);
                applyMainCounters(response.counters);
                if (created) {
                    reloadList('drafts');
                }
            } else if (response.gone) {
                // Sent or removed in another window.
                $form.data('sent', true);
                $form.find('[data-sx-compose-draft-status]').text('');
            }
        }).fail(function () {
            $form.data('dirty', true);
            $form.find('[data-sx-compose-draft-status]').text('Черновик не сохранён');
        }).always(function () {
            $form.removeData('draftXhr');
            if ($form.data('draftAgain')) {
                $form.removeData('draftAgain');
                saveDraft($form);
            }
        });
        $form.data('draftXhr', xhr);
        return xhr;
    }

    $(document).on('input change', '[data-sx-compose] input, [data-sx-compose] select, [data-sx-compose] textarea', function () {
        // The recipient being typed is saved once it becomes a chip.
        if (!$(this).is('[data-sx-compose-chips-input], [data-sx-attach-input]')) {
            markDirty($(this).closest('[data-sx-compose]'));
        }
    });

    // Closing the window or the panel: the last changes are saved on the way out.
    window.addEventListener('pagehide', function () {
        $('[data-sx-compose]').each(function () {
            var $form = $(this);
            if (!$form.data('dirty') || $form.data('sent') || $form.data('draftXhr') || !navigator.sendBeacon) {
                return;
            }
            $form.data('dirty', false);
            navigator.sendBeacon($form.data('draft-url'), new FormData(this));
        });
    });

    function deleteDraft($form) {
        var id = draftId($form);
        clearTimeout($form.data('draftTimer'));
        $form.data('sent', true);
        if (!id) {
            return $.Deferred().resolve().promise();
        }
        return $.post($form.data('draft-delete-url'), csrf({id: id})).done(function (response) {
            applyMainCounters(response.counters);
            reloadList('drafts');
        });
    }

    $(document).on('click', '[data-sx-compose-draft-delete]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        if (!window.confirm('Удалить черновик?')) {
            return;
        }
        var wait = $form.data('draftXhr') || $.Deferred().resolve().promise();
        $form.data('sent', true);
        $.when(wait).always(function () {
            deleteDraft($form).done(function () {
                var $letterEl = $form.closest('[data-sx-letter]');
                if ($letterEl.length) {
                    $form.remove();
                    $letterEl.find('[data-sx-letter-reply-bar]').prop('hidden', false).find('span').text('Ответить');
                } else if (!closeOwnPanel()) {
                    $form.replaceWith('<div class="sx-compose__done">Черновик удалён.</div>');
                }
            }).fail(function () {
                $form.data('sent', false);
                notify('error', 'Не удалось удалить черновик.');
            });
        });
    });

    /**
     * Forwarding or quoting a letter whose files lie only in the mailbox: they
     * are fetched first, then the form is built again with them attached.
     */
    function fetchSourceFiles($form) {
        var $notice = $form.find('[data-sx-compose-source-fetch]');
        if (!$notice.length || $notice.data('started')) {
            return;
        }
        $notice.data('started', true);
        var kind = $notice.data('kind');
        fetchFromMailbox(kind, null, function (files) {
            var list = $.map(files, function (file) {
                return kind === 'images' && !file.inline ? null : file;
            });
            return list.length > 0 && list.every(settled);
        }, $notice).done(function () {
            var url = $form.data('url');
            if (!url) {
                window.location.reload();
                return;
            }
            $.get(url).done(function (html) {
                var $box = $form.parent();
                $box.html(html);
                var $fresh = $box.find('[data-sx-compose]').data('url', url);
                $fresh.find('[data-sx-compose-source-fetch]').remove();
                initCompose($fresh);
                focusCompose($fresh);
            });
        }).fail(function (error) {
            $notice.find('[data-sx-compose-source-text]').text(error);
            $notice.find('.sx-letter__spinner').remove();
        });
    }

    function showErrors($form, errors) {
        $form.find('[data-sx-compose-error]').text('').closest('.sx-compose__row').removeClass('has-error');
        var first = null;
        $.each(errors || {}, function (attribute, text) {
            var key = attribute === 'body_html' ? 'body' : attribute;
            var $error = $form.find('[data-sx-compose-error="' + key + '"]');
            if (!$error.length) {
                $error = $form.find('[data-sx-compose-error="to"]');
            }
            $error.text(text);
            var $row = $form.find('[data-sx-compose-row="' + key + '"]').prop('hidden', false).addClass('has-error');
            first = first || $row;
        });
        if (first && first.get(0)) {
            first.get(0).scrollIntoView({block: 'nearest'});
        }
    }

    function sendCompose($form) {
        var $send = $form.find('[data-sx-compose-send]');
        if ($send.prop('disabled')) {
            return;
        }
        if (isUploading($form)) {
            notify('error', 'Дождитесь, пока загрузятся файлы.');
            return;
        }
        $form.find('[data-sx-compose-chips]').each(function () {
            commitTyped($(this));
        });
        // The editor keeps its text in the body_html field itself.
        $send.prop('disabled', true);
        $form.find('[data-sx-compose-status]').text('Отправка…');
        // No autosave while sending; a save under way finishes first, so the draft id is known.
        clearTimeout($form.data('draftTimer'));
        $form.data('sent', true);
        var resume = function () {
            $form.data('sent', false);
            $send.prop('disabled', false);
        };

        $.when($form.data('draftXhr')).always(function () {
            $.post($form.attr('action'), $form.serialize()).done(function (response) {
                if (!response.success) {
                    $form.find('[data-sx-compose-status]').text('');
                    showErrors($form, response.errors);
                    resume();
                    return;
                }
                applyMainCounters(response.counters);
                afterSent($form, response);
            }).fail(function () {
                $form.find('[data-sx-compose-status]').text('');
                resume();
                notify('error', 'Письмо не отправлено: ошибка на сервере. Попробуйте ещё раз.');
            });
        });
    }

    function afterSent($form, response) {
        reloadList();
        notify('success', 'Письмо отправляется');
        var $letterEl = $form.closest('[data-sx-letter]');
        if ($letterEl.length) {
            var to = $form.find('[data-sx-compose-row="to"] [data-sx-compose-chip]').map(function () {
                return $(this).text().replace('×', '');
            }).get().join(', ');
            $form.closest('[data-sx-letter-compose-box]').empty();
            $letterEl.find('[data-sx-letter-sent]').text('Письмо отправляется: ' + to + '. Оно появится в «Отправленных».').prop('hidden', false);
            // The draft of the answer is gone with the sent letter.
            $letterEl.find('[data-sx-letter-reply-bar]').prop('hidden', false).find('span').text('Ответить');
            return;
        }
        if (!closeOwnPanel()) {
            $form.replaceWith('<div class="sx-compose__done">Письмо отправляется.</div>');
        }
    }

    $(document).on('submit', '[data-sx-compose]', function (e) {
        e.preventDefault();
        sendCompose($(this));
    });

    $(document).on('keydown', '[data-sx-compose]', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            sendCompose($(this));
        }
    });

    $(document).on('click', '[data-sx-compose-cancel]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        if (composeHasText($form) && !window.confirm('Удалить начатое письмо?')) {
            return;
        }
        // "Отменить" discards the answer: its draft goes too.
        var wait = $form.data('draftXhr') || $.Deferred().resolve().promise();
        clearTimeout($form.data('draftTimer'));
        $form.data('sent', true);
        $.when(wait).always(function () {
            deleteDraft($form);
        });
        var $letterEl = $form.closest('[data-sx-letter]');
        // detach(), not remove(): the form keeps its state until its draft is deleted.
        $form.detach();
        $letterEl.find('[data-sx-letter-reply-bar]').prop('hidden', false).find('span').text('Ответить');
    });

    // A full-page editor ("Написать" panel) is ready on load.
    $(function () {
        $('[data-sx-compose]').each(function () {
            var $form = $(this);
            initCompose($form);
            // The panel focuses its close button once the frame has loaded: focus after it.
            window.addEventListener('load', function () {
                setTimeout(function () {
                    window.focus();
                    focusCompose($form);
                }, 300);
            });
        });
    });

    /* ------------------------------------------------------------------
     * Sorting letters: CRM links, records created from a letter, the
     * responsible person, list filters and the row menu
     * ------------------------------------------------------------------ */

    /** A standard backend window; onDone runs after it closes with a saved record. */
    function openBackendWindow(url, onDone) {
        var options = {isOpenNewWindow: true, url: url};
        if (onDone) {
            options.updateSuccessCallback = onDone;
        }
        return new sx.classes.backend.widgets.Action(options).go();
    }

    /** Reloads open letter panels (only the letter id, when given): new links and marks show at once. */
    function refreshLetters(id) {
        letterPanels().forEach(function (entry) {
            try {
                var w = entry.iframe.contentWindow;
                var $el = w.jQuery ? w.jQuery('[data-sx-letter]') : $();
                if (w !== window && (!id || String($el.data('id')) === String(id))) {
                    w.location.reload();
                }
            } catch (e) {
            }
        });
    }

    /** After a change made inside the open letter: this letter and the list behind it. */
    function letterChanged() {
        reloadList();
        window.location.reload();
    }

    function closeMenus() {
        // Labels changed from the list menu: the rows show them once the menu is closed.
        var $floating = $(root + ' [data-sx-mail-floating]');
        if ($floating.data('changed') && !$floating.prop('hidden')) {
            $floating.data('changed', false);
            setTimeout(function () {
                $.pjax.reload({container: '#sx-mail-pjax', timeout: 15000});
            }, 0);
        }
        $(".sx-mail__menu").prop("hidden", true);
        $(".sx-mail__row-menu.is-active, .sx-letter__unlink.is-active").removeClass("is-active");
    }

    $(document).on('click', function (e) {
        // A menu item that rebuilt its menu is no longer in the page: the click was inside.
        if (!document.documentElement.contains(e.target)) {
            return;
        }
        if (!$(e.target).closest('.sx-mail__menu, [data-sx-mail-menu-toggle], [data-sx-letter-assign], [data-sx-letter-unlink], [data-sx-mail-row-menu], '
            + '[data-sx-mail-folder-menu], [data-sx-mail-labels-menu], [data-sx-letter-folder-menu], [data-sx-letter-labels-menu], [data-sx-mail-context-action]').length) {
            closeMenus();
        }
    });
    // Esc closes an open menu first (capture phase: before the panel closes on Esc).
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && $('.sx-mail__menu:not([hidden])').length) {
            e.stopPropagation();
            closeMenus();
        }
    }, true);

    $(document).on('click', '[data-sx-mail-menu-toggle]', function () {
        var $menu = $(this).siblings('[data-sx-mail-menu]');
        var open = $menu.prop('hidden');
        closeMenus();
        $menu.prop('hidden', !open);
    });

    // "Создать задачу / лид / сделку": the standard create window, filled from the letter.
    $(document).on('click', '[data-sx-letter] [data-sx-letter-create]', function () {
        closeMenus();
        openBackendWindow($(this).data('sx-letter-create'), letterChanged);
    });

    // "Привязать": the window with the CRM choice; it refreshes this letter itself.
    $(document).on('click', '[data-sx-letter] [data-sx-letter-link]', function () {
        openBackendWindow($(this).data('sx-letter-link'));
    });

    // "×" of a linked record: this letter or the whole conversation.
    $(document).on('click', '[data-sx-letter] [data-sx-letter-unlink]', function (e) {
        e.preventDefault();
        var $button = $(this);
        var $wrap = $button.closest('.sx-letter__record-wrap');
        var $menu = $wrap.find('[data-sx-letter-unlink-menu]');
        if (!$menu.length) {
            var data = $button.data('sx-letter-unlink');
            $menu = $('<span class="sx-mail__menu sx-mail__menu--right" data-sx-letter-unlink-menu hidden></span>');
            $('<span class="sx-mail__menu-note"></span>').text('Отвязать от «' + data.name + '»').appendTo($menu);
            $('<button type="button" class="sx-mail__menu-item" data-conversation="0">Только это письмо</button>').appendTo($menu);
            $('<button type="button" class="sx-mail__menu-item" data-conversation="1">Всю переписку</button>').appendTo($menu);
            $menu.data('sxOwner', $button).appendTo($wrap);
        }
        var open = $menu.prop('hidden');
        closeMenus();
        $menu.prop('hidden', !open);
        $button.toggleClass('is-active', open);
    });

    $(document).on('click', '[data-sx-letter-unlink-menu] [data-conversation]', function () {
        var $item = $(this);
        var data = $item.closest('.sx-letter__record-wrap').find('[data-sx-letter-unlink]').data('sx-letter-unlink');
        var $letterEl = $item.closest('[data-sx-letter]');
        $item.prop('disabled', true);
        $.post($letterEl.data('unlink-url'), csrf({code: data.code, id: data.id, conversation: $item.data('conversation')})).done(function (response) {
            if (response.success) {
                notify('success', response.message);
                letterChanged();
            } else {
                notify('error', response.message);
                $item.prop('disabled', false);
            }
        }).fail(function () {
            notify('error', 'Не удалось отвязать письмо.');
            $item.prop('disabled', false);
        });
    });

    /** Fills a menu with the employees who may be responsible for the letter. */
    function assignMenu($menu, url, onPick) {
        $menu.empty().append('<span class="sx-mail__menu-note">Загрузка…</span>').prop('hidden', false);
        $.get(url).done(function (response) {
            $menu.empty();
            var items = response.items || [];
            var me = items.filter(function (item) {
                return item.me;
            })[0];
            if (me && response.current !== me.id) {
                $('<button type="button" class="sx-mail__menu-item"></button>').text('Назначить меня').data('user', me.id).appendTo($menu);
                $('<span class="sx-mail__menu-sep"></span>').appendTo($menu);
            }
            items.forEach(function (item) {
                $('<button type="button" class="sx-mail__menu-item"></button>').text(item.name)
                    .toggleClass('is-current', item.id === response.current).data('user', item.id).appendTo($menu);
            });
            if (response.current) {
                $('<span class="sx-mail__menu-sep"></span>').appendTo($menu);
                $('<button type="button" class="sx-mail__menu-item"></button>').text('Снять ответственного').data('user', 0).appendTo($menu);
            }
            if (!items.length) {
                $('<span class="sx-mail__menu-note">Письмо не видно ни одному сотруднику, кроме вас.</span>').appendTo($menu);
            }
            $menu.find('.sx-mail__menu-item').on('click', function () {
                onPick($(this).data('user'));
            });
        }).fail(function () {
            $menu.empty().append('<span class="sx-mail__menu-note">Не удалось загрузить сотрудников.</span>');
        });
    }

    function postAssign(url, userId) {
        return $.post(url, csrf({user_id: userId || ''})).done(function (response) {
            if (response.success) {
                notify('success', response.message);
                applyMainCounters(response.counters);
            } else {
                notify('error', response.message);
            }
        }).fail(function () {
            notify('error', 'Не удалось назначить ответственного.');
        });
    }

    $(document).on('click', '[data-sx-letter] [data-sx-letter-assign]', function () {
        var $letterEl = $(this).closest('[data-sx-letter]');
        var $menu = $letterEl.find('[data-sx-letter-assign-menu]');
        if (!$menu.prop('hidden')) {
            closeMenus();
            return;
        }
        closeMenus();
        assignMenu($menu, $letterEl.data('assign-url'), function (userId) {
            closeMenus();
            postAssign($letterEl.data('assign-url'), userId).done(function (response) {
                if (response.success) {
                    letterChanged();
                }
            });
        });
    });

    // Filters of the list: each option is the URL of the filtered list.
    $(document).on('change', root + ' [data-sx-mail-filter]', function () {
        $.pjax({url: this.value, container: '#sx-mail-pjax', timeout: 15000});
    });

    // The row menu: right click on a row or its "⋯".
    var contextRow = null;

    function contextConfig() {
        return $mail().find('[data-sx-mail-context]').data('config') || {};
    }

    function openContext($row, x, y, $owner) {
        var $menu = $mail().find('[data-sx-mail-context]');
        if (!$menu.length) {
            return;
        }
        closeMenus();
        contextRow = $row;
        setCursor($row);
        var unread = $row.hasClass('is-unread');
        var readable = !$row.find('[data-sx-mail-toggle-read]').prop('disabled');
        $menu.find('[data-sx-mail-context-action="read"]').prop('hidden', !readable || !unread);
        $menu.find('[data-sx-mail-context-action="unread"]').prop('hidden', !readable || unread);
        $menu.prop('hidden', false);
        var width = $menu.outerWidth(), height = $menu.outerHeight();
        $menu.css({
            left: Math.max(8, Math.min(x, window.innerWidth - width - 8)),
            top: Math.max(8, y + height > window.innerHeight - 8 ? y - height : y)
        });
        if ($owner) {
            $owner.addClass('is-active');
        }
    }

    $(document).on('contextmenu', root + ' .sx-mail__row', function (e) {
        if ($(e.target).closest('a, input').length) {
            return;
        }
        e.preventDefault();
        openContext($(this), e.clientX, e.clientY);
    });

    $(document).on('click', root + ' [data-sx-mail-row-menu]', function (e) {
        e.stopPropagation();
        var $button = $(this);
        if ($button.hasClass('is-active')) {
            closeMenus();
            return;
        }
        var rect = this.getBoundingClientRect();
        openContext($button.closest('.sx-mail__row'), rect.right - 230, rect.bottom + 4, $button);
    });

    $(document).on('click', root + ' [data-sx-mail-context-action]', function () {
        var $row = contextRow;
        var action = $(this).data('sx-mail-context-action');
        closeMenus();
        if (!$row || !$row.length) {
            return;
        }
        var id = String($row.data('id'));
        var config = contextConfig();
        var withId = function (url) {
            return String(url).split('__ID__').join(id);
        };
        var changed = function () {
            reloadList();
            refreshLetters(id);
        };
        if (action === 'open') {
            openLetter($row);
        } else if (action === 'read' || action === 'unread') {
            setRowsRead([id], action === 'read');
            postMark([id], action === 'read');
        } else if (action === 'link') {
            openBackendWindow(withId(config.link));
        } else if (action === 'create') {
            var create = (config.create || {})[$(this).data('type')];
            if (create) {
                openBackendWindow(withId(create.url), changed);
            }
        } else if (action === 'assign-me') {
            postAssign(withId(config.assign), config.me).done(function (response) {
                if (response.success) {
                    changed();
                }
            });
        } else if (action === 'move') {
            moveLetters({ids: [id]}, $(this).data('move'));
        } else if (action === 'folder' || action === 'labels') {
            var rect = $row.get(0).getBoundingClientRect();
            openListMenu(action, {ids: [id]}, {left: Math.min(rect.right - 280, window.innerWidth - 300), top: rect.bottom + 2});
        }
    });

    /* ------------------------------------------------------------------
     * Actions in the mailbox: trash, archive, spam, a folder, labels
     * (MailboxActions on the server; a job then moves letters there)
     * ------------------------------------------------------------------ */

    /** Whether letters leave the open folder after the action: they go from the screen at once. */
    function leavesFolder(action) {
        var kind = String($mail().data('folder-kind') || '');
        if (kind === 'in' || kind === 'out' || kind === 'archive' || kind === 'junk' || kind === 'trash') {
            return true;
        }
        return action === 'trash' || action === 'junk';
    }

    function closeLetterPanels(ids) {
        var stack = windowStack();
        letterPanels().forEach(function (entry) {
            try {
                var $el = entry.iframe.contentWindow.jQuery('[data-sx-letter]');
                if (!ids || ids.indexOf(String($el.data('id'))) !== -1) {
                    stack.close(entry.widget);
                }
            } catch (e) {
            }
        });
    }

    /** Moves the chosen letters of the list; the rows go at once, the server follows. */
    function moveLetters(target, action, folder) {
        if (!target || !$mail().data('can-manage')) {
            return;
        }
        var data = $.extend({action: action}, target);
        if (folder !== undefined) {
            data.folder = folder;
        }
        var $rows = target.all ? rows() : rows().filter(function () {
            return target.ids.indexOf(String($(this).data('id'))) !== -1;
        });
        var leaves = leavesFolder(action);
        if (leaves) {
            $rows.addClass('is-leaving');
        }
        $.post($mail().data('move-url'), csrf(data)).done(function (response) {
            notify(response.moved ? 'success' : 'warning', response.message);
            applyCounters(response.counters);
            if (leaves && response.moved && !target.all) {
                var ids = (response.ids || []).map(String);
                closeLetterPanels(ids);
                $rows.remove();
                $mail().find('[data-sx-mail-select], [data-sx-mail-all]').prop('checked', false);
                refreshSelection();
                if (!rows().length) {
                    $.pjax.reload({container: '#sx-mail-pjax', timeout: 15000});
                }
            } else {
                $.pjax.reload({container: '#sx-mail-pjax', timeout: 15000});
            }
        }).fail(function (xhr) {
            $rows.removeClass('is-leaving');
            notify('error', (xhr.responseJSON && xhr.responseJSON.message) || 'Не удалось перенести письма.');
        });
    }

    $(document).on('click', root + ' [data-sx-mail-move]', function () {
        moveLetters(chosen(), $(this).data('sx-mail-move'));
    });

    /** Places a menu of the list next to its button or at a point. */
    function placeMenu($menu, at) {
        $menu.prop('hidden', false);
        var width = $menu.outerWidth(), height = $menu.outerHeight();
        $menu.css({
            left: Math.max(8, Math.min(at.left, window.innerWidth - width - 8)),
            top: Math.max(8, at.top + height > window.innerHeight - 8 ? at.top - height - 40 : at.top)
        });
    }

    /** "В папку" / "Метки" of the list for these letters. */
    function openListMenu(kind, target, at) {
        var $menu = $mail().find('[data-sx-mail-floating]');
        if (!target) {
            return;
        }
        closeMenus();
        var config = {
            foldersUrl: $mail().data('server-folders-url'),
            labelsUrl: $mail().data('labels-url'),
            labelSaveUrl: $mail().data('label-save-url'),
            target: target,
            onFolder: function (folder) {
                closeMenus();
                moveLetters(target, 'folder', folder);
            },
            onLabels: function () {
                $menu.data('changed', true);
            }
        };
        placeMenu($menu.empty().append('<span class="sx-mail__menu-note">Загрузка…</span>'), at);
        (kind === 'folder' ? folderMenu : labelsMenu)($menu, config, function () {
            placeMenu($menu, at);
        });
    }

    $(document).on('click', root + ' [data-sx-mail-folder-menu], ' + root + ' [data-sx-mail-labels-menu]', function () {
        var rect = this.getBoundingClientRect();
        var $menu = $mail().find('[data-sx-mail-floating]');
        var kind = $(this).is('[data-sx-mail-folder-menu]') ? 'folder' : 'labels';
        if (!$menu.prop('hidden') && $menu.data('kind') === kind) {
            closeMenus();
            return;
        }
        openListMenu(kind, chosen(), {left: rect.left, top: rect.bottom + 4});
        $menu.data('kind', kind);
    });

    /** List hotkeys of mailbox actions; true when handled. */
    function listHotkey(e) {
        if (!$mail().data('can-manage')) {
            return false;
        }
        var target = chosen();
        if (!target) {
            return false;
        }
        var kind = String($mail().data('folder-kind') || '');
        if (e.code === 'Delete' || (e.code === 'Digit3' && e.shiftKey)) {
            if (kind !== 'trash') {
                moveLetters(target, 'trash');
                return true;
            }
        } else if (e.code === 'KeyE' && !e.shiftKey) {
            moveLetters(target, kind === 'archive' ? 'restore' : 'archive');
            return true;
        } else if (e.code === 'Digit1' && e.shiftKey) {
            moveLetters(target, kind === 'junk' ? 'restore' : 'junk');
            return true;
        } else if ((e.code === 'KeyV' || e.code === 'KeyM') && !e.shiftKey) {
            var $button = $mail().find('.sx-mail__toolbar ' + (e.code === 'KeyV' ? '[data-sx-mail-folder-menu]' : '[data-sx-mail-labels-menu]'));
            var rect = ($button.get(0) || document.body).getBoundingClientRect();
            openListMenu(e.code === 'KeyV' ? 'folder' : 'labels', target, {left: rect.left, top: rect.bottom + 4});
            return true;
        }
        return false;
    }

    /** Fills a menu with server folders of the letters; onPick(folder). */
    function folderMenu($menu, config, placed) {
        $.get(config.foldersUrl, config.target).done(function (response) {
            $menu.empty();
            if (!response.success) {
                $('<span class="sx-mail__menu-note"></span>').text(response.message).appendTo($menu);
            } else {
                $('<span class="sx-mail__menu-note">Перенести в папку ящика</span>').appendTo($menu);
                (response.items || []).forEach(function (item) {
                    $('<button type="button" class="sx-mail__menu-item"></button>').text(item.title).on('click', function () {
                        config.onFolder(item.folder);
                    }).appendTo($menu);
                });
                if (!(response.items || []).length) {
                    $('<span class="sx-mail__menu-note">Других папок в ящике нет. Папки создаются в самой почте и появляются здесь после проверки писем.</span>').appendTo($menu);
                }
            }
            placed();
        }).fail(function () {
            $menu.empty().append('<span class="sx-mail__menu-note">Не удалось загрузить папки.</span>');
        });
    }

    /**
     * Fills a menu with labels: a click puts the label on the letters or
     * takes it off; a new label is made right there and put on.
     */
    function labelsMenu($menu, config, placed) {
        var target = config.target;
        var render = function (response) {
            $menu.empty().addClass('sx-mail__labels');
            (response.items || []).forEach(function (item) {
                var $item = $('<button type="button" class="sx-mail__menu-item sx-mail__label-item" role="menuitemcheckbox"></button>')
                    .attr('aria-checked', item.state === 'all' ? 'true' : (item.state === 'some' ? 'mixed' : 'false'))
                    .toggleClass('is-on', item.state === 'all').toggleClass('is-some', item.state === 'some');
                $('<span class="sx-mail__label-check" aria-hidden="true"></span>').appendTo($item);
                $('<span class="sx-mail__label-dot"></span>').css('--sx-mail-label', item.color).appendTo($item);
                $('<span class="sx-mail__label-name"></span>').text(item.name).appendTo($item);
                $item.on('click', function () {
                    var on = item.state !== 'all';
                    $item.prop('disabled', true);
                    $.post(config.labelsUrl, csrf($.extend({label_id: item.id, on: on ? 1 : 0}, target))).done(function (r) {
                        notify(r.changed ? 'success' : 'warning', r.message);
                        item.state = on ? 'all' : 'none';
                        $item.attr('aria-checked', on ? 'true' : 'false').toggleClass('is-on', on).removeClass('is-some');
                        config.onLabels();
                    }).fail(function () {
                        notify('error', 'Не удалось изменить метку.');
                    }).always(function () {
                        $item.prop('disabled', false);
                    });
                });
                if (response.canEdit) {
                    $('<span class="sx-mail__label-edit" role="button" tabindex="0" title="Изменить метку">✎</span>').on('click', function (e) {
                        e.stopPropagation();
                        editLabel($menu, config, item, response.colors, function () {
                            load();
                        });
                    }).appendTo($item);
                }
                $item.appendTo($menu);
            });
            if (!(response.items || []).length) {
                $('<span class="sx-mail__menu-note">Меток пока нет.</span>').appendTo($menu);
            }
            if (response.canEdit) {
                $('<span class="sx-mail__menu-sep"></span>').appendTo($menu);
                $('<button type="button" class="sx-mail__menu-item"></button>').text('+ Новая метка').on('click', function () {
                    editLabel($menu, config, null, response.colors, function (label) {
                        // A new label goes on the chosen letters right away.
                        $.post(config.labelsUrl, csrf($.extend({label_id: label.id, on: 1}, target))).always(function () {
                            config.onLabels();
                            load();
                        });
                    });
                }).appendTo($menu);
            }
            placed();
        };
        var load = function () {
            $.get(config.labelsUrl, target).done(render).fail(function () {
                $menu.empty().append('<span class="sx-mail__menu-note">Не удалось загрузить метки.</span>');
            });
        };
        load();
    }

    /** A small form inside the menu: name and color of a label; delete for an existing one. */
    function editLabel($menu, config, label, colors, onSaved) {
        $menu.empty();
        var color = label ? label.color : colors[0];
        var $form = $('<form class="sx-mail__label-form"></form>').appendTo($menu);
        var $name = $('<input type="text" class="form-control" maxlength="64" placeholder="Название метки">').val(label ? label.name : '').appendTo($form);
        var $colors = $('<span class="sx-mail__label-colors"></span>').appendTo($form);
        (colors || []).forEach(function (c) {
            $('<button type="button" class="sx-mail__label-color"></button>').css('--sx-mail-label', c).attr('title', c)
                .toggleClass('is-active', c === color).on('click', function () {
                    color = c;
                    $colors.children().removeClass('is-active');
                    $(this).addClass('is-active');
                }).appendTo($colors);
        });
        var $error = $('<span class="sx-mail__menu-note sx-letter__error" hidden></span>').appendTo($form);
        var $buttons = $('<span class="sx-mail__label-buttons"></span>').appendTo($form);
        $('<button type="submit" class="btn btn-primary btn-sm"></button>').text(label ? 'Сохранить' : 'Создать').appendTo($buttons);
        if (label) {
            $('<button type="button" class="btn btn-default btn-sm"></button>').text('Удалить метку').on('click', function () {
                if (!window.confirm('Удалить метку «' + label.name + '»? Она пропадёт у всех писем.')) {
                    return;
                }
                $.post(config.labelSaveUrl, csrf({id: label.id, delete: 1})).done(function () {
                    config.onLabels();
                    onSaved(null);
                });
            }).appendTo($buttons);
        }
        $form.on('submit', function (e) {
            e.preventDefault();
            $.post(config.labelSaveUrl, csrf({id: label ? label.id : '', name: $name.val(), color: color})).done(function (r) {
                if (r.success) {
                    if (label) {
                        config.onLabels();
                    }
                    onSaved(r.label);
                } else {
                    $error.text(r.message).prop('hidden', false);
                }
            }).fail(function () {
                $error.text('Не удалось сохранить метку.').prop('hidden', false);
            });
        });
        $name.trigger('focus');
    }

    // The open letter: the same actions for this letter.
    $(document).on('click', '[data-sx-letter] [data-sx-letter-move]', function () {
        var $letterEl = $(this).closest('[data-sx-letter]');
        var action = $(this).data('sx-letter-move');
        $(this).prop('disabled', true);
        moveOpenLetter($letterEl, action);
    });

    function moveOpenLetter($letterEl, action, folder) {
        var data = {ids: [$letterEl.data('id')], action: action};
        if (folder !== undefined) {
            data.folder = folder;
        }
        $.post($letterEl.data('move-url'), csrf(data)).done(function (response) {
            notify(response.moved ? 'success' : 'warning', response.message);
            applyMainCounters(response.counters);
            reloadList();
            // Gone from the folder: like web mail, back to the list.
            if (!response.moved || !closeOwnPanel()) {
                window.location.reload();
            }
        }).fail(function (xhr) {
            notify('error', (xhr.responseJSON && xhr.responseJSON.message) || 'Не удалось перенести письмо.');
            $letterEl.find('[data-sx-letter-move]').prop('disabled', false);
        });
    }

    $(document).on('click', '[data-sx-letter] [data-sx-letter-folder-menu], [data-sx-letter] [data-sx-letter-labels-menu]', function () {
        var $letterEl = $(this).closest('[data-sx-letter]');
        var $menu = $(this).siblings('[data-sx-letter-floating]');
        if (!$menu.prop('hidden')) {
            closeMenus();
            return;
        }
        closeMenus();
        var id = String($letterEl.data('id'));
        var config = {
            foldersUrl: $letterEl.data('server-folders-url'),
            labelsUrl: $letterEl.data('labels-url'),
            labelSaveUrl: $letterEl.data('label-save-url'),
            target: {ids: [id]},
            onFolder: function (folder) {
                closeMenus();
                moveOpenLetter($letterEl, 'folder', folder);
            },
            onLabels: function () {
                reloadList();
                $letterEl.data('labelsChanged', true);
            }
        };
        $menu.empty().append('<span class="sx-mail__menu-note">Загрузка…</span>').prop('hidden', false);
        ($(this).is('[data-sx-letter-folder-menu]') ? folderMenu : labelsMenu)($menu, config, function () {
        });
    });

    // Labels changed in the letter: show them when its menu closes.
    $(document).on('click keydown', function () {
        var $letterEl = $letter();
        if ($letterEl.data('labelsChanged') && !$letterEl.find('[data-sx-letter-floating]:not([hidden])').length) {
            $letterEl.data('labelsChanged', false);
            window.location.reload();
        }
    });

    // "Привязать письмо" window: the kind of record switches the search field.
    $(document).on('change', '[data-sx-mail-link-form] [data-sx-mail-link-type]', function () {
        var $form = $(this).closest('[data-sx-mail-link-form]');
        var type = $form.find('[data-sx-mail-link-type]:checked').val();
        $form.find('[data-sx-mail-link-for]').each(function () {
            $(this).prop('hidden', $(this).data('sx-mail-link-for') !== type);
        });
        $form.find('[data-sx-mail-link-error]').prop('hidden', true);
    });

    $(document).on('submit', '[data-sx-mail-link-form]', function (e) {
        e.preventDefault();
        var $form = $(this);
        var type = $form.find('[data-sx-mail-link-type]:checked').val();
        var id = $form.find('[data-sx-mail-link-select="' + type + '"]').val();
        var $error = $form.find('[data-sx-mail-link-error]');
        if (!id) {
            $error.text('Выберите запись.').prop('hidden', false);
            return;
        }
        var $submit = $form.find('[type="submit"]').prop('disabled', true);
        $.post($form.attr('action'), csrf({
            type: type,
            id: id,
            conversation: $form.find('[name="conversation"]:checked').val() || $form.find('[name="conversation"]').val()
        })).done(function (response) {
            if (response.success) {
                notify('success', response.message);
                reloadList();
                refreshLetters();
                if (!closeOwnPanel()) {
                    $error.text(response.message).prop('hidden', false);
                }
            } else {
                $error.text(response.message).prop('hidden', false);
                $submit.prop('disabled', false);
            }
        }).fail(function () {
            $error.text('Не удалось привязать письмо.').prop('hidden', false);
            $submit.prop('disabled', false);
        });
    });

    /* ------------------------------------------------------------------
     * "Прикрепить из…": a document of another package (a proposal, a bill)
     * goes into the letter as its personal link in the text and its files.
     * The server makes the link when the letter is sent (SendService).
     * ------------------------------------------------------------------ */

    var SEND_PLACEHOLDER = 'https://sx-send.invalid/';

    function sendableMenu($form) {
        return $form.find('[data-sx-compose-sendables-menu]');
    }

    /** Where the link goes: the caret in the text when the menu was opened. */
    function rememberCaret($form) {
        var editor = editorElement($form);
        var selection = window.getSelection();
        $form.removeData('sendableRange');
        if (editor && selection.rangeCount && editor.contains(selection.anchorNode)) {
            $form.data('sendableRange', selection.getRangeAt(0).cloneRange());
        }
    }

    function insertSendableLink($form, html) {
        var range = $form.data('sendableRange');
        var editor = editorElement($form);
        if (range && editor && editor.contains(range.startContainer)) {
            editor.focus();
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        }
        insertTemplate($form, html);
    }

    function sendableLinks($form, ref) {
        var parts = String(ref).split(':');
        var href = SEND_PLACEHOLDER + parts[0] + '/' + parts[1];
        return $(editorElement($form)).find('a').filter(function () {
            return (this.getAttribute('href') || '').replace(/\/$/, '') === href;
        });
    }

    function closeSendableMenus() {
        $('[data-sx-compose-sendables-menu]').prop('hidden', true).empty();
    }

    function showSources($form) {
        var $menu = sendableMenu($form).empty();
        ($form.data('sendable-sources') || []).forEach(function (source) {
            $('<button type="button" class="sx-compose__templates-item" role="menuitem" data-sx-compose-sendable-source></button>')
                .text(source.title).data('source', source).appendTo($menu);
        });
        $menu.prop('hidden', false);
    }

    function showPicker($form, source) {
        var $menu = sendableMenu($form).empty();
        var many = ($form.data('sendable-sources') || []).length > 1;
        var $head = $('<div class="sx-compose__sendables-head"></div>').appendTo($menu);
        if (many) {
            $('<button type="button" class="sx-compose__link" data-sx-compose-sendables-back>‹</button>').attr('aria-label', 'Назад').appendTo($head);
        }
        $('<strong></strong>').text(source.title).appendTo($head);
        var $search = $('<input type="search" class="sx-compose__input sx-compose__sendables-search" autocomplete="off">')
            .attr('placeholder', 'Поиск').attr('aria-label', 'Поиск: ' + source.title).appendTo($menu);
        var $list = $('<div class="sx-compose__sendables-list" role="listbox"></div>').appendTo($menu);
        $menu.prop('hidden', false);
        var load = function () {
            var xhr = $menu.data('xhr');
            if (xhr) {
                xhr.abort();
            }
            $list.html('<div class="sx-compose__templates-empty">Загрузка…</div>');
            $menu.data('xhr', $.get($form.data('sendables-url'), {
                type: source.type,
                q: $search.val(),
                context_model_code: $form.find('[name$="[context_model_code]"]').val(),
                context_model_id: $form.find('[name$="[context_model_id]"]').val()
            }).done(function (response) {
                $list.empty();
                var items = (response && response.items) || [];
                if (!items.length) {
                    $('<div class="sx-compose__templates-empty"></div>').text('Ничего не найдено.').appendTo($list);
                }
                items.forEach(function (item) {
                    var $item = $('<button type="button" class="sx-compose__templates-item sx-compose__sendables-item" role="option" data-sx-compose-sendable-item></button>')
                        .data('ref', item.ref).appendTo($list);
                    $('<span class="sx-compose__sendables-title"></span>').text(item.title).appendTo($item);
                    if (item.hint) {
                        $('<span class="sx-compose__sendables-hint"></span>').text(item.hint).appendTo($item);
                    }
                });
            }));
        };
        $search.on('input', function () {
            clearTimeout($menu.data('timer'));
            $menu.data('timer', setTimeout(load, 250));
        }).on('keydown', function (e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                closeSendableMenus();
            }
        });
        load();
        $search.trigger('focus');
    }

    $(document).on('mousedown', '[data-sx-compose-sendables-toggle]', function (e) {
        e.preventDefault();
        rememberCaret($(this).closest('[data-sx-compose]'));
    });

    $(document).on('click', '[data-sx-compose-sendables-toggle]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        var $menu = sendableMenu($form);
        if (!$menu.prop('hidden')) {
            closeSendableMenus();
            return;
        }
        $form.find('[data-sx-compose-templates-menu]').prop('hidden', true);
        var sources = $form.data('sendable-sources') || [];
        if (sources.length === 1) {
            showPicker($form, sources[0]);
        } else {
            showSources($form);
        }
    });

    $(document).on('click', '[data-sx-compose-sendable-source]', function () {
        showPicker($(this).closest('[data-sx-compose]'), $(this).data('source'));
    });

    $(document).on('click', '[data-sx-compose-sendables-back]', function () {
        showSources($(this).closest('[data-sx-compose]'));
    });

    $(document).on('click', '[data-sx-compose-sendable-item]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        var ref = $(this).data('ref');
        closeSendableMenus();
        if ($form.find('[data-sx-compose-sendable="' + ref + '"]').length) {
            notify('info', 'Этот документ уже в письме.');
            return;
        }
        $.get($form.data('sendables-url'), {ref: ref}).done(function (response) {
            if (!response || !response.success) {
                notify('error', (response && response.message) || 'Документ недоступен.');
                return;
            }
            $form.find('[data-sx-compose-files]').append(response.chip);
            // On a line of its own: the server turns the link into a button there.
            var $link = $('<div>').html(response.item.link).find('a').first();
            insertSendableLink($form, '<br>' + $link.prop('outerHTML') + '<br>');
            $form.find('[data-sx-compose-error="sendables"]').text('');
            markDirty($form);
        }).fail(function () {
            notify('error', 'Не удалось добавить документ.');
        });
    });

    // Removing the document takes its link out of the text too.
    $(document).on('click', '[data-sx-compose-sendable-remove]', function () {
        var $form = $(this).closest('[data-sx-compose]');
        var $chip = $(this).closest('[data-sx-compose-sendable]');
        sendableLinks($form, $chip.data('sx-compose-sendable')).each(function () {
            var $block = $(this).parent();
            $(this).remove();
            if ($block.is('div, p') && !$.trim($block.text()) && !$block.find('img').length && $block.get(0) !== editorElement($form)) {
                $block.remove();
            }
        });
        $chip.remove();
        editorChanged($form);
        markDirty($form);
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('[data-sx-compose-sendables]').length) {
            $('[data-sx-compose-sendables-menu]:not([hidden])').prop('hidden', true).empty();
        }
    });

    /* ------------------------------------------------------------------
     * "Отправить клиенту": contacts of the client choose the channel and the
     * recipient; each channel has its own form (mail — the letter form).
     * ------------------------------------------------------------------ */

    function sendTab($root, id) {
        $root.find('[data-sx-send-tab]').each(function () {
            var on = $(this).data('sx-send-tab') === id;
            $(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false');
        });
        $root.find('[data-sx-send-panel]').each(function () {
            $(this).prop('hidden', $(this).data('sx-send-panel') !== id);
        });
        // The chosen contact is shown for the open channel only.
        $root.find('[data-sx-send-contact]').each(function () {
            $(this).toggleClass('is-current', $(this).data('channel') === id);
        });
    }

    $(document).on('click', '[data-sx-send-tab]', function () {
        sendTab($(this).closest('[data-sx-send]'), $(this).data('sx-send-tab'));
    });

    $(document).on('click', '[data-sx-send-contact]', function () {
        var $contact = $(this);
        var $root = $contact.closest('[data-sx-send]');
        var channel = $contact.data('channel');
        if (!channel) {
            return;
        }
        sendTab($root, channel);
        $root.find('[data-sx-send-contact]').filter(function () {
            return $(this).data('channel') === channel;
        }).removeClass('is-chosen');
        $contact.addClass('is-chosen');
        var $panel = $root.find('[data-sx-send-panel="' + channel + '"]');
        var $chips = $panel.find('[data-sx-compose-row="to"] [data-sx-compose-chips]');
        if ($chips.length) {
            // One send — one recipient: the contact replaces the address.
            $chips.find('[data-sx-compose-chip]').remove();
            addChip($chips, String($contact.data('value')), String($contact.data('name') || ''));
        } else {
            $panel.find('[data-sx-send-recipient]').val($contact.data('value')).trigger('input');
        }
    });

    function smsCount(n, one, few, many) {
        var mod10 = n % 10, mod100 = n % 100;
        return n + ' ' + ((mod10 === 1 && mod100 !== 11) ? one
            : (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) ? few : many);
    }

    /** What the client gets: the text and the link on its own line, with a rough SMS count. */
    function sendPreview($form) {
        var text = $.trim($form.find('[data-sx-send-text]').val() || '');
        var $preview = $form.find('[data-sx-send-preview]').empty();
        if (text) {
            $('<span></span>').text(text).appendTo($preview);
            $preview.append('<br>');
        }
        $('<span class="sx-send-form__link"></span>').text($preview.data('link-label')).appendTo($preview);
        // A personal link is about 40 characters; Cyrillic SMS hold 70 (67 in a long one), Latin 160 (153).
        var total = text.length + (text ? 1 : 0) + 40;
        var cyrillic = /[^\x00-\x7F]/.test(text);
        var parts = Math.ceil(total / (cyrillic ? (total > 70 ? 67 : 70) : (total > 160 ? 153 : 160)));
        $form.find('[data-sx-send-count]').text(smsCount(text.length, 'символ', 'символа', 'символов') + ' + ссылка ≈ ' + parts + ' СМС');
    }

    $(document).on('input', '[data-sx-send-form] [data-sx-send-text]', function () {
        sendPreview($(this).closest('[data-sx-send-form]'));
    });

    function checkSendOptOut($form) {
        var phone = $.trim(String($form.find('[data-sx-send-recipient]').val() || ''));
        requestOptOut($form, $form.find('[data-sx-send-optout]'), phone ? {phones: [phone]} : {});
    }

    $(document).on('input', '[data-sx-send-form] [data-sx-send-recipient]', function () {
        checkSendOptOut($(this).closest('[data-sx-send-form]'));
    });

    $(document).on('input', '[data-sx-send-form] [data-sx-send-recipient]', function () {
        $(this).closest('[data-sx-send-form]').find('[data-sx-send-error="recipient"]').text('')
            .closest('.sx-compose__row').removeClass('has-error');
    });

    $(document).on('submit', '[data-sx-send-form]', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $submit = $form.find('[data-sx-send-submit]');
        if ($submit.prop('disabled')) {
            return;
        }
        $form.find('[data-sx-send-error]').text('').closest('.sx-compose__row').removeClass('has-error');
        $submit.prop('disabled', true);
        $form.find('[data-sx-send-status]').text('Отправка…');
        $.post($form.attr('action'), $form.serialize()).done(function (response) {
            $form.find('[data-sx-send-status]').text('');
            if (!response.success) {
                $.each(response.errors || {}, function (field, text) {
                    var $error = $form.find('[data-sx-send-error="' + field + '"]');
                    ($error.length ? $error : $form.find('[data-sx-send-error="channel"]')).text(text)
                        .closest('.sx-compose__row').addClass('has-error');
                });
                $submit.prop('disabled', false);
                return;
            }
            notify('success', response.message);
            if (!closeOwnPanel()) {
                $form.replaceWith($('<div class="sx-compose__done"></div>').text(response.message + '.'));
            }
        }).fail(function () {
            $form.find('[data-sx-send-status]').text('');
            $submit.prop('disabled', false);
            notify('error', 'Не отправлено: ошибка на сервере. Попробуйте ещё раз.');
        });
    });

    $(function () {
        $('[data-sx-send-form]').each(function () {
            sendPreview($(this));
            checkSendOptOut($(this));
        });
        // The first contact of each channel is its recipient already: show it chosen.
        $('[data-sx-send]').each(function () {
            var $root = $(this);
            sendTab($root, $root.find('[data-sx-send-tab].is-active').data('sx-send-tab'));
            $root.find('[data-sx-send-contact]').each(function () {
                var $c = $(this);
                var first = $root.find('[data-sx-send-contact]').filter(function () {
                    return $(this).data('channel') === $c.data('channel');
                }).first();
                if (first.get(0) === this) {
                    $c.addClass('is-chosen');
                }
            });
        });
    });
})(jQuery);
