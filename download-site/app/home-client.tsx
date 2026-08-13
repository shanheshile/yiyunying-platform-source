"use client";

import {
  Activity,
  AppWindow,
  ArrowRight,
  BadgeCheck,
  BarChart3,
  Bell,
  Check,
  ChevronDown,
  Clipboard,
  Cloud,
  Code2,
  Download,
  ExternalLink,
  FileText,
  Flag,
  KeyRound,
  LifeBuoy,
  LockKeyhole,
  Mail,
  MessageCircle,
  MessagesSquare,
  PackageCheck,
  RefreshCw,
  Share2,
  ShieldCheck,
  ShoppingBag,
  Smartphone,
  UserCheck,
  Users,
  UsersRound,
  Wrench,
} from "lucide-react";
import { useEffect, useState, useSyncExternalStore } from "react";
import { isFormalPublicRelease } from "./release-state.mjs";

type PublicRelease = {
  id: "user" | "admin" | "authorized" | "owner";
  name: string;
  shortName: string;
  audience: string;
  description: string;
  fileName: string;
  packageName?: string;
  versionName?: string;
  sizeBytes: number;
  size: string;
  sha256: string;
};

export type PublicReleaseMetadata = {
  versionName: string;
  releaseDate: string;
  downloadRootBase: string;
  finalizationStatus: string;
  channel: string;
  releaseEvidenceCommit: string;
  releaseTag: string;
  releaseNotes: string[];
  releases: PublicRelease[];
};

const API_DOCS_URL = "/api-docs/";

const PRODUCT_MODULES = [
  ["用户系统", "注册登录、资料、会员、等级与账号生命周期统一管理。", UserCheck, "user-system", ["注册与登录", "资料和邮箱绑定", "会员等级与钱包", "停用、注销与状态回读"]],
  ["邮箱系统", "只覆盖邮箱验证码、资料绑定与密码找回，不提供收件箱或通用发信。", Mail, "email-system", ["注册邮箱验证码", "邮箱资料绑定", "密码找回验证"]],
  ["论坛系统", "分区、帖子、评论、点赞、收藏、置顶与内容治理。", MessageCircle, "forum-system", ["版块与分类", "发帖和评论", "点赞收藏", "举报与审核联动"]],
  ["文档系统", "创建、编辑、版本、收藏与受控分享形成内容闭环。", FileText, "document-system", ["文档增删改查", "标签与搜索", "版本记录", "受控分享"]],
  ["好友系统", "搜索、申请、同意、拒绝、分组和删除关系完整流转。", Users, "friend-system", ["用户搜索", "好友申请", "同意或拒绝", "分组备注与删除"]],
  ["群聊系统", "建群、成员、群角色、群资料、入群审核与群安全策略。", UsersRound, "group-system", ["创建和解散群", "成员与角色", "邀请和入群审核", "群文件与群相册"]],
  ["聊天系统", "私聊与群聊统一会话，覆盖消息发送、已读、撤回和检索。", MessagesSquare, "chat-system", ["会话列表", "发送私聊", "已读与未读", "撤回、转发与搜索"]],
  ["安全系统", "登录校验、设备保护、Token 时效、状态与权限核验。", ShieldCheck, "security-system", ["租户与角色校验", "时效 Token", "设备会话管理", "状态与权限回读"]],
  ["卡密系统", "卡密批量生成、分发、兑换、状态追踪与停用管理。", KeyRound, "card-system", ["批次与类型", "生成和分发", "用户兑换", "日志与停用"]],
  ["云仓库", "集中管理资源、上传记录、云同步快照与可控恢复。", Cloud, "cloud-system", ["资源分类", "文件上传", "同步快照", "恢复与删除"]],
  ["商城系统", "商品、库存、订单、支付与权益交付协同运营。", ShoppingBag, "shop-system", ["分类与商品", "库存和价格", "下单与取消", "订单和权益回读"]],
  ["发布运营中心", "公告、版本更新与维护窗口三合一，及时触达用户。", RefreshCw, "lifecycle-system", ["公告发布", "版本策略", "维护窗口", "启动状态回读"]],
] as const;

