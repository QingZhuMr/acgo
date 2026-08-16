-- =====================================================
-- ACGO 用户设备会话表（多端登录 / 设备密钥）
-- =====================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `user_device`;
CREATE TABLE `user_device` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID，对应user.id',
  `platform`      VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '平台：win/mac/ios/android/h5',
  `device_id`     VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '设备唯一标识（客户端生成的UUID）',
  `device_key`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '设备密钥（每设备唯一，请求校验用）',
  `login_token`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '本次登录 token',
  `last_login_time` INT        NOT NULL DEFAULT 0 COMMENT '最后登录时间',
  `last_login_ip` VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `status`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '状态：0下线 1在线',
  `online_status` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '在线状态：0离线 1在线(长连接)',
  `last_online_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后在线时间戳',
  `create_time`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_platform_device` (`user_id`, `platform`, `device_id`),
  KEY `idx_device_key` (`device_key`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户设备会话表';
