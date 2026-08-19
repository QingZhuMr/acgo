-- =====================================================
-- ACGO 迁移脚本：星球寻人「永久免费次数」（后台按账号+星球赠送）
-- 背景：原方案为「每用户每星球每日免费次数」（user_find.free_used_count +
--       free_stat_date，跨天重置）。现改为「账号永久免费次数」——
--       由后台运营针对 某个账号 + 某个星球 赠送若干次，用尽前发起寻找不扣熊爪，
--       用尽后回落到星球 match_cost 扣熊爪。
-- 新增表 user_find_free 记录每个 (user_id, planet_id) 的累计赠送与已用次数。
-- 旧的 user_find.free_used_count / free_stat_date 列保留（已是历史数据，不再使用）。
-- =====================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_find_free` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '账号 ID',
  `planet_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '星球 ID',
  `free_total`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计赠送的免费次数',
  `free_used`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已使用的免费次数',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_planet` (`user_id`, `planet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星球寻人：账号永久免费次数（后台赠送，按账号+星球）';
