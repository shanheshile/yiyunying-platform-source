import {
  AlertTriangle,
  ArrowLeft,
  Check,
  Code2,
  Gauge,
  LockKeyhole,
  ShieldCheck,
  UploadCloud,
} from "lucide-react";
import {
  CodeExample,
  CopyTextButton,
  DocsPageActions,
  EndpointSearch,
  SectionActions,
} from "./docs-actions";
import {
  OFFICIAL_API_BASE_URL,
  SELF_HOSTED_API_BASE_URL_EXAMPLE,
  SELF_HOSTED_HTTP_API_BASE_URL_EXAMPLE,
} from "../public-api.mjs";

const QUICKSTART_HEADERS = `Base URL: ${OFFICIAL_API_BASE_URL}
X-App-Key: APP_API_UNIQUE_ID
Authorization: Bearer ACCESS_TOKEN_PLACEHOLDER
Content-Type: application/json`;

const UPLOAD_CURL_EXAMPLE = `curl --request POST '${OFFICIAL_API_BASE_URL}api/user/uploads' \\
  --header 'Accept: application/json' \\
  --header 'X-App-Key: APP_API_UNIQUE_ID' \\
  --header 'Authorization: Bearer ACCESS_TOKEN_PLACEHOLDER' \\
  --form 'scene=general' \\
  --form 'file=@./FILE_TO_UPLOAD'`;

const RESPONSE_ENVELOPE = `HTTP 200 / 201
{
  "code": 1,
  "msg": "操作成功",
  "data": {},
  "trace_id": "TRACE_ID"
}`;

type Endpoint = {
  method: "GET" | "POST" | "PUT" | "DELETE";
  path: string;
  purpose: string;
  fields: string;
  result: string;
  failure: string;
  usage: string;
};

type SystemGuide = {
  id: string;
  title: string;
  summary: string;
  features: string[];
  links: string;
  prerequisites: string;
  flow: string[];
  endpoints: Endpoint[];
};

const ROLE_GUIDES = [
  {
    id: "role-user",
    title: "用户端",
    scope: "应用内普通用户；只有用户端会签发并轮换 Refresh Token。",
    fields: "登录 JSON body 必填 app_key、account、password；登录请求可额外携带 X-App-Key，但必须与 body 的 app_key 相同，且不能代替必填 body 字段。登录后的受保护用户接口继续携带 X-App-Key。app_key 就是应用 API 唯一标识。",
    success: "返回 Bearer access_token、refresh_token、expires_at、refresh_expires_at 与应用上下文。",
    flow: "登录 → GET /api/user/me 回读账号状态 → 调用业务接口 → Access 过期时只刷新一次 → 退出并清理凭证。",
    example: `curl --request POST '${OFFICIAL_API_BASE_URL}api/user/login' \\
  --header 'Content-Type: application/json' \\
  --header 'X-App-Key: APP_API_UNIQUE_ID' \\
  --data '{"app_key":"APP_API_UNIQUE_ID","account":"demo_user","password":"YOUR_PASSWORD","device":"Android"}'`,
  },
  {
    id: "role-admin",
    title: "管理员端",
    scope: "管理自己名下的一个或多个应用、用户、内容和运营数据。",
    fields: "platform_key、app_key、account、password；app_key 是所选应用的 API 唯一标识。",
    success: "返回有时效性的 access_token 与管理员、当前应用、权限信息；管理员端没有 Refresh Token。",
    flow: "登录 → GET /api/admin/me → GET /api/admin/apps → 选择 app_id → 调用 /api/admin/apps/{app_id}/... → 401 后重新登录。",
    example: `curl --request POST '${OFFICIAL_API_BASE_URL}api/admin/login' \\
  --header 'Content-Type: application/json' \\
  --data '{"platform_key":"PLATFORM_KEY_PLACEHOLDER","app_key":"APP_API_UNIQUE_ID","account":"demo_admin","password":"YOUR_PASSWORD"}'`,
  },
  {
    id: "role-agent",
    title: "授权代理端（Level 2）",
    scope: "使用平台接口管理授权范围内的下级管理员和应用；权限由服务端 Level 2 身份裁剪。",
    fields: "platform_key、account、password；不提交 app_key 登录。",
    success: "返回有时效性的 access_token、账号层级与有效权限；代理端没有 Refresh Token。",
    flow: "登录 → GET /api/platform/me 与 /permissions → 只访问授权范围 → 关键写入后回读 → 401 后重新登录。",
    example: `curl --request POST '${OFFICIAL_API_BASE_URL}api/platform/login' \\
  --header 'Content-Type: application/json' \\
  --data '{"platform_key":"PLATFORM_KEY_PLACEHOLDER","account":"demo_agent","password":"YOUR_PASSWORD"}'`,
  },
  {
    id: "role-owner",
    title: "平台总控客户端（官方托管，Level 1）",
    scope: "官方托管平台的最高管理客户端；固定连接官方 API，与代理共用 /api/platform 登录，但只有 Level 1 能进入 operators 等总控范围。它不等同于源码买断方的自建部署。",
    fields: "platform_key、account、password；真实平台 KEY 不出现在网页、日志或示例中。",
    success: "返回有时效性的 access_token、Level 1 身份和总控权限；官方平台总控客户端没有 Refresh Token。",
    flow: "登录 → 回读 /me 和 /permissions → GET /api/platform/operators → 管理代理 → 回读目标与操作日志。",
    example: `curl --request POST '${OFFICIAL_API_BASE_URL}api/platform/login' \\
  --header 'Content-Type: application/json' \\
  --data '{"platform_key":"OWNER_PLATFORM_KEY_PLACEHOLDER","account":"demo_owner","password":"YOUR_PASSWORD"}'`,
  },
] as const;

const ep = (
  method: Endpoint["method"], path: string, purpose: string, fields: string,
  result: string, failure: string, usage: string,
): Endpoint => ({ method, path, purpose, fields, result, failure, usage });

