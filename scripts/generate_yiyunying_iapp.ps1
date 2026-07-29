$ErrorActionPreference = "Stop"

$workspace = "C:\Users\Administrator\Documents\易云后台"
$project = Join-Path $workspace "generated\易运盈后台_iApp源码"
$src = Join-Path $project "src"
$assets = Join-Path $project "apk\assets"
$resDrawable = Join-Path $project "res\drawable"
$resMipmap = Join-Path $project "res\mipmap"
$filesDemo = Join-Path $project "files\demo"
$outDir = Join-Path $workspace "generated"

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Ensure-Dir($path) {
    if (!(Test-Path -Path $path)) {
        New-Item -ItemType Directory -Force -Path $path | Out-Null
    }
}

function Write-Text($path, $content) {
    $dir = Split-Path -Parent $path
    Ensure-Dir $dir
    $content = $content -replace '</View><View', "</View>`r`n<View"
    $content = $content -replace '</View><UIEventset', "</View>`r`n<UIEventset"
    $content = $content -replace '</View><eventItme', "</View>`r`n<eventItme"
    [System.IO.File]::WriteAllText($path, $content, $utf8NoBom)
}

function New-ZipFromDirectory($sourceDir, $zipPath, $includeRoot) {
    if (Test-Path -Path $zipPath) { Remove-Item -Path $zipPath -Force }
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $base = (Resolve-Path -Path $sourceDir).Path.TrimEnd('\')
        $rootName = Split-Path -Leaf $base
        Get-ChildItem -Path $sourceDir -Recurse -File -Force | ForEach-Object {
            $full = $_.FullName
            $rel = $full.Substring($base.Length + 1)
            if ($includeRoot) {
                $rel = Join-Path $rootName $rel
            }
            $entryName = $rel -replace '\\','/'
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $full, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally {
        $zip.Dispose()
    }
}

function Iyu-Escape($text) {
    return ($text -replace "&", "＆" -replace "<", "＜" -replace ">", "＞" -replace '"', "'")
}

function Build-Header($title, $backTarget) {
    $backEvent = "uigo(`"$backTarget`")"
    if ([string]::IsNullOrWhiteSpace($backTarget)) {
        $backEvent = "tw(`"已经在当前页面`")"
    }
@"
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
background=#F5F7FB</ppt>
<event></event>
</View>
<View id="2" did="1" type="LinearLayout">
<ppt>width=-1
height=58dp
orientation=horizontal
gravity=center
paddingLeft=12dp
paddingRight=12dp
background=#1768E8</ppt>
<event></event>
</View>
<View id="3" did="2" type="TextView">
<ppt>width=48dp
height=-1
text=返回
gravity=center
textColor=#FFFFFF
textSize=14sp</ppt>
<event><eventItme type="clicki">$backEvent</eventItme></event>
</View>
<View id="4" did="2" type="TextView">
<ppt>width=-1
height=-1
text=$title
gravity=center
textColor=#FFFFFF
textSize=18sp
textStyle=bold</ppt>
<event></event>
</View>
<View id="5" did="2" type="TextView">
<ppt>width=48dp
height=-1
text=设置
gravity=center
textColor=#FFFFFF
textSize=14sp</ppt>
<event><eventItme type="clicki">uigo("server_config.iyu")</eventItme></event>
</View>
"@
}

function Build-ScrollStart() {
@"
<View id="10" did="1" type="ScrollView">
<ppt>width=-1
height=-1
fillViewport=true</ppt>
<event></event>
</View>
<View id="11" did="10" type="LinearLayout">
<ppt>width=-1
height=-2
orientation=vertical
padding=14dp</ppt>
<event></event>
</View>
"@
}

function Build-Intro($title, $subtitle) {
@"
<View id="20" did="11" type="TextView">
<ppt>width=-1
height=-2
text=$title
textColor=#17233D
textSize=22sp
textStyle=bold
layout_marginBottom=6dp</ppt>
<event></event>
</View>
<View id="21" did="11" type="TextView">
<ppt>width=-1
height=-2
text=$subtitle
textColor=#65758B
textSize=14sp
layout_marginBottom=12dp</ppt>
<event></event>
</View>
"@
}

function Build-Button($id, $parent, $text, $desc, $target, $toast) {
    $event = "tw(`"$toast`")"
    if (![string]::IsNullOrWhiteSpace($target)) {
        $event = "uigo(`"$target`")"
    }
@"
<View id="$id" did="$parent" type="LinearLayout">
<ppt>width=-1
height=-2
orientation=vertical
padding=14dp
layout_marginBottom=10dp
background=#FFFFFF</ppt>
<event><eventItme type="clicki">$event</eventItme></event>
</View>
<View id="$($id+1)" did="$id" type="TextView">
<ppt>width=-1
height=-2
text=$text
textColor=#17233D
textSize=16sp
textStyle=bold</ppt>
<event></event>
</View>
<View id="$($id+2)" did="$id" type="TextView">
<ppt>width=-1
height=-2
text=$desc
textColor=#65758B
textSize=13sp
layout_marginTop=4dp</ppt>
<event></event>
</View>
"@
}

