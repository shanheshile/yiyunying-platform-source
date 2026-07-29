"use client";

import {
  BadgeCheck,
  Check,
  ChevronDown,
  Clipboard,
  Download,
  ExternalLink,
  FileCheck2,
  LockKeyhole,
  MonitorSmartphone,
  PackageCheck,
  ShieldCheck,
  Smartphone,
} from "lucide-react";
import { useEffect, useMemo, useState, useSyncExternalStore } from "react";
import releaseMetadata from "../release-metadata.json";

type Release = {
  id: "user" | "admin" | "authorized" | "owner";
  name: string;
  shortName: string;
  audience: string;
  description: string;
  fileName: string;
  sizeBytes: number;
  size: string;
  sha256: string;
  accent: string;
};

const VERSION = releaseMetadata.versionName;
const RELEASE_DATE = releaseMetadata.releaseDate;
const DOWNLOAD_ROOT = `${releaseMetadata.downloadRootBase}/${VERSION}`;

const subscribeDevice = () => () => {};

function getIsAndroidSnapshot() {
  return typeof navigator !== "undefined" && /Android/i.test(navigator.userAgent);
}

const RELEASES = releaseMetadata.releases as Release[];
const RELEASE_NOTES: string[] = Array.isArray(releaseMetadata.releaseNotes)
  ? releaseMetadata.releaseNotes
  : String(releaseMetadata.releaseNotes ?? "")
      .split(/[；。]\s*/)
      .map((note) => note.trim())
      .filter(Boolean);

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