const SYSTEMS: SystemGuide[] = [
  {
    id: "user-system", title: "用户系统", summary: "账号注册、登录、资料、会员权益与账号状态的基础边界。",
    features: ["注册登录", "资料修改", "会员与权限", "停用/注销与状态回读"],
    links: "为论坛、好友、聊天、文档、商城、卡密提供统一 user_id、app_id 和权限上下文。",
    prerequisites: "开发者已把 HTTPS Base URL 与 app_key 写入源码；应用、管理员和用户状态均为可用。",
    flow: ["注册或登录", "用 Bearer 调用 /me 回读状态", "进入业务模块", "退出或在状态失效时清理本地会话"],
    endpoints: [
      ep("POST", "/api/user/register", "创建应用内账号", "app_key, account, password, password_confirmation", "201，通常返回 user、access_token 与 refresh_token；VIP-only 未激活时 Token 为 null 并返回 requires_vip_activation", "403/409/422：功能关闭、重复账号或参数/规则不通过", "若返回 access_token，直接 GET /me 回读；若 requires_vip_activation，则先激活再登录"),
      ep("POST", "/api/user/login", "校验应用、账号和密码并签发时效会话", "app_key, account, password；可选 device", "access_token, refresh_token 与各自过期时间", "401/403：凭据错误、账号/应用/上级状态不可用", "保存凭证后立即 GET /api/user/me 回读"),
      ep("GET", "/api/user/me", "读取当前用户、应用归属与状态", "Authorization: Bearer ACCESS_TOKEN", "当前用户、资料与租户上下文", "401/403：Token 无效或账号状态变化", "启动、恢复前台和关键写入后调用"),
      ep("PUT", "/api/user/profile", "修改昵称、头像、邮箱等资料", "nickname/avatar；绑定邮箱时 email + email_code", "更新后的 user 资料", "403/409/422：功能关闭、已绑定冲突或字段错误", "邮箱变更先走验证码，再提交并回读 /me"),
    ],
  },
  {
    id: "email-system", title: "邮箱系统", summary: "只提供邮箱验证码、资料绑定和密码找回，不提供收件箱、群发或通用邮件模板。",
    features: ["发送邮箱验证码", "注册邮箱校验", "资料邮箱绑定", "密码找回"],
    links: "与用户注册、资料系统、身份解绑和安全系统联动。",
    prerequisites: "应用已配置可用邮箱验证渠道；请求携带 app_key/X-App-Key，scene 与后续动作一致。",
    flow: ["请求验证码", "用户输入短时验证码", "提交注册/绑定/找回", "成功后验证码作废并回读账号资料"],
    endpoints: [
      ep("POST", "/api/public/verification-code/email", "向指定邮箱发送场景验证码", "app_key 或 X-App-Key, email, scene(register/profile_email 等)", "201，data.verification_id、scene、target_masked、expired_at", "403/422/429/503：功能关闭、字段无效、频率过高或邮件服务不可用", "同一倒计时内不要重复发送"),
      ep("PUT", "/api/user/profile", "绑定尚未占用的邮箱", "email, email_code；Bearer", "返回更新后的 user", "409：已有邮箱不可直接替换；422：验证码错误", "已绑定邮箱需先走解绑审核，不覆盖写入"),
      ep("POST", "/api/user/password/reset/code", "为密码找回签发验证码", "app_key, account, email_or_phone", "返回验证码已发送状态", "404/429：账号联系渠道不匹配或过于频繁", "只向账号已验证恢复渠道发送"),
      ep("POST", "/api/user/password/reset", "验证验证码并设置新密码", "app_key, account, email_or_phone, code, new_password", "密码更新成功并撤销旧会话", "403/404/422：功能关闭、用户不存在、验证码失效或新密码不合规", "成功后清理旧 Token 并重新登录"),
    ],
  },
  {
    id: "forum-system", title: "论坛系统", summary: "版块、发帖、评论、点赞收藏与举报审核的内容闭环。",
    features: ["版块分类", "帖子发布", "评论点赞收藏", "置顶/审核/举报"],
    links: "与用户身份、上传、通知、统计和审核举报能力联动。",
    prerequisites: "论坛功能已开启；用户具备发帖权限；媒体先通过上传安全检查。",
    flow: ["GET 版块", "POST 发帖", "GET 详情并评论/点赞", "用户举报或管理员审核", "回读最终 audit_status"],
    endpoints: [
      ep("GET", "/api/user/forum-posts", "分页读取可见帖子", "page, limit；可选 plate_id/category_id/tag/keyword/sort", "帖子 items 与分页信息", "403：论坛关闭；422：筛选参数错误", "以返回的可见范围为准，不自行拼接跨应用 ID"),
      ep("POST", "/api/user/forum-posts", "发布帖子", "plate_id, title；可选 content/images/tags/category_id", "201，post_id 与审核状态", "403/422：无权限、内容或媒体不合规", "创建后 GET /{post_id} 确认正文和审核状态"),
      ep("POST", "/api/user/forum-posts/{post_id}/comments", "发表评论或回复", "content；可选 parent_id/mentions/tags", "201，comment_id 与审核状态", "403/404/422：不可见、帖子锁定或内容无效", "成功后刷新评论列表而非仅追加本地数据"),
      ep("POST", "/api/user/reports", "举报帖子、评论等目标", "target_type, target_id；可选 reason/report_tag_id", "201，仅返回 data.report_id", "404/409/422：目标无效或重复举报", "提交后通过我的举报或管理员审核回读"),
      ep("PUT", "/api/admin/apps/{app_id}/forum-posts/{post_id}/audit", "管理员审核帖子", "audit_status, reason；Bearer", "更新后的审核结果", "403/404/409：无权限、跨应用或状态冲突", "审核后回读帖子和统计，保留操作日志"),
    ],
  },
  {
    id: "document-system", title: "文档系统", summary: "个人文档、版本、收藏与受控分享。",
    features: ["创建编辑", "标签搜索", "版本留痕", "受控分享"],
    links: "与用户、云同步、上传和公开分享读取联动。",
    prerequisites: "文档能力已启用；Bearer 有效；分享时设置可接受的过期时间与可选密码。",
    flow: ["POST 创建", "GET/PUT 编辑回读", "POST share 生成分享", "公开端按 share_code 读取", "删除后按策略恢复或清理"],
    endpoints: [
      ep("GET", "/api/user/notes", "分页和搜索自己的文档", "page, limit；可选 keyword/folder_id/date_from/date_to", "文档摘要 items 与分页", "401/403：会话或功能不可用", "列表只取摘要，详情再按 document_id 获取"),
      ep("POST", "/api/user/notes", "创建文档并写入首个版本", "title；可选 content/content_type/tags/is_public", "201，data.document（含 id 与 version_no）", "422：内容过大或 content_type 不支持", "创建后 GET 详情核对版本号"),
      ep("PUT", "/api/user/notes/{document_id}", "更新内容并生成版本", "title/content/content_type/tags/is_public 中至少一项", "更新后的版本信息", "404/422：文档不存在或字段错误", "以服务端返回版本为新基线"),
      ep("POST", "/api/user/notes/{document_id}/share", "创建或更新受控分享", "可选 password, expired_at", "data.share（含 share_code、expired_at、password_required 等）", "403/404/422：无权分享或过期时间无效", "只分享 share_code 页面，不泄露存储路径"),
    ],
  },
  {
    id: "friend-system", title: "好友系统", summary: "用户搜索、申请、决策、列表、分组备注与删除。",
    features: ["用户搜索", "好友申请", "同意/拒绝/忽略", "备注分组与删除"],
    links: "与用户资料、私聊、动态可见性、通知和黑名单联动。",
    prerequisites: "双方属于同一 app_key 应用；申请方未被屏蔽；好友功能开启。",
    flow: ["搜索用户", "POST 申请", "对方读取 requests 并决策", "GET friends 回读", "建立私聊或维护备注"],
    endpoints: [
      ep("GET", "/api/user/users/search", "搜索可建立关系的用户", "keyword, page, limit", "脱敏用户列表", "403/422：搜索关闭或关键词无效", "使用返回的 uid/user_id，不按昵称猜测身份"),
      ep("POST", "/api/user/friends/requests", "发送好友申请", "to_uid 或 to_user_id；可选 message/requester_remark", "201，request_id、to_user_id 与 to_uid", "404/409：目标不存在、已是好友或已有申请", "申请后由接收方决策，不直接创建好友"),
      ep("POST", "/api/user/friends/requests/{request_id}/accept", "接受好友申请", "路径 request_id", "双方好友关系与状态", "403/404/409：非接收方或状态已变化", "成功后双方 GET /friends 回读"),
      ep("DELETE", "/api/user/friends/{friend_user_id}", "删除好友关系", "路径 friend_user_id", "200，data.friend_user_id；关系原本不存在也按成功处理", "通常不会因关系不存在失败；401/403 表示会话或功能不可用", "可安全重试；删除后私聊历史仍按应用策略处理"),
    ],
  },
  {
    id: "group-system", title: "群聊系统", summary: "群资料、成员角色、邀请审核、消息、文件和群安全。",
    features: ["创建/解散群", "成员与角色", "邀请/入群审核", "群消息/文件/相册"],
    links: "与好友、聊天、上传、审核举报、在线人数和通知联动。",
    prerequisites: "群聊能力开启；创建者有配额；成员和目标属于同一应用。",
    flow: ["POST 建群", "邀请或审核成员", "发送消息并标记已读", "管理群资料/成员", "解散后按恢复窗口处理"],
    endpoints: [
      ep("POST", "/api/user/chat-rooms", "创建群聊", "name；可选 description/join_mode/max_members/initial_member_ids", "201，data.room 与 initial_member_ids", "403/409/422：配额、成员或规则不满足", "创建后 GET /{room_id} 与 /members 回读"),
      ep("GET", "/api/user/chat-rooms/{room_id}/members", "读取可见成员和角色", "page, limit", "成员 items、角色和禁言状态", "403/404：未入群或群不存在", "管理按钮只对服务端返回的角色开放"),
      ep("POST", "/api/user/chat-rooms/{room_id}/messages", "发送群消息", "content；可选 attachments/reply_to_message_id/mentions/tags", "201，data.message_id 与 data.message（created_at 位于 message 内）", "403/404/422：禁言、群不存在或内容不合规", "成功后用消息 ID 去重并回读增量"),
      ep("POST", "/api/user/chat-rooms/{room_id}/read", "标记群消息已读", "message_id（必填）", "最新已读游标/未读状态", "403/404：非成员或群失效", "进入会话并渲染成功后再更新已读"),
      ep("POST", "/api/admin/apps/{app_id}/chat-rooms/{room_id}/join-requests/{request_id}/decision", "管理员处理入群申请", "action=approved/rejected/ignored（兼容 approve/reject/ignore）", "申请与成员最终状态", "403/404/409：权限、目标或状态冲突", "决策后分别回读申请列表和成员列表"),
    ],
  },
  {
    id: "chat-system", title: "聊天系统", summary: "会话、私聊、已读未读、撤回编辑、转发收藏和搜索。",
    features: ["会话列表", "发送私聊", "消息状态", "撤回/转发/搜索"],
    links: "与好友、群聊、上传、通知、举报和管理员合规参与联动。",
    prerequisites: "双方账号有效且具备通信权限；附件已上传；客户端按服务端 message_id 去重。",
    flow: ["GET conversations", "POST private message", "按 conversation 拉取增量并自动标记已读", "按 message_id 去重", "favorite/delete 后回读"],
    endpoints: [
      ep("GET", "/api/user/conversations", "读取私聊与会话摘要", "page, limit", "会话 items、最后消息与未读数", "401/403：Token 或聊天能力不可用", "以 conversation_id 作为后续消息游标范围"),
      ep("GET", "/api/user/conversations/{conversation_id}/messages", "分页/增量读取会话消息", "page/limit 或 since_id", "消息 items 和分页/增量游标", "403/404：不属于当前会话", "按 message_id 去重，避免轮询重复"),
      ep("POST", "/api/user/messages/private", "发送私聊消息", "to_uid/to_user_id, content；可选 attachments/reply_to_message_id", "201，message_id 与会话信息", "403/404/409/422：关系、目标、状态冲突或内容问题", "成功后以服务端消息替换本地占位，并按 message_id 去重"),
      ep("POST", "/api/user/messages/{message_id}/state", "收藏或本地删除消息", "action=favorite/delete", "收藏或删除状态", "403/404/422：无权、消息不存在或 action 无效", "会话消息读取会自动标记已读；本接口不处理已读"),
    ],
  },
  {
    id: "security-system", title: "安全系统", summary: "租户、角色、账号密码、时效 Token、设备会话与状态校验。",
    features: ["租户/角色校验", "时效凭证", "设备会话", "权限与状态回读"],
    links: "所有业务系统的共同前置；与审计日志、账号停用和应用状态联动。",
    prerequisites: "生产仅使用 HTTPS；客户端不让最终用户填写 Base URL；真实密码/Token/平台 KEY 不记日志。",
    flow: ["按角色登录", "回读 me/permissions", "每次请求带 Bearer", "401 按角色刷新或重登", "撤销会话后验证失效"],
    endpoints: [
      ep("POST", "/api/user/token/refresh", "原子轮换用户 Access/Refresh Token", "refresh_token；可选 X-App-Key/app_key，若提供必须与原会话一致", "新的 access_token/refresh_token 和过期时间", "401/403：Refresh 失效、已撤销或跨应用", "只尝试一次；旧 Refresh 立即作废"),
      ep("GET", "/api/user/permissions", "读取用户当前有效权限", "Bearer", "权限集合与能力边界", "401/403：会话或上级状态失效", "渲染入口前读取，敏感动作仍由服务端再校验"),
      ep("GET", "/api/admin/security/sessions", "管理员查看当前设备会话", "Bearer", "会话列表、设备和最近活动", "401/403：管理员无效或无权限", "展示脱敏设备信息，不展示 Token"),
      ep("DELETE", "/api/admin/security/sessions/{session_id}", "撤销指定管理员会话", "路径 session_id", "撤销结果", "403/404/409：不可操作或已撤销", "撤销后重新拉取列表，并验证目标 Token 失效"),
      ep("GET", "/api/platform/permissions", "代理/买断总控读取层级权限", "Platform Bearer", "Level 1/2 对应的有效权限", "401/403：平台会话或账号状态失效", "总控独有 operators 能力必须以返回权限为准"),
    ],
  },
  {
    id: "card-system", title: "卡密系统", summary: "批次生成、卡片状态、用户兑换和日志追踪。",
    features: ["卡密批次", "生成分发", "用户兑换", "状态/日志"],
    links: "与用户会员、余额、经验、订单、统计和安全风控联动。",
    prerequisites: "管理员已选定 app_id 并配置卡类型/奖励；用户已登录；客户端避免重复提交，收到 409 后回读兑换记录与钱包。",
    flow: ["管理员 POST 批次", "查询 cards 并安全分发", "用户 POST redeem", "回读钱包/VIP 与 redeem logs", "停用异常卡"],
    endpoints: [
      ep("POST", "/api/admin/apps/{app_id}/card-batches", "创建并生成卡密批次", "name, total_count, value_json；可选 card_type/有效期/使用次数", "201，batch_id 与生成数量", "403/409/422：配额、重复或规则无效", "创建后 GET batches/cards 核对数量和状态"),
      ep("GET", "/api/admin/apps/{app_id}/cards", "分页查询卡密状态", "page, limit；可选 batch_id/status/keyword", "卡片 items 与使用状态", "403/404：跨应用或批次不存在", "导出/展示时按权限脱敏完整卡号"),
      ep("POST", "/api/user/cards/redeem", "兑换余额、经验或 VIP 等卡密", "card_code；Bearer", "redeem_id、rewards 与 wallet", "404/409/422：卡不存在、已用/过期或类型不支持", "成功后回读钱包/VIP，不重复提交"),
      ep("GET", "/api/user/cards/redeem-logs", "读取自己的兑换记录", "page, limit", "兑换 items 和奖励快照", "401/403：会话或功能不可用", "用于交易后回读与客服核验"),
    ],
  },
  {
    id: "cloud-system", title: "云仓库", summary: "远程资源、上传记录、云同步快照与恢复。",
    features: ["资源分类", "受控上传", "同步快照", "恢复/删除"],
    links: "与文档、论坛、聊天、群文件、商城资源和上传安全联动。",
    prerequisites: "上传类型/体积在白名单内；资源路径由服务端生成；云同步策略允许当前 data_type。",
    flow: ["读取 policy 或上传列表", "multipart POST upload", "GET uploads 回读 upload_id/SHA-256", "按需创建 snapshot", "restore 后读取业务数据核验"],
    endpoints: [
      ep("GET", "/api/user/uploads", "分页浏览当前用户的上传记录", "page, limit；可选 keyword, scene, category, date_from, date_to", "分页 items、文件分类、预览能力、SHA-256 与 filter_options", "401/403：会话或远程文件功能不可用", "上传成功后按 upload_id 回读，不依赖前端成功提示"),
      ep("POST", "/api/user/uploads", "上传文件并返回可引用的 upload_id", "multipart/form-data；file 必填；可选 scene, original_upload；Bearer + X-App-Key", "201，upload_id、SHA-256、大小与受控文件地址；重复内容可安全复用", "403/422：功能/封禁、缺少文件、类型或体积不合规", "不要手工设置 application/json；让客户端生成 multipart boundary，随后 GET uploads 回读"),
      ep("GET", "/api/user/cloud-sync/policy", "读取云同步类型和限额", "Bearer", "允许的数据类型、体积与保留策略", "403：云同步未开启", "只使用 chat/stickers/favorites；chat 先准备 scope_type 与 target_id"),
      ep("POST", "/api/user/cloud-sync/snapshots", "创建服务端数据快照", "data_type=chat/stickers/favorites；chat 还需 scope_type + target_id，可选 filters/title；请求体不上传业务数据本体", "201，snapshot_id、data_type、title、item_count、size_bytes、charged_balance、read_only", "403/422：功能、类型或范围不合规", "创建后 GET 详情校验摘要"),
      ep("POST", "/api/user/cloud-sync/snapshots/{snapshot_id}/restore", "按快照类型只读拉取或合并恢复", "仅路径 snapshot_id；接口不读取请求 body", "chat 返回只读数据；stickers/favorites 返回合并恢复结果", "403/404：无权或快照不存在；快照损坏时可能返回 500", "先 GET 详情确认 snapshot_id；chat 不覆盖服务端数据，stickers/favorites 按服务端规则合并，完成后回读对应模块"),
      ep("POST", "/api/admin/apps/{app_id}/remote-files", "管理员登记受控远程文件元数据", "name（必填）；可选 file_type,parent_id,content,file_url,mime_type,size_bytes,visibility,status", "201，file_id 与 file_type", "403/404/422：跨应用、父级不存在、类型或字段无效", "本接口只登记元数据；文件本体应先通过独立的上传安全流程"),
    ],
  },
  {
    id: "shop-system", title: "商城系统", summary: "商品、库存、订单、取消与支付后权益交付。",
    features: ["分类商品", "价格库存", "下单支付", "订单/权益回读"],
    links: "与用户钱包、支付回调、卡密/权益、通知、统计和审核联动。",
    prerequisites: "商品上架、库存和价格有效；支付渠道可用；提交后必须按订单 ID 回读。",
    flow: ["GET 商品", "POST buy", "读取订单", "支付回调由服务端处理", "回读订单和权益；失败时不重复扣款"],
    endpoints: [
      ep("GET", "/api/user/shop-goods", "分页浏览可售商品", "page, limit；可选 category_id/goods_type/keyword", "商品 items、价格、库存摘要", "403：商城关闭", "详情页再 GET /{goods_id} 获取最新价格"),
      ep("POST", "/api/user/shop-goods/{goods_id}/buy", "创建购买订单", "quantity；按商品可含 buyer_info", "201，order_id/order_no、金额与状态", "409/422：库存变化、余额不足或数量无效", "提交前展示最终价格，成功后查询订单"),
      ep("GET", "/api/user/orders/{order_source}/{order_id}", "读取统一订单详情", "路径 order_source/order_id", "订单状态、金额、交付信息", "403/404：订单不属于当前用户", "轮询遵循退避，终态后停止"),
      ep("POST", "/api/user/orders/{order_source}/{order_id}/cancel", "取消允许取消的订单", "仅路径参数", "取消/退款处理状态", "409：仅待支付 payment 订单或已支付 shop 订单可按规则取消，其他状态冲突", "取消后回读订单和钱包，不只看提示"),
      ep("POST", "/api/admin/apps/{app_id}/shop-goods", "管理员创建商品", "name, stock；可选 price_balance/price_money/cover_url/description/status", "201，goods_id 与初始状态", "403/422：权限或商品规则无效", "创建后 GET 管理端商品列表核验上架状态"),
    ],
  },
  {
    id: "lifecycle-system", title: "公告、更新与维护", summary: "公告、版本更新和维护窗口三合一生命周期中心。",
    features: ["启动引导", "公告", "版本策略", "维护窗口"],
    links: "与所有客户端启动、登录状态、通知、下载和运营统计联动。",
    prerequisites: "lifecycle 的 edition_code 必填：user 必须传 app_key；platform_owner、authorized_platform、admin 必须传 platform_key；admin edition 的 admin_id 可选，提供时会校验具体管理员归属与状态。X-App-Key 适用于 bootstrap、已选应用接口和 public notices，不会被 lifecycle 控制器读取。生产更新地址使用 HTTPS。",
    flow: ["启动 GET bootstrap/lifecycle", "展示公告", "比较 version", "客户端在 maintenance.active 时禁用业务写入并展示维护页", "管理员发布后客户端下次启动回读"],
    endpoints: [
      ep("GET", "/api/public/bootstrap", "一次读取品牌、能力和启动配置", "X-App-Key 或 app_key", "应用公开配置与能力摘要", "404/403：应用不存在或停用", "冷启动优先调用，失败不要进入业务页"),
      ep("GET", "/api/public/lifecycle", "检查应用、维护与版本生命周期", "edition_code（必填）；user 必须 app_key；platform_owner/authorized_platform/admin 必须 platform_key；admin 可选 admin_id；可选 version_code", "edition_code、current_version_code、update、maintenance、festival_theme、server_time", "404/422：应用或版本参数无效", "登录前和从后台恢复时回读"),
      ep("GET", "/api/public/notices", "读取公开公告", "app_key 或 X-App-Key；可选 type, limit", "data.items 公告列表", "403：公告能力关闭", "按 notice_id 缓存已读，不永久缓存正文"),
      ep("POST", "/api/admin/apps/{app_id}/notices", "管理员发布公告", "title, content；可选 type,is_popup,display_enabled,popup_frequency,audience_type,audience,start_at,end_at；status 固定为1", "201，data.notice", "403/422：跨应用或内容无效", "发布后用公开 notices 回读实际可见性"),
      ep("PUT", "/api/admin/apps/{app_id}/versions", "发布或更新版本策略", "version_name, update_content, version_code, apk_url, package_name, sha256, size_bytes", "版本策略与更新时间", "409/422：版本倒退、地址或字段无效", "发布后 GET /api/public/version 验证客户端视角"),
      ep("POST", "/api/admin/apps/{app_id}/maintenances", "创建维护窗口", "可选 title,message,starts_at,ends_at,forced,allowlist,priority；省略时间会立即且无截止生效，创建时 status 固定为启用", "201，policy_id", "403/422：权限或字段/时间无效", "生产环境建议显式传 starts_at/ends_at；维护前发布公告，启用后用 public lifecycle 回读"),
    ],
  },
  {
    id: "embedded-governance", title: "内嵌治理与数据", summary: "反馈、在线人数、统计、审核和举报嵌入各业务流程。",
    features: ["反馈闭环", "在线心跳", "运营统计", "审核/举报"],
    links: "横跨论坛、好友、群聊、聊天、商城、文件和账号安全。",
    prerequisites: "每个请求绑定 app_id/user_id/角色；敏感管理动作有权限与操作日志。",
    flow: ["业务动作产生事件", "统计/审核队列聚合", "管理员处理", "用户查看状态", "关键结果回读并留痕"],
    endpoints: [
      ep("POST", "/api/user/heartbeat", "维持在线状态并回读账号可用性", "Bearer；可选 device", "在线状态与账号有效性", "401/403：Token 或账号失效", "仅前台按服务端建议间隔发送"),
      ep("POST", "/api/user/feedbacks", "提交应用内反馈", "title, content；可选 images/type", "201，仅返回 feedback_id", "403/422：功能关闭或内容/附件字段不合规", "提交后 GET feedbacks 查看回复"),
      ep("GET", "/api/admin/apps/{app_id}/statistics", "读取当前应用运营统计", "可选 date_start, date_end", "用户、内容、接口等聚合指标", "403/422：无权限或范围无效", "不把聚合数据当作单条业务事实"),
      ep("GET", "/api/admin/apps/{app_id}/feedbacks", "管理员分页处理反馈", "page, limit；可选 status", "反馈 items 与状态", "403：跨应用或无权限", "回复后通过反馈列表回读状态（无单条详情路由）"),
    ],
  },
];

