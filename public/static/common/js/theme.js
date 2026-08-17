/**
 * ACGO 全局主题管理（白天 / 黑夜 / 跟随系统）
 * 主题偏好存储在 localStorage key: 'acgo_theme'，取值: 'light' | 'dark' | 'system'
 * 应用后给 <html> 设置 data-theme="light" | "dark"，CSS 变量据此切换
 *
 * 跨窗口/iframe 同步：使用 BroadcastChannel，所有同源 iframe/标签页实时同步。
 * 页面首次加载请用 <head> 内联同步脚本立即设置 data-theme 避免闪烁。
 */
(function (w) {
    var STORAGE_KEY = 'acgo_theme';
    var CHANNEL_NAME = 'acgo_theme';
    var mediaDark = w.matchMedia('(prefers-color-scheme: dark)');
    var bc = null;
    try { bc = w.BroadcastChannel ? new BroadcastChannel(CHANNEL_NAME) : null; } catch (e) { bc = null; }

    var Theme = {
        getPreference: function () {
            try {
                var v = w.localStorage.getItem(STORAGE_KEY);
                return (v === 'light' || v === 'dark' || v === 'system') ? v : null;
            } catch (e) { return null; }
        },
        setPreference: function (mode) {
            try { w.localStorage.setItem(STORAGE_KEY, mode); } catch (e) {}
            var theme = this.apply(true, true); // 广播
            return theme;
        },
        resolve: function (mode) {
            mode = mode || this.getPreference() || 'light';
            if (mode === 'system') return mediaDark.matches ? 'dark' : 'light';
            return mode;
        },
        // apply：设置本窗口 <html data-theme>
        // broadcast=true 时通过 BroadcastChannel 通知其他窗口/iframe
        apply: function (fireEvent, broadcast) {
            var pre = this.getPreference();
            if (!pre) { this._setAttr('light'); return 'light'; }
            var theme = this.resolve(pre);
            var prev = w.document.documentElement.getAttribute('data-theme');
            this._setAttr(theme);
            if (fireEvent && w.jQuery) {
                w.jQuery(w.document).trigger('themeChanged', [theme, prev]);
            }
            if (broadcast && bc) {
                try { bc.postMessage({ theme: theme, pref: pre }); } catch (e) {}
            }
            return theme;
        },
        _setAttr: function (theme) {
            var root = w.document.documentElement;
            root.setAttribute('data-theme', theme);
        },
        init: function () { return this.apply(); }
    };

    // 接收其他窗口/iframe 的主题广播
    if (bc) {
        bc.onmessage = function (e) {
            if (e && e.data && (e.data.theme === 'light' || e.data.theme === 'dark')) {
                var root = w.document.documentElement;
                var prev = root.getAttribute('data-theme');
                if (prev !== e.data.theme) {
                    root.setAttribute('data-theme', e.data.theme);
                    if (w.jQuery) w.jQuery(w.document).trigger('themeChanged', [e.data.theme, prev]);
                }
            }
        };
    }
    // 跟随系统：system 模式下系统变化时实时切换
    mediaDark.addEventListener('change', function () {
        if (Theme.getPreference() === 'system') Theme.apply(true, true);
    });

    w.ACGOTheme = Theme;
})(window);