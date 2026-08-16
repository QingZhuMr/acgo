-- =====================================================
-- ACGO 客户端更新推送 - 建表脚本
-- 数据库：acgo
-- =====================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------
-- update_version 客户端版本推送表
-- 用于 admin 后台向多端客户端推送新版本（win/mac/ios/android）
-- -----------------------------------------------------
DROP TABLE IF EXISTS `update_version`;
CREATE TABLE `update_version` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `platform`       VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '平台：win/mac/ios/android',
  `version`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '版本号，如 0.2.0',
  `title`          VARCHAR(100) NOT NULL DEFAULT '' COMMENT '通知标题',
  `message`        TEXT         COMMENT '更新内容描述',
  `download_url`   VARCHAR(500) NOT NULL DEFAULT '' COMMENT '下载/跳转地址（win/android 为安装包URL，ios/mac 为 App Store 地址）',
  `mandatory`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否强制更新：0否 1是',
  `status`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '状态：0下线 1上线',
  `create_time`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_platform_status` (`platform`, `status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端更新推送表';
