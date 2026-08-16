<?php
declare (strict_types = 1);

namespace app\common;

/**
 * 公共密码工具类
 * 前台、后台共用，统一使用 password_hash 单向加密
 * 说明：单向 hash 不可解密，忘记密码时由管理员重置新密码
 */
class Password
{
    /**
     * 加密密码
     * @param  string $password 明文密码
     * @return string hash 值
     */
    public static function encrypt(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * 校验密码
     * @param  string $password 明文密码
     * @param  string $hash     已存 hash
     * @return bool
     */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * 生成随机登录 token（用于异地登录检测）
     * @return string
     */
    public static function makeToken(): string
    {
        return md5(uniqid((string) mt_rand(), true) . microtime(true));
    }
}
