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
            var height = Math.max(
                doc.documentElement ? doc.documentElement.scrollHeight : 0,
                doc.body ? doc.body.scrollHeight : 0
            );
            if (height > 0) {
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
            window.sxMailFitFrame(frame);
        });
        frame.srcdoc = doc;
        setTimeout(waitText, 0);
    }

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
        var action = new sx.classes.backend.widgets.Action({isOpenNewWindow: true, url: $row.data('url')});
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

    // The letter panel moves through the list with j / k.
    window.sxMail = {
        step: step,
        hasList: function () {
            return $(root).length > 0;
        }
    };

    function selectedIds() {
        return $mail().find('[data-sx-mail-select]:checked').map(function () {
            return this.value;
        }).get();
    }

    function refreshSelection() {
        var ids = selectedIds();
        var $rows = rows();
        $rows.each(function () {
            $(this).toggleClass('is-selected', $(this).find('[data-sx-mail-select]').is(':checked'));
        });
        $mail().find('.sx-mail__toolbar [data-sx-mail-mark]').prop('disabled', ids.length === 0);
        var $all = $mail().find('[data-sx-mail-all]');
        $all.prop('checked', ids.length > 0 && ids.length === $rows.length);
        $all.prop('indeterminate', ids.length > 0 && ids.length < $rows.length);

        var $bar = $mail().find('[data-sx-mail-selection]');
        if (ids.length) {
            $bar.find('[data-sx-mail-selected]').text(plural(ids.length));
            $bar.prop('hidden', false);
        } else {
            $bar.prop('hidden', true);
        }
    }

    function plural(n) {
        var mod10 = n % 10, mod100 = n % 100;
        var word = (mod10 === 1 && mod100 !== 11) ? 'письмо'
            : (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) ? 'письма' : 'писем';
        return 'Выбрано ' + n + ' ' + word;
    }

    function markSelected(read) {
        var ids = selectedIds();
        if (!ids.length && cursorRow().length) {
            ids = [String(cursorRow().data('id'))];
        }
        if (!ids.length) {
            return;
        }
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

    /** Refreshes the list behind the panel, if it is open there. */
    function reloadList() {
        var w = mainWindow();
        try {
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
            case 'KeyU':
                if (e.shiftKey) {
                    click('[data-sx-letter-unread]');
                }
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

    /* Files: attachments. Formatting, links, paste and pictures inside the text
     * belong to the shared editor (skeeks/cms RichTextInputWidget, sx.RichText). */

    function uploadFile($form, file) {
        var max = parseInt($form.data('max-size'), 10) || 0;
        if (max && file.size > max) {
            notify('error', '«' + file.name + '» больше ' + Math.round(max / 1024 / 1024) + ' МБ: почтовые серверы такие письма не принимают.');
            return $.Deferred().reject().promise();
        }
        var data = new FormData();
        data.append('file', file, file.name);
        data.append(yii.getCsrfParam(), yii.getCsrfToken());
        $form.data('uploads', ($form.data('uploads') || 0) + 1);
        return $.ajax({
            url: $form.data('upload-url'),
            type: 'POST',
            data: data,
            processData: false,
            contentType: false,
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload && file.$chip) {
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            file.$chip.find('.sx-compose__file-size').text(Math.round(e.loaded / e.total * 100) + '%');
                        }
                    });
                }
                return xhr;
            }
        }).then(function (response) {
            if (!response.success) {
                notify('error', response.message || 'Файл не загрузился.');
                return $.Deferred().reject().promise();
            }
            return response;
        }, function () {
            notify('error', 'Файл «' + file.name + '» не загрузился.');
            return $.Deferred().reject().promise();
        }).always(function () {
            $form.data('uploads', $form.data('uploads') - 1);
        });
    }

    function attachFiles($form, files) {
        // New files wait in the temporary upload area until the letter is sent.
        var name = $form.find('[data-sx-compose-uploads]').attr('name');
        Array.prototype.forEach.call(files, function (file) {
            var $chip = $('<span class="sx-compose__file is-loading"></span>');
            $('<span class="sx-compose__file-name"></span>').text(file.name).attr('title', file.name).appendTo($chip);
            $('<span class="sx-compose__file-size">0%</span>').appendTo($chip);
            $form.find('[data-sx-compose-files]').append($chip);
            file.$chip = $chip;
            uploadFile($form, file).done(function (response) {
                $chip.removeClass('is-loading');
                $chip.find('.sx-compose__file-size').text(response.sizeText);
                $chip.append('<button type="button" class="sx-compose__chip-remove" data-sx-compose-file-remove aria-label="Убрать файл">×</button>');
                $('<input type="hidden">').attr('name', name + '[]').val(response.token).appendTo($chip);
            }).fail(function () {
                $chip.remove();
            });
        });
    }

    $(document).on('click', '[data-sx-compose-attach]', function () {
        $(this).closest('[data-sx-compose]').find('[data-sx-compose-file-input]').trigger('click');
    });

    $(document).on('change', '[data-sx-compose-file-input]', function () {
        attachFiles($(this).closest('[data-sx-compose]'), this.files);
        this.value = '';
    });

    $(document).on('click', '[data-sx-compose-file-remove]', function () {
        $(this).closest('.sx-compose__file').remove();
    });

    // The editor's "Прикрепить файлом" drop zone, and non-image files pasted or dropped into the text.
    $(document).on('sx-rte:attach', '[data-sx-compose-rte]', function (e, files) {
        attachFiles($(this).closest('[data-sx-compose]'), files);
    });

    // Files dropped anywhere else on the letter are attached.
    $(document).on('dragover', '[data-sx-compose]', function (e) {
        var dt = e.originalEvent.dataTransfer;
        if (dt && Array.prototype.indexOf.call(dt.types || [], 'Files') !== -1) {
            e.preventDefault();
        }
    });

    $(document).on('drop', '[data-sx-compose]', function (e) {
        var dt = e.originalEvent.dataTransfer;
        if (!dt || !dt.files || !dt.files.length) {
            return;
        }
        e.preventDefault();
        attachFiles($(this), dt.files);
    });

    /* Sending */

    function initCompose($form) {
        $form.find('[data-sx-compose-chips]').each(function () {
            var $chips = $(this);
            var value = $chips.find('[data-sx-compose-value]').val();
            $chips.find('[data-sx-compose-chips-input]').val(value);
            commitTyped($chips);
        });
        sx.RichText.init(composeEditor($form));
        fetchSourceFiles($form);
    }

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
        if ($form.data('uploads') > 0 || sx.RichText.isBusy(composeEditor($form))) {
            notify('error', 'Дождитесь, пока загрузятся файлы.');
            return;
        }
        $form.find('[data-sx-compose-chips]').each(function () {
            commitTyped($(this));
        });
        // The editor keeps its text in the body_html field itself.
        $send.prop('disabled', true);
        $form.find('[data-sx-compose-status]').text('Отправка…');

        $.post($form.attr('action'), $form.serialize()).done(function (response) {
            if (!response.success) {
                $form.find('[data-sx-compose-status]').text('');
                showErrors($form, response.errors);
                $send.prop('disabled', false);
                return;
            }
            afterSent($form, response);
        }).fail(function () {
            $form.find('[data-sx-compose-status]').text('');
            $send.prop('disabled', false);
            notify('error', 'Письмо не отправлено: ошибка на сервере. Попробуйте ещё раз.');
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
            $letterEl.find('[data-sx-letter-reply-bar]').prop('hidden', false);
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
        var $letterEl = $form.closest('[data-sx-letter]');
        $form.remove();
        $letterEl.find('[data-sx-letter-reply-bar]').prop('hidden', false);
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
})(jQuery);
