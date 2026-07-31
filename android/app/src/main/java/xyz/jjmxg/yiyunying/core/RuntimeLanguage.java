package xyz.jjmxg.yiyunying.core;

import android.content.Context;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.TextView;

import androidx.appcompat.widget.Toolbar;
import androidx.core.os.LocaleListCompat;

import com.google.android.material.bottomnavigation.BottomNavigationView;
import com.google.android.material.textfield.TextInputLayout;

import java.util.LinkedHashMap;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;

/** Applies locale choices to legacy literal UI text while screens migrate to resources. */
public final class RuntimeLanguage {
    private static final Map<String, String> EN = new LinkedHashMap<>();
    private static final Map<String, String> JA = new LinkedHashMap<>();
    private static final Map<String, String> EN_TO_ZH = new LinkedHashMap<>();
    private static final Map<String, String> JA_TO_ZH = new LinkedHashMap<>();

    static {
        add("设置", "Settings", "設定");
        add("设置中心", "Settings", "設定センター");
        add("账号、隐私、通知和存储集中管理", "Manage account, privacy, notifications and storage", "アカウント・プライバシー・通知・ストレージを一括管理");
        add("外观与显示", "Appearance", "外観と表示");
        add("主题模式与界面显示", "Theme mode and interface appearance", "テーマモードと画面表示");
        add("消息与隐私", "Messages & privacy", "メッセージとプライバシー");
        add("好友申请、陌生消息与黑名单", "Friend requests, unknown messages and blocked users", "友達申請・知らない人からのメッセージ・ブロックリスト");
        add("动态展示", "Activity visibility", "投稿の公開範囲");
        add("笔记、帖子、悬赏、关注与粉丝可见范围", "Visibility of notes, posts, bounties, following and followers", "ノート・投稿・依頼・フォロー・フォロワーの公開範囲");
        add("通知、来电与后台", "Notifications, calls & background", "通知・通話・バックグラウンド");
        add("消息提醒、通话权限与后台存活", "Message alerts, call permissions and background availability", "メッセージ通知・通話権限・バックグラウンド動作");
        add("账号与安全", "Account & security", "アカウントとセキュリティ");
        add("找回密码、账号安全与退出登录", "Password recovery, account security and sign out", "パスワード再設定・アカウント保護・ログアウト");
        add("存储与软件", "Storage & app", "ストレージとアプリ");
        add("缓存、拍摄保存、版本与维护", "Cache, captured media, versions and maintenance", "キャッシュ・撮影データ・バージョン・メンテナンス");
        add("谁能在我的主页看到这些内容", "Who can see this content on my profile", "プロフィールでこの内容を見られる人");
        add("关闭某项后，其他用户只能看到你允许公开的基础资料；平台管理范围内的审核权限不受影响。", "When an item is off, other users can only see the basic profile you made public. Platform review permissions are unaffected.", "項目をオフにすると、他のユーザーには公開を許可した基本情報のみ表示されます。プラットフォームの審査権限には影響しません。");
        add("展示笔记动态", "Show note activity", "ノートの投稿を表示");
        add("展示论坛帖子与互动", "Show forum posts and interactions", "フォーラム投稿と交流を表示");
        add("展示悬赏发布与参与", "Show created and joined bounties", "作成・参加した依頼を表示");
        add("展示我的关注列表", "Show my following list", "フォロー一覧を表示");
        add("展示我的粉丝列表", "Show my followers list", "フォロワー一覧を表示");
        add("可见对象", "Visible to", "公開対象");
        add("好友可以查看动态", "Friends can see activity", "友達に投稿を公開");
        add("关注我的用户可以查看动态", "Followers can see activity", "フォロワーに投稿を公開");
        add("陌生人可以查看动态", "Strangers can see activity", "知らない人に投稿を公開");
        add("隐藏会话联系人可以查看动态", "Contacts in hidden conversations can see activity", "非表示の会話相手に投稿を公開");
        add("特别关心联系人可以查看动态", "Special-care contacts can see activity", "特別な関心の相手に投稿を公開");
        add("保存动态展示设置", "Save activity visibility", "投稿の公開範囲を保存");
        add("外观：跟随系统", "Theme: Follow system", "テーマ：システムに従う");
        add("界面语言：简体中文", "Language: Simplified Chinese", "表示言語：簡体字中国語");
        add("软件主色：易运盈蓝", "Accent: Yiyunying blue", "アクセント：易運盈ブルー");
        add("全局聊天背景：默认", "Global chat background: Default", "全体チャット背景：デフォルト");
        add("桌面图标：默认", "App icon: Default", "アプリアイコン：デフォルト");
        add("字体：系统默认", "Font: System default", "フォント：システム既定");
        add("主题模式", "Theme mode", "テーマモード");
        add("外观模式", "Appearance mode", "外観モード");
        add("界面语言", "Interface language", "表示言語");
        add("软件主色", "Accent", "アクセント");
        add("全局聊天背景", "Global chat background", "全体チャット背景");
        add("桌面图标", "App icon", "アプリアイコン");
        add("界面字体", "Interface font", "表示フォント");
        add("字体", "Font", "フォント");
        add("系统默认", "System default", "システム既定");
        add("简体中文", "Simplified Chinese", "簡体字中国語");
        add("易运盈蓝", "Yiyunying blue", "易運盈ブルー");
        add("青绿色", "Teal", "ティール");
        add("玫红色", "Rose", "ローズ");
        add("默认", "Default", "デフォルト");
        add("自定义图片", "Custom image", "カスタム画像");
        add("简约", "Minimal", "ミニマル");
        add("深色", "Dark", "ダーク");
        add("默认图标", "Default icon", "デフォルトアイコン");
        add("简约图标", "Minimal icon", "ミニマルアイコン");
        add("深色图标", "Dark icon", "ダークアイコン");
        add("现代无衬线", "Modern sans", "モダンゴシック");
        add("阅读衬线", "Reading serif", "読書向け明朝");
        add("等宽字体", "Monospace", "等幅フォント");
        add("跟随系统", "Follow system", "システムに従う");
        add("浅色模式", "Light", "ライト");
        add("深色模式", "Dark", "ダーク");
        add("消息", "Messages", "メッセージ");
        add("动态", "Activity", "タイムライン");
        add("活动", "Events", "イベント");
        add("我的", "Me", "マイページ");
        add("首页", "Home", "ホーム");
        add("搜索", "Search", "検索");
        add("搜索联系人或群聊", "Search contacts or groups", "連絡先またはグループを検索");
        add("搜索动态内容", "Search activity", "投稿を検索");
        add("搜索活动或商品", "Search events or products", "イベントまたは商品を検索");
        add("搜索我的功能", "Search my features", "機能を検索");
        add("聊天", "Chat", "チャット");
        add("搜索聊天记录", "Search chat history", "チャット履歴を検索");
        add("当前聊天设置", "Chat settings", "チャット設定");
        add("群聊设置", "Group settings", "グループ設定");
        add("权限管理", "Permissions", "権限管理");
        add("查看资料", "View profile", "プロフィールを見る");
        add("查看好友资料", "View contact profile", "連絡先プロフィールを見る");
        add("自动生成关系标签", "Generate relationship label", "関係ラベルを自動生成");
        add("关系标签已自动生成，可继续修改", "Relationship label generated; you can still edit it", "関係ラベルを生成しました。引き続き編集できます");
        add("线索根据好友时间、分组和权限自动生成，不需要手动填写", "Clues are generated from friendship time, group and permissions", "手がかりは友達になった日時・グループ・権限から自動生成されます");
        add("成为好友", "Friends since", "友達になった日");
        add("好友分组", "Contact group", "友達グループ");
        add("已设为特别关心", "Marked as special care", "特別な関心に設定済み");
        add("当前仅开放聊天权限", "Chat-only permission is enabled", "現在はチャットのみ許可されています");
        add("暂无更多互动线索", "No additional interaction clues", "追加の交流情報はありません");
        add("设置当前聊天背景", "Set chat background", "このチャットの背景を設定");
        add("恢复使用全局背景", "Use global background", "全体背景に戻す");
        add("恢复系统默认", "Restore system default", "システム既定に戻す");
        add("当前会话使用系统默认聊天背景", "This conversation uses the system default background", "この会話はシステム既定の背景を使用しています");
        add("当前会话已恢复系统默认聊天背景", "System default background restored for this conversation", "この会話の背景をシステム既定に戻しました");
        add("这里设置所有聊天的默认背景；好友会话可以在聊天右上角单独覆盖。", "This sets the default background for every chat. A conversation can override it from its top-right settings.", "すべてのチャットの既定背景を設定します。各会話の右上設定から個別に上書きできます。");
        add("选择全局背景图片", "Choose global background image", "全体背景画像を選択");
        add("恢复系统默认背景", "Restore default background", "既定の背景に戻す");
        add("添加我的方式", "How people can add me", "追加方法");
        add("好友与群聊申请管理", "Friend & group requests", "友達・グループ申請");
        add("好友列表与分组", "Friends & groups", "友達リストとグループ");
        add("隐藏会话", "Hidden conversations", "非表示の会話");
        add("允许通过名片添加我", "Allow adding me via contact card", "連絡先カードからの追加を許可");
        add("允许通过好友码或二维码添加我", "Allow adding me via friend code or QR", "友達コード・QRからの追加を許可");
        add("允许通过 UID 或账号名称找到我", "Allow search by UID or account name", "UID・アカウント名での検索を許可");
        add("允许通过已绑定手机号找到我", "Allow search by linked phone", "連携済み電話番号での検索を許可");
        add("允许通过已绑定邮箱找到我", "Allow search by linked email", "連携済みメールでの検索を許可");
        add("允许通过群成员列表添加我", "Allow adding me from group members", "グループメンバーからの追加を許可");
        add("申请与会话权限", "Requests & conversations", "申請と会話の権限");
        add("允许其他用户申请加我为好友", "Allow other users to send me friend requests", "他のユーザーからの友達申請を許可");
        add("接收陌生人消息", "Receive messages from strangers", "知らない人からのメッセージを受信");
        add("允许其他用户邀请我加入群聊", "Allow other users to invite me to groups", "他のユーザーからのグループ招待を許可");
        add("向其他用户显示在线状态", "Show my online status", "オンライン状態を表示");
        add("发送和接收消息已读状态", "Send and receive read receipts", "既読情報を送受信");
        add("撤回消息提示后缀", "Recall message suffix", "メッセージ取消表示の末尾");
        add("保存消息与隐私设置", "Save messages and privacy settings", "メッセージとプライバシー設定を保存");
        add("系统与客服通知", "System and support notifications", "システムとサポートの通知");
        add("私聊消息通知", "Private message notifications", "個人メッセージ通知");
        add("群聊消息通知", "Group message notifications", "グループメッセージ通知");
        add("聊天室消息通知", "Chat room notifications", "チャットルーム通知");
        add("论坛互动通知", "Forum interaction notifications", "フォーラム交流通知");
        add("悬赏进度与互动通知", "Bounty progress and interaction notifications", "依頼の進捗と交流通知");
        add("被 @ 时强提醒", "Priority alerts when mentioned", "メンション時に強く通知");
        add("通知呈现", "Notification presentation", "通知の表示");
        add("通知栏显示消息预览", "Show message previews in notifications", "通知にメッセージのプレビューを表示");
        add("播放通知提示音", "Play notification sound", "通知音を鳴らす");
        add("收到通知时振动", "Vibrate for notifications", "通知時に振動");
        add("通知、来电与后台运行权限", "Notification, incoming call and background permissions", "通知・着信・バックグラウンド実行の権限");
        add("保存通知设置", "Save notification settings", "通知設定を保存");
        add("找回或重置密码", "Recover or reset password", "パスワードを再設定");
        add("拍照或录像后保存到系统相册", "Save captured photos and videos to the device gallery", "撮影した写真と動画を端末のアルバムに保存");
        add("关闭后，拍摄内容只保存在应用缓存中用于发送，不会写入系统相册；清理缓存时会一并删除。", "When off, captured media is kept only in the app cache for sending and is removed when the cache is cleared.", "オフにすると、撮影データは送信用のアプリキャッシュにのみ保存され、キャッシュ消去時に削除されます。");
        add("存储、下载与缓存", "Storage, downloads and cache", "ストレージ・ダウンロード・キャッシュ");
        add("软件更新与维护状态", "App update and maintenance status", "アプリ更新とメンテナンス状況");
        add("允许好友申请", "Allow friend requests", "友達申請を許可");
        add("允许陌生人发消息", "Allow messages from strangers", "知らない人からのメッセージを許可");
        add("黑名单管理", "Blocked users", "ブロックリスト");
        add("特别关心", "Special care", "特別な関心");
        add("消息免打扰", "Do not disturb", "通知をミュート");
        add("保存", "Save", "保存");
        add("取消", "Cancel", "キャンセル");
        add("确定", "OK", "確定");
        add("删除", "Delete", "削除");
        add("编辑", "Edit", "編集");
        add("新增", "Add", "追加");
        add("发送", "Send", "送信");
        add("返回", "Back", "戻る");
        add("刷新", "Refresh", "更新");
        add("重试", "Retry", "再試行");
        add("加载中", "Loading", "読み込み中");
        add("正在加载", "Loading", "読み込み中");
        add("暂无数据", "No data", "データがありません");
        add("暂无消息", "No messages", "メッセージはありません");
        add("登录", "Sign in", "ログイン");
        add("注册", "Register", "登録");
        add("退出登录", "Sign out", "ログアウト");
        add("账号", "Account", "アカウント");
        add("密码", "Password", "パスワード");
        add("修改密码", "Change password", "パスワード変更");
        add("找回密码", "Recover password", "パスワードを再設定");
        add("个人资料", "Profile", "プロフィール");
        add("好友列表", "Contacts", "友達一覧");
        add("好友分组", "Contact groups", "友達グループ");
        add("好友通知", "Friend notifications", "友達通知");
        add("群聊通知", "Group notifications", "グループ通知");
        add("通知中心", "Notification center", "通知センター");
        add("全部已读", "Mark all read", "すべて既読");
        add("论坛", "Forum", "フォーラム");
        add("悬赏", "Bounties", "依頼");
        add("资源", "Resources", "リソース");
        add("应用商店", "App store", "アプリストア");
        add("源码商城", "Source marketplace", "ソースマーケット");
        add("余额商店", "Balance store", "残高ストア");
        add("投票", "Polls", "投票");
        add("抽奖", "Lucky draw", "抽選");
        add("红包", "Red packet", "レッドパケット");
        add("转账", "Transfer", "送金");
        add("礼物", "Gift", "ギフト");
        add("收藏", "Favorites", "お気に入り");
        add("订单", "Orders", "注文");
        add("笔记", "Notes", "ノート");
        add("发布帖子", "Create post", "投稿を作成");
        add("发起投票", "Create poll", "投票を作成");
        add("发布悬赏", "Create bounty", "依頼を作成");
        add("分类", "Categories", "カテゴリ");
        add("标签", "Tags", "タグ");
        add("评论", "Comments", "コメント");
        add("回复", "Replies", "返信");
        add("点赞", "Likes", "いいね");
        add("关注", "Following", "フォロー");
        add("粉丝", "Followers", "フォロワー");
        add("获赞", "Likes received", "獲得したいいね");
        add("取消关注", "Unfollow", "フォロー解除");
        add("编辑资料", "Edit profile", "プロフィールを編集");
        add("发消息", "Message", "メッセージ");
        add("已是好友", "Already friends", "友達です");
        add("已发送申请", "Request sent", "申請済み");
        add("处理好友申请", "Review friend request", "友達申請を確認");
        add("对方已关闭申请", "Friend requests disabled", "相手は友達申請を無効にしています");
        add("加好友", "Add friend", "友達に追加");
        add("公开资料", "Public profile", "公開プロフィール");
        add("注册时间", "Joined", "登録日時");
        add("手机号码", "Phone", "電話番号");
        add("邮箱", "Email", "メール");
        add("性别", "Gender", "性別");
        add("生日", "Birthday", "誕生日");
        add("等级", "Level", "レベル");
        add("经验", "Experience", "経験値");
        add("会员到期", "Membership expires", "会員期限");
        add("资料状态", "Profile status", "プロフィール状態");
        add("生活动态", "Life activity", "ライフ投稿");
        add("查看公开动态、图片、视频、点赞与评论", "View public activity, media, likes and comments", "公開投稿・メディア・いいね・コメントを表示");
        add("动态笔记", "Activity notes", "投稿ノート");
        add("论坛帖子", "Forum posts", "フォーラム投稿");
        add("暂无公开内容", "No public content", "公開コンテンツはありません");
        add("通知详情", "Notification details", "通知の詳細");
        add("通知时间", "Notification time", "通知日時");
        add("阅读状态", "Read status", "既読状態");
        add("通知类型", "Notification type", "通知タイプ");
        add("相关内容", "Related content", "関連内容");
        add("发起人", "Initiator", "送信者");
        add("当前状态", "Current status", "現在の状態");
        add("处理说明", "Handling note", "処理メモ");
        add("评论内容", "Comment", "コメント内容");
        add("回复内容", "Reply", "返信内容");
        add("金额", "Amount", "金額");
        add("余额变动", "Balance change", "残高変動");
        add("数量", "Quantity", "数量");
        add("群聊/聊天室", "Group / chat room", "グループ／チャットルーム");
        add("商品", "Product", "商品");
        add("订单号", "Order number", "注文番号");
        add("版本", "Version", "バージョン");
        add("维护开始", "Maintenance starts", "メンテナンス開始");
        add("维护结束", "Maintenance ends", "メンテナンス終了");
        add("生效时间", "Effective time", "適用日時");
        add("有效期至", "Valid until", "有効期限");
        add("系统通知", "System notification", "システム通知");
        add("动态互动", "Activity interaction", "投稿への反応");
        add("论坛通知", "Forum notification", "フォーラム通知");
        add("悬赏通知", "Bounty notification", "依頼通知");
        add("好友与群聊通知", "Friend & group notification", "友達・グループ通知");
        add("资产与订单通知", "Assets & orders", "資産・注文通知");
        add("聊天互动通知", "Chat interaction", "チャット通知");
        add("活动通知", "Event notification", "イベント通知");
        add("其他通知", "Other notification", "その他の通知");
        add("待处理", "Pending", "保留中");
        add("已通过", "Approved", "承認済み");
        add("未通过", "Rejected", "却下");
        add("已完成", "Completed", "完了");
        add("已取消", "Cancelled", "キャンセル済み");
        add("已过期", "Expired", "期限切れ");
        add("进行中", "Active", "進行中");
        add("举报", "Report", "通報");
        add("审核通过", "Approve", "承認");
        add("审核不通过", "Reject", "却下");
        add("软件更新与维护", "Updates & maintenance", "更新とメンテナンス");
        add("缓存管理", "Cache management", "キャッシュ管理");
        add("权限中心", "Permission center", "権限センター");
        add("相册", "Gallery", "アルバム");
        add("完整相册", "Full gallery", "すべての写真");
        add("拍摄", "Camera", "撮影");
        add("文件", "Files", "ファイル");
        add("名片", "Contact card", "連絡先カード");
        add("语音通话", "Voice call", "音声通話");
        add("视频通话", "Video call", "ビデオ通話");
        add("原图/原视频", "Original quality", "オリジナル画質");
        add("外观", "Theme", "テーマ");
        add("当前版本", "Current version", "現在のバージョン");
        add("已选择", "Selected", "選択済み");
        add("新消息", "New messages", "新着メッセージ");
        add("未读消息", "Unread messages", "未読メッセージ");
        add("聊天背景", "Chat background", "チャット背景");
        add("打开消息列表", "Open messages", "メッセージ一覧を開く");
        add("我的笔记", "My notes", "マイノート");
        add("查看和编辑笔记", "View and edit notes", "ノートを表示・編集");
        add("扫一扫", "Scan", "スキャン");
        add("扫描好友二维码", "Scan a friend QR code", "友達のQRコードをスキャン");
        add("账号、通知、权限与存储设置", "Account, notification, permission and storage settings", "アカウント・通知・権限・ストレージ設定");
        add("工作台", "Workspace", "ワークスペース");
        add("打开管理工作台", "Open management workspace", "管理ワークスペースを開く");
        add("应用管理", "App management", "アプリ管理");
        add("查看和管理应用", "View and manage apps", "アプリを表示・管理");
        add("管理员", "Administrators", "管理者");
        add("用户管理", "User management", "ユーザー管理");
        add("查看下级账号与资料", "View subordinate accounts and profiles", "下位アカウントとプロフィールを表示");
        add("授权平台", "Authorized platforms", "認定プラットフォーム");
        add("文档管理", "Document management", "ドキュメント管理");
        add("打开常用管理功能", "Open common management features", "よく使う管理機能を開く");
        add("存储与缓存管理", "Storage and cache management", "ストレージとキャッシュ管理");
        add("全局聊天背景已更新", "Global chat background updated", "全体チャット背景を更新しました");
        add("基础权限已允许", "Basic permissions granted", "基本権限を許可しました");
        add("部分权限未允许，可随时从权限中心继续设置", "Some permissions were not granted. You can continue in Permission center at any time.", "一部の権限が許可されていません。権限センターからいつでも設定できます");
        add("后续拍照和录像会保存到系统相册", "Future photos and videos will be saved to the device gallery", "今後撮影する写真と動画は端末のアルバムに保存されます");
        add("后续拍摄只用于发送，不会保存到系统相册", "Future captures will only be used for sending and will not be saved to the device gallery", "今後の撮影データは送信のみに使用し、端末のアルバムには保存しません");
        add("登录时和软件运行期间会自动检查维护状态与强制更新要求。", "Maintenance and mandatory update requirements are checked at sign-in and while the app is running.", "ログイン時およびアプリ実行中に、メンテナンス状況と必須更新を自動確認します。");
        add("知道了", "Got it", "了解");
        add("基础功能权限", "Basic permissions", "基本機能の権限");
        add("系统通知", "System notifications", "システム通知");
        add("全屏来电提醒", "Full-screen incoming call alerts", "全画面の着信通知");
        add("通话悬浮窗", "Call overlay", "通話フローティング表示");
        add("省电白名单", "Battery optimization exemption", "バッテリー最適化の除外");
        add("自启动与后台运行", "Auto-start & background activity", "自動起動とバックグラウンド実行");
        add("勿扰模式访问", "Do Not Disturb access", "サイレントモードへのアクセス");
        add("应用权限详情", "App permission details", "アプリ権限の詳細");
        add("通知、来电与后台运行", "Notifications, calls & background activity", "通知・通話・バックグラウンド実行");
        add("即时消息和网络通话依赖这些权限。系统会逐项征求你的确认，应用不会读取与功能无关的数据。", "Instant messages and internet calls depend on these permissions. Android asks for each permission separately, and the app does not read unrelated data.", "インスタントメッセージとインターネット通話にはこれらの権限が必要です。Android が権限ごとに確認し、アプリが機能に無関係なデータを読み取ることはありません。");
        add("基础功能权限均已允许", "All basic permissions are granted", "基本機能の権限はすべて許可されています");
        add("已允许", "Allowed", "許可済み");
        add("已开启", "On", "オン");
        add("未开启", "Off", "オフ");
        add("请在系统设置中允许自启动、后台活动和后台联网", "Allow auto-start, background activity and background network access in system settings", "システム設定で自動起動、バックグラウンド動作、バックグラウンド通信を許可してください");
        add("设置加载失败", "Failed to load settings", "設定を読み込めませんでした");
        add("设置已保存", "Settings saved", "設定を保存しました");
        add("设置保存失败", "Failed to save settings", "設定を保存できませんでした");
        add("桌面图标已切换，部分桌面可能需要几秒刷新", "App icon changed. Some launchers may take a few seconds to refresh.", "アプリアイコンを変更しました。ホーム画面への反映に数秒かかる場合があります");
        add("当前安装包不支持这个桌面图标", "This app build does not support that launcher icon", "このアプリ版ではそのアイコンを利用できません");
        add("通知详情", "Notification details", "通知の詳細");
        add("暂无通知正文", "No notification content", "通知本文はありません");
        add("通知时间", "Notification time", "通知日時");
        add("阅读状态", "Read status", "既読状態");
        add("通知类型", "Notification type", "通知の種類");
        add("系统通知", "System notification", "システム通知");
        add("动态互动", "Activity interaction", "投稿への反応");
        add("论坛通知", "Forum notification", "フォーラム通知");
        add("悬赏通知", "Bounty notification", "依頼通知");
        add("好友与群聊通知", "Friend & group notification", "友達・グループ通知");
        add("资产与订单通知", "Assets & orders", "資産・注文通知");
        add("聊天互动通知", "Chat interaction", "チャット通知");
        add("活动通知", "Event notification", "イベント通知");
        add("已读", "Read", "既読");
        add("未读", "Unread", "未読");
        add("相关内容", "Related details", "関連情報");
        add("复制", "Copy", "コピー");
        add("关闭", "Close", "閉じる");
        add("查看相关内容", "View related item", "関連内容を表示");
        add("未知", "Unknown", "不明");
        add("发起人", "Initiator", "申請者");
        add("相关用户", "Related user", "関連ユーザー");
        add("当前状态", "Current status", "現在の状態");
        add("原因说明", "Reason", "理由");
        add("审核说明", "Review note", "審査メモ");
        add("处理说明", "Processing note", "処理メモ");
        add("评论内容", "Comment", "コメント内容");
        add("回复内容", "Reply", "返信内容");
        add("金额", "Amount", "金額");
        add("余额变动", "Balance change", "残高の変動");
        add("奖励余额", "Reward balance", "報酬残高");
        add("数量", "Quantity", "数量");
        add("次数", "Count", "回数");
        add("群聊/聊天室", "Group / chat room", "グループ／チャットルーム");
        add("群聊", "Group", "グループ");
        add("帖子", "Post", "投稿");
        add("悬赏任务", "Bounty task", "依頼タスク");
        add("商品", "Product", "商品");
        add("订单号", "Order number", "注文番号");
        add("版本", "Version", "バージョン");
        add("维护开始", "Maintenance starts", "メンテナンス開始");
        add("维护结束", "Maintenance ends", "メンテナンス終了");
        add("生效时间", "Effective at", "適用日時");
        add("有效期至", "Valid until", "有効期限");
        add("待处理", "Pending", "処理待ち");
        add("已通过", "Approved", "承認済み");
        add("未通过", "Rejected", "却下");
        add("已完成", "Completed", "完了");
        add("已取消", "Cancelled", "キャンセル済み");
        add("已过期", "Expired", "期限切れ");
        add("进行中", "Active", "進行中");
        add("暂时没有可展示的回答，请换一种问法后重试。", "There is no displayable answer yet. Please rephrase your question and try again.", "表示できる回答がありません。質問を言い換えてもう一度お試しください");
        add("请输入问题", "Enter a question", "質問を入力してください");
        add("天气查询结果", "Weather result", "天気検索結果");
        add("回答", "Answer", "回答");
        add("实时天气", "Live weather", "現在の天気");
        add("智能问答", "AI assistant", "AIアシスタント");
        add("正在发送问题", "Sending question", "質問を送信中");
        add("正在整理", "Preparing answer", "回答を作成中");
        add("发送问题", "Send question", "質問を送信");
        add("试着这样问", "Try asking", "こんな質問を試してください");
        add("今日快报", "Daily briefing", "今日のニュース");
        add("北京明天天气", "Beijing weather tomorrow", "北京の明日の天気");
        add("西安三日攻略", "Three-day Xi'an guide", "西安3日間ガイド");
        add("故宫的历史", "History of the Forbidden City", "故宮の歴史");
        add("怎么创建笔记", "How to create a note", "ノートの作成方法");
        add("你好，需要我帮你查什么？", "Hello, what would you like me to look up?", "こんにちは、何を調べましょうか？");
        add("可以问我应用使用方法、指定城市天气、旅行攻略和历史资料。", "Ask me about the app, weather in any city, travel, history, current news or other topics.", "アプリの使い方、各都市の天気、旅行、歴史、最新ニュースなどを質問できます。");
        add("输入天气、新闻、旅行、历史或其他问题", "Ask about weather, news, travel, history or anything else", "天気・ニュース・旅行・歴史などを質問してください");
        add("新闻来源", "News source", "ニュース提供元");
        add("打开原始报道", "Open original report", "元の記事を開く");
        add("暂时无法打开这条新闻", "This news item cannot be opened right now", "このニュースは現在開けません");
        add("机器人问答", "AI assistant", "AIアシスタント");
        add("提示", "Notice", "お知らせ");
        add("暂时无法完成查询", "Unable to complete the query", "検索を完了できません");
        add("接口工作台", "API workspace", "APIワークスペース");
        add("接口", "API", "API");
        add("请求路径", "Request path", "リクエストパス");
        add("请求参数（高级模式）", "Request parameters (advanced)", "リクエストパラメータ（詳細）");
        add("发送请求", "Send request", "リクエストを送信");
        add("请求成功", "Request succeeded", "リクエスト成功");
        add("请求失败", "Request failed", "リクエスト失敗");
        add("跟踪编号", "Trace ID", "追跡ID");
        add("点击可打开完整可视化详情", "Tap to open the complete visual details", "タップして完全な表示内容を開く");
        add("接口返回详情", "API response details", "APIレスポンス詳細");
        add("请求路径不能为空", "Request path cannot be empty", "リクエストパスを入力してください");
        add("请先补全路径中的参数", "Complete the path parameters first", "先にパスパラメータを入力してください");
        add("JSON 请求体格式错误", "Invalid JSON request body", "JSONリクエスト本文の形式が正しくありません");
        add("确认删除请求", "Delete this request?", "このリクエストを削除しますか");
        add("继续", "Continue", "続行");
        add("暂无内容", "No content", "内容はありません");
        add("复制详情", "Copy details", "詳細をコピー");
        add("管理操作", "Management actions", "管理操作");
        add("操作", "Action", "操作");
        add("网络错误", "Network error", "ネットワークエラー");
        add("板块", "Sections", "セクション");
        add("版主", "Moderator", "モデレーター");
        add("备注", "Notes", "メモ");
        add("本地", "Local", "ローカル");
        add("标题", "Title", "タイトル");
        add("表情", "Emoji", "絵文字");
        add("草稿", "Draft", "下書き");
        add("查看", "View", "表示");
        add("查询", "Search", "検索");
        add("撤回", "Recall", "取り消し");
        add("成员", "Members", "メンバー");
        add("处理", "Process", "処理");
        add("创建", "Create", "作成");
        add("打赏", "Tip", "応援");
        add("单选", "Single choice", "単一選択");
        add("当前", "Current", "現在");
        add("到期", "Expires", "有効期限");
        add("等级", "Level", "レベル");
        add("动图", "Motion photo", "モーションフォト");
        add("兑换", "Redeem", "交換");
        add("多选", "Multiple choice", "複数選択");
        add("发布", "Publish", "公開");
        add("发帖", "Create post", "投稿");
        add("分享", "Share", "共有");
        add("公告", "Announcements", "お知らせ");
        add("公开", "Public", "公開");
        add("功能", "Features", "機能");
        add("购买", "Purchase", "購入");
        add("管理", "Manage", "管理");
        add("好友", "Friends", "友達");
        add("会员", "Membership", "会員");
        add("加精", "Feature", "おすすめ");
        add("接听", "Answer", "応答");
        add("经验", "XP", "経験値");
        add("客服", "Support", "サポート");
        add("快照", "Snapshot", "スナップショット");
        add("类型", "Type", "種類");
        add("链接", "Link", "リンク");
        add("领取", "Claim", "受け取る");
        add("名称", "Name", "名前");
        add("明天", "Tomorrow", "明日");
        add("内容", "Content", "内容");
        add("昵称", "Nickname", "ニックネーム");
        add("排序", "Sort", "並び替え");
        add("评分", "Rating", "評価");
        add("全部", "All", "すべて");
        add("群主", "Group owner", "グループ所有者");
        add("群组", "Groups", "グループ");
        add("热议", "Trending", "話題");
        add("商城", "Marketplace", "マーケット");
        add("商店", "Store", "ストア");
        add("上传", "Upload", "アップロード");
        add("审核", "Review", "審査");
        add("视频", "Video", "動画");
        add("收起", "Collapse", "折りたたむ");
        add("私聊", "Private chat", "個別チャット");
        add("天气", "Weather", "天気");
        add("音频", "Audio", "音声");
        add("图片", "Images", "画像");
        add("附件", "Attachments", "添付ファイル");
        add("详情", "Details", "詳細");
        add("权限", "Permissions", "権限");
        add("申请", "Request", "申請");
        add("邀请", "Invite", "招待");
        add("资料解析失败，请重新打开后再试", "Unable to parse these details. Reopen the page and try again.", "詳細を解析できませんでした。ページを開き直してもう一度お試しください");
        add("用户监管资料", "Managed user profile", "管理対象ユーザーのプロフィール");
        add("权限与限制", "Permissions & restrictions", "権限と制限");
        add("用户编号", "User ID", "ユーザーID");
        add("点按继续查看该用户的完整关系与内容", "Tap to view this user's full relationships and content", "タップしてこのユーザーの関係とコンテンツをすべて表示");
        add("私聊会话", "Private conversations", "個別チャット");
        add("与", "and", "と");
        add("暂无最近消息", "No recent messages", "最近のメッセージはありません");
        add("点按进入这段私聊", "Tap to open this conversation", "タップしてこの個別チャットを開く");
        add("条", "items", "件");
        add("点按以", "Tap to open as", "タップして");
        add("的视角进入群聊", "in this group", "の視点でグループを開く");
        add("聊天室", "Chat room", "チャットルーム");
        add("点按以管理员视角进入聊天室", "Tap to open this chat room as an administrator", "タップして管理者の視点でチャットルームを開く");
        add("客服会话", "Customer service conversation", "カスタマーサービスの会話");
        add("状态", "Status", "状態");
        add("点按查看客服消息", "Tap to view customer service messages", "タップしてカスタマーサービスのメッセージを表示");
        add("点按查看完整中文详情，长按快速查看", "Tap to view complete details; touch and hold for a quick view", "タップして詳細を表示、長押しでクイック表示");
        add("信息", " details", "の詳細");
        add("管理员视角", "Administrator view", "管理者ビュー");
        add("暂无记录", "No records", "記録はありません");
        add("用户监管资料加载失败", "Failed to load managed user profile", "管理対象ユーザーのプロフィールを読み込めませんでした");
        add("关注的人", "Following", "フォロー中");
        add("群成员", "Group members", "グループメンバー");
    }

