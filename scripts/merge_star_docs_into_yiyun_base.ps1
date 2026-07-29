$ErrorActionPreference = "Stop"

$workspace = "C:\Users\Administrator\Documents\易云后台"
$base = Join-Path $workspace "generated\易运盈后台_易云底座源码"
$star = Join-Path $workspace "研究_星光文档\iApp前端"
$srcBase = Join-Path $base "src"
$srcStar = Join-Path $star "src"
$outIapp = Join-Path $workspace "generated\易运盈后台_易云底座.iApp"
$outZip = Join-Path $workspace "generated\易运盈后台_易云底座源码.zip"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-Utf8($path, $content) {
    $dir = Split-Path -Parent $path
    if (!(Test-Path -Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    [System.IO.File]::WriteAllText($path, $content, $utf8NoBom)
}

function Read-Utf8($path) {
    return [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)
}

function New-ZipFromDirectory($sourceDir, $zipPath, $includeRoot) {
    if (Test-Path -Path $zipPath) { Remove-Item -Path $zipPath -Force }
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $baseResolved = (Resolve-Path -Path $sourceDir).Path.TrimEnd('\')
        $rootName = Split-Path -Leaf $baseResolved
        Get-ChildItem -Path $sourceDir -Recurse -File -Force | ForEach-Object {
            $full = $_.FullName
            $rel = $full.Substring($baseResolved.Length + 1)
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

if (!(Test-Path -Path $base)) {
    throw "Base project not found: $base"
}

# Rename project metadata while preserving the original YiYun iApp tool version.
Write-Utf8 (Join-Path $base ".project") '<package>com.yiyunying.admin</package><toolversion>iApp V2.99959</toolversion><developer>yiyunying</developer><time>1783560000000</time>'

Write-Utf8 (Join-Path $base "AndroidManifest.xml") @"
<?xml version="1.0" encoding="utf-8"?>
<title>易运盈后台</title>
<icon>icon.png</icon>
<packageName>com.yiyunying.admin</packageName>
<versionName>1.9.9-y</versionName>
<versionint>2</versionint>
<sdk>15</sdk>
<yuv>3</yuv>
<remark>基于原版易云后台，加入星光文档模块。</remark>
<Permissions>android.permission.REQUEST_INSTALL_PACKAGES
android.permission.SYSTEM_ALERT_WINDOW
android.permission.READ_EXTERNAL_STORAGE
android.permission.WRITE_EXTERNAL_STORAGE
android.permission.INTERNET
android.permission.MOUNT_UNMOUNT_FILESYSTEMS
android.permission.ACCESS_NETWORK_STATE</Permissions>
<createTime>2026-07-09 16:50:00</createTime>
<upTime>2026-07-09 16:50:00</upTime>
"@

# Copy Star Docs resources without overwriting YiYun resources.
Get-ChildItem -Path (Join-Path $star "res") -Recurse -File | ForEach-Object {
    $rel = $_.FullName.Substring((Join-Path $star "res").Length + 1)
    $dest = Join-Path (Join-Path $base "res") $rel
    $destDir = Split-Path -Parent $dest
    if (!(Test-Path -Path $destDir)) { New-Item -ItemType Directory -Force -Path $destDir | Out-Null }
    if (!(Test-Path -Path $dest)) {
        Copy-Item -Path $_.FullName -Destination $dest -Force
    }
}

# Copy Star Docs src with safe names for conflicts.
$rename = @{
    "mian.iyu" = "xg_mian.iyu"
    "dl.iyu" = "xg_dl.iyu"
    "zc.iyu" = "xg_zc.iyu"
    "echofile.iyu" = "xg_echofile.iyu"
    "news.iyu" = "xg_news.iyu"
    "xgmm.iyu" = "xg_xgmm.iyu"
    "远程更新.iyu" = "xg_远程更新.iyu"
}

Get-ChildItem -Path $srcStar -File | Where-Object { $_.Name -notlike "*.bak" } | ForEach-Object {
    $destName = $_.Name
    if ($rename.ContainsKey($_.Name)) { $destName = $rename[$_.Name] }
    $dest = Join-Path $srcBase $destName
    Copy-Item -Path $_.FullName -Destination $dest -Force

    if ($destName -match '\.(iyu|myu)$') {
        $s = Read-Utf8 $dest
        $s = $s.Replace('uigo("dl.iyu")', 'uigo("xg_dl.iyu")')
        $s = $s.Replace('uigo("zc.iyu")', 'uigo("xg_zc.iyu")')
        $s = $s.Replace('uigo("echofile.iyu")', 'uigo("xg_echofile.iyu")')
        $s = $s.Replace('uigo("mian.iyu")', 'uigo("xg_mian.iyu")')
        $s = $s.Replace('uigo("news.iyu")', 'uigo("xg_news.iyu")')
        $s = $s.Replace('uigo("远程更新.iyu")', 'uigo("xg_远程更新.iyu")')
        $s = $s.Replace('"xgmm.iyu"', '"xg_xgmm.iyu"')
        $s = $s.Replace('uigo("内置浏览器.iyu")', 'uigo("内置浏览器.iyu")')

        # Selectively destroy one-way pages after navigation.
        if ($destName -eq "xg_mian.iyu") {
            $s = $s.Replace('uigo("xg_dl.iyu")' + "`r`n" + '				end()', 'uigo("xg_dl.iyu")' + "`r`n" + '				end()')
            $s = $s.Replace('uigo("xg_dl.iyu")' + "`n" + '				end()', 'uigo("xg_dl.iyu")' + "`n" + '				end()')
            $s = $s.Replace('uigo("xg_dl.iyu")' + "`r`n" + 'end()', 'uigo("xg_dl.iyu")' + "`r`n" + 'end()')
        }

        if ($destName -eq "我的.iyu") {
            $s = $s.Replace('uigo("xg_mian.iyu")' + "`r`n" + 'end()', 'uigo("xg_mian.iyu")' + "`r`n" + 'end()')
        }

        Write-Utf8 $dest $s
    }
}

# Add an isolated Star Docs entry page using real iApp .iyu structure.
Write-Utf8 (Join-Path $srcBase "xg_entry.iyu") @"
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
background=#f6f7fb</ppt>
<event></event>
</View>
<View id="2" did="1" type="LinearLayout">
<ppt>width=-1
height=55dp
orientation=horizontal
gravity=center_vertical
background=#29B6F6</ppt>
<event></event>
</View>
<View id="3" did="2" type="TextView">
<ppt>width=60dp
height=-1
text=返回
gravity=center
textColor=#ffffff
textSize=15sp</ppt>
<event><eventItme type="clicki">end()</eventItme></event>
</View>
<View id="4" did="2" type="TextView">
<ppt>width=-1
height=-1
text=星光文档系统
gravity=center
textColor=#ffffff
textSize=18sp
textStyle=bold</ppt>
<event></event>
</View>
<View id="5" did="1" type="ScrollView">
<ppt>width=-1
height=-1</ppt>
<event></event>
</View>
<View id="6" did="5" type="LinearLayout">
<ppt>width=-1
height=-2
orientation=vertical
padding=15dp</ppt>
<event></event>
</View>
<View id="7" did="6" type="TextView">
<ppt>width=-1
height=-2
text=已在易云后台底座中加入星光文档：登录、注册、文档列表、编辑器、分享菜单、个人中心、卡密、公告与管理页。
textColor=#555555
textSize=14sp
layout_marginBottom=12dp</ppt>
<event></event>
</View>
<View id="10" did="6" type="LinearLayout">
<ppt>width=-1
height=58dp
orientation=vertical
gravity=center_vertical
paddingLeft=15dp
paddingRight=15dp
background=#ffffff
layout_marginBottom=10dp</ppt>
<event><eventItme type="clicki">uigo("xg_mian.iyu")</eventItme></event>
</View>
<View id="11" did="10" type="TextView">
<ppt>width=-1
height=-2
text=进入星光文档启动页
textColor=#222222
textSize=16sp
textStyle=bold</ppt>
<event></event>
</View>
<View id="20" did="6" type="LinearLayout">
<ppt>width=-1
height=58dp
orientation=vertical
gravity=center_vertical
paddingLeft=15dp
paddingRight=15dp
background=#ffffff
layout_marginBottom=10dp</ppt>
<event><eventItme type="clicki">sss url="http://004.ink/wd"
uigo("xg_dl.iyu")</eventItme></event>
</View>
<View id="21" did="20" type="TextView">
<ppt>width=-1
height=-2
text=文档账号登录
textColor=#222222
textSize=16sp
textStyle=bold</ppt>
<event></event>
</View>
<View id="30" did="6" type="LinearLayout">
<ppt>width=-1
height=58dp
orientation=vertical
gravity=center_vertical
paddingLeft=15dp
paddingRight=15dp
background=#ffffff
layout_marginBottom=10dp</ppt>
<event><eventItme type="clicki">sss url="http://004.ink/wd"
uigo("xg_zc.iyu")</eventItme></event>
</View>
<View id="31" did="30" type="TextView">
<ppt>width=-1
height=-2
text=文档账号注册
textColor=#222222
textSize=16sp
textStyle=bold</ppt>
<event></event>
</View>
<View id="40" did="6" type="LinearLayout">
<ppt>width=-1
height=58dp
orientation=vertical
gravity=center_vertical
paddingLeft=15dp
paddingRight=15dp
background=#ffffff
layout_marginBottom=10dp</ppt>
<event><eventItme type="clicki">sss url="http://004.ink/wd"
uigo("主页.iyu")</eventItme></event>
</View>
<View id="41" did="40" type="TextView">
<ppt>width=-1
height=-2
text=直接进入文档主页
textColor=#222222
textSize=16sp
textStyle=bold</ppt>
<event></event>
</View>
<View id="50" did="6" type="LinearLayout">
<ppt>width=-1
height=58dp
orientation=vertical
gravity=center_vertical
paddingLeft=15dp
paddingRight=15dp
background=#ffffff
layout_marginBottom=10dp</ppt>
<event><eventItme type="clicki">tw("文档模块来自星光文档，已接入易云后台源码底座。")</eventItme></event>
</View>
<View id="51" did="50" type="TextView">
<ppt>width=-1
height=-2
text=模块说明
textColor=#222222
textSize=16sp
textStyle=bold</ppt>
<event></event>
</View>
<UIEventset><eventItme type="loading">sss url="http://004.ink/wd"</eventItme></UIEventset>
"@

# Insert Star Docs entry into YiYun dock first card if not already inserted.
$dock = Join-Path $srcBase "dock.iyu"
$dockText = Read-Utf8 $dock
if ($dockText -notmatch 'xg_entry\.iyu') {
    $insert = @"
<View id="24" did="11" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
gravity=center
layout_weight=1</ppt>
<event><eventItme type="clicki">uigo("xg_entry.iyu")</eventItme></event>
</View>
<View id="25" did="24" type="ImageView">
<ppt>width=35dp
height=35dp
src=@wendang.png</ppt>
<event></event>
</View>
<View id="26" did="24" type="TextView">
<ppt>width=-2
height=-2
text=文档系统
textColor=#555555
textSize=13sp
layout_marginTop=10dp</ppt>
<event></event>
</View>
"@
    $dockText = $dockText.Replace('<View id="44" did="1" type="CardView">', $insert + "`r`n" + '<View id="44" did="1" type="CardView">')
    Write-Utf8 $dock $dockText
}

# Add a readable merge note.
Write-Utf8 (Join-Path $base "易运盈合并说明.txt") @"
易运盈后台_易云底座版

处理方式：
1. 以原版易云后台 v1.9.9 iApp 工程作为底座。
2. 保留易云后台原 UI、事件和源码风格。
3. 加入星光文档全部主要页面、myu、mjava 与资源。
4. 冲突页面改名：
   mian.iyu -> xg_mian.iyu
   dl.iyu -> xg_dl.iyu
   zc.iyu -> xg_zc.iyu
   echofile.iyu -> xg_echofile.iyu
   news.iyu -> xg_news.iyu
   xgmm.iyu -> xg_xgmm.iyu
   远程更新.iyu -> xg_远程更新.iyu
5. 新增入口：xg_entry.iyu。
6. 在易云 dock.iyu 的第一组功能里加入“文档系统”入口。
7. 单向退出/登录类跳转保留或补充 end()，普通子页面跳转不销毁，方便返回。
"@

New-ZipFromDirectory $base $outIapp $false
New-ZipFromDirectory $base $outZip $true

Write-Output "Merged source: $base"
Write-Output "iApp: $outIapp"
Write-Output "zip: $outZip"