function New-MenuPage($file, $title, $subtitle, $backTarget, $items, $loadingCode) {
    $body = Build-Header (Iyu-Escape $title) $backTarget
    $body += Build-ScrollStart
    $body += Build-Intro (Iyu-Escape $title) (Iyu-Escape $subtitle)
    $id = 100
    foreach ($item in $items) {
        $body += Build-Button $id 11 (Iyu-Escape $item.Text) (Iyu-Escape $item.Desc) $item.Target (Iyu-Escape $item.Toast)
        $id += 10
    }
    if ([string]::IsNullOrWhiteSpace($loadingCode)) {
        $loadingCode = "syso(`"$file loaded`")"
    }
    $body += "<UIEventset><eventItme type=`"loading`">$loadingCode</eventItme></UIEventset>`r`n"
    Write-Text (Join-Path $src $file) $body
}

function New-FormPage($file, $title, $subtitle, $backTarget, $fields, $buttons, $loadingCode) {
    $body = Build-Header (Iyu-Escape $title) $backTarget
    $body += Build-ScrollStart
    $body += Build-Intro (Iyu-Escape $title) (Iyu-Escape $subtitle)
    $id = 100
    foreach ($field in $fields) {
        $body += @"
<View id="$id" did="11" type="TextView">
<ppt>width=-1
height=-2
text=$(Iyu-Escape $field.Label)
textColor=#17233D
textSize=14sp
layout_marginTop=8dp</ppt>
<event></event>
</View>
<View id="$($id+1)" did="11" type="EditText">
<ppt>width=-1
height=46dp
text=$(Iyu-Escape $field.Value)
hint=$(Iyu-Escape $field.Hint)
textSize=14sp
singleLine=true
background=#FFFFFF
paddingLeft=10dp
paddingRight=10dp</ppt>
<event></event>
</View>
"@
        $id += 10
    }
    foreach ($btn in $buttons) {
        $body += Build-Button $id 11 (Iyu-Escape $btn.Text) (Iyu-Escape $btn.Desc) $btn.Target (Iyu-Escape $btn.Toast)
        $id += 10
    }
    if ([string]::IsNullOrWhiteSpace($loadingCode)) {
        $loadingCode = "syso(`"$file loaded`")"
    }
    $body += "<UIEventset><eventItme type=`"loading`">$loadingCode</eventItme></UIEventset>`r`n"
    Write-Text (Join-Path $src $file) $body
}

function New-ListPage($file, $title, $subtitle, $backTarget, $template, $records, $extraButtons) {
    $body = Build-Header (Iyu-Escape $title) $backTarget
    $body += @"
<View id="10" did="1" type="LinearLayout">
<ppt>width=-1
height=48dp
orientation=horizontal
padding=8dp
background=#FFFFFF</ppt>
<event></event>
</View>
<View id="12" did="10" type="EditText">
<ppt>width=-1
height=-1
hint=输入关键词搜索
singleLine=true
textSize=14sp
background=#F0F3F8
paddingLeft=10dp</ppt>
<event></event>
</View>
<View id="13" did="10" type="TextView">
<ppt>width=70dp
height=-1
text=刷新
gravity=center
textColor=#1768E8
textSize=14sp</ppt>
<event><eventItme type="clicki">tw("已刷新演示数据")</eventItme></event>
</View>
"@
    $body += Build-ScrollStart
    $body += Build-Intro (Iyu-Escape $title) (Iyu-Escape $subtitle)
    $id = 80
    foreach ($btn in $extraButtons) {
        $body += Build-Button $id 11 (Iyu-Escape $btn.Text) (Iyu-Escape $btn.Desc) $btn.Target (Iyu-Escape $btn.Toast)
        $id += 10
    }
    $body += @"
<View id="7" did="11" type="ListView">
<ppt>width=-1
height=520dp
dividerHeight=0</ppt>
<event></event>
</View>
<View id="8" did="11" type="TextView">
<ppt>width=-1
height=-2
text=演示数据已加载，可点击列表项进入详情或功能页。
textColor=#65758B
textSize=13sp
gravity=center
layout_marginTop=8dp</ppt>
<event></event>
</View>
"@
    $loading = ""
    foreach ($r in $records) {
        $pairs = @()
        foreach ($key in $r.Keys) {
            $val = Iyu-Escape ([string]$r[$key])
            $pairs += "$key=`"$val`""
        }
        $loading += "ula(list,$($pairs -join ','))`r`n"
        $loading += "uls(7,list,`"$template`",-1,-2)`r`n"
    }
    $body += "<UIEventset><eventItme type=`"loading`">$loading</eventItme></UIEventset>`r`n"
    Write-Text (Join-Path $src $file) $body
}

function New-ItemTemplate($file, $kind, $clickTarget) {
    $click = "tw(`"已选择：`"+st_vW)"
    if (![string]::IsNullOrWhiteSpace($clickTarget)) {
        $click = "uigo(`"$clickTarget`")"
    }
    $body = @"
<View id="100" did="0" type="LinearLayout">
<ppt>width=-1
height=-2
orientation=vertical
padding=12dp
layout_marginBottom=8dp
background=#FFFFFF</ppt>
<event><eventItme type="clicki">$click</eventItme></event>
</View>
<View id="1" did="100" type="TextView">
<ppt>width=-1
height=-2
text=${kind}标题
textColor=#17233D
textSize=16sp
textStyle=bold</ppt>
<event></event>
</View>
<View id="2" did="100" type="TextView">
<ppt>width=-1
height=-2
text=${kind}说明
textColor=#65758B
textSize=13sp
layout_marginTop=4dp</ppt>
<event></event>
</View>
<View id="3" did="100" type="TextView">
<ppt>width=-1
height=-2
text=时间
textColor=#98A2B3
textSize=12sp
layout_marginTop=4dp</ppt>
<event></event>
</View>
<View id="4" did="100" type="TextView">
<ppt>width=-1
height=-2
text=状态
textColor=#1768E8
textSize=12sp
layout_marginTop=4dp</ppt>
<event></event>
</View>
<View id="5" did="100" type="TextView">
<ppt>width=-1
height=-2
text=扩展字段
textColor=#65758B
textSize=12sp
layout_marginTop=4dp</ppt>
<event></event>
</View>
<UIEventset></UIEventset>
"@
    Write-Text (Join-Path $src $file) $body
}

function New-ChatItem($file, $align, $bg) {
    $body = @"
<View id="100" did="0" type="LinearLayout">
<ppt>width=-1
height=-2
orientation=vertical
gravity=$align
padding=8dp</ppt>
<event></event>
</View>
<View id="1" did="100" type="TextView">
<ppt>width=-2
height=-2
text=昵称
textColor=#65758B
textSize=12sp</ppt>
<event></event>
</View>
<View id="3" did="100" type="TextView">
<ppt>width=-2
height=-2
text=消息内容
textColor=#17233D
textSize=15sp
padding=10dp
background=$bg
layout_marginTop=3dp</ppt>
<event></event>
</View>
<View id="4" did="100" type="TextView">
<ppt>width=-2
height=-2
text=刚刚
textColor=#98A2B3
textSize=11sp
layout_marginTop=3dp</ppt>
<event></event>
</View>
<UIEventset></UIEventset>
"@
    Write-Text (Join-Path $src $file) $body
}

# Directories
Ensure-Dir $project
Ensure-Dir $src
Ensure-Dir $assets
Ensure-Dir $resDrawable
Ensure-Dir $resMipmap
Ensure-Dir $filesDemo

# Core project files
Write-Text (Join-Path $project ".project") '<package>com.yiyunying.admin</package><toolversion>iApp V3.0.1035</toolversion><developer>yiyunying</developer><time>1783560000000</time>'

Write-Text (Join-Path $project "AndroidManifest.xml") @"
<?xml version="1.0" encoding="utf-8"?>
<title>易运盈后台</title>
<icon>icon.png</icon>
<packageName>com.yiyunying.admin</packageName>
<versionName>1.0.0</versionName>
<versionint>1</versionint>
<sdk>15</sdk>
<yuv>3</yuv>
<remark>易运盈后台 iApp 源码包，包含管理端、用户端示例和演示模式。</remark>
<Permissions>android.permission.INTERNET
android.permission.ACCESS_NETWORK_STATE
android.permission.READ_EXTERNAL_STORAGE
android.permission.WRITE_EXTERNAL_STORAGE
android.permission.REQUEST_INSTALL_PACKAGES</Permissions>
<createTime>2026-07-09 16:00:00</createTime>
<upTime>2026-07-09 16:00:00</upTime>
"@

Write-Text (Join-Path $assets "extra_conf1g.xml") "<signature>1</signature>`r`n"
Write-Text (Join-Path $project ".nomedia") ""
Write-Text (Join-Path $filesDemo "readme.txt") "易运盈后台演示数据目录。`r`n"
Write-Text (Join-Path $project "源码说明.txt") @"
易运盈后台 iApp 源码包

这是一个开发期一体化 iApp 工程，包含管理端、用户端示例和演示模式。
当前版本以内置演示数据和接口占位为主，后续可对接 PHP 后端。

导入重点：
1. 保留 .project、AndroidManifest.xml、icon.png、apk、res、src。
2. 不要只压缩 src。
3. src 下 .iyu 是页面，.myu 是公共模块。
"@

# Icon
try {
    Add-Type -AssemblyName System.Drawing
    $bmp = New-Object System.Drawing.Bitmap 256, 256
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.Clear([System.Drawing.Color]::FromArgb(23,104,232))
    $brush = New-Object System.Drawing.Drawing2D.LinearGradientBrush(
        (New-Object System.Drawing.Rectangle 0,0,256,256),
        [System.Drawing.Color]::FromArgb(23,104,232),
        [System.Drawing.Color]::FromArgb(12,178,139),
        45
    )
    $g.FillRectangle($brush, 0, 0, 256, 256)
    $font = New-Object System.Drawing.Font("Microsoft YaHei", 96, [System.Drawing.FontStyle]::Bold)
    $white = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)
    $format = New-Object System.Drawing.StringFormat
    $format.Alignment = [System.Drawing.StringAlignment]::Center
    $format.LineAlignment = [System.Drawing.StringAlignment]::Center
    $rect = New-Object System.Drawing.RectangleF 0, 0, 256, 256
    $g.DrawString("盈", $font, $white, $rect, $format)
    $icon = Join-Path $project "icon.png"
    $bmp.Save($icon, [System.Drawing.Imaging.ImageFormat]::Png)
    Copy-Item -Path $icon -Destination (Join-Path $resDrawable "icon.png") -Force
    Copy-Item -Path $icon -Destination (Join-Path $resMipmap "icon.png") -Force
    $g.Dispose()
    $bmp.Dispose()
} catch {
    Copy-Item -Path (Join-Path $workspace "研究_易云后台\v229_iApp\icon.png") -Destination (Join-Path $project "icon.png") -Force
    Copy-Item -Path (Join-Path $project "icon.png") -Destination (Join-Path $resDrawable "icon.png") -Force
    Copy-Item -Path (Join-Path $project "icon.png") -Destination (Join-Path $resMipmap "icon.png") -Force
}

# MYU modules
Write-Text (Join-Path $src "app_config.myu") @"
fn app_init()
sss app_name="易运盈后台"
sss app_version="1.0.0"
sss base_url="https://example.com/yiyunying"
sss demo_admin="demo"
sss demo_pass="123456"
end fn

fn app_about()
tw("易运盈后台 iApp 源码包：管理端、用户端、演示模式一体化工程")
end fn
"@

Write-Text (Join-Path $src "api_client.myu") @"
fn api_get(path, query, result)
ss(sss.base_url+path+"?"+query,url)
hs(url,result)
end fn

fn api_post(path, data, result)
ss(sss.base_url+path,url)
hs(url,data,"utf-8",result)
end fn

fn api_demo(msg)
tw("演示模式："+msg)
end fn
"@

Write-Text (Join-Path $src "auth_service.myu") @"
fn auth_save_admin(account)
fw("$yyy_last_role","admin")
fw("$yyy_admin_account",account)
fw("$yyy_admin_token","demo-admin-token")
end fn

fn auth_save_user(account)
fw("$yyy_last_role","user")
fw("$yyy_user_account",account)
fw("$yyy_user_token","demo-user-token")
end fn

fn auth_logout()
fw("$yyy_last_role","")
fw("$yyy_admin_token","")
fw("$yyy_user_token","")
tw("已退出登录")
end fn
"@

Write-Text (Join-Path $src "demo_service.myu") @"
fn demo_on()
sss demo_mode=true
fw("$yyy_demo_mode","true")
tw("已进入演示模式")
end fn

fn demo_reset()
fw("$yyy_demo_mode","")
tw("演示数据已恢复默认")
end fn
"@

Write-Text (Join-Path $src "ui_state.myu") @"
fn ui_toast(msg)
tw(msg)
end fn

fn ui_loading()
tw("加载中")
end fn

fn ui_success(msg)
tw("成功："+msg)
end fn

fn ui_error(msg)
tw("失败："+msg)
end fn
"@

Write-Text (Join-Path $src "doc_service.myu") @"
fn doc_save_draft(docid,title,content)
ss("$yyy_doc_draft_"+docid,key)
ss(title+"\n"+content,value)
fw(key,value)
end fn

fn doc_clear_draft(docid)
ss("$yyy_doc_draft_"+docid,key)
fw(key,"")
end fn
"@

Write-Text (Join-Path $src "utils.myu") @"
fn util_not_ready()
tw("接口已预留，接入后端后启用")
end fn

fn util_copy_demo()
tw("已复制演示内容")
end fn
"@

# Entry pages
New-MenuPage "mian.iyu" "易运盈后台" "启动入口：初始化配置、检查更新、进入模式选择。当前源码包已内置演示数据。" "" @(
    @{Text="进入模式选择";Desc="选择管理端、用户端示例或演示模式";Target="mode_select.iyu";Toast=""}
    @{Text="服务器配置";Desc="设置后端地址、API 标识和演示模式";Target="server_config.iyu";Toast=""}
    @{Text="关于源码包";Desc="查看当前 iApp 源码包说明";Target="about.iyu";Toast=""}
) 'fr("$yyy_demo_mode",demo) syso(demo)'

New-MenuPage "mode_select.iyu" "模式选择" "开发期一体化工程，三种模式都可以从这里进入。" "mian.iyu" @(
    @{Text="管理端模式";Desc="进入后台管理员登录和管理功能";Target="admin_login.iyu";Toast=""}
    @{Text="用户端示例";Desc="进入普通用户登录和业务演示";Target="user_login.iyu";Toast=""}
    @{Text="演示模式";Desc="无需网络，直接进入完整演示工作台";Target="admin_home.iyu";Toast=""}
    @{Text="服务器配置";Desc="配置 base_url、api_key、演示模式开关";Target="server_config.iyu";Toast=""}
) 'sss demo_mode=true fw("$yyy_demo_mode","true")'

New-FormPage "server_config.iyu" "服务器配置" "这里保存 iApp 端连接后端所需的基础配置。" "mode_select.iyu" @(
    @{Label="后端地址";Value="https://example.com/yiyunying";Hint="请输入后端地址"}
    @{Label="API 标识";Value="demo_api";Hint="请输入 API 标识"}
    @{Label="演示模式";Value="true";Hint="true 或 false"}
) @(
    @{Text="保存配置";Desc="保存到本地缓存，后续请求统一读取";Target="";Toast="配置已保存到本地"}
    @{Text="测试连接";Desc="当前版本为接口占位，演示提示连接成功";Target="";Toast="连接测试成功（演示）"}
) ''

New-MenuPage "about.iyu" "关于源码包" "易运盈后台 iApp 源码包，包含管理端、用户端示例和演示模式。" "mian.iyu" @(
    @{Text="工程规则";Desc=".iApp 必须保留 .project、Manifest、icon、apk、res、src";Target="";Toast="工程结构完整"}
    @{Text="源码说明";Desc=".iyu 是页面，.myu 是公共模块，列表用 ula 和 uls";Target="";Toast="源码说明已内置"}
    @{Text="返回首页";Desc="回到启动页";Target="mian.iyu";Toast=""}
) ''

# Login pages
New-FormPage "admin_login.iyu" "管理员登录" "演示账号 demo / 123456。真实接口接入后调用 /admin/auth/login。" "mode_select.iyu" @(
    @{Label="管理员账号";Value="demo";Hint="请输入管理员账号"}
    @{Label="密码";Value="123456";Hint="请输入密码"}
) @(
    @{Text="登录管理端";Desc="演示模式直接进入管理端首页";Target="admin_home.iyu";Toast=""}
    @{Text="注册管理员";Desc="创建新的后台管理员账号";Target="admin_register.iyu";Toast=""}
    @{Text="找回密码";Desc="进入找回密码页面";Target="forgot_password.iyu";Toast=""}
) 'fw("$yyy_last_role","admin")'

New-FormPage "admin_register.iyu" "管理员注册" "创建后台管理员账号。当前源码包为演示表单，接口已预留。" "admin_login.iyu" @(
    @{Label="账号";Value="";Hint="请输入账号"}
    @{Label="密码";Value="";Hint="请输入密码"}
    @{Label="昵称";Value="";Hint="请输入昵称"}
    @{Label="QQ";Value="";Hint="请输入 QQ"}
) @(
    @{Text="提交注册";Desc="演示提交成功，真实版调用注册接口";Target="admin_login.iyu";Toast=""}
) ''

New-FormPage "user_login.iyu" "用户登录" "普通用户示例登录，演示账号 demo / 123456。" "mode_select.iyu" @(
    @{Label="用户账号";Value="demo";Hint="请输入账号"}
    @{Label="密码";Value="123456";Hint="请输入密码"}
) @(
    @{Text="登录用户端";Desc="演示模式直接进入用户端首页";Target="user_home.iyu";Toast=""}
    @{Text="注册用户";Desc="进入用户注册页面";Target="user_register.iyu";Toast=""}
    @{Text="找回密码";Desc="进入找回密码页面";Target="forgot_password.iyu";Toast=""}
) 'fw("$yyy_last_role","user")'

New-FormPage "user_register.iyu" "用户注册" "普通用户注册表单，适合接入 API 项目。" "user_login.iyu" @(
    @{Label="账号";Value="";Hint="请输入账号"}
    @{Label="密码";Value="";Hint="请输入密码"}
    @{Label="确认密码";Value="";Hint="请再次输入密码"}
    @{Label="昵称";Value="";Hint="请输入昵称"}
    @{Label="QQ";Value="";Hint="请输入 QQ"}
    @{Label="邀请码";Value="";Hint="可选"}
) @(
    @{Text="提交注册";Desc="演示提交成功，真实版调用 /api/auth/register";Target="user_login.iyu";Toast=""}
) ''

New-FormPage "forgot_password.iyu" "找回密码" "通过账号和 QQ 或邮箱找回密码。当前为接口占位。" "mode_select.iyu" @(
    @{Label="账号";Value="";Hint="请输入账号"}
    @{Label="QQ 或邮箱";Value="";Hint="请输入绑定信息"}
) @(
    @{Text="提交找回";Desc="接口已预留";Target="";Toast="找回密码接口已预留"}
) ''

New-FormPage "change_password.iyu" "修改密码" "登录后修改密码。" "admin_settings.iyu" @(
    @{Label="旧密码";Value="";Hint="请输入旧密码"}
    @{Label="新密码";Value="";Hint="请输入新密码"}
    @{Label="确认新密码";Value="";Hint="请再次输入"}
) @(
    @{Text="保存新密码";Desc="演示提交成功";Target="";Toast="密码修改成功（演示）"}
) ''

# Home and dashboard
New-MenuPage "admin_home.iyu" "管理端首页" "易运盈后台管理端工作台，覆盖项目、用户、文档、运营和系统管理。" "mode_select.iyu" @(
    @{Text="数据看板";Desc="查看用户、文档、卡密、订单和消息统计";Target="admin_dashboard.iyu";Toast=""}
    @{Text="API 项目";Desc="创建、切换和配置接入应用";Target="admin_project_list.iyu";Toast=""}
    @{Text="用户管理";Desc="搜索用户、资产调整、封禁和详情";Target="admin_user_list.iyu";Toast=""}
    @{Text="文档管理";Desc="查看、删除、恢复和管理用户文档";Target="admin_doc_list.iyu";Toast=""}
    @{Text="卡密管理";Desc="生成、查询、导出和禁用卡密";Target="admin_card_list.iyu";Toast=""}
    @{Text="公告更新";Desc="公告发布和远程版本更新";Target="admin_notice_list.iyu";Toast=""}
    @{Text="社区管理";Desc="板块、帖子、审核、置顶和加精";Target="admin_forum_post.iyu";Toast=""}
    @{Text="客服聊天";Desc="处理用户会话和在线咨询";Target="admin_chat_list.iyu";Toast=""}
    @{Text="文件管理";Desc="查看头像、附件、应用截图和文档文件";Target="admin_file_list.iyu";Toast=""}
    @{Text="订单管理";Desc="查看充值、购买会员和文档券订单";Target="admin_order_list.iyu";Toast=""}
    @{Text="操作日志";Desc="查看后台危险操作和登录记录";Target="admin_log_list.iyu";Toast=""}
    @{Text="系统设置";Desc="服务器、缓存、密码和退出登录";Target="admin_settings.iyu";Toast=""}
) ''

New-MenuPage "user_home.iyu" "用户端首页" "普通用户示例首页，展示接入易运盈后台后的完整体验。" "mode_select.iyu" @(
    @{Text="用户工作台";Desc="查看资产、公告、签到和最近文档";Target="user_dashboard.iyu";Toast=""}
    @{Text="每日签到";Desc="获得积分、经验和文档券";Target="user_sign.iyu";Toast=""}
    @{Text="我的资产";Desc="查看积分、金币、经验、文档券和 VIP";Target="user_asset.iyu";Toast=""}
    @{Text="兑换卡密";Desc="输入卡密兑换会员、积分或文档券";Target="user_card_use.iyu";Toast=""}
    @{Text="我的文档";Desc="文档列表、新建、编辑、分享";Target="doc_list.iyu";Toast=""}
    @{Text="社区论坛";Desc="板块、帖子、评论和点赞";Target="forum_board_list.iyu";Toast=""}
    @{Text="聊天消息";Desc="私信和客服会话";Target="chat_room_list.iyu";Toast=""}
    @{Text="个人中心";Desc="资料、密码、服务器和退出登录";Target="user_profile.iyu";Toast=""}
) ''

New-MenuPage "admin_dashboard.iyu" "数据看板" "演示统计：今日注册 18，用户 1280，文档 3560，订单 92。" "admin_home.iyu" @(
    @{Text="今日注册 18";Desc="普通用户今日新增数量";Target="admin_user_list.iyu";Toast=""}
    @{Text="文档总数 3560";Desc="用户创建的文档数量";Target="admin_doc_list.iyu";Toast=""}
    @{Text="卡密使用 286";Desc="已兑换卡密数量";Target="admin_card_list.iyu";Toast=""}
    @{Text="待处理消息 12";Desc="客服会话和系统消息";Target="admin_chat_list.iyu";Toast=""}
) ''

New-MenuPage "user_dashboard.iyu" "用户工作台" "演示用户：demo，积分 120，金币 60，文档券 8，VIP 未开通。" "user_home.iyu" @(
    @{Text="立即签到";Desc="今日未签到，点击领取奖励";Target="user_sign.iyu";Toast=""}
    @{Text="新建文档";Desc="消耗 1 张文档券创建新文档";Target="doc_create.iyu";Toast=""}
    @{Text="最近文档";Desc="继续编辑最近保存的内容";Target="doc_list.iyu";Toast=""}
    @{Text="公告中心";Desc="查看系统公告和更新";Target="user_notice_list.iyu";Toast=""}
) ''

# List pages
New-ListPage "admin_project_list.iyu" "API 项目列表" "管理所有接入应用，一个 API 项目服务一个 iApp 应用。" "admin_home.iyu" "item_project.iyu" @(
    @{"1"="易运盈演示应用";"2"="api_key: demo_api";"3"="用户 1280，文档 3560";"4"="正常";"5"="包名 com.demo.app";"-1"="1"}
    @{"1"="星光文档增强版";"2"="api_key: star_doc";"3"="用户 326，文档 1024";"4"="正常";"5"="文档系统演示";"-1"="2"}
    @{"1"="会员社区示例";"2"="api_key: member_bbs";"3"="用户 860，帖子 430";"4"="维护中";"5"="社区和会员演示";"-1"="3"}
) @(
    @{Text="新建 API 项目";Desc="创建一个新的接入项目";Target="admin_project_edit.iyu";Toast=""}
)

New-FormPage "admin_project_edit.iyu" "API 项目编辑" "新建或修改 API 项目基础信息。" "admin_project_list.iyu" @(
    @{Label="项目名称";Value="易运盈演示应用";Hint="请输入项目名称"}
    @{Label="API 标识";Value="demo_api";Hint="只能字母数字下划线"}
    @{Label="应用包名";Value="com.demo.app";Hint="请输入包名"}
    @{Label="积分名称";Value="积分";Hint="例如金币、积分"}
) @(
    @{Text="保存项目";Desc="保存项目并返回列表";Target="admin_project_list.iyu";Toast=""}
    @{Text="项目规则配置";Desc="注册、登录、签到、会员规则";Target="admin_project_setting.iyu";Toast=""}
) ''

New-FormPage "admin_project_setting.iyu" "API 项目配置" "配置注册、登录、签到、会员和文档券规则。" "admin_project_list.iyu" @(
    @{Label="允许注册";Value="true";Hint="true 或 false"}
    @{Label="允许登录";Value="true";Hint="true 或 false"}
    @{Label="注册送积分";Value="10";Hint="数字"}
    @{Label="签到送积分";Value="5";Hint="数字"}
    @{Label="签到送文档券";Value="1";Hint="数字"}
    @{Label="创建文档扣券";Value="1";Hint="数字"}
) @(
    @{Text="保存配置";Desc="演示保存成功";Target="admin_project_list.iyu";Toast=""}
) ''

New-ListPage "admin_user_list.iyu" "用户列表" "搜索、查看、封禁和调整用户资产。" "admin_home.iyu" "item_user.iyu" @(
    @{"1"="demo";"2"="演示用户";"3"="积分 120 金币 60";"4"="正常";"5"="注册 2026-07-09";"-1"="1"}
    @{"1"="10086";"2"="文档达人";"3"="积分 980 文档券 35";"4"="VIP";"5"="注册 2026-07-01";"-1"="2"}
    @{"1"="20001";"2"="社区用户";"3"="积分 40 金币 12";"4"="封禁";"5"="封禁至 2026-08-01";"-1"="3"}
) @()

New-MenuPage "admin_user_detail.iyu" "用户详情" "用户资料、资产、文档、卡密记录和封禁操作。" "admin_user_list.iyu" @(
    @{Text="调整用户资产";Desc="积分、金币、经验、文档券、VIP";Target="admin_user_asset.iyu";Toast=""}
    @{Text="查看用户文档";Desc="筛选该用户创建的文档";Target="admin_doc_list.iyu";Toast=""}
    @{Text="发送客服消息";Desc="进入客服聊天页面";Target="admin_chat_room.iyu";Toast=""}
    @{Text="封禁用户";Desc="危险操作，需要二次确认";Target="";Toast="演示：用户已封禁"}
    @{Text="重置密码";Desc="危险操作，需要记录日志";Target="";Toast="演示：密码已重置为 123456"}
) ''

New-FormPage "admin_user_asset.iyu" "用户资产调整" "修改用户积分、金币、经验、文档券和 VIP。" "admin_user_detail.iyu" @(
    @{Label="资产类型";Value="文档券";Hint="积分/金币/经验/文档券/VIP"}
    @{Label="操作类型";Value="增加";Hint="增加/减少/设置"}
    @{Label="数值";Value="10";Hint="请输入数字"}
    @{Label="备注";Value="后台手动调整";Hint="请输入备注"}
) @(
    @{Text="提交调整";Desc="写入资产流水和操作日志";Target="admin_user_detail.iyu";Toast=""}
) ''

New-ListPage "admin_doc_list.iyu" "文档管理" "管理所有用户文档，支持搜索、删除、恢复和查看版本。" "admin_home.iyu" "item_doc.iyu" @(
    @{"1"="易运盈后台开发计划.md";"2"="融合易云后台和星光文档能力";"3"="2026-07-09 15:30";"4"="md";"5"="私密";"-1"="1"}
    @{"1"="用户协议.txt";"2"="普通文本示例";"3"="2026-07-09 14:20";"4"="txt";"5"="公开";"-1"="2"}
    @{"1"="接口备忘录.note";"2"="API 对接字段说明";"3"="2026-07-08 22:10";"4"="note";"5"="分享";"-1"="3"}
) @(
    @{Text="新建文档";Desc="管理员创建演示文档";Target="doc_create.iyu";Toast=""}
)

New-MenuPage "admin_doc_detail.iyu" "文档详情" "查看文档信息、内容、分享状态和版本历史。" "admin_doc_list.iyu" @(
    @{Text="打开编辑器";Desc="编辑当前文档内容";Target="doc_editor.iyu";Toast=""}
    @{Text="分享设置";Desc="私密、链接可见、公开";Target="doc_share.iyu";Toast=""}
    @{Text="历史版本";Desc="查看和恢复旧版本";Target="doc_version_list.iyu";Toast=""}
    @{Text="删除文档";Desc="进入回收站，不直接永久删除";Target="";Toast="演示：文档已进入回收站"}
) ''

New-ListPage "admin_card_list.iyu" "卡密列表" "查询卡密、筛选状态、查看使用人和批量生成。" "admin_home.iyu" "item_card.iyu" @(
    @{"1"="YYK-DEMO-0001";"2"="文档券";"3"="+10";"4"="未使用";"5"="";"-1"="1"}
    @{"1"="YYK-DEMO-0002";"2"="VIP";"3"="30天";"4"="已使用";"5"="demo";"-1"="2"}
    @{"1"="YYK-DEMO-0003";"2"="积分";"3"="+100";"4"="未使用";"5"="";"-1"="3"}
) @(
    @{Text="生成卡密";Desc="批量生成积分、VIP、文档券卡密";Target="admin_card_generate.iyu";Toast=""}
)

New-FormPage "admin_card_generate.iyu" "生成卡密" "批量生成可兑换的卡密。" "admin_card_list.iyu" @(
    @{Label="卡密类型";Value="文档券";Hint="积分/金币/VIP/经验/文档券"}
    @{Label="面值";Value="10";Hint="请输入面值"}
    @{Label="数量";Value="20";Hint="请输入生成数量"}
    @{Label="前缀";Value="YYK";Hint="可选前缀"}
    @{Label="过期时间";Value="2026-12-31";Hint="yyyy-mm-dd"}
) @(
    @{Text="立即生成";Desc="演示生成成功并返回列表";Target="admin_card_list.iyu";Toast=""}
) ''

New-ListPage "admin_notice_list.iyu" "公告列表" "管理首页公告、弹窗公告和系统消息。" "admin_home.iyu" "item_notice.iyu" @(
    @{"1"="易运盈后台 V1.0 规划";"2"="管理端和用户端演示源码包已生成";"3"="2026-07-09";"4"="弹窗公告";"5"="启用";"-1"="1"}
    @{"1"="文档系统上线";"2"="支持新建、编辑、分享和回收站";"3"="2026-07-08";"4"="首页公告";"5"="启用";"-1"="2"}
) @(
    @{Text="新建公告";Desc="发布新的系统公告";Target="admin_notice_edit.iyu";Toast=""}
    @{Text="远程更新";Desc="配置版本号、下载链接和强制更新";Target="admin_version_edit.iyu";Toast=""}
)

New-FormPage "admin_notice_edit.iyu" "公告编辑" "编辑公告标题、内容、类型和启用状态。" "admin_notice_list.iyu" @(
    @{Label="标题";Value="易运盈后台公告";Hint="请输入标题"}
    @{Label="内容";Value="欢迎使用易运盈后台";Hint="请输入内容"}
    @{Label="类型";Value="首页公告";Hint="首页公告/弹窗公告/系统消息"}
    @{Label="启用状态";Value="true";Hint="true 或 false"}
) @(
    @{Text="保存公告";Desc="演示保存成功";Target="admin_notice_list.iyu";Toast=""}
) ''

New-FormPage "admin_version_edit.iyu" "远程更新" "配置 App 最新版本、最低版本、更新内容和下载链接。" "admin_notice_list.iyu" @(
    @{Label="最新版本";Value="1.0.0";Hint="例如 1.0.0"}
    @{Label="最低版本";Value="1.0.0";Hint="低于此版本强制更新"}
    @{Label="下载链接";Value="https://example.com/app.apk";Hint="请输入 APK 链接"}
    @{Label="强制更新";Value="false";Hint="true 或 false"}
) @(
    @{Text="保存更新配置";Desc="演示保存成功";Target="admin_notice_list.iyu";Toast=""}
) ''

New-ListPage "admin_forum_post.iyu" "帖子管理" "审核、删除、置顶、加精、锁定社区帖子。" "admin_home.iyu" "item_post.iyu" @(
    @{"1"="易运盈后台怎么接入 iApp";"2"="demo";"3"="分享接入经验";"4"="评论 12";"5"="点赞 80";"-1"="1"}
    @{"1"="文档系统建议";"2"="文档达人";"3"="希望增加版本对比";"4"="评论 5";"5"="点赞 21";"-1"="2"}
) @(
    @{Text="板块管理";Desc="管理社区板块";Target="admin_forum_board.iyu";Toast=""}
)

New-ListPage "admin_forum_board.iyu" "板块管理" "新建、编辑、排序和停用社区板块。" "admin_forum_post.iyu" "item_post.iyu" @(
    @{"1"="公告交流";"2"="官方公告和用户反馈";"3"="帖子 30";"4"="启用";"5"="排序 1";"-1"="1"}
    @{"1"="文档讨论";"2"="文档写作和模板交流";"3"="帖子 86";"4"="启用";"5"="排序 2";"-1"="2"}
) @(
    @{Text="新建板块";Desc="接口已预留";Target="";Toast="板块创建接口已预留"}
)

New-ListPage "admin_chat_list.iyu" "客服会话" "查看用户咨询和未读消息。" "admin_home.iyu" "item_user.iyu" @(
    @{"1"="demo";"2"="演示用户";"3"="最后消息：如何兑换卡密";"4"="未读 2";"5"="刚刚";"-1"="1"}
    @{"1"="10086";"2"="文档达人";"3"="最后消息：文档无法保存";"4"="未读 1";"5"="5 分钟前";"-1"="2"}
) @(
    @{Text="进入演示聊天";Desc="打开客服聊天窗口";Target="admin_chat_room.iyu";Toast=""}
)

New-ListPage "admin_file_list.iyu" "文件管理" "查看头像、附件、应用截图和文档内容文件。" "admin_home.iyu" "item_file.iyu" @(
    @{"1"="avatar_demo.png";"2"="用户头像";"3"="32KB";"4"="正常";"5"="demo";"-1"="1"}
    @{"1"="doc_1.md";"2"="文档正文";"3"="8KB";"4"="正常";"5"="文档 1";"-1"="2"}
) @()

New-ListPage "admin_order_list.iyu" "订单管理" "查看会员、文档券、积分等订单。" "admin_home.iyu" "item_order.iyu" @(
    @{"1"="ORDER20260709001";"2"="购买文档券";"3"="9.90";"4"="已支付";"5"="demo";"-1"="1"}
    @{"1"="ORDER20260709002";"2"="开通 VIP";"3"="19.90";"4"="待支付";"5"="10086";"-1"="2"}
) @()

New-ListPage "admin_log_list.iyu" "操作日志" "记录登录、资产调整、封禁、删除等操作。" "admin_home.iyu" "item_log.iyu" @(
    @{"1"="管理员登录";"2"="demo";"3"="2026-07-09 16:00";"4"="成功";"5"="127.0.0.1";"-1"="1"}
    @{"1"="生成卡密";"2"="生成 20 张文档券卡密";"3"="2026-07-09 16:05";"4"="成功";"5"="demo";"-1"="2"}
) @()

New-MenuPage "admin_settings.iyu" "系统设置" "服务器、缓存、密码、演示模式和退出登录。" "admin_home.iyu" @(
    @{Text="服务器配置";Desc="修改后端地址和 API 标识";Target="server_config.iyu";Toast=""}
    @{Text="修改密码";Desc="修改当前管理员密码";Target="change_password.iyu";Toast=""}
    @{Text="清理缓存";Desc="清理本地草稿和列表缓存";Target="";Toast="缓存已清理（演示）"}
    @{Text="恢复演示数据";Desc="退出后恢复默认演示数据";Target="";Toast="演示数据已恢复"}
    @{Text="退出登录";Desc="返回模式选择";Target="mode_select.iyu";Toast=""}
) ''

# User pages and docs
New-MenuPage "user_profile.iyu" "个人中心" "用户资料、资产、VIP、服务器设置和退出登录。" "user_home.iyu" @(
    @{Text="我的资产";Desc="积分、金币、经验、文档券和 VIP";Target="user_asset.iyu";Toast=""}
    @{Text="修改密码";Desc="修改用户密码";Target="change_password.iyu";Toast=""}
    @{Text="服务器配置";Desc="修改后端地址和 API 标识";Target="server_config.iyu";Toast=""}
    @{Text="清理缓存";Desc="清理文档草稿和消息缓存";Target="";Toast="缓存已清理（演示）"}
    @{Text="退出登录";Desc="返回模式选择";Target="mode_select.iyu";Toast=""}
) ''

New-MenuPage "user_sign.iyu" "每日签到" "签到可获得积分、经验和文档券。" "user_home.iyu" @(
    @{Text="立即签到";Desc="演示奖励：积分 +5，经验 +2，文档券 +1";Target="";Toast="签到成功：积分 +5，文档券 +1"}
    @{Text="查看资产";Desc="查看签到后的资产变化";Target="user_asset.iyu";Toast=""}
) ''

New-MenuPage "user_asset.iyu" "我的资产" "积分 120，金币 60，经验 300，等级 3，文档券 8，VIP 未开通。" "user_home.iyu" @(
    @{Text="兑换卡密";Desc="使用卡密增加权益";Target="user_card_use.iyu";Toast=""}
    @{Text="开通 VIP";Desc="订单支付接口已预留";Target="";Toast="购买 VIP 接口已预留"}
    @{Text="资产流水";Desc="显示积分、文档券、VIP 变动记录";Target="";Toast="资产流水接口已预留"}
) ''

New-FormPage "user_card_use.iyu" "兑换卡密" "输入卡密兑换积分、金币、VIP、经验或文档券。" "user_home.iyu" @(
    @{Label="卡密";Value="YYK-DEMO-0001";Hint="请输入卡密"}
) @(
    @{Text="立即兑换";Desc="演示兑换文档券 +10";Target="user_asset.iyu";Toast=""}
) ''

New-ListPage "user_notice_list.iyu" "公告中心" "查看系统公告、活动公告和更新说明。" "user_home.iyu" "item_notice.iyu" @(
    @{"1"="欢迎使用易运盈后台";"2"="当前为 iApp 源码包演示版";"3"="2026-07-09";"4"="首页公告";"5"="已读";"-1"="1"}
    @{"1"="远程更新说明";"2"="后续可对接真实版本检查";"3"="2026-07-09";"4"="更新公告";"5"="未读";"-1"="2"}
) @()

New-ListPage "doc_list.iyu" "我的文档" "文档列表支持搜索、刷新、新建、编辑、分享和回收站。" "user_home.iyu" "item_doc.iyu" @(
    @{"1"="易运盈使用笔记.md";"2"="记录后台接入流程";"3"="今天 16:00";"4"="md";"5"="私密";"-1"="1"}
    @{"1"="接口字段说明.txt";"2"="登录、文档、卡密接口字段";"3"="昨天 20:10";"4"="txt";"5"="分享";"-1"="2"}
    @{"1"="运营计划.note";"2"="签到、会员、卡密运营";"3"="2026-07-08";"4"="note";"5"="公开";"-1"="3"}
) @(
    @{Text="新建文档";Desc="创建 txt、md、note、code 文档";Target="doc_create.iyu";Toast=""}
    @{Text="回收站";Desc="恢复或永久删除文档";Target="doc_recycle.iyu";Toast=""}
)

New-FormPage "doc_create.iyu" "新建文档" "创建文档会按项目配置消耗文档券。" "doc_list.iyu" @(
    @{Label="文档标题";Value="新建文档";Hint="请输入标题"}
    @{Label="文档类型";Value="md";Hint="txt/md/note/code"}
    @{Label="初始内容";Value="这里输入文档内容";Hint="请输入内容"}
) @(
    @{Text="创建并编辑";Desc="进入编辑器继续写作";Target="doc_editor.iyu";Toast=""}
) ''

New-FormPage "doc_editor.iyu" "文档编辑器" "支持标题、正文、本地草稿、保存、分享和版本历史。" "doc_list.iyu" @(
    @{Label="标题";Value="易运盈使用笔记.md";Hint="请输入标题"}
    @{Label="正文";Value="这里是演示文档内容。保存按钮会保留草稿并提示成功。";Hint="请输入正文"}
) @(
    @{Text="保存文档";Desc="演示保存成功，本地草稿保留";Target="";Toast="文档保存成功（演示）"}
    @{Text="分享设置";Desc="设置私密、链接可见或公开";Target="doc_share.iyu";Toast=""}
    @{Text="历史版本";Desc="查看和恢复旧版本";Target="doc_version_list.iyu";Toast=""}
    @{Text="预览文档";Desc="只读查看当前文档";Target="doc_preview.iyu";Toast=""}
) ''

New-MenuPage "doc_share.iyu" "文档分享" "设置文档权限：私密、链接可见、公开。" "doc_editor.iyu" @(
    @{Text="设为私密";Desc="只有自己能查看";Target="";Toast="已设为私密（演示）"}
    @{Text="链接可见";Desc="生成分享码，复制给他人查看";Target="";Toast="分享码：SHARE-DEMO-001"}
    @{Text="公开展示";Desc="公开文档广场可见";Target="";Toast="已设为公开（演示）"}
) ''

New-ListPage "doc_recycle.iyu" "文档回收站" "删除后的文档先进入回收站，可恢复或永久删除。" "doc_list.iyu" "item_doc.iyu" @(
    @{"1"="已删除文档.txt";"2"="这是回收站演示数据";"3"="2026-07-06";"4"="txt";"5"="已删除";"-1"="10"}
) @(
    @{Text="恢复选中文档";Desc="演示恢复成功";Target="doc_list.iyu";Toast=""}
)

New-ListPage "doc_version_list.iyu" "历史版本" "每次手动保存可生成一个历史版本。" "doc_editor.iyu" "item_doc.iyu" @(
    @{"1"="版本 3";"2"="新增卡密功能说明";"3"="今天 16:00";"4"="md";"5"="当前";"-1"="3"}
    @{"1"="版本 2";"2"="完善文档券规则";"3"="今天 15:30";"4"="md";"5"="可恢复";"-1"="2"}
    @{"1"="版本 1";"2"="初始内容";"3"="今天 15:00";"4"="md";"5"="可恢复";"-1"="1"}
) @()

New-MenuPage "doc_preview.iyu" "文档预览" "只读预览当前文档内容，后续可扩展 Markdown 渲染。" "doc_editor.iyu" @(
    @{Text="预览内容";Desc="这里显示当前文档正文摘要和格式化结果";Target="";Toast="当前为只读预览"}
    @{Text="返回编辑";Desc="继续编辑当前文档";Target="doc_editor.iyu";Toast=""}
) ''

New-ListPage "doc_search.iyu" "文档搜索" "按标题和内容摘要搜索文档。" "doc_list.iyu" "item_doc.iyu" @(
    @{"1"="搜索结果：接口字段说明";"2"="包含登录、文档、卡密字段";"3"="昨天";"4"="txt";"5"="分享";"-1"="2"}
) @()

# Forum and chat
New-ListPage "forum_board_list.iyu" "社区板块" "用户端社区入口，按板块查看帖子。" "user_home.iyu" "item_post.iyu" @(
    @{"1"="公告交流";"2"="官方公告、版本讨论";"3"="帖子 30";"4"="启用";"5"="今日更新";"-1"="1"}
    @{"1"="文档讨论";"2"="文档写作和模板交流";"3"="帖子 86";"4"="启用";"5"="热门";"-1"="2"}
) @(
    @{Text="进入帖子列表";Desc="查看演示帖子";Target="forum_post_list.iyu";Toast=""}
)

New-ListPage "forum_post_list.iyu" "帖子列表" "查看帖子、发帖、评论、点赞和举报。" "forum_board_list.iyu" "item_post.iyu" @(
    @{"1"="易运盈后台如何接入 iApp";"2"="demo";"3"="从配置 base_url 开始";"4"="评论 12";"5"="点赞 80";"-1"="1"}
    @{"1"="文档券规则建议";"2"="文档达人";"3"="VIP 是否免扣文档券";"4"="评论 5";"5"="点赞 21";"-1"="2"}
) @(
    @{Text="发布帖子";Desc="进入发帖页面";Target="forum_post_create.iyu";Toast=""}
    @{Text="查看帖子详情";Desc="打开演示帖子详情";Target="forum_post_detail.iyu";Toast=""}
)

New-FormPage "forum_post_create.iyu" "发布帖子" "用户发布社区帖子，可接入审核。" "forum_post_list.iyu" @(
    @{Label="标题";Value="";Hint="请输入标题"}
    @{Label="内容";Value="";Hint="请输入帖子内容"}
    @{Label="板块";Value="文档讨论";Hint="请选择板块"}
) @(
    @{Text="发布";Desc="演示发布成功";Target="forum_post_list.iyu";Toast=""}
) ''

New-MenuPage "forum_post_detail.iyu" "帖子详情" "查看帖子内容、点赞、评论和举报。" "forum_post_list.iyu" @(
    @{Text="帖子内容";Desc="这里展示演示帖子正文：如何把 iApp 与易运盈后台接口连接。";Target="";Toast="正在阅读帖子"}
    @{Text="点赞";Desc="给帖子点赞";Target="";Toast="点赞成功（演示）"}
    @{Text="评论";Desc="进入评论列表";Target="forum_comment_list.iyu";Toast=""}
    @{Text="举报";Desc="提交举报给审核员";Target="";Toast="举报已提交（演示）"}
) ''

New-ListPage "forum_comment_list.iyu" "评论列表" "帖子评论展示和回复入口。" "forum_post_detail.iyu" "item_comment.iyu" @(
    @{"1"="demo";"2"="这个接口设计很清楚";"3"="刚刚";"4"="正常";"5"="点赞 2";"-1"="1"}
    @{"1"="文档达人";"2"="建议增加 Markdown 预览";"3"="5 分钟前";"4"="正常";"5"="点赞 5";"-1"="2"}
) @(
    @{Text="发表评论";Desc="评论接口已预留";Target="";Toast="评论成功（演示）"}
)

New-ListPage "chat_room_list.iyu" "会话列表" "客服、私信和好友消息入口。" "user_home.iyu" "item_user.iyu" @(
    @{"1"="客服";"2"="易运盈客服";"3"="你好，请问需要帮助吗";"4"="未读 1";"5"="刚刚";"-1"="1"}
    @{"1"="10086";"2"="文档达人";"3"="文档模板发你了";"4"="已读";"5"="10 分钟前";"-1"="2"}
) @(
    @{Text="进入聊天";Desc="打开演示聊天页";Target="chat_room.iyu";Toast=""}
)

New-ListPage "chat_room.iyu" "聊天" "HTTP 轮询式聊天演示，后续可接真实 since_id 拉取。" "chat_room_list.iyu" "item_message_left.iyu" @(
    @{"1"="客服";"2"="易运盈客服";"3"="你好，这里是演示客服消息";"4"="刚刚";"5"="";"-1"="1"}
    @{"1"="我";"2"="demo";"3"="我想了解文档券怎么用";"4"="刚刚";"5"="";"-1"="2"}
) @(
    @{Text="发送消息";Desc="演示发送成功";Target="";Toast="消息已发送（演示）"}
)

New-ListPage "friend_list.iyu" "好友列表" "用户好友和私信入口。" "user_home.iyu" "item_user.iyu" @(
    @{"1"="10086";"2"="文档达人";"3"="共同编辑文档";"4"="在线";"5"="好友";"-1"="1"}
) @()

# Misc pages
New-MenuPage "message_center.iyu" "消息中心" "系统消息、客服未读、社区通知和文档通知。" "admin_home.iyu" @(
    @{Text="系统消息";Desc="公告、更新和后台通知";Target="user_notice_list.iyu";Toast=""}
    @{Text="客服消息";Desc="未读客服会话";Target="admin_chat_list.iyu";Toast=""}
    @{Text="社区通知";Desc="评论、点赞、审核结果";Target="forum_post_list.iyu";Toast=""}
) ''

New-ListPage "search_global.iyu" "全局搜索" "搜索用户、文档、帖子和卡密。" "admin_home.iyu" "item_simple.iyu" @(
    @{"1"="文档：易运盈使用笔记";"2"="匹配文档标题";"3"="今天";"4"="文档";"5"="";"-1"="1"}
    @{"1"="用户：demo";"2"="匹配用户账号";"3"="今天";"4"="用户";"5"="";"-1"="2"}
) @()

New-MenuPage "splash_update.iyu" "更新提示" "远程更新弹窗页面，展示版本号、更新内容和下载链接。" "mian.iyu" @(
    @{Text="立即更新";Desc="打开 APK 下载链接";Target="";Toast="下载链接接口已预留"}
    @{Text="稍后再说";Desc="返回启动页";Target="mian.iyu";Toast=""}
) ''

# Item templates
New-ItemTemplate "item_project.iyu" "项目" "admin_project_setting.iyu"
New-ItemTemplate "item_user.iyu" "用户" "admin_user_detail.iyu"
New-ItemTemplate "item_doc.iyu" "文档" "doc_editor.iyu"
New-ItemTemplate "item_card.iyu" "卡密" ""
New-ItemTemplate "item_notice.iyu" "公告" ""
New-ItemTemplate "item_post.iyu" "帖子" "forum_post_detail.iyu"
New-ItemTemplate "item_comment.iyu" "评论" ""
New-ItemTemplate "item_file.iyu" "文件" ""
New-ItemTemplate "item_order.iyu" "订单" ""
New-ItemTemplate "item_log.iyu" "日志" ""
New-ItemTemplate "item_simple.iyu" "结果" ""
New-ChatItem "item_message_left.iyu" "left" "#FFFFFF"
New-ChatItem "item_message_right.iyu" "right" "#DCEBFF"

# Package files
Ensure-Dir $outDir
$iappPath = Join-Path $outDir "易运盈后台.iApp"
$zipPath = Join-Path $outDir "易运盈后台_iApp源码.zip"
if (Test-Path -Path $iappPath) { Remove-Item -Path $iappPath -Force }
if (Test-Path -Path $zipPath) { Remove-Item -Path $zipPath -Force }

New-ZipFromDirectory $project $iappPath $false
New-ZipFromDirectory $project $zipPath $true

Write-Output "Generated project: $project"
Write-Output "Generated iApp: $iappPath"
Write-Output "Generated zip: $zipPath"
