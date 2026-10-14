/**
 * Kashiwazaki SEO StayPop - フロントエンド
 *
 * 離脱予兆の検知、頻度制限、A/Bテスト割り当て、バナーの表示/閉じる、
 * アクセシビリティ（フォーカス移動・復帰）、計測イベントの送信を行う。
 *
 * 表示の決まり（「いつ出るか」が利用者に説明できること）:
 * - バナーが出るのは「きっかけ」が起きた瞬間だけ。きっかけは
 *   マウスが画面の上端から外に出た瞬間（PC）／上に戻るスクロールをした瞬間（スマホ）／
 *   指定した秒数ページを見た瞬間／指定した位置までスクロールした瞬間、のいずれか。
 * - そのとき「開いてから○秒は出さない」「入力中は出さない」などの抑制に当たれば、そのきっかけは捨てる。
 *   後から遅れて出すことはしない（遅れて出ると、原因が分からず「デタラメ」に見えるため）。
 * - 秒数は「ページが画面に出ていた時間」だけを数える（裏のタブにある間は数えない）。
 */
(function () {
    'use strict';

    var Data = window.KashiwazakiSeoStayPopData || null;
    var api = window.KashiwazakiSeoStayPop = window.KashiwazakiSeoStayPop || {};

    var FORM_COOLDOWN_MS = 10000;   // 入力欄を触ってからこの間は出さない
    var POINTER_TRAVEL_PX = 150;    // PC: マウスがこの距離を動くまでは「離脱」とみなさない
    var UPWARD_PX = 80;             // スマホ: この距離を上に戻したら「離脱しそう」とみなす
    var UPWARD_MIN_DEPTH_PX = 300;  // スマホ: ここまで下に読んでいない人の上スクロールは数えない
    var VISIT_GAP_MS = 30 * 60 * 1000; // 30分あくと次の訪問

    /* ---------- ユーティリティ ---------- */

    var deviceOverride = null; // プレビュー描画のときだけ 'pc' / 'mobile' を入れる

    function now() { return Date.now(); }

    function isMobile() {
        if (deviceOverride) { return deviceOverride === 'mobile'; }
        return window.matchMedia('(max-width: 782px)').matches ||
            /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
    }

    function safeStorage(type) {
        try {
            var s = window[type];
            s.setItem('__t', '1');
            s.removeItem('__t');
            return s;
        } catch (e) {
            return null;
        }
    }
    var LS = safeStorage('localStorage');
    var SS = safeStorage('sessionStorage');

    function storeGet(store, key) {
        if (!store) { return null; }
        try {
            var raw = store.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }
    function storeSet(store, key, val) {
        if (!store) { return; }
        try { store.setItem(key, JSON.stringify(val)); } catch (e) { /* 無視 */ }
    }

    function pathFromUrl(value) {
        // 完全なURLで指定された場合はパス部分だけにする（サーバー側と同じ扱い）
        if (/^[a-z][a-z0-9+.\-]*:\/\//i.test(value)) {
            try {
                return new URL(value).pathname || '/';
            } catch (e) {
                return value;
            }
        }
        return value;
    }

    function normHex(s) { return s.replace(/%[0-9A-Fa-f]{2}/g, function (m) { return m.toLowerCase(); }); }
    // u フラグでサロゲートペア（絵文字など）を1コードポイントとして扱う（未指定だと分割され URIError になる）
    function encNonAscii(s) { return s.replace(/[^\x00-\x7F]/gu, function (c) { return encodeURIComponent(c); }); }

    function matchesPattern(path, patterns) {
        if (!patterns) { return false; }
        // パターンの非ASCII文字はパーセントエンコードした形でも照合し、16進の大小は同一視する（PHP側と同じ）。
        var pc = normHex(path);
        var lines = String(patterns).split(/\r\n|\r|\n/);
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (!line) { continue; }
            line = pathFromUrl(line);
            var variants = [line];
            var enc = encNonAscii(line);
            if (enc !== line) { variants.push(enc); }
            for (var j = 0; j < variants.length; j++) {
                var v = variants[j];
                var vc = normHex(v);
                if (v.indexOf('*') !== -1) {
                    // すべての特殊文字を退避してから * だけを .* に戻す
                    var escaped = vc.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    var re = new RegExp('^' + escaped.replace(/\\\*/g, '.*') + '$', 'i');
                    if (re.test(pc)) { return true; }
                } else if (pc === vc) {
                    return true;
                } else if (vc !== '/' && pc.indexOf(vc.replace(/\/$/, '') + '/') === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }

    function pagePath() {
        return (Data && Data.page) || window.location.pathname;
    }

    function isTest() { return !!(Data && Data.test); }
    function noRecord() { return !Data || !!Data.preview || isTest(); }

    /* ---------- 計測 ---------- */

    // 計測イベントを送る。wantResult=true のときだけ送信結果（サーバー受理）を Promise<boolean> で返す。
    // 結果不要のイベントは sendBeacon（発火のみ）を使い、keepalive の負荷を避ける。
    function sendEvent(bannerId, event, variant, wantResult) {
        if (noRecord() || !Data.allowAnalytics || !Data.eventAction) { return Promise.resolve(false); }
        try {
            var body = new URLSearchParams();
            body.append('action', Data.eventAction);
            body.append('nonce', Data.nonce);
            body.append('banner_id', bannerId);
            body.append('event', event);
            body.append('variant', variant || '');
            body.append('device', isMobile() ? 'mobile' : 'pc');
            body.append('page', pagePath());
            if (!wantResult && navigator.sendBeacon) {
                navigator.sendBeacon(Data.ajaxUrl, new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' }));
                return Promise.resolve(true);
            }
            return fetch(Data.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                keepalive: true,
                credentials: 'same-origin'
            }).then(function (res) {
                // HTTP 200 でも本文 success=true のときだけ受理とみなす（記録失敗や拒否を確実に拾う）
                return res.json().then(function (json) {
                    return !!(res.ok && json && json.success);
                }).catch(function () {
                    return false;
                });
            }).catch(function () {
                return false;
            });
        } catch (e) {
            return Promise.resolve(false); // 計測失敗は表示に影響させない
        }
    }

    /* ---------- 頻度制限 ---------- */

    function freqKey(id) { return 'kashiwazaki_staypop_b' + id; }
    function convKey(id) { return 'kashiwazaki_staypop_converted_' + id; }
    function sentKey(id) { return 'kashiwazaki_staypop_convsent_' + id; }
    function clickKey(id) { return 'kashiwazaki_staypop_clicked_' + id; }
    function variantKey(id) { return 'kashiwazaki_staypop_variant_' + id; }

    // 「訪問」はタブをまたいで共通。最後にページを開いてから30分あくと次の訪問になる。
    var visitId = '';
    function touchVisit() {
        if (!LS) { return ''; }
        var v = storeGet(LS, 'kashiwazaki_staypop_visit');
        var t = now();
        if (!v || !v.id || (t - (v.last || 0)) > VISIT_GAP_MS) {
            v = { id: t.toString(36) + Math.random().toString(36).slice(2, 7) };
        }
        v.last = t;
        storeSet(LS, 'kashiwazaki_staypop_visit', v);
        return v.id;
    }

    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
    }

    // 成果イベントを1回だけ送る。計測が許可されていないときは送らない。
    // 送信前に既送マーク（pending）を立てて多重送信を防ぎ、受理されなければ外して次回再試行する。
    // 応答を待たずにページを離脱した場合に備え、古すぎる pending（60秒超）は失敗とみなして再試行する。
    function sendConversion(id, variant) {
        if (noRecord() || !Data.allowAnalytics) { return; }
        var s = storeGet(LS, sentKey(id));
        if (s) {
            if (!s.pending) { return; }                                   // 送信済み
            if (s.at && (now() - s.at) < 60000) { return; }               // 送信中（新しい）
            try { LS.removeItem(sentKey(id)); } catch (e) { /* 無視 */ }  // 古い pending は解除して再送
        }
        storeSet(LS, sentKey(id), { at: now(), pending: true });
        function clearPending() {
            var cur = storeGet(LS, sentKey(id));
            if (cur && cur.pending) { try { LS.removeItem(sentKey(id)); } catch (e) { /* 無視 */ } }
        }
        sendEvent(id, 'conversion', variant || '', true).then(function (ok) {
            if (ok) {
                storeSet(LS, sentKey(id), { at: now() });
            } else {
                clearPending();
            }
        }).catch(clearPending);
    }

    function frequencyAllows(banner) {
        if (isTest()) { return true; } // テスト表示では回数の制限をかけない
        var f = banner.frequency || {};
        var state = storeGet(LS, freqKey(banner.id)) || {};
        var t = now();
        if (banner.stopAfterConv && storeGet(LS, convKey(banner.id))) { return false; }
        if (state.closedAt && f.snooze_days > 0 && t < (state.closedAt + f.snooze_days * 86400000)) { return false; }
        var mode = f.mode || 'once_session';
        if (mode === 'once_ever' && state.count > 0) { return false; }
        if (mode === 'once_session') {
            if (LS && visitId) {
                if (state.visit === visitId) { return false; }
            } else if (storeGet(SS, freqKey(banner.id))) {
                return false;
            }
        }
        if (mode === 'once_day' && state.day === today()) { return false; }
        return true;
    }

    function recordShown(banner) {
        if (noRecord()) { return; }
        var state = storeGet(LS, freqKey(banner.id)) || {};
        state.lastShown = now();
        state.count = (state.count || 0) + 1;
        state.visit = visitId;
        state.day = today();
        storeSet(LS, freqKey(banner.id), state);
        storeSet(SS, freqKey(banner.id), { shown: true });
    }

    function recordClosed(banner) {
        if (noRecord()) { return; }
        var state = storeGet(LS, freqKey(banner.id)) || {};
        state.closedAt = now();
        storeSet(LS, freqKey(banner.id), state);
    }

    /* ---------- A/B テスト ---------- */

    function weighted(variants) {
        var total = 0;
        variants.forEach(function (v) { total += Math.max(0, v.weight || 0); });
        var r = Math.random() * (total || variants.length);
        var acc = 0;
        for (var j = 0; j < variants.length; j++) {
            acc += total ? Math.max(0, variants[j].weight || 0) : 1; // 重みが全部0なら均等に
            if (r < acc) { return variants[j]; }
        }
        return variants[variants.length - 1];
    }

    // fixedLabel を渡すとそのパターンを返す（プレビュー用。記録しない）
    function pickVariant(banner, fixedLabel) {
        if (!banner.ab || !banner.ab.enabled || !banner.ab.variants || banner.ab.variants.length === 0) {
            return null;
        }
        var list = banner.ab.variants;
        var i;
        if (fixedLabel) {
            for (i = 0; i < list.length; i++) {
                if (list[i].label === fixedLabel) { return list[i]; }
            }
            return list[0];
        }
        if (noRecord()) { return weighted(list); }
        var stored = storeGet(LS, variantKey(banner.id));
        if (stored && stored.label) {
            for (i = 0; i < list.length; i++) {
                if (list[i].label === stored.label) { return list[i]; }
            }
        }
        var chosen = weighted(list);
        storeSet(LS, variantKey(banner.id), { label: chosen.label });
        return chosen;
    }

    /* ---------- バナーの DOM 構築 ---------- */

    function contentFor(banner, fixedVariant) {
        var variant = pickVariant(banner, fixedVariant);
        var base = banner.content || {};
        var image = base.image, heading = base.heading, btnText = base.btnText, width = 0;
        var d = isMobile() ? (banner.device && banner.device.mobile) : (banner.device && banner.device.pc);
        if (d && d.image) { image = d.image; }
        if (d && d.width) { width = d.width; }
        if (variant) {
            if (variant.image) { image = variant.image; }
            if (variant.heading) { heading = variant.heading; }
            if (variant.button_text) { btnText = variant.button_text; }
        }
        return { image: image, heading: heading, btnText: btnText, desc: base.desc, btnUrl: base.btnUrl, btnNewTab: base.btnNewTab, subText: base.subText, subUrl: base.subUrl, width: width, variant: variant };
    }

    function styleBox(box, banner, width) {
        var dz = banner.design || {};
        var format = banner.format || 'center';
        box.style.background = dz.bg_color || '#fff';
        box.style.color = dz.text_color || '#222';
        box.style.borderRadius = (format === 'bottom_bar' ? 0 : (dz.radius || 0)) + 'px';
        box.style.padding = (dz.padding != null ? dz.padding : 16) + 'px';
        box.style.fontSize = (dz.font_size || 16) + 'px';
        if (format === 'center') {
            box.style.maxWidth = (width || dz.width || 520) + 'px';
        } else if (format === 'corner') {
            // 隅に出すものは小さく（指定幅が大きくても 380px まで）
            box.style.maxWidth = Math.min(width || dz.width || 360, 380) + 'px';
        }
    }

    function buildBanner(banner, fixedVariant) {
        var c = contentFor(banner, fixedVariant);
        var format = banner.format || 'center';
        var isModal = format === 'center';
        var root = el('div', 'kashiwazaki-staypop-root kashiwazaki-staypop-format-' + format);
        if (format === 'corner') { root.classList.add('kashiwazaki-staypop-corner-' + (banner.corner || 'bottom-right')); }
        if (c.image) { root.classList.add('kashiwazaki-staypop-has-image'); }
        root.setAttribute('role', 'dialog');
        // 中央ポップアップだけがモーダル。下部バー・隅は本文を操作できる非モーダル。
        if (isModal) { root.setAttribute('aria-modal', 'true'); }
        root.setAttribute('aria-label', c.heading || 'お知らせ');

        var backdrop = null;
        if (format === 'center') {
            backdrop = el('div', 'kashiwazaki-staypop-backdrop');
            var op = (banner.design && banner.design.backdrop_opacity != null) ? banner.design.backdrop_opacity : 0.5;
            backdrop.style.background = 'rgba(0,0,0,' + op + ')';
            root.appendChild(backdrop);
        }
        var box = el('div', 'kashiwazaki-staypop-box');
        styleBox(box, banner, c.width);

        var closeBtn = el('button', 'kashiwazaki-staypop-close');
        closeBtn.setAttribute('type', 'button');
        closeBtn.setAttribute('aria-label', '閉じる');
        closeBtn.innerHTML = '<span aria-hidden="true">&times;</span>';
        box.appendChild(closeBtn);

        if (c.image) {
            var img = el('img', 'kashiwazaki-staypop-image');
            img.src = c.image;
            img.alt = c.heading || '';
            box.appendChild(img);
        }
        var body = el('div', 'kashiwazaki-staypop-body');
        if (c.heading) { body.appendChild(el('div', 'kashiwazaki-staypop-heading', c.heading)); }
        if (c.desc) { body.appendChild(el('div', 'kashiwazaki-staypop-desc', c.desc)); }
        var actions = el('div', 'kashiwazaki-staypop-actions');
        if (c.btnText) {
            var a = el('a', 'kashiwazaki-staypop-button', c.btnText);
            a.href = c.btnUrl || '#';
            if (c.btnNewTab) { a.target = '_blank'; a.rel = 'noopener'; }
            var dz = banner.design || {};
            if (dz.button_bg) { a.style.background = dz.button_bg; }
            if (dz.button_color) { a.style.color = dz.button_color; }
            actions.appendChild(a);
        }
        if (c.subText && c.subUrl) {
            var sub = el('a', 'kashiwazaki-staypop-sub', c.subText);
            sub.href = c.subUrl;
            actions.appendChild(sub);
        }
        if (actions.childNodes.length) { body.appendChild(actions); }
        box.appendChild(body);

        root.appendChild(box);
        return { root: root, closeBtn: closeBtn, backdrop: backdrop, button: box.querySelector('.kashiwazaki-staypop-button'), content: c, isModal: isModal };
    }

    /*
     * 編集画面のライブプレビュー用: バナーの見た目だけを作って返す（発火・計測・記録はしない）。
     * options.device: 'pc' | 'mobile'、options.variant: 'A' | 'B'
     */
    api.render = function (banner, options) {
        options = options || {};
        deviceOverride = options.device === 'mobile' ? 'mobile' : 'pc';
        try {
            return buildBanner(banner, options.variant || '').root;
        } finally {
            deviceOverride = null;
        }
    };

    // 表示条件を文章にする（編集画面・テスト表示で共通に使う）
    api.describe = describeTriggers;

    // 戻り値: { pc: '…とき' | '', mobile: '…とき' | '' }（空文字はその端末では表示されない）
    function describeTriggers(t) {
        t = t || {};
        var read = t.read_rate || t.scroll_pct || 0;
        var dwell = t.dwell_seconds > 0 ? Math.max(t.dwell_seconds, t.min_dwell_seconds || 0) : 0;
        var anyConfigured = !!(t.exit_intent || t.upward_scroll || dwell > 0 || read > 0);
        if (!anyConfigured) {
            var s = Math.max(t.min_dwell_seconds || 0, 1) + '秒たったとき';
            return { pc: s, mobile: s };
        }
        function forDevice(instant) {
            if (t.condition_logic === 'all') {
                var mid = [], last = [];
                if (dwell > 0) { mid.push(dwell + '秒以上見て'); last.push(dwell + '秒以上見たとき'); }
                if (read > 0) { mid.push(read + '%以上スクロールして'); last.push(read + '%以上スクロールしたとき'); }
                if (instant) { return (mid.length ? mid.join('、') + 'から、' : '') + instant; }
                if (!last.length) { return ''; }
                return mid.slice(0, -1).concat(last.slice(-1)).join('、');
            }
            var list = [];
            if (instant) { list.push(instant); }
            if (dwell > 0) { list.push(dwell + '秒見たとき'); }
            if (read > 0) { list.push(read + '%までスクロールしたとき'); }
            return list.join('、または ');
        }
        return {
            pc: forDevice(t.exit_intent ? 'マウスが画面の上端から外に出たとき' : ''),
            mobile: forDevice(t.upward_scroll ? '上に戻るスクロールをしたとき' : '')
        };
    }

    if (!Data) { return; } // 編集画面（描画だけ使う）

    var HAS_BANNERS = Array.isArray(Data.banners) && Data.banners.length > 0;
    var HAS_CONV = Array.isArray(Data.conversionBanners) && Data.conversionBanners.length > 0;
    if (!HAS_BANNERS && !HAS_CONV) { return; }

    /* ---------- ページ全体の状態（画面に出ていた時間・入力・マウス） ---------- */

    var visibleMs = 0;
    var visibleSince = document.hidden ? null : now();
    var visibilityListeners = [];
    function visibleElapsed() { return visibleMs + (visibleSince !== null ? now() - visibleSince : 0); }
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (visibleSince !== null) { visibleMs += now() - visibleSince; visibleSince = null; }
        } else if (visibleSince === null) {
            visibleSince = now();
        }
        visibilityListeners.forEach(function (fn) { try { fn(!document.hidden); } catch (e) { /* 無視 */ } });
    });

    function isOwnElement(n) {
        return !!(n && n.closest && n.closest('.kashiwazaki-staypop-root, .kashiwazaki-staypop-testbar'));
    }
    function isFormField(n) {
        if (!n || !n.tagName || isOwnElement(n)) { return false; }
        var tag = n.tagName.toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || n.isContentEditable === true;
    }
    var lastFormAt = 0;
    function markForm(e) { if (isFormField(e.target)) { lastFormAt = now(); } }
    document.addEventListener('focusin', markForm, true);
    document.addEventListener('input', markForm, true);
    document.addEventListener('change', markForm, true);
    function formBusy() {
        return isFormField(document.activeElement) || (lastFormAt > 0 && (now() - lastFormAt) < FORM_COOLDOWN_MS);
    }

    // 「訪問」の最終時刻は、ページを操作している間も更新する（見なくなってから30分で次の訪問）
    var lastVisitTouch = 0;
    function keepVisit() {
        var t = now();
        if (t - lastVisitTouch < 60000) { return; }
        lastVisitTouch = t;
        visitId = touchVisit();
    }
    ['mousemove', 'keydown', 'touchstart'].forEach(function (type) {
        document.addEventListener(type, keepVisit, { capture: true, passive: true });
    });
    window.addEventListener('scroll', keepVisit, { capture: true, passive: true });
    visibilityListeners.push(function (visible) { if (visible) { keepVisit(); } });

    var pointerTravel = 0, curX = null, curY = null, prevY = null;
    document.addEventListener('mousemove', function (e) {
        if (curX !== null) {
            var dx = e.clientX - curX, dy = e.clientY - curY;
            pointerTravel += Math.min(Math.sqrt(dx * dx + dy * dy), 400); // 1回の大きな飛びは割り引く
        }
        prevY = curY;
        curX = e.clientX;
        curY = e.clientY;
    }, { capture: true, passive: true });

    // きっかけを捨てる理由（無ければ ''）
    function blockReason(t, moment) {
        if (document.hidden) { return 'hidden'; }
        if ((t.min_dwell_seconds || 0) > 0 && visibleElapsed() < t.min_dwell_seconds * 1000) { return 'min_dwell'; }
        if (formBusy()) { return 'form'; }
        if (moment === 'exit' && pointerTravel < POINTER_TRAVEL_PX) { return 'pointer'; }
        return '';
    }

    /* ---------- 表示・閉じる ---------- */

    var activeBanner = null;

    function focusables(root) {
        var nodes = root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
        return Array.prototype.slice.call(nodes).filter(function (n) {
            return n.offsetWidth > 0 || n.offsetHeight > 0 || n === document.activeElement;
        });
    }

    function showBanner(banner) {
        if (activeBanner) { return; }
        var built = buildBanner(banner, '');
        document.body.appendChild(built.root);

        var prevFocus = document.activeElement;
        var focusTarget = built.button || built.closeBtn;
        if (focusTarget && focusTarget.focus) { window.setTimeout(function () { focusTarget.focus(); }, 30); }

        var closed = false;
        function close() {
            if (closed) { return; }
            closed = true;
            activeBanner = null;
            if (built.root.parentNode) { built.root.parentNode.removeChild(built.root); }
            document.removeEventListener('keydown', onKey, true);
            recordClosed(banner);
            sendEvent(banner.id, 'close', built.content.variant ? built.content.variant.label : '');
            if (prevFocus && prevFocus.focus) { prevFocus.focus(); }
            if (testBar) { testBar.onClosed(); }
        }
        function onKey(e) {
            if (e.key === 'Escape' || e.keyCode === 27) { close(); return; }
            // モーダル（中央ポップアップ）では Tab をダイアログ内に閉じ込める
            if (built.isModal && (e.key === 'Tab' || e.keyCode === 9)) {
                var f = focusables(built.root);
                if (f.length === 0) { return; }
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }
        document.addEventListener('keydown', onKey, true);
        built.closeBtn.addEventListener('click', close);
        if (built.backdrop) { built.backdrop.addEventListener('click', close); }
        if (built.button) {
            var onClick = function () {
                // クリックした事実を控える（この後の成果をこのバナーに帰属させるため）
                if (!noRecord()) { storeSet(LS, clickKey(banner.id), { at: now() }); }
                sendEvent(banner.id, 'click', built.content.variant ? built.content.variant.label : '');
            };
            built.button.addEventListener('click', onClick);
            // 中クリック（別タブを開く）は click ではなく auxclick で発生する（左=click, 中=auxclick button 1）
            built.button.addEventListener('auxclick', function (e) { if (e.button === 1) { onClick(); } });
        }
        activeBanner = banner;
        recordShown(banner);
        sendEvent(banner.id, 'impression', built.content.variant ? built.content.variant.label : '');
        if (testBar) { testBar.onShown(); }
    }

    /* ---------- 表示のきっかけ ---------- */

    var triggerCleanups = [];
    function cleanupAllTriggers() {
        triggerCleanups.forEach(function (fn) { try { fn(); } catch (e) { /* 無視 */ } });
        triggerCleanups = [];
    }

    var controllers = [];

    function setupTriggers(banner, onReady) {
        var t = banner.triggers || {};
        var mobile = isMobile();
        var minDwell = Math.max(0, t.min_dwell_seconds || 0);
        var readPct = t.read_rate || t.scroll_pct || 0;
        var useExit = !mobile && !!t.exit_intent;
        var useUpward = mobile && !!t.upward_scroll;
        var dwellSec = t.dwell_seconds > 0 ? Math.max(t.dwell_seconds, minDwell) : 0;
        var anyConfigured = !!(t.exit_intent || t.upward_scroll || t.dwell_seconds > 0 || readPct > 0);
        if (!anyConfigured) {
            // 条件をひとつも決めていないバナーは「開いてから○秒（最低1秒）たったら」
            dwellSec = Math.max(minDwell, 1);
        }
        var hasInstant = useExit || useUpward;
        var hasState = dwellSec > 0 || readPct > 0;
        var state = {
            fired: false,
            dwellDone: false,
            maxPct: 0,
            lastBlock: '',
            pendingRecheck: false
        };
        if (!hasInstant && !hasState) {
            controllers.push({ banner: banner, state: state, none: true, rearm: function () {} });
            return; // この端末では表示する条件がない
        }

        function readNow() {
            var docH = document.documentElement.scrollHeight - window.innerHeight;
            var y = Math.min(Math.max(window.pageYOffset || 0, 0), Math.max(docH, 0));
            return { y: y, pct: docH > 0 ? Math.round(y / docH * 100) : 100 };
        }

        function statesOk() {
            if (dwellSec > 0 && !state.dwellDone) { return false; }
            if (readPct > 0 && state.maxPct < readPct) { return false; }
            return true;
        }

        function evaluate(moment) {
            if (state.fired || activeBanner) { return; }
            var ok;
            if (t.condition_logic === 'all' && anyConfigured) {
                ok = statesOk() && (!hasInstant || moment === 'exit' || moment === 'upward');
            } else if (moment === 'exit' || moment === 'upward') {
                ok = true;
            } else if (moment === 'dwell') {
                ok = dwellSec > 0 && state.dwellDone;
            } else if (moment === 'read') {
                ok = readPct > 0 && state.maxPct >= readPct;
            } else {
                ok = false;
            }
            if (!ok) { return; }
            var reason = blockReason(t, moment);
            state.lastBlock = reason;
            if (reason) {
                // 状態のきっかけ（秒数）だけは、次にスクロールしたときにもう一度確かめる
                if (moment === 'dwell') { state.pendingRecheck = true; }
                return;
            }
            state.fired = true;
            onReady(banner);
        }

        /* PC: マウスが画面の上端から外に出た瞬間 */
        var exitTimer = null;
        function onMouseOut(e) {
            if (!useExit || e.relatedTarget || e.toElement) { return; }
            var tg = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
            if (tg === 'select' || tg === 'option') { return; } // ネイティブのプルダウンを開いたときの誤検知
            var y = typeof e.clientY === 'number' ? e.clientY : 9999;
            var x = typeof e.clientX === 'number' ? e.clientX : -9999;
            var sensitivity = Math.max(0, t.exit_sensitivity || 0);
            if (y > sensitivity) { return; }
            if (x < -5 || x > window.innerWidth + 5) { return; } // 左右から出たときは数えない
            var movingUp = y <= 0 || (curY !== null && y < curY) || (prevY !== null && curY !== null && curY < prevY);
            if (!movingUp) { return; }
            if (y >= 0 && document.elementFromPoint) {
                var under = document.elementFromPoint(Math.max(0, x), y);
                if (under && under.tagName && under.tagName.toLowerCase() === 'iframe') { return; } // 埋め込みに入っただけ
            }
            if (t.exit_delay_ms > 0) {
                if (exitTimer) { window.clearTimeout(exitTimer); }
                exitTimer = window.setTimeout(function () { exitTimer = null; evaluate('exit'); }, t.exit_delay_ms);
            } else {
                evaluate('exit');
            }
        }
        // 待ち時間の間にマウスが戻ってきたら取り消す
        function onMouseOver(e) {
            if (exitTimer && !e.relatedTarget) { window.clearTimeout(exitTimer); exitTimer = null; }
        }

        /* スマホ: 上に戻るスクロール／共通: スクロール位置 */
        var start = readNow();
        var lastY = start.y, maxY = start.y, upAccum = 0;
        var readyAt = now() + 800; // 読み込み直後のスクロール位置の復元は数えない
        function onScroll() {
            var cur = readNow();
            if (cur.pct > state.maxPct) { state.maxPct = cur.pct; }
            if (cur.y > maxY) { maxY = cur.y; }
            if (cur.y < lastY) { upAccum += lastY - cur.y; } else if (cur.y > lastY) { upAccum = 0; }
            lastY = cur.y;
            if (now() < readyAt) { upAccum = 0; return; }
            if (useUpward && upAccum >= UPWARD_PX && maxY >= UPWARD_MIN_DEPTH_PX) {
                upAccum = 0;
                evaluate('upward');
            }
            if (readPct > 0 && state.maxPct >= readPct) { evaluate('read'); }
            if (state.pendingRecheck) {
                state.pendingRecheck = false;
                evaluate('dwell');
            }
        }

        /* 共通: ページを見ていた秒数（画面に出ている間だけ数える） */
        var dwellTimer = null;
        function armDwell() {
            if (dwellTimer) { window.clearTimeout(dwellTimer); dwellTimer = null; }
            if (dwellSec <= 0 || state.dwellDone || document.hidden) { return; }
            var remain = dwellSec * 1000 - visibleElapsed();
            dwellTimer = window.setTimeout(function () {
                dwellTimer = null;
                if (document.hidden) { return; }
                if (visibleElapsed() < dwellSec * 1000 - 50) { armDwell(); return; }
                state.dwellDone = true;
                evaluate('dwell');
            }, Math.max(0, remain));
        }
        function onVisibility(visible) {
            if (visible) { armDwell(); } else if (dwellTimer) { window.clearTimeout(dwellTimer); dwellTimer = null; }
        }

        document.addEventListener('mouseout', onMouseOut, true);
        document.addEventListener('mouseover', onMouseOver, true);
        window.addEventListener('scroll', onScroll, { capture: true, passive: true });
        visibilityListeners.push(onVisibility);
        armDwell();

        triggerCleanups.push(function () {
            document.removeEventListener('mouseout', onMouseOut, true);
            document.removeEventListener('mouseover', onMouseOver, true);
            window.removeEventListener('scroll', onScroll, { capture: true, passive: true });
            var i = visibilityListeners.indexOf(onVisibility);
            if (i !== -1) { visibilityListeners.splice(i, 1); }
            if (dwellTimer) { window.clearTimeout(dwellTimer); }
            if (exitTimer) { window.clearTimeout(exitTimer); }
        });

        controllers.push({
            banner: banner,
            state: state,
            dwellSec: dwellSec,
            readPct: readPct,
            useExit: useExit,
            useUpward: useUpward,
            rearm: function () { state.fired = false; }
        });
    }

    var ready = [];
    var chooseTimer = null;
    function showBest() {
        chooseTimer = null;
        if (activeBanner) { return; }
        var candidates = ready;
        ready = [];
        var best = null;
        for (var i = 0; i < Data.banners.length; i++) {
            var b = Data.banners[i];
            if (candidates.indexOf(b) === -1 || !frequencyAllows(b)) { continue; }
            if (!best || (b.priority || 0) > (best.priority || 0)) { best = b; }
        }
        // 合流待ちの間に入力が始まったら、このきっかけは捨てて次のきっかけを待つ
        if (!best || formBusy() || document.hidden) {
            controllers.forEach(function (c) { if (candidates.indexOf(c.banner) !== -1) { c.rearm(); } });
            return;
        }
        cleanupAllTriggers();
        showBanner(best);
    }
    function markReady(banner) {
        if (ready.indexOf(banner) === -1) { ready.push(banner); }
        if (activeBanner || chooseTimer) { return; }
        // 近い時刻に成立した候補をまとめ、その中で優先度が最も高い1つだけを表示する
        chooseTimer = window.setTimeout(showBest, 150);
    }

    function armAll() {
        controllers = [];
        Data.banners.forEach(function (banner) {
            if (frequencyAllows(banner)) { setupTriggers(banner, markReady); }
        });
    }

    /* ---------- テスト表示（管理者が「サイトでテスト表示」から開いたときだけ） ---------- */

    var testBar = null;
    function startTestBar() {
        var c0 = Data.banners[0];
        if (!c0) { return; }
        var bar = el('div', 'kashiwazaki-staypop-testbar');
        bar.setAttribute('role', 'status');
        var title = el('div', 'kashiwazaki-staypop-testbar-title', 'テスト表示中：' + (c0.title || ('#' + c0.id)));
        var list = el('ul', 'kashiwazaki-staypop-testbar-list');
        var foot = el('div', 'kashiwazaki-staypop-testbar-foot');
        var again = el('button', 'kashiwazaki-staypop-testbar-btn', 'もう一度試す');
        again.type = 'button';
        foot.appendChild(again);
        bar.appendChild(title);
        bar.appendChild(list);
        bar.appendChild(foot);
        document.body.appendChild(bar);

        var reasons = { hidden: '画面に出ていない', min_dwell: '開いた直後のため待機中', form: '入力中のため見送り', pointer: 'マウスの移動が少ないため見送り' };
        function row(text, done) {
            var li = el('li', done ? 'is-done' : '');
            li.textContent = (done ? '✓ ' : '・ ') + text;
            list.appendChild(li);
        }
        function render() {
            list.innerHTML = '';
            var c = controllers[0];
            var t = c0.triggers || {};
            if (activeBanner) { row('表示しました（閉じると、もう一度試せます）', true); return; }
            if (!c || c.none) { row('この端末（' + (isMobile() ? 'スマホ' : 'PC') + '）では表示する条件がありません', false); return; }
            if (c.useExit) { row('マウスを画面の上端から外に出す', false); }
            if (c.useUpward) { row('下に読んだあと、上に戻るスクロールをする', false); }
            if (c.dwellSec > 0) { row('ページを ' + c.dwellSec + ' 秒見る（いま ' + Math.floor(visibleElapsed() / 1000) + ' 秒）', c.state.dwellDone); }
            if (c.readPct > 0) { row(c.readPct + '% までスクロールする（いま ' + c.state.maxPct + '%）', c.state.maxPct >= c.readPct); }
            var md = t.min_dwell_seconds || 0;
            if (md > 0) { row('開いてから ' + md + ' 秒は表示しない', visibleElapsed() >= md * 1000); }
            if (t.condition_logic === 'all') { row('組み合わせ：すべて満たしたとき', false); }
            if (c.state.lastBlock) { row('直前のきっかけ：' + (reasons[c.state.lastBlock] || c.state.lastBlock), false); }
        }
        var timer = window.setInterval(render, 500);
        render();
        again.addEventListener('click', function () {
            cleanupAllTriggers();
            armAll();
            render();
        });
        testBar = {
            onShown: render,
            onClosed: render,
            stop: function () { window.clearInterval(timer); }
        };
    }

    /* ---------- 起動 ---------- */

    function init() {
        visitId = touchVisit();
        lastVisitTouch = now();

        // 成果ページに到達していたら、そのバナーの成果を記録する（バナーごとに1回だけ）
        if (!isTest()) {
            try {
                var path = pagePath();
                var sources = (Data.banners || []).concat(Data.conversionBanners || []);
                var seen = {};
                sources.forEach(function (banner) {
                    try {
                        var id = banner && banner.id;
                        if (!id || seen[id]) { return; }
                        seen[id] = true;
                        // 成果の計測点は成果ページ。このページが成果URLに一致しなければ何もしない
                        if (!banner.conversionUrls || !matchesPattern(path, banner.conversionUrls)) { return; }
                        // 表示またはクリックされた（＝関与した）バナーだけに成果を帰属させる
                        var shown = storeGet(LS, freqKey(id));
                        var engaged = (shown && shown.count > 0) || !!storeGet(LS, clickKey(id));
                        if (!engaged) { return; }
                        var variant = storeGet(LS, variantKey(id));
                        var vlabel = variant && variant.label ? variant.label : '';
                        // 成果の「達成」はローカルに記録する（成果後の停止判定用。計測の許可に依存しない）
                        if (!storeGet(LS, convKey(id))) { storeSet(LS, convKey(id), { at: now(), variant: vlabel }); }
                        // 計測が許可され、サーバーが受理したときだけ既送にする（拒否時は次回の成果ページ訪問で再試行）
                        sendConversion(id, vlabel);
                    } catch (e) { /* このバナーの判定失敗で他を止めない */ }
                });
            } catch (e) { /* 無視 */ }
        }

        if (!HAS_BANNERS) { return; }
        // 全バナーの条件を同時に見張り、きっかけが起きた中で最優先の1つだけを表示する
        armAll();
        if (isTest()) { startTestBar(); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
