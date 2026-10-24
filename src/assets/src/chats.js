/**
 * "Чаты": opening a chat without reloading, polling for new messages and the
 * list, the reply with files, the dialog bar (take, pass, close).
 */
(function ($) {
    'use strict';

    var POLL_CHAT = 5000, POLL_LIST = 20000;

    function Pane($el) {
        this.$el = $el;
        this.cfg = $el.data('sx-chat-pane');
        this.last = this.cfg.last;
        this.replyTo = null;
        this.bind();
        this.decorate();
        this.scrollDown();
        // The admin shell shows its content after the page is ready: the panel and the bottom wait until the chat has a size.
        var pane = this, tries = 0;
        (function settle() {
            if (!pane.$el.width() && tries++ < 100) {
                return setTimeout(settle, 50);
            }
            pane.initInfo();
            pane.scrollDown();
            pane.stick = true;
        })();
        // Pictures load later and push the messages up: the chat stays at the bottom while the employee is there.
        var self = this, box = $el.find('[data-sx-chat-messages]')[0];
        this.stick = true;
        if (box) {
            box.addEventListener('scroll', function () {
                self.stick = self.atBottom();
            });
            var keep = function () {
                if (self.stick) {
                    self.scrollDown();
                }
            };
            box.addEventListener('load', keep, true);
            $(window).on('load', keep);
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(keep);
            }
            if (window.ResizeObserver) {
                new ResizeObserver(keep).observe(box);
            }
        }
        this.timer = setInterval(this.poll.bind(this), POLL_CHAT);
    }

    Pane.prototype.destroy = function () {
        clearInterval(this.timer);
        this.closeMenu();
        $(document).off('.sxChatMenu');
    };

    Pane.prototype.post = function (url, data, processData) {
        var payload = data;
        if (processData === false) {
            payload.append(this.cfg.csrfParam, this.cfg.csrf);
        } else {
            payload[this.cfg.csrfParam] = this.cfg.csrf;
        }
        return $.ajax({url: url, type: 'POST', data: payload, processData: processData !== false, contentType: processData === false ? false : 'application/x-www-form-urlencoded; charset=UTF-8', dataType: 'json'});
    };

    Pane.prototype.bind = function () {
        var self = this, $el = this.$el;
        var $form = $el.find('[data-sx-chat-reply]'), $text = $el.find('[data-sx-chat-text]'), $file = $el.find('[data-sx-chat-file]');

        $text.on('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 200) + 'px';
        }).on('keydown', function (e) {
            // As in messengers: Enter sends, Shift+Enter is a new line.
            if (e.key === 'Enter' && !e.shiftKey && !e.altKey && !e.isComposing) {
                e.preventDefault();
                $form.trigger('submit');
            }
        });
        $file.on('change', function () {
            var $list = $el.find('[data-sx-chat-files]').empty();
            $.each(this.files || [], function (i, f) {
                $('<span>').text(f.name).appendTo($list);
            });
        });
        $form.on('submit', function (e) {
            e.preventDefault();
            var data = new FormData();
            data.append('text', $text.val());
            if (self.replyTo) {
                data.append('reply_to', self.replyTo);
            }
            $.each($file[0] ? $file[0].files : [], function (i, f) {
                data.append('files[]', f);
            });
            var $send = $el.find('[data-sx-chat-send]').prop('disabled', true);
            self.error('');
            self.post(self.cfg.sendUrl, data, false).done(function (r) {
                if (r.success) {
                    $text.val('').css('height', '');
                    $file.val('');
                    $el.find('[data-sx-chat-files]').empty();
                    self.setReply(null);
                    self.poll();
                } else {
                    self.error(r.error || 'Не удалось отправить.');
                }
            }).fail(function () {
                self.error('Не удалось отправить: нет связи с сервером.');
            }).always(function () {
                $send.prop('disabled', false);
            });
        });
        $el.on('click', '[data-sx-chat-reply-to]', function () {
            self.setReply($(this).data('sx-chat-reply-to'), $(this).data('sx-text'), $(this).data('sx-author-name'));
            $text.trigger('focus');
        });
        $el.on('click', '[data-sx-chat-replying-cancel]', function () {
            self.setReply(null);
        });
        $el.on('click', '[data-sx-chat-do]', function () {
            self.dialog({do: $(this).data('sx-chat-do')});
        });
        $el.on('change', '[data-sx-chat-transfer]', function () {
            var v = $(this).val();
            if (v === '') {
                return;
            }
            self.dialog({do: 'transfer', user_id: v});
        });
        $el.on('click', '[data-sx-chat-info-toggle]', function () {
            self.toggleInfo(!$el.hasClass('sx-chat--info'), true);
        });
        $el.on('click', '[data-sx-chat-back]', function () {
            $el.closest('.sx-chats').removeClass('sx-chats--open');
        });
        // "Привязать…" (the chat) and "Привязать собеседника…": a backend window over the chat.
        $el.on('click', '[data-sx-chat-link-window]', function () {
            var url = $(this).data('sx-chat-link-window') === 'person' ? self.cfg.personWindow : self.cfg.linkWindow;
            new sx.classes.backend.widgets.Action({isOpenNewWindow: true, url: url}).go();
        });
        // "Создать задачу" from a message: the standard create window of tasks, filled from the message.
        $el.on('click', '[data-sx-chat-task]', function () {
            if (!self.cfg.taskWindow) {
                return;
            }
            var url = self.cfg.taskWindow.replace('%7Bid%7D', $(this).data('sx-chat-task')).replace('{id}', $(this).data('sx-chat-task'));
            new sx.classes.backend.widgets.Action({isOpenNewWindow: true, url: url, updateSuccessCallback: function () {
                self.poll();
            }}).go();
        });
        // The menu of a message as in messengers: right click, or a long press on a phone.
        $el.on('contextmenu', '[data-sx-msg]', function (e) {
            if (window.getSelection && String(window.getSelection()).length) {
                return; // A selected text keeps the browser's own menu (copy).
            }
            e.preventDefault();
            self.menu($(this), e.clientX, e.clientY);
        });
        var press = null;
        $el.on('touchstart', '[data-sx-msg]', function (e) {
            var $m = $(this), t = e.originalEvent.touches[0];
            press = setTimeout(function () {
                self.menu($m, t.clientX, t.clientY);
            }, 500);
        }).on('touchend touchmove touchcancel', '[data-sx-msg]', function () {
            clearTimeout(press);
        });
        $(document).on('click.sxChatMenu keydown.sxChatMenu', function (e) {
            if (e.type === 'keydown' && e.key !== 'Escape') {
                return;
            }
            if (e.type === 'click' && $(e.target).closest('.sx-msg-menu').length) {
                return;
            }
            self.closeMenu();
        });
        $el.find('[data-sx-chat-messages]').on('scroll', function () {
            self.closeMenu();
        });
        // "Это наш сотрудник": choose who of the employees the member is; "×" — not ours.
        $el.on('click', '[data-sx-chat-worker-open]', function () {
            $el.find('[data-sx-chat-worker="' + $(this).data('sx-chat-worker-open') + '"]').prop('hidden', false).trigger('focus');
        });
        $el.on('change', '[data-sx-chat-worker]', function () {
            if ($(this).val()) {
                self.dialog({do: 'worker', identity: $(this).data('sx-chat-worker'), user_id: $(this).val()});
            }
        });
        $el.on('click', '[data-sx-chat-unworker]', function () {
            if (confirm('Это не наш сотрудник? Его сообщения станут сообщениями клиента.')) {
                self.dialog({do: 'worker', identity: $(this).data('sx-chat-unworker'), user_id: 0});
            }
        });
        $el.on('change', '[data-sx-chat-task-notify]', function () {
            self.dialog({do: 'task-notify', value: this.checked ? 1 : 0});
        });
        $el.on('click', '[data-sx-chat-link-to]', function () {
            var d = $(this).data('sx-chat-link-to');
            self.dialog({do: 'link-to', type: d.type, id: d.id});
        });
        $el.on('click', '[data-sx-chat-unlink]', function () {
            var d = $(this).data('sx-chat-unlink');
            if (!confirm('Отвязать чат от «' + d.name + '»? Его сообщения уйдут из ленты записи.')) {
                return;
            }
            self.post(self.cfg.unlinkUrl, {code: d.code, id: d.id}).done(function (r) {
                if (r.success) {
                    self.$el.find('[data-sx-chat-links]').html(r.links);
                    $(document).trigger('sx-chats:changed');
                } else {
                    alert(r.message || 'Не получилось.');
                }
            });
        });
    };

    /** The menu of a message: the same actions as its buttons, plus copying the text. */
    Pane.prototype.menu = function ($m, x, y) {
        var self = this, items = [];
        this.closeMenu();
        var $reply = $m.find('[data-sx-chat-reply-to]'), $task = $m.find('[data-sx-chat-task]');
        if ($reply.length) {
            items.push(['Ответить', function () {
                $reply.trigger('click');
            }]);
        }
        var text = $.trim($m.find('.sx-msg__text').clone().find('.sx-msg__meta').remove().end().text());
        if (text) {
            items.push(['Копировать текст', function () {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text);
                }
            }]);
        }
        if ($task.length && this.cfg.taskWindow) {
            items.push(['Создать задачу', function () {
                $task.trigger('click');
            }]);
        }
        $m.find('.sx-msg__task').each(function () {
            var $link = $(this);
            items.push(['Открыть ' + $.trim($link.text()).replace('Задача', 'задачу'), function () {
                $link[0].click();
            }]);
        });
        if (!items.length) {
            return;
        }
        var $menu = $('<div class="sx-msg-menu" role="menu"></div>');
        $.each(items, function (i, item) {
            $('<button type="button" role="menuitem"></button>').text(item[0]).on('click', function () {
                self.closeMenu();
                item[1]();
            }).appendTo($menu);
        });
        $menu.appendTo(document.body);
        // Inside the window, near the pointer.
        var w = $menu.outerWidth(), h = $menu.outerHeight();
        $menu.css({left: Math.max(8, Math.min(x, window.innerWidth - w - 8)), top: Math.max(8, Math.min(y, window.innerHeight - h - 8))});
        $m.addClass('is-menu');
        this.$menu = $menu;
        this.$menuFor = $m;
        $menu.find('button').first().trigger('focus');
    };

    Pane.prototype.closeMenu = function () {
        if (this.$menu) {
            this.$menu.remove();
            this.$menuFor.removeClass('is-menu');
            this.$menu = null;
        }
    };

    Pane.prototype.setReply = function (id, text, author) {
        this.replyTo = id || null;
        var $box = this.$el.find('[data-sx-chat-replying]');
        $box.prop('hidden', !id);
        $box.find('[data-sx-chat-replying-author]').text(author ? 'В ответ ' + author : 'Ответ');
        $box.find('[data-sx-chat-replying-text]').text(text || '');
    };

    /**
     * Series and days as in messengers: messages of one author in a row share
     * one name (above the first) and one photo (beside the last); a day starts
     * with its date. Run after every change of the messages.
     */
    Pane.prototype.decorate = function () {
        var $box = this.$el.find('[data-sx-chat-messages]'), prev = null, day = null;
        $box.find('.sx-chat__day').remove();
        var $all = $box.find('[data-sx-msg]');
        $all.each(function (i) {
            var $m = $(this), next = $all[i + 1] ? $($all[i + 1]) : null;
            var d = $m.data('sx-day') + '', a = $m.data('sx-author') + '';
            if (d && d !== day) {
                $('<div class="sx-chat__day"><span></span></div>').find('span').text($m.data('sx-day-title')).end().insertBefore($m);
                day = d;
                prev = null;
            }
            var sameNext = next && next.data('sx-author') + '' === a && next.data('sx-day') + '' === d;
            $m.toggleClass('is-first', prev !== a).toggleClass('is-last', !sameNext);
            prev = a;
        });
    };

    /** The side panel: open by default on a wide screen, the choice is remembered. */
    Pane.prototype.initInfo = function () {
        var saved = null;
        try {
            saved = window.localStorage.getItem('sx-chat-info');
        } catch (e) {}
        var wide = this.$el.width() >= 1000;
        this.toggleInfo(saved === null ? wide : (saved === '1' && wide), false);
    };

    Pane.prototype.toggleInfo = function (open, remember) {
        this.$el.toggleClass('sx-chat--info', open);
        if (remember && this.$el.width() >= 1000) {
            try {
                window.localStorage.setItem('sx-chat-info', open ? '1' : '0');
            } catch (e) {}
        }
    };

    Pane.prototype.error = function (text) {
        this.$el.find('[data-sx-chat-error]').text(text).prop('hidden', !text);
    };

    Pane.prototype.dialog = function (data) {
        var self = this;
        this.post(this.cfg.dialogUrl, data).done(function (r) {
            if (r.success) {
                self.$el.find('[data-sx-chat-bar]').html(r.bar);
                if (r.links) {
                    self.$el.find('[data-sx-chat-links]').html(r.links);
                }
                if (r.info) {
                    self.$el.find('[data-sx-chat-info-body]').html(r.info);
                }
                if (data.do === 'worker') {
                    // Messages of the member change sides: the chat is drawn again.
                    $(document).trigger('sx-chats:reopen', [self.cfg.id]);
                }
                self.poll();
                $(document).trigger('sx-chats:changed');
            } else {
                alert(r.error || 'Не получилось.');
                self.poll();
            }
        });
    };

    Pane.prototype.atBottom = function () {
        var box = this.$el.find('[data-sx-chat-messages]')[0];
        return box ? box.scrollHeight - box.scrollTop - box.clientHeight < 80 : true;
    };

    Pane.prototype.scrollDown = function () {
        var box = this.$el.find('[data-sx-chat-messages]')[0];
        if (box) {
            box.scrollTop = box.scrollHeight;
        }
    };

    Pane.prototype.poll = function () {
        var self = this;
        if (document.hidden) {
            return;
        }
        $.getJSON(this.cfg.messagesUrl, {after: this.last}).done(function (r) {
            var $box = self.$el.find('[data-sx-chat-messages]'), bottom = self.atBottom();
            $.each(r.replace || [], function (i, item) {
                $box.find('[data-sx-msg="' + item.id + '"]').replaceWith(item.html);
            });
            if (r.html) {
                $box.find('.sx-chats__none').remove();
                $box.append(r.html);
            }
            if (r.html || (r.replace && r.replace.length)) {
                self.decorate();
            }
            if (r.html) {
                if (bottom) {
                    self.scrollDown();
                }
                $(document).trigger('sx-chats:changed');
            }
            self.last = r.last || self.last;
            if (r.bar) {
                var $bar = self.$el.find('[data-sx-chat-bar]');
                if (!$bar.find('select:focus').length) {
                    $bar.html(r.bar);
                }
            }
            if (r.links) {
                self.$el.find('[data-sx-chat-links]').html(r.links);
            }
        });
    };

    /** The screen takes the window height to its bottom: the page itself does not scroll, only the list and the chat. */
    Screen.prototype.fit = function () {
        var el = this.$el[0];
        if (el.classList.contains('sx-chats--panel')) {
            return;
        }
        var top = el.getBoundingClientRect().top + window.scrollY;
        el.style.setProperty('--sx-chats-height', Math.max(420, window.innerHeight - top - 10) + 'px');
        if (window.scrollY) {
            window.scrollTo(0, 0);
        }
    };

    function Screen($el) {
        var self = this;
        this.$el = $el;
        this.cfg = $el.data('sx-chats');
        this.pane = null;
        this.fit();
        $(window).on('resize load', this.fit.bind(this));
        var $pane = $el.find('[data-sx-chat-pane]');
        if ($pane.length) {
            this.pane = new Pane($pane);
        }
        $el.on('click', '[data-sx-chat]', function (e) {
            if (e.ctrlKey || e.metaKey || e.shiftKey) {
                return;
            }
            e.preventDefault();
            self.open($(this));
        });
        $(document).on('sx-chats:changed', function () {
            setTimeout(self.refreshList.bind(self), 300);
        });
        $(document).on('sx-chats:reopen', function (e, id) {
            var $row = self.$el.find('[data-sx-chat="' + id + '"]');
            if ($row.length) {
                self.open($row.first());
            }
        });
        setInterval(this.refreshList.bind(this), POLL_LIST);
    }

    Screen.prototype.open = function ($row) {
        var self = this, id = $row.data('sx-chat');
        this.$el.find('[data-sx-chat]').removeClass('is-active');
        $row.addClass('is-active').removeClass('is-unread').find('.sx-chat-row__badge').remove();
        $.get($row.data('sx-pane-url')).done(function (html) {
            if (self.pane) {
                self.pane.destroy();
            }
            var $main = self.$el.find('[data-sx-chat-main]').html(html);
            self.pane = new Pane($main.find('[data-sx-chat-pane]'));
            self.$el.addClass('sx-chats--open');
            if (window.history && history.replaceState) {
                history.replaceState(null, '', $row.attr('href'));
            }
            self.refreshList();
        });
        this.currentId = id;
    };

    Screen.prototype.refreshList = function () {
        var self = this;
        if (document.hidden) {
            return;
        }
        var current = this.pane ? this.pane.cfg.id : 0;
        $.getJSON(this.cfg.listUrl, {folder: this.cfg.folder, q: this.cfg.search, id: current}).done(function (r) {
            var $list = self.$el.find('[data-sx-chat-list]'), top = $list.scrollTop();
            $list.html(r.html).scrollTop(top);
            $.each(r.folders || {}, function (key, n) {
                self.$el.find('[data-sx-folder-count="' + key + '"]').text(n || '');
            });
        });
    };

    /* The window "Привязать": switch the record type, post, close the window. */
    function windowStack() {
        var candidates = [window.parent, window.top];
        for (var i = 0; i < candidates.length; i++) {
            try {
                if (candidates[i] && candidates[i] !== window && candidates[i].sx && candidates[i].sx.BackendWindowStack) {
                    return candidates[i].sx.BackendWindowStack;
                }
            } catch (e) {
            }
        }
        return null;
    }

    function closeOwnWindow() {
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

    function notifyTop(text) {
        try {
            var w = window.top;
            if (w.sx && w.sx.notify && w.sx.notify.success) {
                w.sx.notify.success(text);
            }
        } catch (e) {
        }
    }

    $(document).on('change', '[data-sx-chat-link-form] [data-sx-chat-link-type]', function () {
        var $form = $(this).closest('[data-sx-chat-link-form]'), type = $form.find('[data-sx-chat-link-type]:checked').val();
        $form.find('[data-sx-chat-link-for]').each(function () {
            $(this).prop('hidden', $(this).data('sx-chat-link-for') !== type);
        });
        $form.find('[data-sx-chat-link-error]').prop('hidden', true);
    });

    $(document).on('submit', '[data-sx-chat-link-form]', function (e) {
        e.preventDefault();
        var $form = $(this), type = $form.find('[data-sx-chat-link-type]:checked').val();
        var id = $form.find('[data-sx-chat-link-select="' + type + '"]').val();
        var $error = $form.find('[data-sx-chat-link-error]');
        if (!id) {
            $error.text('Выберите запись.').prop('hidden', false);
            return;
        }
        var data = {type: type, id: id, person: $form.find('[name="person"]').val()};
        data[yii.getCsrfParam()] = yii.getCsrfToken();
        var $submit = $form.find('[type="submit"]').prop('disabled', true);
        $.post($form.attr('action'), data).done(function (r) {
            if (r.success) {
                notifyTop(r.message);
                if (!closeOwnWindow()) {
                    $error.text(r.message).prop('hidden', false);
                }
            } else {
                $error.text(r.message || 'Не удалось привязать.').prop('hidden', false);
                $submit.prop('disabled', false);
            }
        }).fail(function () {
            $error.text('Не удалось привязать: нет связи с сервером.').prop('hidden', false);
            $submit.prop('disabled', false);
        });
    });

    $(document).on('click', '[data-sx-chat-invite-copy]', function () {
        var input = $(this).closest('.sx-chat-invite').find('[data-sx-chat-invite-url]')[0];
        input.select();
        var done = function () {
            notifyTop('Ссылка скопирована');
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done, function () {
                document.execCommand('copy');
                done();
            });
        } else {
            document.execCommand('copy');
            done();
        }
    });

    $(function () {
        $('[data-sx-chats]').each(function () {
            if (!$(this).find('[data-sx-chat-list]').length) {
                // The panel: one chat without the list.
                var $pane = $(this).find('[data-sx-chat-pane]');
                if ($pane.length) {
                    new Pane($pane);
                }
                return;
            }
            new Screen($(this));
        });
        $('.sx-chats--panel [data-sx-chat-pane]').each(function () {
            new Pane($(this));
        });
    });
})(jQuery);
