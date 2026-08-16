-- 验证码记录表（注册用图形验证码）
CREATE TABLE IF NOT EXISTS `verify_code` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `captcha_id`  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '验证码ID(客户端生成UUID)',
  `code`        VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '验证码内容(加密存储或明文+过期)',
  `type`        VARCHAR(20)  NOT NULL DEFAULT 'register' COMMENT '用途：register等',
  `used`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0未用 1已用',
  `expire_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '过期时间戳',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_captcha_id` (`captcha_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图形验证码记录表';
