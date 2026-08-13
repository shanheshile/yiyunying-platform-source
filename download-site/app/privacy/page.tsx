import { ArrowLeft, Database, ShieldCheck, UserRoundCheck } from "lucide-react";

export const metadata = {
  title: "隐私政策",
  description: "易云盈产品隐私政策，说明数据类型、用途、保留、权限、账号注销与未成年人保护。",
};

const SECTIONS = [
  ["我们处理的数据", "账号与身份资料、应用归属与角色、设备和安全日志、用户主动发布的内容、交易或权益记录，以及用户选择上传的文件。实际处理范围以当前应用启用的功能和系统权限提示为准。"],
  ["处理目的", "用于完成注册登录、身份与应用校验、社交和内容功能、订单或权益交付、客服反馈、安全风控、故障诊断、内容治理及符合法律要求的审计。我们不会因启用单一功能而自动取得与该功能无关的数据。"],
  ["保存与删除", "数据仅在实现功能、履行安全和合规义务所需的期间内保存。账号注销申请可从账号设置发起；满足验证与法定保留条件后，账号将被注销或匿名化。无已验证恢复渠道时，平台可能先停用账号以避免误删。"],
  ["设备权限", "相机、麦克风、相册、通知、文件等权限仅在对应功能需要时请求。拒绝非必要权限不影响其他无关功能；用户可在系统设置中随时撤回，撤回后相关功能可能不可用。"],
  ["共享与委托处理", "仅在提供基础设施、支付、消息、存储、安全或法规要求时，向必要的服务提供方或主管机关提供最小范围数据。我们要求受托方按约定目的和安全措施处理。"],
  ["安全措施", "通过 HTTPS、应用与角色权限、时效 Token、设备安全校验、日志脱敏和访问审计降低风险。任何系统都无法承诺绝对安全，发现异常请及时修改密码并通过应用内反馈联系我们。"],
  ["未成年人保护", "未成年人应在监护人指导下使用。应用运营方应依据实际服务对象设置年龄提示、内容和交易限制；发现未经适当同意处理未成年人数据时，可通过应用内反馈申请核查。"],
  ["政策更新与联系", "功能或规则变化时，我们会通过应用内公告、版本说明或本页更新告知。对数据访问、更正、删除、注销或安全问题，可使用应用内反馈提交请求并完成身份核验。"],
] as const;

export default function PrivacyPage() {
  return (
    <main className="legal-page">
      <header className="legal-header">
        <a className="brand" href="/download-center/">
          <img src="/download-center/logo.svg" alt="" width="38" height="38" />
          <span><strong>易云盈</strong><small>隐私政策</small></span>
        </a>
        <a href="/download-center/"><ArrowLeft size={16} aria-hidden="true" />返回官网</a>
      </header>
      <section className="legal-hero">
        <ShieldCheck aria-hidden="true" />
        <p>PRIVACY POLICY</p>
        <h1>隐私政策</h1>
        <span>更新日期：2026 年 8 月 13 日</span>
      </section>
      <article className="legal-content">
        <div className="legal-intro">
          <Database aria-hidden="true" />
          <p>本政策说明易云盈产品层面的数据处理原则。不同开发者创建的应用可能启用不同功能；该应用的运营方应同时向用户说明其独立的数据处理规则。</p>
        </div>
        {SECTIONS.map(([title, body], index) => (
          <section key={title}>
            <span>{String(index + 1).padStart(2, "0")}</span>
            <div><h2>{title}</h2><p>{body}</p></div>
          </section>
        ))}
        <aside>
          <UserRoundCheck aria-hidden="true" />
          <div><strong>如何联系我们</strong><p>请从易云盈客户端的“应用内反馈”提交隐私、账号注销或安全问题。为保护账号，我们会在处理前核验请求人身份。</p></div>
        </aside>
      </article>
      <footer className="legal-footer">
        <a href="/download-center/terms/">服务条款</a><a href="/download-center/api-docs/">接口文档</a><a href="/download-center/">返回官网</a>
      </footer>
    </main>
  );
}