    private RuntimeLanguage() { }

    public static String language(Context context) {
        LocaleListCompat locales = androidx.appcompat.app.AppCompatDelegate.getApplicationLocales();
        if (!locales.isEmpty() && locales.get(0) != null) {
            String tag = locales.get(0).toLanguageTag();
            if (tag.startsWith("en")) return "en";
            if (tag.startsWith("ja")) return "ja";
        }
        String value = context.getSharedPreferences(AppearanceStyleStore.PREFERENCES, Context.MODE_PRIVATE)
            .getString(AppearanceStyleStore.KEY_LANGUAGE, "zh-CN");
        if (value != null && value.startsWith("en")) return "en";
        if (value != null && value.startsWith("ja")) return "ja";
        return "zh-CN";
    }

    public static CharSequence translate(Context context, CharSequence source) {
        if (source == null) return null;
        String language = language(context);
        String value = canonical(source.toString());
        if ("zh-CN".equals(language)) return value;
        String translated = ("ja".equals(language) ? JA : EN).get(value);
        if (translated != null) return translated;
        return translatePrefix(language, value);
    }

    /**
     * Assigns text supplied by a user or by business data. Runtime localization must never
     * translate account names, nicknames, group names, room names or user-authored content.
     */
    public static void setDynamicText(TextView view, CharSequence value) {
        if (view == null) return;
        protectDynamicText(view);
        view.setText(value == null ? "" : value);
    }

