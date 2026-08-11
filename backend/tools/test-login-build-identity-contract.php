<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $relative): string => (string) file_get_contents($root . '/' . $relative);
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$gradle = $read('android/app/build.gradle');
$manifest = $read('android/app/src/main/AndroidManifest.xml');
$login = $read('android/app/src/main/java/xyz/jjmxg/yiyunying/ui/auth/LoginActivity.java');
$main = $read('android/app/src/main/java/xyz/jjmxg/yiyunying/ui/main/MainActivity.java');
$repository = $read('android/app/src/main/java/xyz/jjmxg/yiyunying/data/repository/AuthRepository.java');
$session = $read('android/app/src/main/java/xyz/jjmxg/yiyunying/data/session/SessionManager.java');
$platform = $read('backend/app/Controllers/Platform/AuthController.php');
$admin = $read('backend/app/Controllers/Admin/AuthController.php');
$platformAdmin = $read('backend/app/Controllers/Platform/AdminController.php');
$adminApps = $read('backend/app/Controllers/Admin/AppController.php');
$provision = $read('backend/app/Services/AdminProvisionService.php');
$register = $read('android/app/src/main/java/xyz/jjmxg/yiyunying/ui/auth/RegisterActivity.java');
$moduleRegistry = $read('android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java');
$genericModule = $read('android/app/src/main/java/xyz/jjmxg/yiyunying/ui/module/GenericModuleFragment.java');
$user = $read('backend/app/Controllers/User/AuthController.php');
$auth = $read('backend/app/Services/AuthService.php');
$platformService = $read('backend/app/Services/PlatformService.php');
$apiDocs = $read('backend/docs/API_FULL.md');

foreach (['DEFAULT_API_BASE_URL', 'DEFAULT_APP_KEY', 'DEFAULT_PLATFORM_KEY'] as $field) {
    $check(str_contains($gradle, "buildConfigField 'String', '{$field}'"), "构建配置缺少 {$field}");
}
$check(str_contains($login, 'String server = BuildConfig.DEFAULT_API_BASE_URL;'), '登录请求未固定使用构建服务器地址');
$check(str_contains($login, 'String platformKey = BuildConfig.DEFAULT_PLATFORM_KEY;'), '登录请求未固定使用构建平台 KEY');
$check(str_contains($login, 'String appKey = BuildConfig.DEFAULT_APP_KEY;'), '登录请求未固定使用构建应用 KEY');
$check(str_contains($login, 'applyBuildIdentity(session)'), '启动时未将旧连接身份收敛到构建配置');
$check(str_contains($login, 'selectedRole.mePath()'), '已有会话未通过实时 me 接口验证');
$check(str_contains($login, 'liveIdentityMatches(session, result.dataObject())'), '实时会话结果未校验账号角色与租户身份');
$check(!str_contains($login, 'String server = text(binding.serverInput.getText())'), '隐藏服务器输入仍可进入登录请求');
$check(!str_contains($login, 'String appKey = text(binding.appKeyInput.getText())'), '隐藏应用 KEY 输入仍可进入登录请求');
$check(!str_contains($login, 'String platformKey = text(binding.platformKeyInput.getText())'), '隐藏平台 KEY 输入仍可进入登录请求');
$check(str_contains($session, 'reconcileEditionIdentity();'), '进程启动未清理旧版本连接身份');
$check(str_contains($session, 'if (hadConnectionIdentity && isAuthenticated() && (endpointChanged || tenantChanged))'),
    '旧地址或租户 KEY 变化时未撤销本地认证');
$check(str_contains($session, '.putString(KEY_BASE_URL, buildBase)'), '本地服务器地址未回写当前构建配置');
$check(str_contains($session, '.putString(KEY_APP_KEY, BuildConfig.DEFAULT_APP_KEY)'), '本地应用 KEY 未回写当前构建配置');
$check(str_contains($session, '.putString(KEY_PLATFORM_KEY, BuildConfig.DEFAULT_PLATFORM_KEY)'), '本地平台 KEY 未回写当前构建配置');
$check(str_contains($session, 'catch (IllegalArgumentException exception)'), '无效构建服务器地址会在登录页前导致崩溃');
$check(str_contains($session, 'AppEdition.role() == Role.ADMIN'), '管理员安装应用 KEY 变化未纳入旧会话收敛');
$check(str_contains($login, 'if (forceLogin) {') && str_contains($login, 'session.clearAuthentication();'),
    '强制登录未清除旧认证会话');

