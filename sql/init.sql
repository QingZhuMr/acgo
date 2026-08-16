-- =====================================================
-- ACGO 后台管理 - 数据库初始化脚本
-- 数据库：acgo
-- =====================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------
-- 1. admin 管理员表
-- -----------------------------------------------------
DROP TABLE IF EXISTS `admin`;
CREATE TABLE `admin` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `username`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '登录账号',
  `nickname`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '昵称',
  `password`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '密码hash',
  `level`           TINYINT      NOT NULL DEFAULT 1  COMMENT '权限等级：1普通 2管理 9超级',
  `status`          TINYINT      NOT NULL DEFAULT 1  COMMENT '状态：0禁用 1正常',
  `login_token`     VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '当前登录token(用于异地登录检测)',
  `last_login_time` INT          NOT NULL DEFAULT 0  COMMENT '最后登录时间',
  `last_login_ip`   VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `create_time`     INT          NOT NULL DEFAULT 0  COMMENT '创建时间',
  `update_time`     INT          NOT NULL DEFAULT 0  COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台管理员表';

-- -----------------------------------------------------
-- 2. admin_log 管理员操作日志表
-- -----------------------------------------------------
DROP TABLE IF EXISTS `admin_log`;
CREATE TABLE `admin_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id`   INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '管理员ID',
  `username`   VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '管理员账号',
  `action`     VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '动作：login登入 logout登出 kick下线',
  `login_ip`   VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '登录IP',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '浏览器标识',
  `create_time` INT         NOT NULL DEFAULT 0  COMMENT '操作时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员登录日志表';

-- -----------------------------------------------------
-- 3. 初始管理员账号（密码需通过程序生成hash后替换）
--    默认账号：admin  默认密码：admin123
--    说明：此处先插入占位hash，首次可通过后台工具重置
-- -----------------------------------------------------
INSERT INTO `admin` (`username`, `nickname`, `password`, `level`, `status`, `create_time`)
VALUES ('admin', '超级管理员', '$2y$12$SON7/9RRENGQsH5ymh7S3Ox656MciyJ90qGkts9Ce6NofQ96VpRT.', 9, 1, UNIX_TIMESTAMP());