    /** Marks a view (including a Toolbar title) as business data rather than UI copy. */
    public static void protectDynamicText(View view) {
        if (view == null) return;
        view.setTag(R.id.tag_runtime_dynamic_text, Boolean.TRUE);
        view.setTag(R.id.tag_runtime_localized_text, null);
    }

    public static void setDynamicToolbarTitle(Toolbar toolbar, CharSequence value) {
        if (toolbar == null) return;
        protectDynamicText(toolbar);
        toolbar.setTitle(value == null ? "" : value);
    }

    public static void applyTree(Context context, View root) {
        if (root == null) return;
        if (root instanceof Toolbar) applyToolbar(context, (Toolbar) root);
        if (root instanceof BottomNavigationView) applyMenu(context, ((BottomNavigationView) root).getMenu());
        if (root instanceof TextInputLayout) {
            TextInputLayout input = (TextInputLayout) root;
            applyHint(context, input);
        }
        if (root instanceof TextView) {
            TextView text = (TextView) root;
            // Never translate editable content. Recreating a screen after a language,
            // theme or font change must preserve drafts exactly as the user entered them.
            if (!(text instanceof EditText) && !isDynamicText(text)) applyText(context, text);
            applyTextHint(context, text);
        }
        if (root instanceof ViewGroup) {
            ViewGroup group = (ViewGroup) root;
            for (int index = 0; index < group.getChildCount(); index++) {
                applyTree(context, group.getChildAt(index));
            }
        }
    }

