/**
 * Kashiwazaki SEO StayPop - 管理画面
 *
 * - バナー一覧: 「表示のタイミング」を編集画面と同じ文で出す
 * - 設定: 選んだときだけ出す欄（同意の Cookie 名）
 * - 編集画面: タブ、条件で出す欄、画像の選択、テンプレートの文言、ページの検索、ライブプレビュー、未保存の表示
 */
(function ($) {
    'use strict';

    var R = window.KashiwazakiSeoStayPop || {};
    var FIELD = 'kashiwazaki_seo_staypop';

    /* ---------- 共通 ---------- */

    function store(type) {
        try {
            var s = window[type];
            s.setItem('__ksp', '1');
            s.removeItem('__ksp');
            return s;
        } catch (e) {
            return null;
        }
    }
    var SS = store('sessionStorage');

    // 「表示のタイミング」の文を入れる（空ならその端末では表示されない）
    function fillSummary($scope, t, notShown) {
        if (typeof R.describe !== 'function') { return; }
        var d = R.describe(t || {});
        $scope.find('[data-summary]').each(function () {
            var text = d[$(this).attr('data-summary')] || '';
            $(this).text(text || notShown).toggleClass('is-none', !text);
        });
    }

    /* ---------- バナー一覧 ---------- */

    $(function () {
        var L = window.KashiwazakiSeoStayPopList;
        if (!L) { return; }
        $('.ksp-list [data-triggers]').each(function () {
            var t = {};
            try { t = JSON.parse($(this).attr('data-triggers') || '{}'); } catch (e) { t = {}; }
            fillSummary($(this), t, L.notShown);
        });
    });

    /* ---------- 設定: 選んだときだけ出す欄 ---------- */

    $(function () {
        var $rows = $('[data-show-if-setting]');
        if (!$rows.length) { return; }
        function apply() {
            $rows.each(function () {
                var m = String($(this).attr('data-show-if-setting')).split('=');
                var v = $('[name="kashiwazaki_seo_staypop_settings[' + m[0] + ']"]:checked').val();
                $(this).toggle(v === m[1]);
            });
        }
        $(document).on('change', '.ksp-settings input', apply);
        apply();
    });

    /* ---------- 編集画面 ---------- */

    $(function () {
        var E = window.KashiwazakiSeoStayPopEditor;
        var $ed = $('#ksp-editor');
        if (!E || !$ed.length) { return; }
        var $form = $ed.closest('form');
        var i18n = E.i18n || {};

        /* --- 値の読み取り（FIELD[a][b] の形の入力） --- */

        function nameOf(path) {
            return FIELD + '[' + path.split('.').join('][') + ']';
        }
        function fields(path) {
            return $form.find('[name="' + nameOf(path) + '"]');
        }
        function get(path) {
            var $f = fields(path);
            if (!$f.length) { return ''; }
            var $cb = $f.filter(':checkbox');
            if ($cb.length) { return $cb.is(':checked') ? '1' : '0'; }
            var $r = $f.filter(':radio');
            if ($r.length) { return String($r.filter(':checked').val() || ''); }
            return String($f.last().val() || '');
        }
        function num(path, min, max) {
            var n = parseFloat(get(path));
            if (isNaN(n)) { n = 0; }
            if (min != null) { n = Math.max(min, n); }
            if (max != null) { n = Math.min(max, n); }
            return n;
        }
        function on(path) { return get(path) === '1'; }
        function mediaUrl(role) {
            return String($ed.find('.ksp-media[data-role="' + role + '"]').attr('data-url') || '');
        }
        function triggerCount() {
            return $ed.find('[data-trigger]:checked').length;
        }

        /* --- 編集パネルの高さが変わったら、左メニューの位置を測り直させる --- */
        // WordPress は、測っておいたページの高さで左メニューを固定するかを決める (wp-admin/js/common.js の setPinMenu)。
        // タブの切り替えなどでページの高さが変わっても測り直さないと、スクロールしたときにメニューが固定され、
        // ページの高さが縮んで縦のスクロールバーが消える。本体がメタボックスの開閉で使うのと同じ 'wp-pin-menu' を送る。
        var pinTimer = null;
        function repinMenu() {
            if (pinTimer) { return; }
            pinTimer = window.setTimeout(function () {
                pinTimer = null;
                $(document).trigger('wp-pin-menu');
            }, 50);
        }
        if (window.ResizeObserver) {
            new window.ResizeObserver(repinMenu).observe($ed[0]);
        }

        /* --- タブ --- */

        var postId = String($('#post_ID').val() || 'new');
        var TAB_KEY = 'kspEditorTab:' + postId;

        function showTab(key) {
            var $tab = $ed.find('.ksp-tab[data-tab="' + key + '"]');
            if (!$tab.length) { $tab = $ed.find('.ksp-tab').first(); key = $tab.attr('data-tab'); }
            $ed.find('.ksp-tab').each(function () {
                var active = $(this).attr('data-tab') === key;
                $(this).toggleClass('is-active', active).attr({ 'aria-selected': active ? 'true' : 'false', tabindex: active ? '0' : '-1' });
            });
            $ed.find('.ksp-panel').each(function () {
                $(this).prop('hidden', $(this).attr('data-panel') !== key);
            });
            if (SS) { try { SS.setItem(TAB_KEY, key); } catch (e) { /* 無視 */ } }
            repinMenu(); // ResizeObserver が無いブラウザでも、タブを切り替えたら測り直させる
        }
        $ed.on('click', '.ksp-tab', function () { showTab($(this).attr('data-tab')); });
        // 左右キーでタブを移る
        $ed.on('keydown', '.ksp-tab', function (e) {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
            var $tabs = $ed.find('.ksp-tab');
            var i = $tabs.index(this) + (e.key === 'ArrowRight' ? 1 : -1);
            var $next = $tabs.eq((i + $tabs.length) % $tabs.length);
            showTab($next.attr('data-tab'));
            $next.trigger('focus');
            e.preventDefault();
        });
        showTab((SS && SS.getItem(TAB_KEY)) || 'content');

        // 別のタブにある入力が不正で保存できないときは、そのタブを開いて見せる
        $form[0].addEventListener('invalid', function (e) {
            var $t = $(e.target);
            var $panel = $t.closest('.ksp-panel');
            if ($panel.length && $panel.prop('hidden')) { showTab($panel.attr('data-panel')); }
            $t.closest('details').prop('open', true);
        }, true);

        /* --- 条件で出す欄（data-show-if） --- */

        function test(expr) {
            var m = String(expr).match(/^([\w.]+)\s*(>=|<=|!=|=)\s*(.*)$/);
            if (!m) { return true; }
            var v = m[1] === 'triggers.count' ? String(triggerCount()) : get(m[1]);
            switch (m[2]) {
                case '=': return v === m[3];
                case '!=': return v !== m[3];
                case '>=': return parseFloat(v) >= parseFloat(m[3]);
                case '<=': return parseFloat(v) <= parseFloat(m[3]);
            }
            return true;
        }
        function applyShowIf() {
            $ed.find('[data-show-if]').each(function () {
                var show = test($(this).attr('data-show-if'));
                $(this).toggle(show);
                // 隠れている欄は入力チェックの対象から外す（値は残して送る）
                $(this).find('input[type="number"], input[type="text"], input[type="url"], textarea').prop('readOnly', !show);
            });
        }

        /* --- チェックで使う数値（data-enables） --- */

        function applyEnables() {
            $ed.find('[data-enables]').each(function () {
                $('#' + $(this).attr('data-enables')).toggleClass('is-off', !$(this).is(':checked'));
            });
        }
        // 数値を触ったら、その条件をオンにする
        $ed.on('focus input', '.is-off', function () {
            var id = this.id;
            var $cb = $ed.find('[data-enables="' + id + '"]');
            if ($cb.length && !$cb.is(':checked')) { $cb.prop('checked', true).trigger('change'); }
        });

        /* --- 画像 --- */

        $ed.on('click', '.ksp-media-select', function (e) {
            e.preventDefault();
            if (!window.wp || !wp.media) { return; }
            var $box = $(this).closest('.ksp-media');
            var frame = wp.media({
                title: i18n.selectImage || '',
                button: { text: i18n.useImage || '' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                var a = frame.state().get('selection').first().toJSON();
                var sizes = a.sizes || {};
                var thumb = (sizes.medium && sizes.medium.url) || a.url;
                var full = (sizes.large && sizes.large.url) || a.url;
                $box.find('.ksp-media-id').val(a.id);
                $box.find('.ksp-media-url').val('');
                $box.find('.ksp-media-thumb').attr('src', thumb);
                $box.attr('data-url', full).addClass('has-image');
                $box.find('.ksp-media-id').trigger('change');
            });
            frame.open();
        });
        $ed.on('click', '.ksp-media-clear', function (e) {
            e.preventDefault();
            var $box = $(this).closest('.ksp-media');
            $box.find('.ksp-media-id, .ksp-media-url').val('');
            $box.find('.ksp-media-thumb').attr('src', '');
            $box.attr('data-url', '').removeClass('has-image');
            $box.find('.ksp-media-id').trigger('change');
        });

        /* --- テンプレートの文言 --- */

        function presetButton() {
            var key = get('template');
            $ed.find('.ksp-apply-preset').prop('disabled', !(E.presets && E.presets[key] && E.presets[key].heading));
        }
        $ed.on('click', '.ksp-apply-preset', function () {
            var p = (E.presets || {})[get('template')];
            if (!p) { return; }
            var filled = ['heading', 'description', 'button_text'].some(function (k) {
                return String(fields('content.' + k).val() || '').trim() !== '';
            });
            if (filled && !window.confirm(i18n.presetConfirm || '')) { return; }
            fields('content.heading').val(p.heading || '');
            fields('content.description').val(p.description || '');
            fields('content.button_text').val(p.button_text || '').trigger('input');
        });

        /* --- 投稿・固定ページの選択 --- */

        function chipFor($picker, item) {
            var field = item.type === 'page' ? 'page_ids' : 'post_ids';
            var $chip = $('<span class="ksp-chip"></span>').attr('data-id', item.id);
            $chip.append($('<span class="ksp-chip-type"></span>').text(item.label)).append(' ').append(document.createTextNode(item.title));
            $chip.append($('<input type="hidden">').attr('name', $picker.attr('data-base') + '[' + field + '][]').val(item.id));
            $chip.append($('<button type="button" class="ksp-chip-remove">&times;</button>').attr('aria-label', i18n.remove || ''));
            return $chip;
        }
        function chosen($picker) {
            var ids = {};
            $picker.find('.ksp-chip').each(function () { ids[String($(this).attr('data-id'))] = true; });
            return ids;
        }
        var searchTimer = null;
        var searchSeq = 0;
        function search($picker) {
            var $list = $picker.find('.ksp-picker-results');
            var q = String($picker.find('input[type="search"]').val() || '').trim();
            var seq = ++searchSeq;
            $list.prop('hidden', false).empty().append($('<li class="ksp-picker-note"></li>').text(i18n.searching || ''));
            $.get(E.ajaxUrl, { action: 'kashiwazaki_staypop_search_posts', nonce: E.searchNonce, q: q }).done(function (res) {
                if (seq !== searchSeq) { return; } // 古い検索の結果は捨てる
                $list.empty();
                var have = chosen($picker);
                var items = (res && res.success && Array.isArray(res.data)) ? res.data.filter(function (it) { return !have[String(it.id)]; }) : [];
                if (!items.length) {
                    $list.append($('<li class="ksp-picker-note"></li>').text(i18n.noResult || ''));
                    return;
                }
                items.forEach(function (it) {
                    var $b = $('<button type="button" class="ksp-picker-item"></button>').data('item', it);
                    $b.append($('<span class="ksp-chip-type"></span>').text(it.label)).append(' ').append(document.createTextNode(it.title));
                    $list.append($('<li role="option"></li>').append($b));
                });
            }).fail(function () {
                if (seq !== searchSeq) { return; }
                $list.empty().append($('<li class="ksp-picker-note"></li>').text(i18n.noResult || ''));
            });
        }
        $ed.on('input focus', '.ksp-picker input[type="search"]', function () {
            var $picker = $(this).closest('.ksp-picker');
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { search($picker); }, 250);
        });
        $ed.on('keydown', '.ksp-picker input[type="search"]', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); } // 検索欄の Enter で投稿を保存してしまわない
            if (e.key === 'Escape') { $(this).closest('.ksp-picker').find('.ksp-picker-results').prop('hidden', true); }
            if (e.key === 'ArrowDown') {
                $(this).closest('.ksp-picker').find('.ksp-picker-item').first().trigger('focus');
                e.preventDefault();
            }
        });
        $ed.on('keydown', '.ksp-picker-item', function (e) {
            var $items = $(this).closest('.ksp-picker-results').find('.ksp-picker-item');
            var i = $items.index(this);
            if (e.key === 'ArrowDown') { $items.eq(Math.min(i + 1, $items.length - 1)).trigger('focus'); e.preventDefault(); }
            if (e.key === 'ArrowUp') {
                if (i <= 0) { $(this).closest('.ksp-picker').find('input[type="search"]').trigger('focus'); } else { $items.eq(i - 1).trigger('focus'); }
                e.preventDefault();
            }
            if (e.key === 'Escape') { $(this).closest('.ksp-picker-results').prop('hidden', true); }
        });
        $ed.on('click', '.ksp-picker-item', function () {
            var $picker = $(this).closest('.ksp-picker');
            $picker.find('.ksp-chips').append(chipFor($picker, $(this).data('item')));
            $(this).closest('li').remove();
            $picker.find('input[type="search"]').val('').trigger('focus');
            markDirty();
        });
        $ed.on('click', '.ksp-chip-remove', function () {
            $(this).closest('.ksp-chip').remove();
            markDirty();
        });
        $(document).on('mousedown', function (e) {
            if (!$(e.target).closest('.ksp-picker').length) { $ed.find('.ksp-picker-results').prop('hidden', true); }
        });

        /* --- プレビュー（保存前の入力をそのまま描く） --- */

        function collectTriggers() {
            return {
                exit_intent: on('triggers.exit_intent'),
                exit_sensitivity: num('triggers.exit_sensitivity', 0, 300),
                exit_delay_ms: Math.round(num('triggers.exit_delay_sec', 0, 5) * 1000),
                upward_scroll: on('triggers.upward_scroll'),
                dwell_seconds: on('triggers.dwell_enabled') ? Math.round(num('triggers.dwell_seconds', 0, 3600)) : 0,
                read_rate: on('triggers.read_enabled') ? Math.round(num('triggers.read_rate', 0, 100)) : 0,
                min_dwell_seconds: Math.round(num('triggers.min_dwell_seconds', 0, 3600)),
                condition_logic: get('triggers.condition_logic') === 'all' ? 'all' : 'any'
            };
        }
        // サーバーの export_config と同じ形にする
        function collectBanner() {
            var wa = Math.round(num('ab_test.weight_a', 0, 100));
            return {
                id: 0,
                format: get('format') || 'center',
                corner: get('corner_position') || 'bottom-right',
                content: {
                    image: mediaUrl('content'),
                    heading: get('content.heading'),
                    desc: get('content.description'),
                    btnText: get('content.button_text'),
                    btnUrl: get('content.button_url'),
                    btnNewTab: on('content.button_new_tab'),
                    subText: get('content.secondary_text'),
                    subUrl: get('content.secondary_url')
                },
                design: {
                    width: num('design.width', 200, 1200),
                    bg_color: get('design.bg_color'),
                    text_color: get('design.text_color'),
                    button_bg: get('design.button_bg'),
                    button_color: get('design.button_color'),
                    radius: num('design.radius', 0, 60),
                    padding: num('design.padding', 0, 80),
                    font_size: num('design.font_size', 10, 32),
                    backdrop_opacity: num('design.backdrop_pct', 0, 100) / 100
                },
                device: {
                    pc: { image: '', width: 0 },
                    mobile: { image: mediaUrl('mobile'), width: num('device.mobile.width', 0, 1200) }
                },
                triggers: collectTriggers(),
                ab: {
                    enabled: on('ab_test.enabled'),
                    variants: [
                        { label: 'A', weight: wa, image: mediaUrl('a'), heading: get('ab_test.variants.0.heading'), button_text: get('ab_test.variants.0.button_text') },
                        { label: 'B', weight: 100 - wa, image: mediaUrl('b'), heading: get('ab_test.variants.1.heading'), button_text: get('ab_test.variants.1.button_text') }
                    ]
                }
            };
        }

        var view = { device: 'pc', variant: 'A' };
        // プレビューの仮の画面の大きさ（これを枠の幅に合わせて縮める）
        var STAGE = { pc: { w: 1200, h: 760 }, mobile: { w: 390, h: 720 } };
        var $frame = $ed.find('.ksp-frame');
        var $inner = $frame.find('.ksp-frame-inner');
        var $mount = $frame.find('.ksp-frame-mount');

        function renderPreview() {
            if (typeof R.render !== 'function') { return; }
            var banner = collectBanner();
            var stage = STAGE[view.device];
            var avail = Math.max(120, $frame.innerWidth());
            var maxH = Math.max(260, window.innerHeight - 260);
            var scale = Math.min(1, avail / stage.w, maxH / stage.h);
            $frame.attr('data-device', view.device).css('height', Math.round(stage.h * scale) + 'px');
            $inner.css({ width: stage.w + 'px', height: stage.h + 'px', transform: 'scale(' + scale + ')' });
            if (view.device === 'mobile') {
                $inner.css('left', Math.max(0, Math.round((avail - stage.w * scale) / 2)) + 'px');
            } else {
                $inner.css('left', '0');
            }
            $ed.find('[data-preview="variant"]').prop('hidden', !banner.ab.enabled);
            var node = R.render(banner, { device: view.device, variant: banner.ab.enabled ? view.variant : '' });
            $mount.empty().append(node);
            fillSummary($ed.find('.ksp-summary'), banner.triggers, i18n.notShown || '');
        }
        var raf = null;
        function schedule() {
            if (raf) { return; }
            raf = window.requestAnimationFrame(function () {
                raf = null;
                applyShowIf();
                applyEnables();
                presetButton();
                renderPreview();
            });
        }
        // プレビューの中のボタン・リンクは動かさない
        $frame.on('click', function (e) { e.preventDefault(); });

        $ed.on('click', '.ksp-seg button', function () {
            var $seg = $(this).closest('.ksp-seg');
            $seg.find('button').removeClass('is-active').attr('aria-pressed', 'false');
            $(this).addClass('is-active').attr('aria-pressed', 'true');
            view[$seg.attr('data-preview')] = $(this).attr('data-value');
            schedule();
        });
        $ed.find('.ksp-seg button').each(function () {
            $(this).attr('aria-pressed', $(this).hasClass('is-active') ? 'true' : 'false');
        });

        /* --- 実物大で見る --- */

        var $full = null;
        var lastFocus = null;
        function closeFull() {
            if (!$full) { return; }
            $full.remove();
            $full = null;
            $(document).off('keydown.kspfull');
            if (lastFocus) { $(lastFocus).trigger('focus'); }
        }
        $ed.on('click', '.ksp-preview-full', function () {
            if (typeof R.render !== 'function') { return; }
            lastFocus = this;
            var banner = collectBanner();
            $full = $('<div class="ksp-full" role="dialog" aria-modal="true"></div>').attr('aria-label', i18n.fullNote || '');
            var $bar = $('<div class="ksp-full-bar"></div>');
            $bar.append($('<span></span>').text(i18n.fullNote || ''));
            var $close = $('<button type="button" class="button ksp-full-close"></button>').text(i18n.fullClose || '');
            $bar.append($close);
            var $stage = $('<div class="ksp-full-stage"></div>').attr('data-device', view.device);
            $stage.append('<div class="ksp-fake-page" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>');
            $stage.append(R.render(banner, { device: view.device, variant: banner.ab.enabled ? view.variant : '' }));
            $full.append($bar).append($stage).appendTo(document.body);
            $close.trigger('focus');
            $full.on('click', function (e) {
                e.preventDefault();
                if ($(e.target).closest('.ksp-full-close, .kashiwazaki-staypop-close, .kashiwazaki-staypop-backdrop').length) { closeFull(); }
            });
            $(document).on('keydown.kspfull', function (e) { if (e.key === 'Escape') { closeFull(); } });
        });

        /* --- 未保存の表示 --- */

        function snapshot() {
            return $form.find('[name^="' + FIELD + '["], #title').serialize();
        }
        var saved = snapshot();
        var dirty = false;
        var submitting = false;
        function markDirty() {
            dirty = snapshot() !== saved;
            $ed.find('.ksp-unsaved').prop('hidden', !dirty);
        }
        $form.on('submit', function () { submitting = true; });
        $(window).on('beforeunload.ksp', function (e) {
            if (dirty && !submitting) {
                e.preventDefault();
                e.originalEvent.returnValue = '';
                return '';
            }
        });

        $form.on('input change', '#ksp-editor :input, #title', function () {
            schedule();
            markDirty();
        });
        $(window).on('resize', schedule);
        schedule();
    });
}(jQuery));
