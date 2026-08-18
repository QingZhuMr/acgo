-- 好友备注：user_follow 加 remark 列（follower 对 following 的备注，仅自己可见）
ALTER TABLE user_follow ADD COLUMN remark VARCHAR(50) NOT NULL DEFAULT '' COMMENT '好友备注' AFTER following_id;

-- 帖子图片宽高：post 加 image_sizes 列（与 images 等长的 [[w,h],...]，用于客户端按图高动态布局）
ALTER TABLE post ADD COLUMN image_sizes TEXT NULL COMMENT '图片宽高JSON数组' AFTER images;