    private static void applyToolbar(Context context, Toolbar toolbar) {
        if (!isDynamicText(toolbar)) applyToolbarValue(context, toolbar, R.id.tag_runtime_localized_text, true);
        applyToolbarValue(context, toolbar, R.id.tag_runtime_localized_hint, false);
        applyMenu(context, toolbar.getMenu());
    }

    private static boolean isDynamicText(View view) {
        return Boolean.TRUE.equals(view.getTag(R.id.tag_runtime_dynamic_text));
    }

    private static void applyMenu(Context context, Menu menu) {
        for (int index = 0; index < menu.size(); index++) {
            MenuItem item = menu.getItem(index);
            item.setTitle(translate(context, item.getTitle()));
            if (item.hasSubMenu()) applyMenu(context, item.getSubMenu());
        }
    }

    private static TextState state(View view, int key, CharSequence current) {
        if (current == null) return null;
        Object value = view.getTag(key);
        if (!(value instanceof TextState)) {
            TextState created = new TextState(canonical(current.toString()), current.toString());
            view.setTag(key, created);
            return created;
        }
        TextState state = (TextState) value;
        if (!current.toString().equals(state.applied)) state.original = canonical(current.toString());
        return state;
    }

    private static void applyText(Context context, TextView view) {
        TextState state = state(view, R.id.tag_runtime_localized_text, view.getText());
        if (state == null) return;
        CharSequence translated = translate(context, state.original);
        state.applied = translated.toString();
        if (!translated.toString().contentEquals(view.getText())) view.setText(translated);
    }