$check(str_contains($repository, 'role == Role.ADMIN || role == Role.PLATFORM'), '平台登录请求未携带构建平台 KEY');
$check(substr_count($repository, 'role == Role.ADMIN || role == Role.USER') >= 1, '管理员登录请求未携带构建应用 API 唯一 ID');
$check(str_contains($repository, 'body.addProperty("platform_key", platformKey.trim())'), '管理/平台登录请求缺少 platform_key');
$check(str_contains($repository, 'body.addProperty("app_key", appKey.trim())'), '用户登录请求缺少 app_key');
$check(str_contains($repository, 'fields.addProperty("app_key", appKey.trim())'), '管理员注册请求未携带构建应用 KEY');
$check(str_contains($register, 'return BuildConfig.DEFAULT_API_BASE_URL;'), '注册页未固定使用构建服务器地址');
$check(str_contains($register, 'return BuildConfig.DEFAULT_APP_KEY;'), '注册页未固定使用构建应用 KEY');
$check(str_contains($register, 'return BuildConfig.DEFAULT_PLATFORM_KEY;'), '注册页未固定使用构建平台 KEY');
$check(!str_contains($register, 'getIntent().getStringExtra(EXTRA_BASE_URL)'), '恶意注册页服务器地址 extra 可覆盖构建配置');
$check(!str_contains($register, 'getIntent().getStringExtra(EXTRA_APP_KEY)'), '恶意注册页应用 KEY extra 可覆盖构建配置');
$check(!str_contains($register, 'getIntent().getStringExtra(EXTRA_PLATFORM_KEY)'), '恶意注册页平台 KEY extra 可覆盖构建配置');
$check(str_contains($moduleRegistry, 'req("app_key", "首个应用 API 唯一 ID")'), '平台管理员创建表单缺少首个应用 API 唯一 ID');

$check(str_contains($platform, "Validator::required(\$data, ['platform_key', 'account', 'password'])"), '平台登录未强制平台 KEY、账号和密码');
$check(str_contains($platform, "hash_equals((string) \$platform['platform_key'], \$platformKey)"), '平台登录未实际比对平台唯一 KEY');
$check(str_contains($admin, "Validator::required(\$data, ['platform_key', 'app_key', 'account', 'password'])"),
    '管理员登录未强制平台 KEY、应用 API 唯一 ID、账号和密码');
$check(str_contains($admin, "PlatformService::byKey((string) (\$data['platform_key'] ?? ''))"), '管理员登录未按平台 KEY 绑定所属平台');
$check(str_contains($admin, 'Password::verify($password'), '管理员登录未校验密码');
$check(str_contains($admin, 'WHERE admin_id = ? AND app_key = ? AND status = 1 AND deleted_at IS NULL'),
    '管理员登录未验证应用 API 唯一 ID 属于当前账号');
$check(str_contains($admin, "'app_identity_verified' => \$appIdentityVerified"),
    '管理员实时 me 未回读应用身份验证结果');
$check(str_contains($admin, "'initial_app' => \$admin['initial_app']"), '管理员注册未返回首个应用标识');
$check(str_contains($admin, "'app_secret' => \$admin['initial_app_secret']"), '管理员注册未一次性返回首个应用密钥');
$check(str_contains($platformAdmin, "'initial_app' => \$admin['initial_app']"), '平台创建管理员未返回首个应用标识');
$check(str_contains($platformAdmin, "'app_secret' => \$admin['initial_app_secret']"), '平台创建管理员未一次性返回首个应用密钥');
$check(str_contains($provision, "Validator::required(\$data, ['account', 'password', 'app_key'])"),
    '管理员创建未强制首个应用唯一 ID');
$check(str_contains($provision, 'SubmissionInspectionService::catalogSchemaTransaction'),
    '管理员与首个应用未在目录安全事务中创建');
