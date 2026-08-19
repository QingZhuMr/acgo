/* =====================================================================
 * admin-core.js —— 后台公共 JS 基础设施（由 layout.html 在 layui.js 之后统一引入）
 *
 * 1) 全局掉线拦截：
 *    当任意 AJAX 接口返回未登录标识（{ code:-9, need_login:true }，由后端
 *    CheckLogin 中间件对 AJAX 请求返回）时，让【顶层窗口】整页跳转登录页。
 *    解决之前「掉线后登录框出现在 iframe 内容页面里」的问题（管理员在 iframe
 *    内容区操作，应整页回到登录页，而不是在 iframe 内层弹登录框）。
 *
 * 2) 弹窗辅助 Acgo.dialog：
 *    统一弹窗的打开与【精确关闭】，避免各列表页用 layer.closeAll() 粗粒度关闭
 *    其它弹窗，减少「弹窗提交后再次点击按钮失效」的隐患。
 * ===================================================================== */
(function (global) {
    'use strict';

    var $ = global.jQuery || (global.layui && global.layui.$);
    if (!$ || !global.layui) return;

    var Acgo = global.Acgo = global.Acgo || {};

    /* ---------- 1. 全局掉线拦截 ---------- */
    function isLoginPage() {
        var p = global.location.pathname || '';
        return p === '/login' || p.indexOf('/login/') === 0;
    }

    $(document).ajaxComplete(function (ev, xhr, settings) {
        if (isLoginPage()) return;                 // 登录页自身请求不拦截
        var ct = xhr.getResponseHeader('Content-Type') || '';
        if (ct.indexOf('json') === -1) return;     // 仅处理 JSON（302 跟随得到的 HTML 跳过）
        var res;
        try { res = JSON.parse(xhr.responseText); } catch (e) { return; }
        if (res && res.need_login === true) {
            // 顶层窗口跳转（iframe 内容页也能整页回到登录页）
            var top = global.top && global.top !== global ? global.top : global;
            top.location.href = '/login/index?kick=1';
        }
    });

    /* ---------- 2. 弹窗辅助 ---------- */
    Acgo.dialog = {
        // 打开弹窗，返回 layer index
        open: function (options) { return global.layui.layer.open(options); },
        // 精确关闭指定弹窗（推荐：不要 closeAll）
        close: function (index) { if (index != null) global.layui.layer.close(index); },
        // 关闭本页所有（谨慎）
        closeAll: function (type) { global.layui.layer.closeAll(type); }
    };

    // 统一轻提示
    Acgo.msg = function (msg, icon) {
        global.layui.layer.msg(msg, { icon: icon || 0, time: 1800 });
    };

    // 统一确认框，回调：(ok)=>void
    Acgo.confirm = function (text, ok, title) {
        global.layui.layer.confirm(text, { title: title || '提示', btn: ['确定', '取消'] }, function (i) {
            global.layui.layer.close(i);
            ok && ok();
        });
    };
})(window);
