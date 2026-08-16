-- =====================================================
-- ACGO 用户系统 - 建表脚本
-- 数据库：acgo
-- =====================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------
-- 1. user 前台用户表
-- -----------------------------------------------------
DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `nickname`       VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '昵称',
  `password`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '密码hash',
  `password_changed` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '密码修改时间戳(检测后台改密)',
  `phone`          VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '手机号(登录标识，唯一)',
  `avatar`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像',
  `status`         TINYINT      NOT NULL DEFAULT 1  COMMENT '状态：0注销 1正常 2封禁',
  `ban_time`       INT          NOT NULL DEFAULT 0  COMMENT '封禁时间',
  `ban_reason`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '封禁原因',
  `login_token`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '当前登录token',
  `last_login_time` INT         NOT NULL DEFAULT 0  COMMENT '最后登录时间',
  `last_login_ip`  VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `register_time`  INT          NOT NULL DEFAULT 0  COMMENT '注册时间',
  `update_time`    INT          NOT NULL DEFAULT 0  COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_phone` (`phone`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台用户表';

-- -----------------------------------------------------
-- 2. user_log 用户日志表（注册/登录/封禁/解封/注销）
-- -----------------------------------------------------
DROP TABLE IF EXISTS `user_log`;
CREATE TABLE `user_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`    INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '用户ID',
  `phone`      VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '用户手机号',
  `username`   VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '冗余(历史遗留，已弃用)',
  `action`     VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '动作：register/login/ban/unban/logout/delete',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '操作IP',
  `operator`   VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '操作者（后台操作时记录管理员账号）',
  `remark`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT         NOT NULL DEFAULT 0  COMMENT '操作时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户日志表';

-- -----------------------------------------------------
-- 3. wallet 用户钱包表（存用户资产数据，uid 对应 user.id）
--    双轨制货币：人民币(balance_cny) + 熊爪(balance_paw)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `wallet`;
CREATE TABLE `wallet` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `uid`         INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '用户ID，对应user.id',
  `balance_cny` DECIMAL(18,2)   NOT NULL DEFAULT 0    COMMENT '人民币余额',
  `balance_paw` DECIMAL(18,2)   NOT NULL DEFAULT 0    COMMENT '熊爪余额（整数积分）',
  `status`      TINYINT         NOT NULL DEFAULT 1    COMMENT '状态：0注销 1正常',
  `create_time` INT             NOT NULL DEFAULT 0    COMMENT '创建时间',
  `update_time` INT             NOT NULL DEFAULT 0    COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户钱包表';

-- -----------------------------------------------------
-- 4. config 系统配置表（应用名称等）
-- -----------------------------------------------------
DROP TABLE IF EXISTS `config`;
CREATE TABLE `config` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '配置项名',
  `value`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '配置项值',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT          NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` INT          NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';

-- 初始配置：应用名称
INSERT INTO `config` (`name`, `value`, `remark`, `create_time`, `update_time`) VALUES
('app_name', 'ACGO 资产管理系统', '系统应用名称', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