const ENDPOINT_SEARCH_CATALOG = SYSTEMS.flatMap((system) => system.endpoints.map((endpoint) => ({
  method: endpoint.method,
  path: endpoint.path,
  purpose: endpoint.purpose,
  systemId: system.id,
  systemTitle: system.title,
})));

const SYSTEM_EXAMPLES: Record<string, string> = {
  "user-system": `# 沿用：Content-Type: application/json；X-App-Key: APP_API_UNIQUE_ID
POST /api/user/login
{"app_key":"APP_API_UNIQUE_ID","account":"demo_user","password":"YOUR_PASSWORD"}
→ 200 {"code":1,"msg":"登录成功","data":{"access_token":"EXAMPLE-ACCESS-TOKEN","refresh_token":"REFRESH_TOKEN_PLACEHOLDER"},"trace_id":"TRACE_ID"}
GET /api/user/me  Authorization: Bearer ACCESS_TOKEN_PLACEHOLDER
→ 从 data.user 回读 status 与应用归属`,
  "email-system": `# 沿用 Content-Type；所有值均为占位
POST /api/public/verification-code/email
{"app_key":"APP_API_UNIQUE_ID","email":"user@example.test","scene":"profile_email"}
→ 201 {"code":1,"msg":"验证码已发送到邮箱","data":{"verification_id":"VERIFICATION_ID","scene":"profile_email","target_masked":"u***@example.test","expired_at":"ISO_TIME"},"trace_id":"TRACE_ID"}
PUT /api/user/profile  Authorization: Bearer ACCESS_TOKEN_PLACEHOLDER
{"email":"user@example.test","email_code":"CODE_PLACEHOLDER"}
→ 从 data.user 回读绑定邮箱`,
  "forum-system": `# 沿用 Content-Type + Bearer；大写 ID 替换为真实值
POST /api/user/forum-posts
{"plate_id":"PLATE_ID","title":"示例标题","content":"示例正文","client_draft_id":"CLIENT_DRAFT_ID_PLACEHOLDER"}
→ 201 {"code":1,"msg":"...","data":{"post_id":"POST_ID","audit_status":"pending_or_approved"},"trace_id":"TRACE_ID"}
GET /api/user/forum-posts/POST_ID → 从 data 回读正文与审核状态`,
  "document-system": `# 沿用 Content-Type + Bearer
POST /api/user/notes
{"title":"接入记录","content":"第一版","content_type":"markdown"}
→ 201 {"code":1,"msg":"...","data":{"document":{"id":"DOCUMENT_ID","version_no":1}},"trace_id":"TRACE_ID"}
GET /api/user/notes/DOCUMENT_ID → 回读 data.document.id 与 version_no`,
  "friend-system": `# 沿用 Content-Type + Bearer
POST /api/user/friends/requests
{"to_uid":"TARGET_USER_UID","message":"你好"}
→ 201 {"code":1,"msg":"...","data":{"request_id":"REQUEST_ID","to_user_id":"TARGET_USER_ID","to_uid":"TARGET_USER_UID"},"trace_id":"TRACE_ID"}
POST /api/user/friends/requests/REQUEST_ID/accept；GET /api/user/friends → 回读关系`,
  "group-system": `# 沿用 Content-Type + Bearer
POST /api/user/chat-rooms
{"name":"项目群","join_mode":"approval","initial_member_ids":["USER_ID_2"]}
→ 201 {"code":1,"msg":"...","data":{"room":{"id":"ROOM_ID","name":"项目群"},"initial_member_ids":["USER_ID_2"]},"trace_id":"TRACE_ID"}
GET /api/user/chat-rooms/ROOM_ID/members → 回读成员角色`,
  "chat-system": `# 沿用 Content-Type + Bearer
POST /api/user/messages/private
{"to_uid":"TARGET_USER_UID","content":"占位消息"}
→ 201 {"code":1,"msg":"...","data":{"message_id":"MESSAGE_ID","conversation_id":"CONVERSATION_ID"},"trace_id":"TRACE_ID"}
GET /api/user/conversations/CONVERSATION_ID/messages → 自动标已读并按 message_id 去重`,
  "security-system": `# 沿用 Content-Type；X-App-Key 可选，若给出必须匹配原会话
POST /api/user/token/refresh
{"refresh_token":"REFRESH_TOKEN_PLACEHOLDER"}
→ 200 {"code":1,"msg":"...","data":{"access_token":"EXAMPLE-NEW-ACCESS-TOKEN","refresh_token":"NEW_REFRESH_TOKEN_PLACEHOLDER"},"trace_id":"TRACE_ID"}
GET /api/user/me → 验证新 Token；旧 Refresh 再用应为 401`,
  "card-system": `# 沿用 Content-Type + Bearer
POST /api/user/cards/redeem
{"card_code":"CARD_CODE_PLACEHOLDER"}
→ 200 {"code":1,"msg":"...","data":{"redeem_id":"REDEEM_ID","rewards":{"vip_days":"EXAMPLE_DAYS"},"wallet":{"balance":"BALANCE_PLACEHOLDER"}},"trace_id":"TRACE_ID"}
GET /api/user/cards/redeem-logs 与 /api/user/me → 回读记录和权益`,
  "cloud-system": `# 沿用 Content-Type + Bearer；data_type 仅 chat/stickers/favorites
POST /api/user/cloud-sync/snapshots
{"data_type":"chat","scope_type":"private","target_id":"CONVERSATION_ID","title":"聊天备份"}
→ 201 {"code":1,"msg":"...","data":{"snapshot_id":"SNAPSHOT_ID"},"trace_id":"TRACE_ID"}
POST /api/user/cloud-sync/snapshots/SNAPSHOT_ID/restore → 只读拉取 data，不覆盖服务端数据`,
  "shop-system": `# 沿用 Content-Type + Bearer；GOODS_ID/ORDER_ID 替换为真实路径参数
POST /api/user/shop-goods/GOODS_ID/buy
{"quantity":1}
→ 201 {"code":1,"msg":"...","data":{"order_id":"ORDER_ID","status":"ORDER_STATUS"},"trace_id":"TRACE_ID"}
# ORDER_STATUS 实际为 pending、paid 或 completed
GET /api/user/orders/shop/ORDER_ID → 回读 data 中金额、状态与权益`,
  "lifecycle-system": `GET /api/public/lifecycle?edition_code=user&app_key=APP_API_UNIQUE_ID&version_code=VERSION_CODE_PLACEHOLDER
→ 200 {"code":1,"msg":"...","data":{"edition_code":"user","current_version_code":123,"update":null,"maintenance":{"active":false},"festival_theme":{"active":false},"server_time":"ISO_TIME"},"trace_id":"TRACE_ID"}
POST /api/admin/apps/APP_ID/notices  Authorization: Bearer ADMIN_ACCESS_TOKEN_PLACEHOLDER
{"title":"维护通知","content":"示例内容"}；再 GET /api/public/notices?app_key=APP_API_UNIQUE_ID 回读`,
  "embedded-governance": `# 沿用 Content-Type + Bearer
POST /api/user/feedbacks
{"title":"功能建议","content":"示例反馈","type":"suggestion","images":[]}
→ 201 {"code":1,"msg":"...","data":{"feedback_id":"FEEDBACK_ID"},"trace_id":"TRACE_ID"}
GET /api/user/feedbacks → 管理员回复后再次回读闭环`,
}

