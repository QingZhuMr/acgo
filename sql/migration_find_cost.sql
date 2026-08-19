-- =====================================================
-- ACGO 迁移脚本：星球寻找 付费 + 免费次数
-- 背景：在「开始寻找」基础上引入收费机制——
--       发起寻找需消耗熊爪(paw)；每颗星球可配置单次匹配费用
--       与每用户每日免费匹配次数，超过免费次数后按费用扣熊爪。
-- 一、planet 表新增付费配置：
--     match_cost        单次匹配费用（熊爪，0=免费）
--     free_match_count  每用户每日免费匹配次数（0=无免费）
-- 二、config 表新增运营配置项（星球未配置时使用全局默认）：
--     find_default_match_cost       全局默认单次匹配费用
--     find_default_free_match_count 全局默认每日免费次数
--     find_cost_notice              费用规则说明文案（客户端展示）
-- 三、user_find 表新增免费次数记录字段：
--     free_used_count  当日已用免费匹配次数
--     free_stat_date   免费次数统计日期（YYYY-MM-DD，跨天自动重置）
-- 对应代码：app/api/controller/Find.php（扣费/免费逻辑）、
--           app/admin/controller/Planet.php、客户端 lib/pages/planet_page.dart
-- =====================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------
-- 1. planet 表新增付费配置字段
-- -----------------------------------------------------
ALTER TABLE `planet`
  ADD COLUMN `match_cost`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '单次匹配费用（熊爪，0=免费）' AFTER `force_asset_auth`,
  ADD COLUMN `free_match_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每用户每日免费匹配次数（0=无免费，超出按 match_cost 扣费）' AFTER `match_cost`;

-- -----------------------------------------------------
-- 2. config 表新增运营配置项（全局默认，planet 未配置时生效）
-- -----------------------------------------------------
INSERT INTO `config` (`name`, `value`, `remark`, `create_time`, `update_time`) VALUES
('find_default_match_cost',       '10', '星球寻人默认单次匹配费用（熊爪，星球未配置时生效）', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('find_default_free_match_count', '1',  '星球寻人默认每用户每日免费匹配次数（星球未配置时生效）', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('find_cost_notice',              '每日首次匹配免费，超出后每次消耗 10 熊爪', '星球寻人费用规则说明文案（客户端展示）', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `remark` = VALUES(`remark`), `update_time` = UNIX_TIMESTAMP();

-- -----------------------------------------------------
-- 3. user_find 表新增免费次数记录字段
-- -----------------------------------------------------
ALTER TABLE `user_find`
  ADD COLUMN `free_used_count` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '当日已用免费匹配次数' AFTER `stop_time`,
  ADD COLUMN `free_stat_date`  VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '免费次数统计日期（YYYY-MM-DD，跨天自动重置）' AFTER `free_used_count`;