$check(str_contains($provision, 'AdminAccessService::requireAppQuota($admin, true)'),
    '首个应用创建未校验应用额度');
$check(str_contains($provision, '应用 API 唯一 ID 已被占用'), '首个应用唯一 ID 冲突未被明确拒绝');
$check(str_contains($provision, 'AppService::seedDefaults($adminId, $appId)'), '首个应用未初始化默认配置');
$check(str_contains($provision, "SubmissionInspectionService::seedResourceCategories(\$adminId, \$appId, 'source_market')"),
    '首个应用未初始化源码分类');
$check(str_contains($provision, "'initial_app_secret' => \$appSecret"), '首个应用密钥未限制在创建响应路径');
$check(str_contains($register, 'showBootstrapAppSecret(result.dataObject());'), 'Android 管理员注册后未展示一次性应用密钥');
$check(str_contains($user, "AppService::byKey(trim((string) \$data['app_key']))"), '用户登录未按应用唯一 ID 绑定应用');
$check(str_contains($user, 'WHERE admin_id = ? AND app_id = ? AND account = ?'), '用户账号查询未限制管理员与应用租户');
$check(str_contains($user, "Password::verify((string) \$data['password']"), '用户登录未校验密码');

$check(str_contains($auth, 'revoked_at IS NULL AND expired_at > NOW()'), '管理员/用户 Token 未校验撤销与时效');
$check(str_contains($auth, "hash_equals((string) \$user['app_key'], \$appKey)"), '用户实时 Token 未再次绑定 X-App-Key');
$check(str_contains($auth, 'SET last_used_at = NOW()'), '实时 Token 使用状态未更新');
$check(str_contains($platformService, 'revoked_at IS NULL AND t.expired_at > NOW()'), '平台 Token 未校验撤销与时效');
$check(str_contains($platformService, 'SET last_used_at = NOW()'), '平台 Token 使用状态未更新');

$check(str_contains($admin, "Validator::required(\$data, ['platform_key']);"), '管理员注册未强制平台 KEY');
$check(str_contains($platformAdmin, "'registration_gift' => \$admin['registration_gift']"), '平台创建管理员响应未返回规范化赠送额度');
$check(str_contains($platformAdmin, "'vip_days' => \$request->input('vip_days', \$request->input('membership_days'))"), '平台创建管理员未兼容会员天数输入');
$check(str_contains($moduleRegistry, 'integer("vip_days", "会员天数")'), '平台管理员创建表单会员天数字段未对齐后端');
$check(substr_count($provision, 'SubmissionInspectionService::catalogSchemaTransaction') === 2,
    '管理员创建未将目录锁保持至账号、权益、首个应用和日志提交完成');
$check(str_contains($provision, "\$gift['app_quota'] = max(1,"), '首个应用未将管理员赠送应用额度下限固定为 1');
$check(str_contains($provision, "'app_quota' => max(1, (int) PlatformService::setting"), '默认管理员赠送应用额度允许为 0');
$check(str_contains($provision, '(array) ($admin[\'registration_gift\'] ?? [])'), '平台自定义赠送记录未回写规范化额度');
$check(str_contains($genericModule, 'if (!Jsons.string(result.dataObject(), "app_secret").isEmpty())'), '通用管理动作未拦截一次性应用密钥');
$check(str_contains($genericModule, 'showOneTimeAppSecret(result.dataObject(), item, () ->'), '创建应用或重置密钥后未展示一次性应用密钥');
$check(str_contains($genericModule, '.setCancelable(false)'), '平台创建管理员密钥弹窗可以在保存前关闭');
$check(str_contains($genericModule, '.setPositiveButton("我已安全保存"'), '一次性应用密钥弹窗缺少明确的已保存确认');
$check(str_contains($provision, '$afterProvision($admin);'), '平台创建管理员的操作日志未纳入首应用创建事务');
$check(str_contains($adminApps, 'SubmissionInspectionService::catalogSchemaTransaction')
    && str_contains($adminApps, "LogService::adminOperation(\$request, (int) \$admin['id'], \$appId, 'app', 'create'"),
    '创建应用与操作日志未处于同一目录安全事务');
