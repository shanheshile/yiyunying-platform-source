$ErrorActionPreference = "Stop"

$workspace = Split-Path -Parent $PSScriptRoot
$baseSource = Join-Path $workspace "研究_易云后台\v199_iApp"
$starSource = Join-Path $workspace "研究_星光文档\iApp前端"
$target = Join-Path $workspace "generated\易运盈后台_功能融合源码"
$srcTarget = Join-Path $target "src"
$outIapp = Join-Path $workspace "generated\易运盈后台_功能融合.iApp"
$outZip = Join-Path $workspace "generated\易运盈后台_功能融合源码.zip"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-Utf8($path, $content) {
    $dir = Split-Path -Parent $path
    if (!(Test-Path -Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    [System.IO.File]::WriteAllText($path, $content, $utf8NoBom)
}

function Read-Utf8($path) {
    return [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)
}

function Copy-Tree($source, $destination) {
    if (Test-Path -Path $destination) { Remove-Item -Path $destination -Recurse -Force }
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination) | Out-Null
    Copy-Item -Path $source -Destination $destination -Recurse -Force
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

if (!(Test-Path -Path $baseSource)) { throw "Base iApp project not found: $baseSource" }
if (!(Test-Path -Path $starSource)) { throw "Star Docs project not found: $starSource" }

Copy-Tree $baseSource $target

Write-Utf8 (Join-Path $target ".project") '<package>com.yiyunying.admin</package><toolversion>iApp V2.99959</toolversion><developer>yiyunying</developer><time>1783560000000</time>'

Write-Utf8 (Join-Path $target "AndroidManifest.xml") @'
<?xml version="1.0" encoding="utf-8"?>
<title>易运盈后台</title>
<icon>icon.png</icon>
<packageName>com.yiyunying.admin</packageName>
<versionName>1.9.9-y-doc-fusion</versionName>
<versionint>3</versionint>
<sdk>15</sdk>
<yuv>3</yuv>
<remark>基于原版易云后台，功能融合星光文档文档体验。</remark>
<Permissions>android.permission.REQUEST_INSTALL_PACKAGES
android.permission.SYSTEM_ALERT_WINDOW
android.permission.READ_EXTERNAL_STORAGE
android.permission.WRITE_EXTERNAL_STORAGE
android.permission.INTERNET
android.permission.MOUNT_UNMOUNT_FILESYSTEMS
android.permission.ACCESS_NETWORK_STATE</Permissions>
<createTime>2026-07-09 18:20:00</createTime>
<upTime>2026-07-09 18:20:00</upTime>
'@

$starRes = Join-Path $starSource "res"
if (Test-Path -Path $starRes) {
    Get-ChildItem -Path $starRes -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($starRes.Length + 1)
        $dest = Join-Path (Join-Path $target "res") $rel
        $destDir = Split-Path -Parent $dest
        if (!(Test-Path -Path $destDir)) { New-Item -ItemType Directory -Force -Path $destDir | Out-Null }
        if (!(Test-Path -Path $dest)) {
            Copy-Item -Path $_.FullName -Destination $dest -Force
        }
    }
}

$rename = @{
    "mian.iyu" = "xg_mian.iyu"
    "dl.iyu" = "xg_dl.iyu"
    "zc.iyu" = "xg_zc.iyu"
    "echofile.iyu" = "xg_echofile.iyu"
    "news.iyu" = "xg_news.iyu"
    "xgmm.iyu" = "xg_xgmm.iyu"
    "远程更新.iyu" = "xg_远程更新.iyu"
}

$starSrc = Join-Path $starSource "src"
Get-ChildItem -Path $starSrc -File | Where-Object { $_.Name -notlike "*.bak" } | ForEach-Object {
    $destName = $_.Name
    if ($rename.ContainsKey($_.Name)) { $destName = $rename[$_.Name] }
    $dest = Join-Path $srcTarget $destName
    Copy-Item -Path $_.FullName -Destination $dest -Force

    if ($destName -match '\.(iyu|myu)$') {
        $s = Read-Utf8 $dest
        $s = $s.Replace('"mian.iyu"', '"xg_mian.iyu"')
        $s = $s.Replace('"dl.iyu"', '"xg_dl.iyu"')
        $s = $s.Replace('"zc.iyu"', '"xg_zc.iyu"')
        $s = $s.Replace('"echofile.iyu"', '"xg_echofile.iyu"')
        $s = $s.Replace('"news.iyu"', '"xg_news.iyu"')
        $s = $s.Replace('"xgmm.iyu"', '"xg_xgmm.iyu"')
        $s = $s.Replace('"远程更新.iyu"', '"xg_远程更新.iyu"')
        Write-Utf8 $dest $s
    }
}

Write-Utf8 (Join-Path $srcTarget "yy_doc_home.iyu") @'
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
background=#F6F8FB</ppt>
<event></event>
</View>
<View id="2" did="1" type="LinearLayout">
<ppt>width=-1
height=78dp
orientation=horizontal
gravity=bottom
background=#29B6F6
paddingLeft=12dp
paddingRight=12dp
paddingBottom=7dp</ppt>
<event></event>
</View>
<View id="3" did="2" type="TextView">
<ppt>width=52dp
height=40dp
text=返回
gravity=center
textColor=#ffffff
textSize=14sp</ppt>
<event><eventItme type="clicki">end()</eventItme></event>
</View>
<View id="4" did="2" type="TextView">
<ppt>width=-1
height=40dp
text=易运盈文档中心
gravity=center_vertical
textColor=#ffffff
textSize=19sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
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
padding=12dp</ppt>
<event></event>
</View>
<View id="7" did="6" type="LinearLayout">
<ppt>width=-1
height=92dp
orientation=vertical
gravity=center_vertical
background=#ffffff
paddingLeft=14dp
paddingRight=14dp
layout_marginBottom=10dp</ppt>
<event></event>
</View>
<View id="8" did="7" type="TextView">
<ppt>width=-1
height=-2
text=文档接口状态
textColor=#222222
textSize=17sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="9" did="7" type="TextView">
<ppt>width=-1
height=-2
text=正在读取文档数量...
textColor=#666666
textSize=13sp
layout_marginTop=8dp
maxLines=2
ellipsize=end</ppt>
<event></event>
</View>
<View id="10" did="6" type="LinearLayout">
<ppt>width=-1
height=74dp
orientation=vertical
gravity=center_vertical
background=#ffffff
paddingLeft=14dp
paddingRight=14dp
layout_marginBottom=8dp</ppt>
<event><eventItme type="clicki">uigo("file.iyu")</eventItme></event>
</View>
<View id="11" did="10" type="TextView">
<ppt>width=-1
height=-2
text=文档列表
textColor=#222222
textSize=16sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="12" did="10" type="TextView">
<ppt>width=-1
height=-2
text=查看、打开、长按删除后台文档
textColor=#777777
textSize=12sp
layout_marginTop=4dp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="20" did="6" type="LinearLayout">
<ppt>width=-1
height=74dp
orientation=vertical
gravity=center_vertical
background=#ffffff
paddingLeft=14dp
paddingRight=14dp
layout_marginBottom=8dp</ppt>
<event><eventItme type="clicki">uigo("fileadd.iyu")</eventItme></event>
</View>
<View id="21" did="20" type="TextView">
<ppt>width=-1
height=-2
text=新建文档
textColor=#222222
textSize=16sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="22" did="20" type="TextView">
<ppt>width=-1
height=-2
text=创建成功后自动进入编辑器
textColor=#777777
textSize=12sp
layout_marginTop=4dp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="30" did="6" type="LinearLayout">
<ppt>width=-1
height=74dp
orientation=vertical
gravity=center_vertical
background=#ffffff
paddingLeft=14dp
paddingRight=14dp
layout_marginBottom=8dp</ppt>
<event><eventItme type="clicki">f(sss.file==null||sss.file=="")
{
tw("请先从文档列表选择一个文档")
}
else
{
uigo("echofile.iyu")
}</eventItme></event>
</View>
<View id="31" did="30" type="TextView">
<ppt>width=-1
height=-2
text=继续编辑上次文档
textColor=#222222
textSize=16sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="32" did="30" type="TextView">
<ppt>width=-1
height=-2
text=需要先在列表里打开过文档
textColor=#777777
textSize=12sp
layout_marginTop=4dp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="40" did="6" type="LinearLayout">
<ppt>width=-1
height=74dp
orientation=vertical
gravity=center_vertical
background=#ffffff
paddingLeft=14dp
paddingRight=14dp
layout_marginBottom=8dp</ppt>
<event><eventItme type="clicki">uigo("yy_doc_about.iyu")</eventItme></event>
</View>
<View id="41" did="40" type="TextView">
<ppt>width=-1
height=-2
text=融合说明
textColor=#222222
textSize=16sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="42" did="40" type="TextView">
<ppt>width=-1
height=-2
text=查看已接入的易云接口和星光体验
textColor=#777777
textSize=12sp
layout_marginTop=4dp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="50" did="6" type="LinearLayout">
<ppt>width=-1
height=74dp
orientation=vertical
gravity=center_vertical
background=#ffffff
paddingLeft=14dp
paddingRight=14dp
layout_marginBottom=8dp</ppt>
<event><eventItme type="clicki">uigo("xg_entry.iyu")</eventItme></event>
</View>
<View id="51" did="50" type="TextView">
<ppt>width=-1
height=-2
text=星光文档原型页
textColor=#222222
textSize=16sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="52" did="50" type="TextView">
<ppt>width=-1
height=-2
text=保留原星光页面，主流程已融合到易云文档
textColor=#777777
textSize=12sp
layout_marginTop=4dp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<UIEventset><eventItme type="loading">t()
{
  ss(sss.url+"wdxt_sl.php?admin="+sss.admin+"&api="+sss.api,urll)
  hs(urll,urll)
  ufnsui()
  {
    f(urll==null||urll=="")
    {
      us(9,"text","文档数量读取失败，请检查后台地址、账号和 api")
    }
    else
    {
      us(9,"text","当前后台文档数量："+urll)
    }
  }
}</eventItme></UIEventset>
'@

Write-Utf8 (Join-Path $srcTarget "yy_doc_about.iyu") @'
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
background=#F6F8FB</ppt>
<event></event>
</View>
<View id="2" did="1" type="LinearLayout">
<ppt>width=-1
height=72dp
orientation=horizontal
gravity=bottom
background=#29B6F6
paddingLeft=12dp
paddingRight=12dp
paddingBottom=7dp</ppt>
<event></event>
</View>
<View id="3" did="2" type="TextView">
<ppt>width=52dp
height=40dp
text=返回
gravity=center
textColor=#ffffff
textSize=14sp</ppt>
<event><eventItme type="clicki">end()</eventItme></event>
</View>
<View id="4" did="2" type="TextView">
<ppt>width=-1
height=40dp
text=融合说明
gravity=center_vertical
textColor=#ffffff
textSize=19sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
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
padding=14dp</ppt>
<event></event>
</View>
<View id="7" did="6" type="TextView">
<ppt>width=-1
height=-2
text=易运盈后台以易云后台账号、密码、api 和后台地址为主线，文档模块直接使用 wdxt_list.php、wdxt_sl.php、wdxt_add.php、wdxt_ck.php、wdxt_xg.php、wdxt_delete.php。星光文档的首页、新建后编辑、文档说明、更多入口和删除确认体验已经融入主流程。
textColor=#333333
textSize=14sp
lineSpacingExtra=4dp</ppt>
<event></event>
</View>
<View id="8" did="6" type="TextView">
<ppt>width=-1
height=-2
text=主流程：文档中心 → 文档列表 → 打开编辑 → 保存；文档中心 → 新建文档 → 自动打开编辑；列表长按 → 删除后刷新。登录、启动页等单向跳转保留 end，列表到编辑不销毁，方便返回。
textColor=#333333
textSize=14sp
lineSpacingExtra=4dp
layout_marginTop=12dp</ppt>
<event></event>
</View>
<View id="9" did="6" type="TextView">
<ppt>width=-1
height=-2
text=原星光文档页面仍随包保留为原型参考，但后台可用的文档功能走易云接口，避免两个账号体系互相割裂。
textColor=#333333
textSize=14sp
lineSpacingExtra=4dp
layout_marginTop=12dp</ppt>
<event></event>
</View>
<UIEventset></UIEventset>
'@

Write-Utf8 (Join-Path $srcTarget "file.iyu") @'
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
background=#F6F8FB</ppt>
<event></event>
</View>
<View id="2" did="1" type="LinearLayout">
<ppt>width=-1
height=78dp
orientation=horizontal
gravity=bottom
background=#29B6F6
paddingLeft=12dp
paddingRight=12dp
paddingBottom=7dp</ppt>
<event></event>
</View>
<View id="3" did="2" type="TextView">
<ppt>width=52dp
height=40dp
text=返回
gravity=center
textColor=#ffffff
textSize=14sp</ppt>
<event><eventItme type="clicki">end()</eventItme></event>
</View>
<View id="4" did="2" type="TextView">
<ppt>width=-1
height=40dp
text=文档列表
gravity=center_vertical
textColor=#ffffff
textSize=19sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="19" did="2" type="TextView">
<ppt>width=70dp
height=40dp
text=载入中
gravity=center
textColor=#ffffff
textSize=13sp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="11" did="1" type="LinearLayout">
<ppt>width=-1
height=56dp
orientation=horizontal
background=#ffffff
layout_marginLeft=10dp
layout_marginRight=10dp
layout_marginTop=10dp
layout_marginBottom=6dp</ppt>
<event></event>
</View>
<View id="13" did="11" type="TextView">
<ppt>width=-1
height=-1
text=新建
gravity=center
textColor=#29B6F6
textSize=15sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
<event><eventItme type="clicki">uigo("fileadd.iyu")</eventItme></event>
</View>
<View id="14" did="11" type="TextView">
<ppt>width=-1
height=-1
text=刷新
gravity=center
textColor=#29B6F6
textSize=15sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
<event><eventItme type="clicki">uigo("file.iyu")
end()</eventItme></event>
</View>
<View id="15" did="11" type="TextView">
<ppt>width=-1
height=-1
text=中心
gravity=center
textColor=#29B6F6
textSize=15sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
<event><eventItme type="clicki">uigo("yy_doc_home.iyu")
end()</eventItme></event>
</View>
<View id="6" did="1" type="RelativeLayout">
<ppt>width=-1
height=-1
layout_marginTop=2dp</ppt>
<event></event>
</View>
<View id="7" did="6" type="ListView">
<ppt>width=-1
height=-1
dividerHeight=0dp
visibility=gone</ppt>
<event></event>
</View>
<View id="8" did="6" type="TextView">
<ppt>width=-2
height=-2
text=正在加载文档...
textColor=#535353
textStyle=bold
textSize=15sp
ut_centerInParent=true
maxLines=3
gravity=center</ppt>
<event></event>
</View>
<View id="18" did="6" type="TextView">
<ppt>width=-2
height=-2
text=点击编辑，长按删除
textColor=#777777
textSize=12sp
ut_alignParentBottom=true
layout_marginLeft=12dp
layout_marginBottom=8dp</ppt>
<event></event>
</View>
<UIEventset><eventItme type="loading">t()
{
  ss(sss.url+"wdxt_list.php?admin="+sss.admin+"&pass="+sss.pass+"&api="+sss.api,url)
  hs(url,url)
  syso(url)
  f(url==null)
  {
    ufnsui()
    {
      us(8,"text","数据加载失败，请重试")
      us(7,"visibility","gone")
      us(18,"visibility","gone")
    }
  }
  else f(url=="后台账号不存在"||url=="api不存在"||url=="参数不完整")
  {
    ufnsui()
    {
      us(8,"text",url)
      us(7,"visibility","gone")
      us(18,"visibility","gone")
    }
  }
  else f(url=="")
  {
    ufnsui()
    {
      us(8,"text","后台还没有文档")
      us(7,"visibility","gone")
      us(18,"visibility","gone")
    }
  }
  else
  {
    sj(url,"&lt;br&gt;",null,url)
    sl(url,"&lt;br&gt;",url)
    for(url;url)
    {
      json(url,a)
      json(a,"get","mc",name)
      ss("文档名称:"+name,name)
      json(a,"get","cd",cd)
      ss("内容长度:"+cd,cd)
      json(a,"get","sj",time)
      ss("最后修改:"+time,time)
      ula(yy_doc_list,2=name,3=cd,4=time)
    }
    ufnsui()
    {
      uls(7, yy_doc_list, "filelist.iyu", -1, -2)
      us(8,"visibility","gone")
      us(7,"visibility","visible")
      us(18,"visibility","visible")
    }
  }
}
t()
{
  ss(sss.url+"wdxt_sl.php?admin="+sss.admin+"&api="+sss.api,urll)
  hs(urll,urll)
  ufnsui()
  {
    f(urll==null||urll=="")
    {
      us(19,"text","0")
    }
    else
    {
      us(19,"text",urll)
    }
  }
}</eventItme><eventItme type="restart"></eventItme><eventItme type="loadingComplete"></eventItme></UIEventset>
'@

Write-Utf8 (Join-Path $srcTarget "fileadd.iyu") @'
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
background=#F6F8FB</ppt>
<event></event>
</View>
<View id="2" did="1" type="LinearLayout">
<ppt>width=-1
height=78dp
orientation=horizontal
gravity=bottom
background=#29B6F6
paddingLeft=12dp
paddingRight=12dp
paddingBottom=7dp</ppt>
<event></event>
</View>
<View id="3" did="2" type="TextView">
<ppt>width=52dp
height=40dp
text=返回
gravity=center
textColor=#ffffff
textSize=14sp</ppt>
<event><eventItme type="clicki">end()</eventItme></event>
</View>
<View id="4" did="2" type="TextView">
<ppt>width=-1
height=40dp
text=新建文档
gravity=center_vertical
textColor=#ffffff
textSize=19sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="6" did="1" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
padding=14dp</ppt>
<event></event>
</View>
<View id="9" did="6" type="LinearLayout">
<ppt>width=-1
height=90dp
orientation=vertical
background=#ffffff
paddingLeft=12dp
paddingRight=12dp
paddingTop=12dp
layout_marginTop=8dp</ppt>
<event></event>
</View>
<View id="7" did="9" type="EditText">
<ppt>width=-1
height=50dp
text=
background=
singleline=true
hint=输入文档名
textSize=16sp
textcolor=#222222
textcolorhint=#999999
maxLength=28</ppt>
<event></event>
</View>
<View id="8" did="9" type="TextView">
<ppt>width=-1
height=2dp
text=
background=#29B6F6</ppt>
<event></event>
</View>
<View id="12" did="6" type="TextView">
<ppt>width=-1
height=-2
text=创建成功后会直接打开编辑器，保存时写入易云后台文档接口。
textColor=#666666
textSize=13sp
layout_marginTop=12dp
lineSpacingExtra=3dp</ppt>
<event></event>
</View>
<View id="10" did="6" type="Button">
<ppt>width=-1
height=46dp
text=创建并编辑
background=#29B6F6
textColor=#ffffff
textSize=15sp
layout_marginTop=18dp
layout_marginLeft=6dp
layout_marginRight=6dp</ppt>
<event><eventItme type="clicki">ug(7,"text",name)
f(name=="")
{
  tw("请输入文档名")
}
else
{
  t()
  {
    ss(sss.url+"wdxt_add.php?admin="+sss.admin+"&file="+name+"&pass="+sss.pass+"&api="+sss.api,url)
    hs(url,url)
    f(url==null)
    {
      ufnsui()
      {
        tw("网络错误")
      }
    }
    else
    {
      ufnsui()
      {
        tw(url)
        f(url?"成功")
        {
          sss file=name
          uigo("echofile.iyu")
          end()
        }
      }
    }
  }
}</eventItme></event>
</View>
<UIEventset><eventItme type="loading"></eventItme><eventItme type="loadingComplete"></eventItme></UIEventset>
'@

Write-Utf8 (Join-Path $srcTarget "echofile.iyu") @'
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
background=#F6F8FB</ppt>
<event></event>
</View>
<View id="2" did="1" type="LinearLayout">
<ppt>width=-1
height=78dp
orientation=horizontal
gravity=bottom
background=#29B6F6
paddingLeft=12dp
paddingRight=12dp
paddingBottom=7dp</ppt>
<event></event>
</View>
<View id="3" did="2" type="TextView">
<ppt>width=52dp
height=40dp
text=返回
gravity=center
textColor=#ffffff
textSize=14sp</ppt>
<event><eventItme type="clicki">end()</eventItme></event>
</View>
<View id="4" did="2" type="TextView">
<ppt>width=-1
height=40dp
text=编辑文档
gravity=center_vertical
textColor=#ffffff
textSize=19sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="13" did="2" type="TextView">
<ppt>width=58dp
height=40dp
text=列表
gravity=center
textColor=#ffffff
textSize=14sp
maxLines=1
ellipsize=end</ppt>
<event><eventItme type="clicki">uigo("file.iyu")
end()</eventItme></event>
</View>
<View id="6" did="1" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical</ppt>
<event></event>
</View>
<View id="10" did="6" type="TextView">
<ppt>width=-1
height=38dp
text=当前文档
gravity=center_vertical
paddingLeft=12dp
paddingRight=12dp
textColor=#333333
textSize=14sp
background=#ffffff
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="12" did="6" type="TextView">
<ppt>width=-1
height=30dp
text=正在读取内容...
gravity=center_vertical
paddingLeft=12dp
paddingRight=12dp
textColor=#777777
textSize=12sp
background=#ffffff
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="7" did="6" type="RelativeLayout">
<ppt>width=-1
height=-1
layout_weight=1</ppt>
<event></event>
</View>
<View id="8" did="7" type="Button">
<ppt>width=-1
height=46dp
text=保存内容
background=#29B6F6
layout_marginLeft=10dp
layout_marginRight=10dp
layout_marginBottom=10dp
textColor=#ffffff
textSize=15sp
ut_alignParentBottom=true</ppt>
<event><eventItme type="clicki">ug(9,"text",content)
sr(content,"\n","&lt;br&gt;",post)
t()
{
  ss(sss.url+"wdxt_xg.php?admin="+sss.admin+"&pass="+sss.pass+"&file="+sss.file+"&nr="+post+"&api="+sss.api,url)
  hs(url,url)
  f(url==null)
  {
    ufnsui()
    {
      us(12,"text","保存失败：网络错误")
      tw("网络错误")
    }
  }
  else
  {
    ufnsui()
    {
      us(12,"text","保存结果："+url)
      tw(url)
    }
  }
}</eventItme></event>
</View>
<View id="9" did="7" type="EditText">
<ppt>width=-1
height=-1
ut_above=8
text=
background=#ffffffff
hint=在这里写入文档内容
gravity=top
layout_margin=10dp
padding=10dp
textSize=14sp</ppt>
<event><eventItme type="aftertextchanged">us(12,"text","内容已修改，点击保存同步到后台")</eventItme></event>
</View>
<UIEventset><eventItme type="loading">ufnsui()
{
  f(sss.file==null||sss.file=="")
  {
    us(10,"text","未选择文档")
    us(12,"text","请从文档列表打开文档")
  }
  else
  {
    us(10,"text",sss.file)
    us(12,"text","正在读取内容...")
  }
}
f(sss.file!=null&&sss.file!="")
{
  t()
  {
    ss(sss.url+"wdxt_ck.php?admin="+sss.admin+"&file="+sss.file+"&api="+sss.api,url)
    hs(url,url)
    f(url==null)
    {
      ufnsui()
      {
        us(9,"text","")
        us(12,"text","读取失败：网络错误")
      }
    }
    else
    {
      sr(url,"&lt;br&gt;","\n",url)
      ufnsui()
      {
        us(9,"text",url)
        us(12,"text","内容已载入")
      }
    }
  }
}</eventItme></UIEventset>
'@

Write-Utf8 (Join-Path $srcTarget "filelist.iyu") @'
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=76dp
orientation=vertical
gravity=center_vertical
background=#ffffff
layout_marginLeft=10dp
layout_marginRight=10dp
layout_marginTop=8dp
layout_marginBottom=1dp
paddingLeft=12dp
paddingRight=12dp</ppt>
<event><eventItme type="clicki">ulag(st_vW,2,file)
sj(file,"文档名称:",null,sss.file)
uigo("echofile.iyu")</eventItme><eventItme type="press">ulag(st_vW,2,file)
sj(file,"文档名称:",null,sss.file)
utw(null,"确定删除","是否删除这个文档？删除后不可恢复。","删除","取消",false,v)
{
  t()
  {
    ss(sss.url+"wdxt_delete.php?admin="+sss.admin+"&pass="+sss.pass+"&file="+sss.file+"&api="+sss.api,url)
    hs(url,url)
    f(url==null)
    {
      ufnsui()
      {
        tw("网络错误")
      }
    }
    else
    {
      ufnsui()
      {
        tw(url)
        f(url?"成功")
        {
          uigo("file.iyu")
          end()
        }
      }
    }
  }
}
else
{
}</eventItme></event>
</View>
<View id="2" did="1" type="TextView">
<ppt>width=-1
height=-2
text=文档名
textColor=#222222
textStyle=bold
textSize=16sp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="5" did="1" type="LinearLayout">
<ppt>width=-1
height=-2
orientation=horizontal
layout_marginTop=5dp</ppt>
<event></event>
</View>
<View id="3" did="5" type="TextView">
<ppt>width=-1
height=-2
text=内容长度
textColor=#666666
textSize=12sp
layout_weight=1
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="4" did="5" type="TextView">
<ppt>width=-1
height=-2
text=修改时间
textColor=#666666
textSize=12sp
layout_weight=1
gravity=right
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="6" did="1" type="TextView">
<ppt>width=-1
height=-2
text=点击编辑 / 长按删除
textColor=#999999
textSize=11sp
layout_marginTop=3dp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<UIEventset></UIEventset>
'@

Write-Utf8 (Join-Path $srcTarget "xg_entry.iyu") @'
<View id="1" did="0" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
background=#F6F8FB</ppt>
<event></event>
</View>
<View id="2" did="1" type="LinearLayout">
<ppt>width=-1
height=72dp
orientation=horizontal
gravity=bottom
background=#29B6F6
paddingLeft=12dp
paddingRight=12dp
paddingBottom=7dp</ppt>
<event></event>
</View>
<View id="3" did="2" type="TextView">
<ppt>width=52dp
height=40dp
text=返回
gravity=center
textColor=#ffffff
textSize=14sp</ppt>
<event><eventItme type="clicki">f(sss.yy_url!=null&&sss.yy_url!="")
{
sss url=sss.yy_url
}
end()</eventItme></event>
</View>
<View id="4" did="2" type="TextView">
<ppt>width=-1
height=40dp
text=星光文档原型
gravity=center_vertical
textColor=#ffffff
textSize=19sp
textStyle=bold
layout_weight=1
maxLines=1
ellipsize=end</ppt>
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
padding=14dp</ppt>
<event></event>
</View>
<View id="7" did="6" type="TextView">
<ppt>width=-1
height=-2
text=这里保留星光文档原版页面，便于继续参考它的首页、编辑器、菜单和个人中心。易运盈后台的正式文档功能请从“文档中心”进入，那里已经接入易云后台接口。
textColor=#444444
textSize=14sp
lineSpacingExtra=4dp
layout_marginBottom=10dp</ppt>
<event></event>
</View>
<View id="10" did="6" type="LinearLayout">
<ppt>width=-1
height=64dp
orientation=vertical
gravity=center_vertical
paddingLeft=14dp
paddingRight=14dp
background=#ffffff
layout_marginBottom=8dp</ppt>
<event><eventItme type="clicki">uigo("xg_mian.iyu")</eventItme></event>
</View>
<View id="11" did="10" type="TextView">
<ppt>width=-1
height=-2
text=打开星光启动页
textColor=#222222
textSize=16sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="20" did="6" type="LinearLayout">
<ppt>width=-1
height=64dp
orientation=vertical
gravity=center_vertical
paddingLeft=14dp
paddingRight=14dp
background=#ffffff
layout_marginBottom=8dp</ppt>
<event><eventItme type="clicki">sss yy_url=sss.url
sss url="http://004.ink/wd"
uigo("xg_dl.iyu")</eventItme></event>
</View>
<View id="21" did="20" type="TextView">
<ppt>width=-1
height=-2
text=星光账号登录
textColor=#222222
textSize=16sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<View id="30" did="6" type="LinearLayout">
<ppt>width=-1
height=64dp
orientation=vertical
gravity=center_vertical
paddingLeft=14dp
paddingRight=14dp
background=#ffffff
layout_marginBottom=8dp</ppt>
<event><eventItme type="clicki">sss yy_url=sss.url
sss url="http://004.ink/wd"
uigo("xg_zc.iyu")</eventItme></event>
</View>
<View id="31" did="30" type="TextView">
<ppt>width=-1
height=-2
text=星光账号注册
textColor=#222222
textSize=16sp
textStyle=bold
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
<UIEventset></UIEventset>
'@

$dock = Join-Path $srcTarget "dock.iyu"
$dockText = Read-Utf8 $dock
if ($dockText -notmatch 'yy_doc_home\.iyu') {
    $insert = @'
<View id="24" did="11" type="LinearLayout">
<ppt>width=-1
height=-1
orientation=vertical
gravity=center
layout_weight=1</ppt>
<event><eventItme type="clicki">uigo("yy_doc_home.iyu")</eventItme></event>
</View>
<View id="25" did="24" type="ImageView">
<ppt>width=32dp
height=32dp
src=@wendang.png</ppt>
<event></event>
</View>
<View id="26" did="24" type="TextView">
<ppt>width=-2
height=-2
text=文档中心
textColor=#555555
textSize=12sp
layout_marginTop=8dp
maxLines=1
ellipsize=end</ppt>
<event></event>
</View>
'@
    $dockText = $dockText.Replace('<View id="44" did="1" type="CardView">', $insert + "`r`n" + '<View id="44" did="1" type="CardView">')
    Write-Utf8 $dock $dockText
}

$gnzx = Join-Path $srcTarget "gnzx.iyu"
$gnzxText = Read-Utf8 $gnzx
$gnzxText = $gnzxText.Replace('uigo("file.iyu")', 'uigo("yy_doc_home.iyu")')
Write-Utf8 $gnzx $gnzxText

Write-Utf8 (Join-Path $target "易运盈功能融合说明.txt") @'
易运盈后台 功能融合版

生成方式：
1. 以原版易云后台 v1.9.9 iApp 工程为底座，不重写工程结构。
2. 并入星光文档资源、页面、myu 与 mjava，冲突文件使用 xg_ 前缀保留。
3. 正式文档主流程不再走星光 word/*.php，而是接入易云后台 wdxt_* 接口。

已融合的可用功能：
1. 文档中心 yy_doc_home.iyu：读取易云文档数量，进入列表、新建、继续编辑、融合说明。
2. 文档列表 file.iyu：使用 wdxt_list.php 和 wdxt_sl.php，支持空状态、失败提示、刷新。
3. 文档项 filelist.iyu：点击打开编辑，长按删除，删除成功后刷新列表。
4. 新建文档 fileadd.iyu：使用 wdxt_add.php，成功后设置 sss.file 并自动进入 echofile.iyu，随后 end 销毁新建页。
5. 编辑文档 echofile.iyu：使用 wdxt_ck.php 读取，wdxt_xg.php 保存，支持返回、回列表和保存状态。
6. 导航优化：登录/启动等原本单向页面保留 end；刷新、回列表、新建成功等单向跳转补 end；列表进入编辑不 end，便于返回。

星光文档保留方式：
原星光页面保留在 xg_entry.iyu 入口中，可作为原型参考继续打开；但易运盈后台正式可用功能以易云账号、sss.url、sss.admin、sss.pass、sss.api 为准。
'@

New-ZipFromDirectory $target $outIapp $false
New-ZipFromDirectory $target $outZip $true

Write-Output "Fused source: $target"
Write-Output "iApp: $outIapp"
Write-Output "zip: $outZip"
