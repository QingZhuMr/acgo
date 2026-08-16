<?php
// 全局中间件定义文件
return [
    // 跨域中间件（白名单来自 .env 的 CORS_ALLOW_ORIGIN）
    \app\middleware\Cors::class,

    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    // \think\middleware\SessionInit::class
];