$check(str_contains($adminApps, 'Database::transaction(static function () use ($request, $admin, $appId, $secret): void')
    && str_contains($adminApps, "LogService::adminOperation(\$request, (int) \$admin['id'], \$appId, 'app', 'secret_reset'"),
    '重置密钥与操作日志未处于同一事务');
$check(preg_match('/android:name="\\.ui\\.main\\.MainActivity"\\s+android:exported="false"/', $manifest) === 1, 'MainActivity 仍可被外部显式启动');
$check(str_contains($main, 'binding.contentContainer.setVisibility(View.INVISIBLE);'), 'MainActivity 实时校验前仍可能显示业务内容');
$check(str_contains($main, 'validateMainSession(savedInstanceState);'), 'MainActivity 启动时未执行实时登录校验');
$check(str_contains($main, 'session.role().mePath()'), 'MainActivity 未调用当前角色的受保护 me 接口');
$check(str_contains($main, 'result.isAuthenticationFailure() || result.httpCode() == 403 || result.isSuccessful()'), 'MainActivity 未将无效令牌或身份错配清会话');
$check(str_contains($main, 'setAction("重试", view -> validateMainSession(savedInstanceState))'), 'MainActivity 网络校验失败未提供中文重试');
$check(strpos($main, 'validateMainSession(savedInstanceState);') < strpos($main, 'LifecycleChecker.check'), 'MainActivity 在实时校验前进入首个业务页');
$check(str_contains($session, 'public String configuredAppKey()'), '会话未区分编译登录应用与管理员当前选择应用');
$check(str_contains($main, 'BuildConfig.DEFAULT_APP_KEY.trim().equals(session.configuredAppKey())'), '管理员切换应用时 MainActivity 会错误撤销编译登录身份');
$check(str_contains($login, 'int liveLevel = Jsons.intValue(actor, "level", 0);'), '平台登录页未读取实时平台等级');
$check(str_contains($login, 'liveLevel == AppEdition.requiredPlatformLevel()')
    && str_contains($login, 'liveLevel == session.actorLevel()'), '平台登录页未同时校验实时等级、安装版等级与本地会话等级');
$check(str_contains($main, 'liveLevel == AppEdition.requiredPlatformLevel()')
    && str_contains($main, 'liveLevel == session.actorLevel()'), 'MainActivity 恢复时未校验实时平台等级');
$check(str_contains($login, 'private boolean buildIdentityValid;')
    && str_contains($login, 'setAuthenticationEntryEnabled(false);'), '无效 BuildConfig 未禁用登录认证入口');
$check(str_contains($login, 'if (!buildIdentityValid) {')
    && str_contains($login, 'private void register()'), '无效 BuildConfig 仍可跳转注册页');
$check(str_contains($login, 'java.util.Arrays.asList(1, 2).contains(AppEdition.requiredPlatformLevel())'), '平台安装版等级配置未作失败关闭校验');
$check(str_contains($register, 'private boolean configureBuildIdentity()')
    && str_contains($register, 'catch (IllegalArgumentException exception)')
    && str_contains($register, 'session.configureConnection(baseUrl(), appKey(), platformKey());'), '注册页未捕获 BuildConfig 连接配置异常');
$check(str_contains($register, 'setRegistrationEntryEnabled(false);')
    && str_contains($register, '已禁止注册，请联系开发者重新打包。'), '注册页无效 BuildConfig 未中文提示并禁用提交');
$check(str_contains($apiDocs, '首个应用密钥一次性语义')
    && str_contains($apiDocs, '仅在该次成功响应中明文返回')
    && str_contains($apiDocs, '不写入后续查询、列表、日志或再次读取接口'), '管理员首个应用密钥的一次性语义未同步 API 文档');
$check(str_contains($apiDocs, '`platform_id,account,password,app_key,app_name,vip_days,app_quota,remote_document_quota,balance`')
    && str_contains($apiDocs, '`platform_key,app_key,app_name,account,password,password_confirmation,nickname,email,phone`'), '平台创建管理员或管理员注册文档未同步首个应用参数');

if ($failures !== []) {
    fwrite(STDERR, "Login build identity contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Login build identity contract passed ({$checks} checks).\n";