const EMBEDDED_CAPABILITIES = [
  ["反馈中心", LifeBuoy],
  ["在线人数", Activity],
  ["数据统计", BarChart3],
  ["内容审核", BadgeCheck],
  ["举报治理", Flag],
] as const;

const WORKFLOW = [
  ["01", "创建应用", "在同一账号下建立独立应用，获得应用唯一 ID，并明确成员与权限边界。"],
  ["02", "组合能力", "按业务启用用户、聊天、论坛、卡密、商城等模块，配置运营规则。"],
  ["03", "安全接入", "根据角色完成 platform_key、app_key（应用 API 唯一 ID）、账号密码、时效 Token 与状态校验。"],
  ["04", "持续运营", "通过公告、更新、维护、审核、统计与反馈形成长期运营闭环。"],
] as const;

const subscribeDevice = () => () => {};

function getIsAndroidSnapshot() {
  return typeof navigator !== "undefined" && /Android/i.test(navigator.userAgent);
}

function copyText(value: string, onDone: (message: string) => void) {
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(value).then(
      () => onDone("已复制到剪贴板"),
      () => onDone("复制失败，请长按内容复制"),
    );
    return;
  }
  const input = document.createElement("textarea");
  input.value = value;
  input.style.position = "fixed";
  input.style.opacity = "0";
  document.body.appendChild(input);
  input.select();
  document.execCommand("copy");
  input.remove();
  onDone("已复制到剪贴板");
}

async function shareOfficialWebsite(onDone: (message: string) => void) {
  const url = new URL(window.location.href);
  url.search = "";
  url.hash = "";
  const canonicalUrl = url.toString();
  if (navigator.share) {
    try {
      await navigator.share({
        title: "易云盈｜软件授权与运营平台",
        text: "易云盈正式官网、接口文档与四角色客户端下载",
        url: canonicalUrl,
      });
      onDone("系统分享面板已打开，官网链接可直接访问");
      return;
    } catch (error) {
      if (error instanceof DOMException && error.name === "AbortError") {
        onDone("已取消分享，页面内容未更改");
        return;
      }
    }
  }
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(canonicalUrl);
    } else {
      const input = document.createElement("textarea");
      input.value = canonicalUrl;
      input.setAttribute("readonly", "");
      input.style.position = "fixed";
      input.style.opacity = "0";
      document.body.appendChild(input);
      input.select();
      const copied = document.execCommand("copy");
      input.remove();
      if (!copied) throw new Error("copy_failed");
    }
    onDone("规范官网链接已复制，可直接粘贴分享");
  } catch {
    onDone("分享失败，请从地址栏手动复制官网链接");
  }
}

