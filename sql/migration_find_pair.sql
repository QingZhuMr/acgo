-- =====================================================
-- ACGO 迁移脚本：「开始寻找」星球配对功能
-- 背景：在相同星球上寻找也在发起寻找的人；匹配成功后成为绑定人
-- 一、planet 表新增寻人配置：
--     enable_find 是否开启寻找 / find_fields 需填写的参数（JSON 数组）
--     force_face_auth/force_edu_auth/force_asset_auth 是否强制认证后才能配对
-- 二、新增 4 张表：
--     user_find_profile 我的资料（跨星球复用，首填后自动带出）
--     user_find         寻找状态（谁在哪个星球寻找、目标参数）
--     user_bind         绑定关系（配对成功即绑定，解绑前不得解除好友/再寻找）
--     user_auth         认证状态（颜值/学历/资产，当前仅预留，后续单独开发）
-- 对应代码：app/api/controller/Find.php、app/admin/controller/Planet.php、
--           客户端 lib/pages/planet_page.dart
-- =====================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------
-- 1. planet 表新增寻人配置字段
-- -----------------------------------------------------
ALTER TABLE `planet`
  ADD COLUMN `enable_find`      TINYINT(1)    NOT NULL DEFAULT 0    COMMENT '是否开启寻找配对：0关闭 1开启' AFTER `status`,
  ADD COLUMN `find_fields`      VARCHAR(500)  NOT NULL DEFAULT '["gender","age"]' COMMENT '寻人需填写的参数（JSON 数组，如 gender/age/income_range/personality/city/face_score/edu_level）' AFTER `enable_find`,
  ADD COLUMN `force_face_auth`  TINYINT(1)    NOT NULL DEFAULT 0    COMMENT '是否强制颜值认证后才能配对：0否 1是' AFTER `find_fields`,
  ADD COLUMN `force_edu_auth`   TINYINT(1)    NOT NULL DEFAULT 0    COMMENT '是否强制学历认证后才能配对：0否 1是' AFTER `force_face_auth`,
  ADD COLUMN `force_asset_auth` TINYINT(1)    NOT NULL DEFAULT 0    COMMENT '是否强制资产认证后才能配对：0否 1是' AFTER `force_edu_auth`;

-- -----------------------------------------------------
-- 2. user_find_profile 我的资料表（跨星球复用）
--    我的资料：首填后自动存为「我的资料」，其他星球自动带出可修改
-- -----------------------------------------------------
DROP TABLE IF EXISTS `user_find_profile`;
CREATE TABLE `user_find_profile` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`      INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '用户ID',
  `gender`       TINYINT      NOT NULL DEFAULT 0     COMMENT '我的性别：0未填 1男 2女',
  `age`          TINYINT      NOT NULL DEFAULT 0     COMMENT '我的年龄',
  `income_range` VARCHAR(30)  NOT NULL DEFAULT ''    COMMENT '我的月收入范围（如 1万以内/1-2万/2-5万/5万以上）',
  `personality`  VARCHAR(50)  NOT NULL DEFAULT ''    COMMENT '我的性格标签（逗号分隔，如 开朗,幽默）',
  `city`         VARCHAR(50)  NOT NULL DEFAULT ''    COMMENT '我的城市（用于同城匹配）',
  `face_score`   TINYINT      NOT NULL DEFAULT 0     COMMENT '我的颜值打分 1-10',
  `edu_level`    VARCHAR(30)  NOT NULL DEFAULT ''    COMMENT '我的学历（如 大专/本科/硕士/博士）',
  `create_time`  INT          NOT NULL DEFAULT 0     COMMENT '创建时间',
  `update_time`  INT          NOT NULL DEFAULT 0     COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星球寻找-我的资料表';

-- -----------------------------------------------------
-- 3. user_find 寻找状态表
--    一个用户同时只能在一个星球寻找（发起新寻找自动停止旧寻找）
--    target JSON：目标条件，结构如
--    {"gender":1,"age_min":20,"age_max":30,"income_range":"","personality":"","same_city":0,"face_score_min":0,"face_score_max":0,"edu_level":""}
-- -----------------------------------------------------
DROP TABLE IF EXISTS `user_find`;
CREATE TABLE `user_find` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '发起寻找的用户ID',
  `planet_id`   INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '所在星球ID',
  `target`      VARCHAR(500) NOT NULL DEFAULT ''    COMMENT '目标条件（JSON）',
  `status`      TINYINT      NOT NULL DEFAULT 0     COMMENT '状态：0寻找中 1已配对 2已停止',
  `matched_uid` INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '配对对象ID（status=1 时有值）',
  `create_time` INT          NOT NULL DEFAULT 0     COMMENT '发起时间',
  `match_time`  INT          NOT NULL DEFAULT 0     COMMENT '配对时间',
  `stop_time`   INT          NOT NULL DEFAULT 0     COMMENT '停止时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_planet_status` (`planet_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星球寻找-寻找状态表';

-- -----------------------------------------------------
-- 4. user_bind 绑定关系表
--    配对成功即成为绑定人：好友状态且强制互相关注；
--    未手动解除绑定时不能解除好友关系、不能在任何星球发起寻找
-- -----------------------------------------------------
DROP TABLE IF EXISTS `user_bind`;
CREATE TABLE `user_bind` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_a`      INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '绑定方A（较小ID）',
  `user_b`      INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '绑定方B（较大ID）',
  `planet_id`   INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '在哪颗星球配对',
  `status`      TINYINT      NOT NULL DEFAULT 1     COMMENT '状态：1绑定中 0已解除',
  `create_time` INT          NOT NULL DEFAULT 0     COMMENT '配对时间',
  `unbind_time` INT          NOT NULL DEFAULT 0     COMMENT '解除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair` (`user_a`, `user_b`),
  KEY `idx_user_a` (`user_a`, `status`),
  KEY `idx_user_b` (`user_b`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星球寻找-绑定关系表';

-- -----------------------------------------------------
-- 5. user_auth 认证状态表（预留）
--    后台可勾选「星球是否强制认证后才能配对」，发起/匹配时校验，
--    未认证提示需认证；认证功能后续单独开发
-- -----------------------------------------------------
DROP TABLE IF EXISTS `user_auth`;
CREATE TABLE `user_auth` (
  `user_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
  `face_status`  TINYINT      NOT NULL DEFAULT 0 COMMENT '颜值认证：0未认证 1已认证',
  `edu_status`   TINYINT      NOT NULL DEFAULT 0 COMMENT '学历认证：0未认证 1已认证',
  `asset_status` TINYINT      NOT NULL DEFAULT 0 COMMENT '资产认证：0未认证 1已认证',
  `update_time`  INT          NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星球寻找-认证状态表（预留）';
