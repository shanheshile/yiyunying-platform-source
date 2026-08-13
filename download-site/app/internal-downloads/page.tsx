import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { chatGPTSignOutPath, requireChatGPTUser } from "../chatgpt-auth";
import { isAuthorizedInternalDownloadUser } from "./authorization.server";
import {
  buildInternalDownloadCatalog,
  INTERNAL_DOWNLOAD_NOTICE,
  type InternalPackage,
} from "./catalog.server";
import {
  attachInternalDownloadActionLinks,
  INTERNAL_DOWNLOAD_LINK_TTL_SECONDS,
} from "./signed-links.server";
import styles from "./styles.module.css";

export const dynamic = "force-dynamic";
export const revalidate = 0;

export const metadata: Metadata = {
  title: "内部下载中心",
  description: "易云盈 Android 四角色内部测试、候选与正式发布包的受保护下载清单。",
  robots: { index: false, follow: false, nocache: true },
};

function PackageCard({
  item,
  groupId,
}: {
  item: InternalPackage;
  groupId: "debug" | "candidate" | "final";
}) {
  return (
    <article className={styles.card} data-role={item.role}>
      <div className={styles.cardHeader}>
        <div>
          <p className={styles.role}>{item.roleLabel}</p>
          <h3>{item.versionName}</h3>
        </div>
        <span className={styles.status}>{item.status}</span>
      </div>
      <dl className={styles.facts}>
        <div><dt>版本代码</dt><dd>{item.versionCode}</dd></div>
        <div><dt>文件大小</dt><dd>{item.size}</dd></div>
        <div className={styles.shaRow}>
          <dt>SHA-256</dt>
          <dd><code>{item.sha256}</code></dd>
        </div>
      </dl>
      <p className={styles.fileName}>{item.fileName}</p>
      <div className={styles.actions}>
        {item.downloadHref ? (
          <a
            className={styles.download}
            href={item.downloadHref}
            rel="nofollow noreferrer"
            referrerPolicy="no-referrer"
            download={item.fileName}
          >
            短时安全下载
          </a>
        ) : (
          <span className={styles.downloadUnavailable}>
            {groupId === "final" ? "正式版暂不从内部通道分发" : "签名下载未配置，链接已关闭"}
          </span>
        )}
        <details className={styles.installHelp}>
          <summary>打开安装说明</summary>
          <ol>
            <li>先核对角色、版本代码、文件大小和完整 SHA-256；任何一项不符都不要安装。</li>
            <li>在 Android 文件管理器中打开 APK，仅对本次安装临时允许可信来源安装。</li>
            <li>确认系统安装页显示的应用名称与所选角色一致；不要接受降级安装。</li>
            <li>安装后关闭“允许此来源”，启动应用并检查角色登录页及版本信息。</li>
          </ol>
          <p>
            Windows 校验：<code>Get-FileHash .\{item.fileName} -Algorithm SHA256</code>
          </p>
        </details>
      </div>
    </article>
  );
}

export default async function InternalDownloadsPage() {
  const user = await requireChatGPTUser("/internal-downloads/");
  if (!isAuthorizedInternalDownloadUser(user)) notFound();
  const groups = attachInternalDownloadActionLinks(buildInternalDownloadCatalog());

  return (
    <main className={styles.page}>
      <a className={styles.skipLink} href="#internal-download-content">跳到下载内容</a>
      <header className={styles.hero}>
        <p className={styles.eyebrow}>INTERNAL DISTRIBUTION</p>
        <h1>内部下载中心</h1>
        <p className={styles.lead}>四角色 Android 包按测试、候选、最终正式状态隔离展示。</p>
        <div className={styles.warning} role="note" aria-label="内部访问限制">
          <strong>{INTERNAL_DOWNLOAD_NOTICE}</strong>
          <span>不得公开索引、分享外链或把 Release 候选交付客户。本路由只允许部署在 Sites 身份网关或会剥离伪造 oai-authenticated-user-* 请求头的受信任入口后方。</span>
        </div>
        <p className={styles.linkLifetime}>
          每次点击都会重新核验账号并由服务端即时签发，下载地址仅在 {INTERNAL_DOWNLOAD_LINK_TTL_SECONDS / 60} 分钟内有效；短时地址在过期前仍可能被转发，请勿分享。
        </p>
        <p className={styles.sessionLine}>
          当前维护者：<strong>{user.displayName}</strong>
          <a href={chatGPTSignOutPath("/")}>退出内部下载中心</a>
        </p>
      </header>

      <nav className={styles.sectionNav} aria-label="内部下载分类">
        {groups.map((group) => <a key={group.id} href={`#${group.id}`}>{group.title}</a>)}
      </nav>

      <div id="internal-download-content" className={styles.content}>
        {groups.map((group) => (
          <section className={styles.group} id={group.id} key={group.id} aria-labelledby={`${group.id}-title`}>
            <div className={styles.groupHeading}>
              <div>
                <p className={styles.counter}>{group.packages.length === 0 ? "未开放" : `${group.packages.length} 个角色包`}</p>
                <h2 id={`${group.id}-title`}>{group.title}</h2>
              </div>
              <p>{group.summary}</p>
            </div>
            {group.packages.length > 0 ? (
              <div className={styles.grid}>
                {group.packages.map((item) => (
                  <PackageCard item={item} groupId={group.id} key={`${group.id}-${item.role}`} />
                ))}
              </div>
            ) : (
              <div className={styles.empty} role="status">{group.emptyMessage}</div>
            )}
          </section>
        ))}
      </div>

      <aside className={styles.safety} aria-labelledby="safety-title">
        <h2 id="safety-title">内部安装闭环</h2>
        <ol>
          <li>选择准确角色，只从本页的 APK 白名单打开下载。</li>
          <li>下载后核对大小和 SHA-256，再执行安装。</li>
          <li>记录设备、角色、版本代码、安装结果和首次启动结果。</li>
          <li>候选版必须完成真机验收及发布签署，才可进入“最终正式版”。</li>
        </ol>
        <p>链接过期、签名无效或文件校验不一致时，停止安装并刷新本页重新获取。</p>
      </aside>
    </main>
  );
}