const ERROR_CODES = [
  ["400", "请求参数不完整或格式错误", "修正字段后重试，不要无条件循环。"],
  ["401", "登录凭证无效、过期或已撤销", "用户端只 Refresh 一次；管理员/代理/总控重新登录。"],
  ["403", "账号、角色、应用或上级状态不允许", "停止请求并回读 me/permissions/lifecycle。"],
  ["404", "资源不存在或不属于当前应用", "核对资源 ID 与应用上下文。"],
  ["409", "状态冲突或重复提交", "读取最新状态后决定是否继续。"],
  ["413", "上传文件超过限制", "压缩或拆分文件，不绕过服务端校验。"],
  ["422", "业务规则校验未通过", "展示字段级提示并保留用户输入。"],
  ["429", "请求过于频繁", "若响应提供 Retry-After 则遵循；否则采用抖动指数退避。"],
  ["500", "服务暂时异常", "记录 trace_id，稍后重试或通过应用内反馈联系。"],
] as const;

const AUTH_MATRIX = [
  {
    role: "用户端",
    login: "POST /api/user/login",
    identity: "body: app_key；可选同值 X-App-Key",
    protectedRequest: "Bearer + X-App-Key",
    expiry: "只刷新一次，再失败则重新登录",
  },
  {
    role: "管理员端",
    login: "POST /api/admin/login",
    identity: "body: platform_key + app_key",
    protectedRequest: "Admin Bearer；资源路径绑定 app_id",
    expiry: "重新登录",
  },
  {
    role: "授权代理端（Level 2）",
    login: "POST /api/platform/login",
    identity: "body: platform_key；不提交 app_key",
    protectedRequest: "Platform Bearer；服务端裁剪授权范围",
    expiry: "重新登录",
  },
  {
    role: "平台总控客户端（官方托管，Level 1）",
    login: "POST /api/platform/login",
    identity: "body: platform_key；不提交 app_key",
    protectedRequest: "Platform Bearer；总控权限以 /permissions 为准",
    expiry: "重新登录",
  },
] as const;

