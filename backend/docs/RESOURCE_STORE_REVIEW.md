# 资源与应用商店审核闭环

资源大厅（`resources`）和应用商店（`store_apps`）使用同一套真实审核规则：

- `pending`：待审核；
- `approved`：通过并允许公开；
- `rejected`：不通过，必须记录原因；
- `on_hold`：暂定，必须记录原因和后续要求。

审核接口在事务内执行 `SELECT ... FOR UPDATE`。客户端必须同时提交
`expected_audit_status` 和详情返回的 `expected_review_revision`。后者覆盖标题、说明、分类、价格、
风险字段、受控源文件以及稳定的附件指纹，不包含短时签名 URL 或红包等实时展示状态；任一待审内容
被改动都会返回 `409`，避免用旧页面审核未看过的新内容。
重复提交完全相同的状态和原因不会再次写日志或通知作者。有效变更会记录审核人、审核时间、
管理员操作日志，并向用户投稿作者发送持久通知。

## 管理端

- `GET /api/admin/apps/{app_id}/resources`：资源审核列表，支持 `keyword`、`audit_status`、
  `resource_type`、`risk_level`、分类和状态筛选，返回各审核状态汇总。
- `GET /api/admin/apps/{app_id}/resources/{resource_id}`：资源审核详情。
- `PUT /api/admin/apps/{app_id}/resources/{resource_id}/audit`：通过、不通过或暂定。
- `GET /api/admin/apps/{app_id}/store-apps`：应用审核列表，支持搜索、状态、风险、分类和投稿人筛选。
- `GET /api/admin/apps/{app_id}/store-apps/{store_app_id}`：应用安装包、风险、作者和审核详情。
- `PUT /api/admin/apps/{app_id}/store-apps/{store_app_id}/audit`：通过、不通过或暂定。
- 应用和商店分类同时提供真实编辑、软删除或安全删除接口；二进制文件被替换后强制回到
  `pending`，审核人和审核时间清空，不能沿用旧安装包的通过结果。普通字段编辑不会因为表单
  回填了同一个文件地址而误判为换包；编辑与审核也使用行锁和审核快照冲突检查，冲突返回 `409`。

Android 管理端提供状态筛选、搜索、下拉刷新、详情以及独立的“通过 / 不通过 / 暂定”按钮。
不通过和暂定必须填写原因。源码分类固定使用源码商城范围，内部枚举不会作为表单选项暴露给管理员。

## 用户端与公开端安全边界

公开端和普通目录只能查询 `approved + status=1` 的记录。作者可在“我的投稿”中查看自己的
待审核、暂定或不通过记录及原因，但这些记录不提供下载地址，也不能购买、收藏、点赞、评分、
评论或回复。聚合收藏列表同样再次校验审核状态，防止已经收藏的内容在暂定或不通过后继续出现。

用户端同时提供“已购内容”。条目下架后不再允许新购买或互动，但历史买家仍可通过鉴权下载；
购买请求必须回传看到的价格、`source_upload_id`，应用安装包还要回传 `version_code`，管理员在确认
期间换包、升价或改版本时返回 `409`，不会无提示多扣款。源码和安装包均保存在私有目录，下载
响应强制附件、`nosniff`、强 ETag 与 `If-Range` 校验，不能使用旧前缀拼接新文件。

## 管理员开关

- `resource_user_submit_enabled`：是否允许用户投稿资源；
- `resource_submit_audit`：资源投稿是否必须审核；
- `store_user_submit_enabled`：是否允许用户投稿应用；
- `store_submit_audit`：应用投稿是否必须审核。

新安装和升级后的已有应用都会获得四项默认配置，已有人工配置不会被迁移覆盖。

## 升级时的私有文件硬门禁

升级迁移：`database/migrations/upgrade_20260811_resource_store_review_closure.sql`。这项升级不是只执行
SQL 就结束。旧版曾把源码和安装包放在 `public/uploads`，必须在维护停写窗口完成以下顺序：

1. 停止资源与应用商店写入，等待在途请求结束；完整备份数据库和 `public/uploads`，保留恢复点。
2. 执行第 63 项 SQL。它会把内部 `catalog_private_migration_ready` 设为关闭，所有目录接口暂时返回 503。
   `backend/config/release-identity.json` 必须已经由 `android/tools/version.ps1` 与 Android 版本、下载站版本
   同步；迁移报告会绑定该文件的 `version_name`、`version_code` 和 SHA-256，命令行不能自行冒充其他版本。
3. 只读预检：
   `php tools/migrate-catalog-private-files.php --release-version <本次版本> `
   `（不带 --apply）`。退出码 2 表示发现待迁内容，并不代表可以跳过。
4. 核对容量、冲突和备份后执行：
   `php tools/migrate-catalog-private-files.php --release-version <本次版本> --apply --maintenance-confirmed`。
   工具按预检计划迁入私有目录、校验哈希、清理旧公开字节并原子写出 PASS JSON；公开树扫描按固定
   批次使用专用路径/哈希索引，避免大库全量常驻内存。此时运行门禁仍关闭。
5. 使用上一步输出的精确报告再次独立回读并激活：
   `php tools/verify-catalog-migration-report.php --report <报告绝对路径> `
   `--release-version <本次版本> --activate --maintenance-confirmed`。
   只有新鲜报告、迁移版本、购买保留项、私有文件完整哈希、元数据、清理日志和整个 `public` 树
   全部通过时，才先原子发布激活凭据、再在事务内开启全部应用。
6. 同步部署 `deploy/nginx-site.conf.example`（或 Apache 等价规则），盘点并隔离历史 SVG；从公网回读旧
   `/uploads/*.svg` URL 必须为 404/403，且普通上传响应含 `X-Content-Type-Options: nosniff`。
7. 保留 JSON、JSONL、计划和激活凭据作为非秘密部署证据，再恢复流量并验证购买、断点下载和审核。

任何一步失败都保持门禁关闭；不得手工修改内部设置，也不得删除历史购买记录来“通过”检查。