export default function Home() {
  const [selectedId, setSelectedId] = useState<Release["id"]>("user");
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

  const selected = useMemo(
    () => RELEASES.find((item) => item.id === selectedId) ?? RELEASES[0],
    [selectedId],
  );
  const downloadUrl = `${DOWNLOAD_ROOT}/${selected.fileName}?sha256=${selected.sha256
    .slice(0, 16)
    .toLowerCase()}`;

  return (
    <main>
      <header className="site-header">
        <a className="brand" href="#download" aria-label="易运盈下载中心首页">
          <img src="/logo.svg" alt="" width="38" height="38" />
          <span>
            <strong>易运盈</strong>
            <small>官方软件下载中心</small>
          </span>
        </a>
        <nav aria-label="页面导航">
          <a href="#download">软件下载</a>
          <a href="#release-notes">版本说明</a>
          <a href="#install">安装帮助</a>
        </nav>
        <a className="header-version" href="#release-notes">
          <BadgeCheck size={16} aria-hidden="true" />
          最新版 {VERSION}
        </a>
      </header>

      <section className="download-band" id="download">
        <div className="download-layout">
          <div className="download-copy">
            <p className="eyebrow">
              <span className="status-dot" />
              四个版本均已发布
            </p>
            <h1>易运盈</h1>
            <p className="lead">
              根据你的账号角色选择对应版本。安装包来自易运盈官方服务器，并提供
              SHA-256 校验值。
            </p>
            <div className="release-meta" aria-label="版本信息">
              <span>版本 {VERSION}</span>
              <span>更新于 {RELEASE_DATE}</span>
              <span>Android 8.0+</span>
            </div>
          </div>

          <div className="download-workspace">
            <div className="role-selector" aria-label="选择软件版本">
              {RELEASES.map((release) => (
                <button
                  type="button"
                  className={selectedId === release.id ? "is-active" : ""}
                  onClick={() => setSelectedId(release.id)}
                  aria-pressed={selectedId === release.id}
                  key={release.id}
                >
                  <span>{release.shortName}</span>
                  <small>{release.audience}</small>
                </button>
              ))}
            </div>

            <div className="download-panel">
              <div className="selected-release">
                <span className={`release-icon ${selected.accent}`}>
                  <img src="/logo.svg" alt="" width="58" height="58" />
                </span>
                <span className="release-title">
                  <small>当前选择</small>
                  <strong>{selected.name}</strong>
                  <span>{selected.description}</span>
                </span>
                <span className="file-size">{selected.size}</span>
              </div>

              <a
                className="primary-download"
                href={downloadUrl}
                download={selected.fileName}
                onClick={() => setToast(`${selected.name}已开始下载`)}
              >
                <Download size={21} aria-hidden="true" />
                下载 Android 安装包
              </a>

              <p className="device-hint">
                <Smartphone size={16} aria-hidden="true" />
                {isAndroid
                  ? "已识别为 Android 设备，可直接下载并安装"
                  : "请使用 Android 设备安装，也可先下载后传到手机"}
              </p>

              <div className="verification">
                <div>
                  <span>文件名</span>
                  <code>{selected.fileName}</code>
                  <button
                    type="button"
                    onClick={() => copyText(selected.fileName, setToast)}
                    title="复制文件名"
                    aria-label="复制文件名"
                  >
                    <Clipboard size={17} />
                  </button>
                </div>
                <div>
                  <span>SHA-256</span>
                  <code title={selected.sha256}>{selected.sha256}</code>
                  <button
                    type="button"
                    onClick={() => copyText(selected.sha256, setToast)}
                    title="复制 SHA-256"
                    aria-label="复制 SHA-256"
                  >
                    <Clipboard size={17} />
                  </button>
                </div>
              </div>

              <button
                className="copy-link"
                type="button"
                onClick={() => copyText(downloadUrl, setToast)}
              >
                <ExternalLink size={17} aria-hidden="true" />
                复制当前版本下载链接
              </button>
            </div>
          </div>

          <aside className="product-visual" aria-label="软件版本概览">
            <div className="visual-topbar">
              <span />
              <strong>易运盈</strong>
              <span className="online">在线</span>
            </div>
            <div className="visual-body">
              <img src="/logo.svg" alt="易运盈应用图标" width="88" height="88" />
              <p>{selected.shortName}</p>
              <strong>v{VERSION}</strong>
              <div className="visual-checks">
                <span>
                  <Check size={15} /> 官方发布
                </span>
                <span>
                  <Check size={15} /> 完整校验
                </span>
              </div>
            </div>
            <div className="visual-footer">
              <MonitorSmartphone size={18} />
              Android 安装包
            </div>
          </aside>
        </div>
      </section>

      <section className="trust-band" aria-label="下载保障">
        <div className="trust-grid">
          <div>
            <ShieldCheck aria-hidden="true" />
            <span>
              <strong>官方文件</strong>
              下载链接直连官方服务器
            </span>
          </div>
          <div>
            <FileCheck2 aria-hidden="true" />
            <span>
              <strong>可核验</strong>
              每个版本附 SHA-256
            </span>
          </div>
          <div>
            <LockKeyhole aria-hidden="true" />
            <span>
              <strong>角色隔离</strong>
              按账号权限选择客户端
            </span>
          </div>
          <div>
            <PackageCheck aria-hidden="true" />
            <span>
              <strong>统一版本</strong>
              四端同步发布与维护
            </span>
          </div>
        </div>
      </section>

      <section className="content-band" id="release-notes">
        <div className="section-heading">
          <p>RELEASE NOTES</p>
          <h2>版本说明</h2>
          <span>本页面始终展示当前推荐安装版本。</span>
        </div>
        <div className="notes-layout">
          <div className="version-index">
            <span>当前正式版</span>
            <strong>{VERSION}</strong>
            <p>{RELEASE_DATE} 发布</p>
          </div>
          <ul className="release-list">
            {RELEASE_NOTES.map((note) => (
              <li key={note}>
                <Check size={18} aria-hidden="true" />
                {note}
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="install-band" id="install">
        <div className="section-heading">
          <p>INSTALL GUIDE</p>
          <h2>安卓安装说明</h2>
          <span>首次安装约需一分钟，升级安装不会清除账号数据。</span>
        </div>
        <ol className="install-steps">
          <li>
            <span>01</span>
            <div>
              <strong>下载对应版本</strong>
              <p>确认账号角色后点击下载，不要混装其他角色的客户端。</p>
            </div>
          </li>
          <li>
            <span>02</span>
            <div>
              <strong>允许安装</strong>
              <p>系统提示时，允许浏览器安装来自此来源的应用。</p>
            </div>
          </li>
          <li>
            <span>03</span>
            <div>
              <strong>完成安装</strong>
              <p>升级时直接覆盖安装；系统提示风险时请核对文件名与校验值。</p>
            </div>
          </li>
        </ol>

        <div className="faq-list">
          <details>
            <summary>
              下载后提示无法打开怎么办？
              <ChevronDown size={18} aria-hidden="true" />
            </summary>
            <p>
              请确认文件扩展名为 .apk，并在系统设置中允许当前浏览器安装未知应用。
              若下载中断，请删除不完整文件后重新下载。
            </p>
          </details>
          <details>
            <summary>
              应该选择哪个版本？
              <ChevronDown size={18} aria-hidden="true" />
            </summary>
            <p>
              普通账号选择用户端；管理员、授权平台和平台总控账号必须选择对应版本。
              账号权限不会因为安装更高角色版本而提升。
            </p>
          </details>
          <details>
            <summary>
              如何确认安装包没有损坏？
              <ChevronDown size={18} aria-hidden="true" />
            </summary>
            <p>
              使用文件校验工具计算 APK 的 SHA-256，并与本页当前版本显示的校验值逐字核对。
            </p>
          </details>
        </div>
      </section>

      <footer>
        <span className="footer-brand">
          <img src="/logo.svg" alt="" width="30" height="30" />
          易运盈软件下载中心
        </span>
        <span>© 2026 易运盈</span>
        <a href="#download">返回下载</a>
      </footer>

      <div className={`toast ${toast ? "is-visible" : ""}`} aria-live="polite">
        <Check size={17} aria-hidden="true" />
        {toast}
      </div>
    </main>
  );
}
