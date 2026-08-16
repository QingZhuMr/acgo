-- =====================================================
-- ACGO 后台菜单表
-- =====================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `menu`;
CREATE TABLE `menu` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `parent_id`   INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '父级菜单ID，0为顶级',
  `title`       VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '菜单名称',
  `icon`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '图标类名（Font Awesome 5 或 Layui 图标）',
  `url`         VARCHAR(200) NOT NULL DEFAULT '' COMMENT '菜单链接地址',
  `level`       TINYINT      NOT NULL DEFAULT 1  COMMENT '所需权限等级：1普通 2管理 9超级，>=该等级可见',
  `sort`        INT          NOT NULL DEFAULT 0  COMMENT '排序权重，越小越靠前',
  `status`      TINYINT      NOT NULL DEFAULT 1  COMMENT '状态：0禁用 1启用',
  `create_time` INT          NOT NULL DEFAULT 0  COMMENT '创建时间',
  `update_time` INT          NOT NULL DEFAULT 0  COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台导航菜单表';

-- -----------------------------------------------------
-- 初始菜单数据（id 显式指定，保证父子关联）
-- -----------------------------------------------------
INSERT INTO `menu` (`id`, `parent_id`, `title`, `icon`, `url`, `level`, `sort`, `status`, `create_time`) VALUES
(1, 0, '控制台', 'fas fa-tachometer-alt', '/index/welcome', 1, 0, 1, UNIX_TIMESTAMP()),
(2, 0, '系统管理', 'fas fa-cog', '', 9, 10, 1, UNIX_TIMESTAMP()),
(3, 0, '用户管理', 'fas fa-users', '/user/index', 1, 5, 1, UNIX_TIMESTAMP()),
(4, 2, '菜单管理', 'fas fa-list-alt', '/menu/index', 9, 0, 1, UNIX_TIMESTAMP()),
(5, 2, '管理员管理', 'fas fa-user-shield', '/admin/index', 9, 1, 1, UNIX_TIMESTAMP()),
(6, 2, '系统设置', 'fas fa-cog', '/system/index', 9, 2, 1, UNIX_TIMESTAMP()),
(7, 2, '日志管理', 'fas fa-history', '', 9, 3, 1, UNIX_TIMESTAMP()),
(8, 7, '管理员日志', 'fas fa-user-shield', '/log/adminLog', 9, 0, 1, UNIX_TIMESTAMP()),
(9, 7, '用户日志', 'fas fa-users', '/log/userLog', 9, 1, 1, UNIX_TIMESTAMP()),
(10, 0, '运营管理', 'fas fa-store', '', 1, 4, 1, UNIX_TIMESTAMP()),
(11, 10, '充值列表', 'fas fa-coins', '/recharge/index', 1, 0, 1, UNIX_TIMESTAMP());