export default function Home({
  releaseMetadata,
}: {
  releaseMetadata: PublicReleaseMetadata;
}) {
  const VERSION = releaseMetadata.versionName;
  const RELEASE_DATE = releaseMetadata.releaseDate;
  const DOWNLOAD_ROOT = releaseMetadata.downloadRootBase + "/" + VERSION;
  const PUBLIC_RELEASES = releaseMetadata.releases;
  const IS_FORMAL_RELEASE = isFormalPublicRelease(releaseMetadata);
  const RELEASE_NOTES = releaseMetadata.releaseNotes;
  const [selectedId, setSelectedId] = useState<PublicRelease["id"]>("user");
  const [toast, setToast] = useState("");
  const isAndroid = useSyncExternalStore(
    subscribeDevice,
    getIsAndroidSnapshot,
    () => false,
  );

  useEffect(() => {
    if (!toast) return;
    const timer = window.setTimeout(() => setToast(""), 2400);
    return () => window.clearTimeout(timer);
  }, [toast]);

  const selected =
    PUBLIC_RELEASES.find((release) => release.id === selectedId) ??
    PUBLIC_RELEASES[0];

  if (!selected) {
    return (
      <main className="release-unavailable">
        <ShieldCheck aria-hidden="true" />
        <h1>公开版本正在准备</h1>
        <p>四个角色客户端的发布信息尚未就绪，请稍后再试。</p>
      </main>
    );
  }

  const downloadUrl =
    DOWNLOAD_ROOT +
    "/" +
    selected.fileName +
    "?sha256=" +
    selected.sha256.slice(0, 16).toLowerCase();

  return (
    <main id="top">
      <a className="skip-link" href="#main-content">跳到主要内容</a>
      <header className="site-header">
        <a className="brand" href="#top" aria-label="易云盈官网首页">
          <img src="/logo.svg" alt="" width="40" height="40" />
          <span><strong>易云盈</strong><small>应用运营服务平台</small></span>
        </a>
        <nav aria-label="主导航">
          <a href="#platform">平台能力</a>
          <a href="#features">功能模块</a>
          <a href="#workflow">接入流程</a>
          <a href="#security">安全保障</a>
        </nav>
        <a className="header-download" href="#download">
          下载客户端 <ArrowRight size={16} aria-hidden="true" />
        </a>
        <button className="header-share" data-action="share-home" type="button" onClick={() => shareOfficialWebsite(setToast)} aria-label="分享易云盈正式官网">
          <Share2 size={16} aria-hidden="true" />分享官网
        </button>
      </header>

      <div id="main-content">
        <section className="hero" aria-labelledby="hero-title">
          <div className="hero-glow hero-glow-one" />
          <div className="hero-glow hero-glow-two" />
          <div className="hero-layout">
            <div className="hero-copy">
              <p className="eyebrow"><span aria-hidden="true" />多应用 · 模块化 · 全链路运营</p>
              <h1 id="hero-title">让每一个应用，<span>都有自己的运营中枢</span></h1>
              <p className="hero-lead">
                易云盈面向软件开发者与运营团队，把用户、内容、社交、授权、交易和安全能力装进一个平台。一个账号管理多个应用，每个应用独立配置、独立授权、独立运营。
              </p>
              <div className="hero-actions">
                <a className="primary-action" href="#download">
                  <Download size={19} aria-hidden="true" />
                  {IS_FORMAL_RELEASE ? "下载正式版" : "查看发布候选"}
                </a>
                <a className="secondary-action" href={API_DOCS_URL} target="_blank" rel="noreferrer">
                  <Code2 size={19} aria-hidden="true" />接口文档
                  <ExternalLink size={15} aria-hidden="true" />
                </a>
              </div>
              <div className="hero-proof" aria-label="平台特点">
                <span><Check aria-hidden="true" />多应用隔离</span>
                <span><Check aria-hidden="true" />角色权限管控</span>
                <span><Check aria-hidden="true" />模块按需组合</span>
              </div>
            </div>

            <div className="console-preview" aria-label="易云盈多应用管理界面示意">
              <div className="console-bar">
                <span className="console-brand">
                  <img src="/logo.svg" alt="" width="27" height="27" />易云盈
                </span>
                <span className="console-status"><i aria-hidden="true" />服务正常</span>
              </div>
              <div className="console-body">
                <aside>
                  <p>我的应用</p>
                  <span className="console-app is-current"><b>云</b>社区应用</span>
                  <span className="console-app"><b>商</b>商城应用</span>
                  <span className="console-app"><b>+</b>添加应用</span>
                </aside>
                <div className="console-content">
                  <div className="console-title">
                    <span><small>当前应用</small><strong>社区应用</strong></span>
                    <code>APP-CLOUD-01</code>
                  </div>
                  <div className="metric-grid">
                    <article><span>今日活跃</span><strong>1,286</strong><small>实时在线 238</small></article>
                    <article><span>内容互动</span><strong>3,492</strong><small>待审核 12</small></article>
                    <article><span>接口状态</span><strong>99.98%</strong><small>运行稳定</small></article>
                  </div>
                  <div className="console-lower">
                    <div className="trend-card">
                      <span>近 7 日运营趋势</span>
                      <div className="chart-bars" aria-hidden="true">
                        {[42, 58, 50, 76, 67, 88, 94].map((height, index) => (
                          <i key={index} style={{ height: String(height) + "%" }} />
                        ))}
                      </div>
                    </div>
                    <div className="quick-card">
                      <span>快捷管理</span>
                      <div>
                        <b><Users size={16} />用户</b>
                        <b><MessagesSquare size={16} />聊天</b>
                        <b><Bell size={16} />公告</b>
                        <b><ShieldCheck size={16} />安全</b>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section className="trust-strip" aria-label="平台运营范围">
          <div><strong>1 个账号</strong><span>集中管理多个应用</span></div>
          <div><strong>12 组核心模块</strong><span>覆盖授权与日常运营</span></div>
          <div><strong>5 项内嵌治理</strong><span>伴随业务流程自然运转</span></div>
          <div><strong>双端协同</strong><span>用户端与管理端闭环</span></div>
        </section>

        <section className="section platform-section" id="platform">
          <div className="section-heading centered">
            <p>ONE ACCOUNT, MULTIPLE APPS</p>
            <h2>不止管理软件，更管理每个应用的完整业务</h2>
            <span>
              应用是易云盈的最小运营边界。数据、配置、权限和统计均跟随当前应用切换，团队可以扩展业务，同时保持边界清楚。
            </span>
          </div>
          <div className="platform-grid">
            <article className="platform-card primary-card">
              <span className="card-icon"><AppWindow aria-hidden="true" /></span>
              <p>多应用工作台</p>
              <h3>一个账号，统一掌控不同产品</h3>
              <span>创建、切换、配置和维护多个应用；应用名称与唯一 ID 明确展示，关键操作受权限和二次确认保护。</span>
              <div className="app-stack" aria-hidden="true">
                <i><b>云</b><span>社区应用<small>运行中</small></span></i>
                <i><b>商</b><span>商城应用<small>运行中</small></span></i>
                <i><b>工</b><span>工具应用<small>维护中</small></span></i>
              </div>
            </article>
            <article className="platform-card">
              <span className="card-icon"><PackageCheck aria-hidden="true" /></span>
              <p>模块化能力</p>
              <h3>从最小闭环开始，按业务持续扩展</h3>
              <span>登录、私聊、群聊、红包等能力都可独立形成闭环，也能与用户、内容、交易模块组合成完整产品体验。</span>
              <div className="module-path" aria-label="模块闭环示意">
                <i>身份验证</i><ArrowRight aria-hidden="true" /><i>业务操作</i><ArrowRight aria-hidden="true" /><i>状态回读</i>
              </div>
            </article>
            <article className="platform-card">
              <span className="card-icon"><LockKeyhole aria-hidden="true" /></span>
              <p>应用级隔离</p>
              <h3>每次请求都属于明确的账号与应用</h3>
              <span>用户和管理员以 app_key 作为应用 API 唯一 ID；管理员另校验 platform_key，代理与买断总控校验 platform_key。账号密码、时效 Token、账号状态与角色权限共同避免越权。</span>
              <div className="verification-chain">
                <span><Check />app_key</span><span><Check />platform_key</span><span><Check />时效 Token</span>
              </div>
            </article>
          </div>
        </section>

        <section className="section feature-section" id="features">
          <div className="section-heading split-heading">
            <div><p>PRODUCT CAPABILITIES</p><h2>每个应用，都有一套完整能力</h2></div>
            <span>核心系统保持相对独立，又能通过统一身份、权限和数据上下文协同工作。</span>
          </div>
          <div className="feature-grid">
            {PRODUCT_MODULES.map(([name, summary, Icon, anchor, capabilities], index) => (
              <article className="feature-card" key={name}>
                <span className={"feature-icon tone-" + ((index % 4) + 1)}><Icon aria-hidden="true" /></span>
                <h3>{name}</h3><p>{summary}</p>
                <ul>{capabilities.map((capability) => <li key={capability}>{capability}</li>)}</ul>
                <a href={`${API_DOCS_URL}#${anchor}`}>查看具体接口 <ArrowRight size={13} aria-hidden="true" /></a>
              </article>
            ))}
          </div>
          <div className="embedded-panel">
            <div>
              <p>EMBEDDED GOVERNANCE</p>
              <h3>治理与数据能力，内嵌到每一次业务操作</h3>
              <span>反馈、在线、统计、审核与举报不是孤立菜单，而是论坛、群聊、好友、聊天和商城等系统中的自然组成部分。</span>
            </div>
            <ul>
              {EMBEDDED_CAPABILITIES.map(([name, Icon]) => (
                <li key={name}><Icon aria-hidden="true" />{name}</li>
              ))}
            </ul>
          </div>
        </section>

        <section className="section workflow-section" id="workflow">
          <div className="section-heading centered light-heading">
            <p>GET STARTED</p>
            <h2>四步完成接入，开始持续运营</h2>
            <span>先完成一个可验证的小闭环，再按产品节奏开放更多能力。</span>
          </div>
          <ol className="workflow-grid">
            {WORKFLOW.map(([index, title, detail], stepIndex) => (
              <li key={index}>
                <span>{index}</span><h3>{title}</h3><p>{detail}</p>
                {stepIndex < WORKFLOW.length - 1 && <ArrowRight aria-hidden="true" />}
              </li>
            ))}
          </ol>
        </section>

        <section className="section api-section" id="api-docs">
          <div className="api-copy">
            <p>DEVELOPER FIRST</p>
            <h2>接口文档清楚，接入路径可验证</h2>
            <span>
              从身份校验到业务状态回读，文档按系统组织请求、字段与响应说明。开发者只需在自己的源码中配置所属应用信息，无需让最终用户填写服务器地址或应用标识。
            </span>
            <a href={API_DOCS_URL} target="_blank" rel="noreferrer">
              打开接口文档 <ExternalLink size={17} aria-hidden="true" />
            </a>
          </div>
          <div className="api-window" aria-label="安全接入流程示意">
            <div className="api-window-bar"><span /><span /><span /><code>身份校验流程</code></div>
            <div className="api-window-body">
              <p><b>1</b><span>app_key / platform_key</span><Check /></p>
              <p><b>2</b><span>账号与登录密码</span><Check /></p>
              <p><b>3</b><span>时效 Token（仅用户端支持 Refresh）</span><Check /></p>
              <p><b>4</b><span>账号状态与角色权限</span><Check /></p>
            </div>
          </div>
        </section>

        <section className="section security-section" id="security">
          <div className="section-heading centered">
            <p>SECURITY &amp; TRUST</p>
            <h2>把安全放进每一层，而不是留到最后</h2>
            <span>
              从传输、身份、权限到安装包校验，易云盈提供可验证的安全信息；关键凭据不会展示在公开页面。
            </span>
          </div>
          <div className="security-grid">
            <article><LockKeyhole aria-hidden="true" /><h3>加密连接</h3><p>客户端仅通过受信任的 HTTPS 服务端点访问生产 API。</p></article>
            <article><KeyRound aria-hidden="true" /><h3>时效凭证</h3><p>Token 具备有效期并支持撤销，账号状态变化可及时生效。</p></article>
            <article><ShieldCheck aria-hidden="true" /><h3>权限边界</h3><p>应用、账号、角色与操作共同校验，敏感动作保留明确边界。</p></article>
            <article><PackageCheck aria-hidden="true" /><h3>安装包校验</h3><p>公开客户端附 SHA-256，可核对文件完整性与版本一致性。</p></article>
          </div>
        </section>

        <section className="download-section" id="download">
          <div className="section-heading centered download-heading">
            <p>OFFICIAL CLIENTS</p>
            <h2>{IS_FORMAL_RELEASE ? "下载易云盈正式版" : "易云盈发布候选"}</h2>
            <span>
              {IS_FORMAL_RELEASE
                ? "官网提供用户端、管理员端、授权代理端和买断总控端，请严格按已开通账号角色选择。"
                : "当前构建尚在发布验证阶段，仅供闭环测试；完成签名、HTTPS 与发布校验后将自动切换为正式发布。"}
            </span>
          </div>

          <div className="download-shell">
            <div className="release-summary">
              <div>
                <span className={"release-badge " + (IS_FORMAL_RELEASE ? "is-formal" : "is-candidate")}>
                  {IS_FORMAL_RELEASE ? <BadgeCheck aria-hidden="true" /> : <Wrench aria-hidden="true" />}
                  {IS_FORMAL_RELEASE ? "已正式发布" : "发布候选 · 验证中"}
                </span>
                <h3>易云盈 v{VERSION}</h3>
                <p>
                  <time dateTime={RELEASE_DATE}>{RELEASE_DATE}</time>
                  <span>Android 8.0 及以上</span><span>四角色客户端</span>
                </p>
              </div>
              <Smartphone aria-hidden="true" />
            </div>

            <div className="public-role-tabs" role="group" aria-label="选择公开客户端">
              {PUBLIC_RELEASES.map((release) => (
                <button
                  type="button"
                  className={selected.id === release.id ? "is-active" : ""}
                  onClick={() => setSelectedId(release.id)}
                  aria-pressed={selected.id === release.id}
                  key={release.id}
                >
                  <span>{release.shortName}</span><small>{release.audience}</small>
                </button>
              ))}
            </div>

            <div className="selected-download">
              <div className="selected-product">
                <span className="product-mark"><img src="/logo.svg" alt="" width="60" height="60" /></span>
                <span><small>当前选择</small><strong>{selected.name}</strong><p>{selected.description}</p></span>
                <b>{selected.size}</b>
              </div>

              <a
                className="download-button"
                href={downloadUrl}
                download={selected.fileName}
                onClick={() =>
                  setToast(
                    IS_FORMAL_RELEASE
                      ? selected.name + "已开始下载"
                      : selected.name + "候选包已开始下载",
                  )
                }
              >
                <Download size={21} aria-hidden="true" />
                {IS_FORMAL_RELEASE ? "下载 Android 安装包" : "下载候选包（仅验证）"}
                <span>{selected.size}</span>
              </a>

              <p className="device-note">
                <Smartphone size={16} aria-hidden="true" />
                {isAndroid
                  ? "已识别为 Android 设备，可直接下载后安装。"
                  : "请在 Android 设备安装，也可先下载后传送到手机。"}
              </p>

              <div className="installation-guide" aria-labelledby="installation-guide-title">
                <h4 id="installation-guide-title">APK 打开、安装与校验</h4>
                <ol>
                  <li><strong>下载并打开：</strong>在 Android 浏览器的“下载内容”或“文件管理 → 下载”中点开 <code data-release-file>{selected.fileName}</code>。</li>
                  <li><strong>允许本次安装：</strong>若系统拦截，只为当前浏览器或文件管理器临时开启“安装未知应用”；安装完成后建议关闭该权限。请勿关闭 Play 保护机制。</li>
                  <li><strong>先验完整性：</strong>Windows PowerShell 运行 <code>Get-FileHash .\<span data-release-file>{selected.fileName}</span> -Algorithm SHA256</code>；macOS/Linux 运行 <code>shasum -a 256 <span data-release-file>{selected.fileName}</span></code>，结果必须与下方 64 位 SHA-256 完全一致。</li>
                  <li><strong>安全打开：</strong>仅安装 <code>.apk</code> 格式。电脑不能直接运行 APK；如需传到手机，请使用数据线或可信的本机传输方式，不要转换成 EXE、ZIP 或其他格式。</li>
                </ol>
                <p><strong>下载失败：</strong>先切换稳定网络并刷新官网重试；若文件大小异常、校验不一致或系统提示安装包损坏，请删除该文件重新下载，仍失败时通过应用内反馈并附版本、角色和错误提示，切勿继续安装。</p>
              </div>

              <div className="file-verification">
                <div>
                  <span>文件名</span><code>{selected.fileName}</code>
                  <button
                    type="button"
                    onClick={() => copyText(selected.fileName, setToast)}
                    aria-label="复制安装包文件名"
                    title="复制文件名"
                  >
                    <Clipboard size={17} aria-hidden="true" />
                  </button>
                </div>
                <div>
                  <span>SHA-256</span><code title={selected.sha256}>{selected.sha256}</code>
                  <button
                    type="button"
                    onClick={() => copyText(selected.sha256, setToast)}
                    aria-label="复制 SHA-256 校验值"
                    title="复制 SHA-256"
                  >
                    <Clipboard size={17} aria-hidden="true" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div className="release-notes">
            <div><p>VERSION NOTES</p><h3>v{VERSION} 版本说明</h3></div>
            <ul>
              {RELEASE_NOTES.map((note) => (
                <li key={note}><Check aria-hidden="true" />{note}</li>
              ))}
            </ul>
          </div>
        </section>

        <section className="faq-section" aria-labelledby="faq-title">
          <div className="section-heading centered">
            <p>QUESTIONS</p><h2 id="faq-title">下载与接入常见问题</h2>
          </div>
          <div className="faq-list">
            <details>
              <summary>应该下载哪个客户端？<ChevronDown aria-hidden="true" /></summary>
              <p>普通用户选择用户端，应用运营人员选择管理员端，授权运营方选择代理端，平台所有者选择买断总控端。下载或安装客户端都不会改变账号实际权限。</p>
            </details>
            <details>
              <summary>登录时为什么不需要填写服务器地址和应用标识？<ChevronDown aria-hidden="true" /></summary>
              <p>连接地址和标识由开发者写入源码。用户端使用 app_key（即应用 API 唯一标识），管理员端校验 platform_key、app_key、账号与密码，代理和买断总控校验 platform_key、账号与密码；登录后仍校验时效 Token、账号状态与角色权限。</p>
            </details>
            <details>
              <summary>如何确认下载文件完整？<ChevronDown aria-hidden="true" /></summary>
              <p>Windows 使用 PowerShell 的 Get-FileHash，macOS/Linux 使用 shasum -a 256，计算 APK 的 SHA-256 并与下载区的 64 位校验值逐字核对；不一致就删除并重新下载。</p>
            </details>
            <details>
              <summary>APK 应该用什么打开，能否转换格式？<ChevronDown aria-hidden="true" /></summary>
              <p>APK 由 Android 系统安装器打开；可从浏览器下载记录或文件管理器的“下载”目录点开。电脑不能直接安装，也不要转换为 EXE、ZIP 等格式。系统询问时仅临时授权当前下载来源，安装后建议关闭该权限。</p>
            </details>
            <details>
              <summary>下载失败或提示安装包损坏怎么办？<ChevronDown aria-hidden="true" /></summary>
              <p>先确认网络稳定并刷新官网，删除旧文件后重新下载，再核对文件大小与 SHA-256。仍失败时通过应用内反馈提交所选角色、版本号和完整错误提示，不要安装校验不一致的文件。</p>
            </details>
          </div>
        </section>
      </div>

      <footer className="site-footer">
        <div className="footer-main">
          <span className="footer-brand">
            <img src="/logo.svg" alt="" width="36" height="36" />
            <span><strong>易云盈</strong><small>让应用运营更完整、更清楚</small></span>
          </span>
          <nav aria-label="页脚导航">
            <a href="#features">功能模块</a>
            <a href={API_DOCS_URL} target="_blank" rel="noreferrer">接口文档</a>
            <a href="#security">安全保障</a>
            <a href="/privacy/">隐私政策</a>
            <a href="/terms/">服务条款</a>
            <a href="#download">客户端下载</a>
          </nav>
        </div>
        <div className="footer-bottom">
          <span>© 2026 易云盈</span>
          <span>公开页面不展示真实平台 KEY、账号密码、Token 或其他敏感凭据</span>
          <a href="#top">返回顶部</a>
        </div>
      </footer>

      <div className={"toast " + (toast ? "is-visible" : "")} aria-live="polite">
        <Check size={17} aria-hidden="true" />{toast}
      </div>
    </main>
  );
}