const MINIMUM_CLOSURE = [
  ["公开启动", "请求 bootstrap 或 lifecycle，确认应用、版本、维护状态和服务时间可解析；失败时不进入业务页。"],
  ["身份回读", "按角色登录后立即调用 me 与 permissions，确认账号、应用、层级和状态，而不是只相信登录提示。"],
  ["写入回读", "选择一个低风险业务动作，保存服务端返回 ID，再 GET 同一资源核对最终状态与租户归属。"],
  ["失败恢复", "覆盖 401、403、409、422 与 429，验证刷新或重登、停止越权、读取最新状态和退避行为。"],
  ["集成验收", "上传、下载、支付、推送、语音与 Android 行为必须在实际部署、第三方服务和目标设备上另行验证。"],
] as const;

export const metadata = {
  title: "接口文档",
  description: "易云盈四角色与功能系统接入文档，包含已核验公开路由、认证矩阵、响应约定、失败语义和最小闭环验收。",
};

export default function ApiDocsPage() {
  return (
    <main className="docs-page">
      <header className="docs-header">
        <a className="brand" href="/download-center/" aria-label="返回易云盈官网">
          <img src="/download-center/logo.svg" alt="" width="38" height="38" />
          <span><strong>易云盈</strong><small>客户接口文档</small></span>
        </a>
        <a className="docs-back" href="/download-center/"><ArrowLeft size={16} aria-hidden="true" />返回官网</a>
      </header>

      <section className="docs-hero">
        <div><p>PUBLIC INTEGRATION GUIDE</p><h1>四角色、十二大业务系统 + 内嵌治理，按闭环接入</h1><span>只列已存在路由的客户接入说明：功能、用途、联动、前置条件、关键参数、成功与失败以及最小使用流程。不提供在线执行，也不展示真实 KEY、密码或 Token；路由存在不等同部署环境已开通或生产验收通过。</span></div>
        <aside><ShieldCheck aria-hidden="true" /><strong>已核验公开范围</strong><span>60 条白名单路由 · 安全占位示例</span></aside>
      </section>

      <DocsPageActions />
      <noscript><p className="docs-noscript">当前浏览器已禁用 JavaScript：文档内容和 cURL 示例仍可阅读、选择并手动复制；分享、格式切换和打印按钮需要启用 JavaScript。</p></noscript>

      <div className="docs-layout">
        <nav className="docs-toc" aria-label="接口文档目录">
          <p>目录</p>
          <a href="#quickstart">开始接入</a>
          <a href="#deployment-modes">部署模式</a>
          <a href="#scope-status">文档状态</a>
          <a href="#auth-matrix">认证矩阵</a>
          <a href="#response-contract">响应与回读</a>
          <a href="#closure-checklist">最小闭环验收</a>
          {ROLE_GUIDES.map((role) => <a href={"#" + role.id} key={role.id}>{role.title}</a>)}
          {SYSTEMS.map((system) => <a href={"#" + system.id} key={system.id}>{system.title}</a>)}
          <a href="#conventions">通用约定</a><a href="#errors">错误处理</a><a href="#uploads">上传安全</a>
        </nav>

        <div className="docs-content">
          <EndpointSearch catalog={ENDPOINT_SEARCH_CATALOG} />
          <section className="docs-intro" id="quickstart">
            <div className="docs-section-title"><Code2 aria-hidden="true" /><span><small>QUICK START</small><h2>开始接入</h2></span></div>
            <p><strong>模板路径说明：</strong><code>APP_ID</code>、<code>POST_ID</code>、<code>ROOM_ID</code> 等大写占位符必须替换为服务端实际返回的 ID，不能按字面发送。</p>
            <p>以下可复制示例默认面向官方托管四端，固定使用官方 HTTPS Base URL；源码买断自建的 HTTP/HTTPS 与多线路配置见下一节，不能把两套身份混用。最终用户不填写服务器地址。<code>app_key</code> 与 <code>X-App-Key</code> 表示同一个“应用 API 唯一标识”，不是 <code>app_secret</code>；服务端仍用它绑定租户。管理员登录同时提交 <code>platform_key + app_key</code>，授权代理与平台总控共用平台登录并由 Level 2/1 区分。</p>
            <div className="docs-code-block" aria-label="接入请求头占位示例">
              <div><span>官方托管 API Base URL</span><code>{OFFICIAL_API_BASE_URL}</code></div>
              <div><span>应用 API 唯一标识</span><code>X-App-Key: APP_API_UNIQUE_ID</code></div>
              <div><span>登录后授权</span><code>Authorization: Bearer ACCESS_TOKEN_PLACEHOLDER</code></div>
              <div><span>内容类型</span><code>Content-Type: application/json</code></div>
            </div>
            <div className="docs-code-actions"><CopyTextButton value={QUICKSTART_HEADERS} label="复制接入请求头占位示例" /></div>
            <div className="docs-warning"><AlertTriangle aria-hidden="true" /><p><strong>官方托管服务地址固定为 <code>{OFFICIAL_API_BASE_URL}</code>；只有 KEY、账号、密码、Token 和资源 ID 使用明显占位符。</strong>不要把真实凭据放入网页、工单、截图或日志，也不要把占位值写入程序配置。下载 APK 不会授予角色权限，实际权限始终由后端账号、层级、应用、Token 和状态共同校验。</p></div>
          </section>

          <section className="deployment-modes-section" id="deployment-modes">
            <div className="docs-section-title"><ShieldCheck aria-hidden="true" /><span><small>DEPLOYMENT MODES</small><h2>官方托管与源码买断自建是两条独立轨道</h2><p>平台总控客户端是一种官方托管角色；源码买断则由购买方成为自建系统最高级，二者不能共用连接身份或数据。</p></span></div>
            <div className="deployment-mode-grid">
              <article className="is-official">
                <span>官方托管四端</span>
                <h3>平台总控客户端属于官方平台</h3>
                <code>{OFFICIAL_API_BASE_URL}</code>
                <ul><li><Check aria-hidden="true" />用户、管理员、授权代理、平台总控四端固定连接官方 HTTPS API</li><li><Check aria-hidden="true" />官方包不允许降级到 HTTP，也不接受备用的非官方 Base URL</li><li><Check aria-hidden="true" />使用官方签发的租户身份、账号权限、数据与更新通道</li><li><Check aria-hidden="true" />平台总控为官方托管 Level 1，不代表持有或部署整套源码</li></ul>
              </article>
              <article className="is-self-hosted">
                <span>源码买断自建</span>
                <h3>购买方使用自己的服务器与最高级身份</h3>
                <div className="self-hosted-base-examples"><code>{SELF_HOSTED_API_BASE_URL_EXAMPLE}</code><code>{SELF_HOSTED_HTTP_API_BASE_URL_EXAMPLE}</code></div><b>均为不可执行的域名格式示例</b>
                <ul><li><Check aria-hidden="true" />可在构建时配置一个或多个有序自有 Base URL；规范化后去重，首条是唯一主线路</li><li><Check aria-hidden="true" />HTTPS 是生产推荐；HTTP 仅限购买方明确接受风险的可信内网或测试环境，凭据与业务数据会以明文传输</li><li><Check aria-hidden="true" />Android 使用 HTTP 必须同时选择 <code>self_host</code> 并二次显式开启明文网络策略；该选择具有构建级风险，不能用于官方包</li><li><Check aria-hidden="true" />不得连接 <code>{OFFICIAL_API_BASE_URL}</code>，也不得复用官方 KEY、账号、Token、数据或下载策略</li><li><Check aria-hidden="true" />自动切线仅适用于 GET/HEAD 的连接类异常或 502/503/504；4xx、普通 500、写请求、上传和 Token 刷新不跨线路重放，全部失败也不回退官方服务</li><li><Check aria-hidden="true" />示例域名只说明格式，不能直接写入构建或生产配置；上线前仍须证明所有线路属于同一自建部署</li></ul>
              </article>
            </div>
            <div className="docs-boundary-note"><AlertTriangle aria-hidden="true" /><p><strong>配置门禁：</strong>官方正式包只接受官方 HTTPS Base URL，绝不回退 HTTP；源码买断自建包必须在构建和部署阶段显式写入购买方自己的 HTTP/HTTPS 地址列表与自有身份材料。任一轨道缺少有效配置、线路归属不清或混入另一轨道身份时都应失败关闭。</p></div>
          </section>

          <section className="docs-readiness" id="scope-status">
            <div className="docs-section-title"><Gauge aria-hidden="true" /><span><small>DOCUMENT STATUS</small><h2>先看清公开文档能证明什么</h2><p>接口目录、运行状态、部署验收和 Android 真机验收是四类不同证据。</p></span></div>
            <div className="docs-status-grid">
              <article><strong>60</strong><span>代表性白名单路由</span><p>每条公开路由都与仓库生成的后端路由目录逐条匹配。</p></article>
              <article><strong>4</strong><span>角色身份链</span><p>用户、管理员、授权代理和买断总控使用不同登录字段与会话边界。</p></article>
              <article><strong>13</strong><span>最小接入示例</span><p>十二大业务系统加内嵌治理，均包含请求与状态回读路径。</p></article>
            </div>
            <div className="docs-boundary-note"><AlertTriangle aria-hidden="true" /><p><strong>能力边界：</strong>本页证明公开目录与接入说明可核对，不证明某个部署已启用全部功能，也不替代真实数据库、第三方服务、弱网、并发或 Android 真机验收。</p></div>
          </section>

          <section className="auth-matrix-section" id="auth-matrix">
            <div className="docs-section-title"><LockKeyhole aria-hidden="true" /><span><small>AUTH MATRIX</small><h2>四角色认证矩阵</h2><p>先按角色选登录入口，再决定应用标识、受保护请求头和过期恢复方式。</p></span></div>
            <div className="docs-table-wrap">
              <table>
                <thead><tr><th>角色</th><th>登录入口</th><th>租户身份</th><th>登录后请求</th><th>Access 过期</th></tr></thead>
                <tbody>{AUTH_MATRIX.map((item) => <tr key={item.role}><th scope="row">{item.role}</th><td><code>{item.login}</code></td><td>{item.identity}</td><td>{item.protectedRequest}</td><td>{item.expiry}</td></tr>)}</tbody>
              </table>
            </div>
            <p className="role-security-note"><ShieldCheck aria-hidden="true" />客户端隐藏入口不能代替服务端权限校验；登录成功后仍必须回读 <code>me</code> 与 <code>permissions</code>，跨应用资源由后端再次校验。</p>
          </section>

          <section className="response-contract-section" id="response-contract">
            <div className="docs-section-title"><Code2 aria-hidden="true" /><span><small>RESPONSE CONTRACT</small><h2>统一响应与状态回读</h2><p>同时判断 HTTP 状态与响应信封；写入成功提示不是最终业务状态。</p></span></div>
            <div className="response-contract-layout">
              <div className="response-envelope">
                <pre>{RESPONSE_ENVELOPE}</pre>
                <div className="docs-code-actions"><CopyTextButton value={RESPONSE_ENVELOPE} label="复制统一响应占位示例" /></div>
              </div>
              <ul>
                <li><Check aria-hidden="true" /><span><strong>HTTP + code</strong>HTTP 状态负责协议语义，<code>code=1</code> 表示成功信封。</span></li>
                <li><Check aria-hidden="true" /><span><strong>data</strong>只按当前接口合同读取字段，不把另一个接口的结构套用过来。</span></li>
                <li><Check aria-hidden="true" /><span><strong>trace_id</strong>错误排查时保留追踪号，但日志继续移除密码、Token、验证码和真实 KEY。</span></li>
                <li><Check aria-hidden="true" /><span><strong>服务端 ID</strong>创建后保存返回 ID，并用读取接口确认状态、归属和可见性。</span></li>
              </ul>
            </div>
          </section>

          <section className="closure-checklist-section" id="closure-checklist">
            <div className="docs-section-title"><Check aria-hidden="true" /><span><small>MINIMUM CLOSURE</small><h2>最小功能闭环验收</h2><p>先完成一条可重复、可回读、可失败恢复的窄路径，再扩大到完整业务。</p></span></div>
            <ol className="docs-acceptance-list">{MINIMUM_CLOSURE.map(([title, detail], index) => <li key={title}><span>{index + 1}</span><div><strong>{title}</strong><p>{detail}</p></div></li>)}</ol>
            <div className="docs-boundary-note"><AlertTriangle aria-hidden="true" /><p><strong>不要越级下结论：</strong>静态文档通过、路由存在或单次 HTTP 成功，都不能单独证明客户端、数据库、第三方依赖和目标设备已经形成生产闭环。</p></div>
          </section>

          <section className="role-section" id="roles">
            <div className="docs-section-title"><ShieldCheck aria-hidden="true" /><span><small>ROLE FLOWS</small><h2>四角色登录与会话</h2><p>只有用户端支持 Refresh；管理员、代理和买断总控的 Access Token 过期后重新登录。</p></span></div>
            <div className="role-guide-grid">
              {ROLE_GUIDES.map((role) => (
                <article id={role.id} key={role.id}>
                  <h3>{role.title}</h3><p>{role.scope}</p>
                  <dl><div><dt>关键字段</dt><dd>{role.fields}</dd></div><div><dt>成功结果</dt><dd>{role.success}</dd></div><div><dt>最小流程</dt><dd>{role.flow}</dd></div></dl>
                  <CodeExample raw={role.example} label={`${role.title}登录示例`} />
                </article>
              ))}
            </div>
          </section>

          {SYSTEMS.map((system) => (
            <section className="endpoint-section system-guide" id={system.id} key={system.id}>
              <div className="docs-section-title"><Code2 aria-hidden="true" /><span><h2>{system.title}</h2><p>{system.summary}</p></span></div>
              <SectionActions targetId={system.id} title={system.title} />
              <div className="system-overview">
                <article><h3>有什么功能</h3><ul>{system.features.map((item) => <li key={item}><Check aria-hidden="true" />{item}</li>)}</ul></article>
                <article><h3>联动模块</h3><p>{system.links}</p></article>
                <article><h3>前置条件</h3><p>{system.prerequisites}</p></article>
              </div>
              <ol className="mini-flow" aria-label={system.title + "最小闭环"}>{system.flow.map((item, index) => <li key={item}><span>{index + 1}</span>{item}</li>)}</ol>
              <div className="system-example"><h3>代表性最小闭环示例（大写 ID 请替换为真实路径参数）</h3><CodeExample raw={SYSTEM_EXAMPLES[system.id]} label={`${system.title}最小闭环示例`} /></div>
              <div className="endpoint-cards">
                {system.endpoints.map((endpoint) => (
                  <article className="endpoint-card" key={endpoint.method + endpoint.path}>
                    <header><span className={"method method-" + endpoint.method.toLowerCase()}>{endpoint.method}</span><code>{endpoint.path}</code></header>
                    <h3>{endpoint.purpose}</h3>
                    <dl><div><dt>关键参数</dt><dd>{endpoint.fields}</dd></div><div><dt>成功结果</dt><dd>{endpoint.result}</dd></div><div><dt>常见失败</dt><dd>{endpoint.failure}</dd></div><div><dt>怎么用</dt><dd>{endpoint.usage}</dd></div></dl>
                  </article>
                ))}
              </div>
            </section>
          ))}

          <section className="conventions-section" id="conventions">
            <div className="docs-section-title"><Gauge aria-hidden="true" /><span><small>CONVENTIONS</small><h2>通用约定</h2></span></div>
            <div className="convention-grid">
              <article><h3>分页</h3><p>列表使用 <code>page</code> 与 <code>limit</code>，以响应 <code>pagination.total</code> 与 <code>pagination.total_pages</code> 为准。</p></article>
              <article><h3>限流</h3><p>收到 429 时，若服务端提供 <code>Retry-After</code> 则遵循；否则使用带抖动的指数退避。</p></article>
              <article><h3>请求追踪</h3><p>保存 <code>trace_id</code>，但不记录密码、Token、验证码或真实平台 KEY。</p></article>
              <article><h3>租户边界</h3><p>永远使用服务端 Token 绑定的 app_id/角色，不信任客户端自行声明的跨应用资源。</p></article>
              <article><h3>状态回读</h3><p>创建、更新、删除、审核、支付和恢复后再 GET 目标资源确认最终状态。</p></article>
            </div>
          </section>

          <section className="errors-section" id="errors">
            <div className="docs-section-title"><LockKeyhole aria-hidden="true" /><span><small>ERROR HANDLING</small><h2>错误码与恢复策略</h2></span></div>
            <div className="error-table"><div className="error-head"><b>HTTP</b><b>含义</b><b>客户端处理</b></div>{ERROR_CODES.map(([code, meaning, action]) => <div key={code}><code>{code}</code><span>{meaning}</span><p>{action}</p></div>)}</div>
          </section>

          <section className="upload-section" id="uploads">
            <div className="docs-section-title"><UploadCloud aria-hidden="true" /><span><small>UPLOAD SAFETY</small><h2>上传安全</h2></span></div>
            <ul><li><Check aria-hidden="true" /><code>POST /api/user/uploads</code> 必须使用 <code>multipart/form-data</code>；不要手工设置 <code>Content-Type: application/json</code>，boundary 由 HTTP 客户端生成。</li><li><Check aria-hidden="true" />客户端预检扩展名、MIME 和体积，服务端必须重新验证实际内容。</li><li><Check aria-hidden="true" />文件名和存储路径由服务端生成，不拼接用户输入。</li><li><Check aria-hidden="true" />论坛、聊天、群文件和商城资源只引用已完成安全检查的 upload_id/资源。</li><li><Check aria-hidden="true" /><code>GET /api/user/uploads</code> 支持分页、关键词、场景、分类与日期筛选；上传后必须按 upload_id/SHA-256 回读。</li><li><Check aria-hidden="true" />下载使用受控或短时地址，不暴露真实存储路径。</li></ul>
            <pre><code>{UPLOAD_CURL_EXAMPLE}</code></pre>
            <div className="docs-code-actions"><CopyTextButton value={UPLOAD_CURL_EXAMPLE} label="复制 multipart 文件上传 cURL 示例" /></div>
          </section>

          <aside className="docs-scope-note"><ShieldCheck aria-hidden="true" /><div><strong>文档边界</strong><p>本页只列客户接入所需的代表性白名单路由，不导出完整 811 路由目录，不提供在线执行，不包含数据库、内部地址、真实 KEY、源码包、Git bundle 或交付清单。未列出不代表可调用。</p></div></aside>
        </div>
      </div>

      <footer className="docs-footer"><span>© 2026 易云盈 · 客户接口文档</span><nav><a href="/privacy/">隐私政策</a><a href="/terms/">服务条款</a><a href="/download-center/">返回官网</a></nav></footer>
    </main>
  );
}