    private static void applyTextHint(Context context, TextView view) {
        TextState state = state(view, R.id.tag_runtime_localized_hint, view.getHint());
        if (state == null) return;
        CharSequence translated = translate(context, state.original);
        state.applied = translated.toString();
        if (view.getHint() == null || !translated.toString().contentEquals(view.getHint())) view.setHint(translated);
    }

    private static void applyHint(Context context, TextInputLayout view) {
        TextState state = state(view, R.id.tag_runtime_localized_hint, view.getHint());
        if (state == null) return;
        CharSequence translated = translate(context, state.original);
        state.applied = translated.toString();
        if (view.getHint() == null || !translated.toString().contentEquals(view.getHint())) view.setHint(translated);
    }

    private static void applyToolbarValue(Context context, Toolbar toolbar, int key, boolean title) {
        CharSequence current = title ? toolbar.getTitle() : toolbar.getSubtitle();
        TextState state = state(toolbar, key, current);
        if (state == null) return;
        CharSequence translated = translate(context, state.original);
        state.applied = translated.toString();
        if (title) toolbar.setTitle(translated); else toolbar.setSubtitle(translated);
    }

    private static String translatePrefix(String language, String value) {
        Map<String, String> values = "ja".equals(language) ? JA : EN;
        String[] prefixes = {
            "外观：", "界面语言：", "软件主色：", "全局聊天背景：", "桌面图标：", "字体：",
            "当前版本：", "已选择", "新消息", "未读消息", "聊天背景：", "接口：", "请求路径：",
            "跟踪编号：", "请求成功 · HTTP ", "请求失败 · HTTP "
        };
        for (String prefix : prefixes) {
            if (!value.startsWith(prefix)) continue;
            boolean colon = prefix.endsWith("：");
            boolean http = prefix.endsWith("HTTP ");
            String sourceKey = colon ? prefix.substring(0, prefix.length() - 1)
                : (http ? prefix.substring(0, prefix.indexOf(" · HTTP ")) : prefix);
            String translated = values.get(sourceKey);
            if (translated == null) translated = sourceKey;
            String suffix = canonical(value.substring(prefix.length()).trim());
            String translatedSuffix = values.get(suffix);
            if (http) return translated + " · HTTP " + suffix;
            String resolvedSuffix = translatedSuffix == null ? suffix : translatedSuffix;
            if (colon) return translated + ": " + resolvedSuffix;
            return resolvedSuffix.isEmpty() ? translated : translated + " " + resolvedSuffix;
        }
        if (value.matches("第\\s*\\d+\\s*项")) {
            String number = value.replaceAll("[^0-9]", "");
            return "ja".equals(language) ? number + "番目" : "Item " + number;
        }
        if (value.matches("\\d+\\s*/\\s*\\d+\\s*已允许")) {
            String counts = value.substring(0, value.indexOf('已')).trim();
            return counts + " " + ("ja".equals(language) ? "許可済み" : "allowed");
        }
        return value;
    }

    private static void add(String zh, String en, String ja) {
        EN.put(zh, en);
        JA.put(zh, ja);
        EN_TO_ZH.put(en, zh);
        JA_TO_ZH.put(ja, zh);
    }

    private static String canonical(String value) {
        String zh = EN_TO_ZH.get(value);
        if (zh != null) return zh;
        zh = JA_TO_ZH.get(value);
        return zh == null ? value : zh;
    }

    private static final class TextState {
        String original;
        String applied;

        TextState(String original, String applied) {
            this.original = original;
            this.applied = applied;
        }
    }
}
