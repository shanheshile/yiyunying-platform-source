import { ArrowLeft, Ban, Scale, ShieldCheck } from "lucide-react";

export const metadata = {
  title: "服务条款",
  description: "易云盈产品服务条款，说明账号、授权、使用规则、更新维护和责任边界。",
};

const SECTIONS = [
  ["服务范围", "易云盈提供多应用管理、用户、内容、社交、授权、交易、安全与发布运营等模块。具体能力、额度和可用状态以账号权限、当前应用配置、版本说明及交付约定为准。"],
  ["账号与应用责任", "用户应提供真实、合法且必要的信息，妥善保管密码、应用 KEY、Token 和设备。一个账号可管理多个应用，但不得跨越应用和角色权限访问数据。账号下发生的授权操作由账号持有人承担相应责任。"],
  ["开发者与运营方义务", "创建应用的开发者或运营方应向最终用户说明其身份、功能、收费、数据处理和客服规则，并保证其内容、商品、服务和集成符合法律及第三方权利要求。"],
  ["禁止行为", "不得绕过权限或限流、攻击或干扰服务、批量抓取、传播违法有害内容、欺诈交易、侵犯隐私与知识产权、反编译受保护组件、共享或出售未授权凭据，亦不得将服务用于危害他人或公共安全。"],
  ["版本更新与维护", "为安全、兼容和功能改进，我们可能发布更新、调整接口或安排维护窗口，并尽量通过公告和版本说明提前通知。紧急安全维护可能立即执行；客户端应遵循版本和生命周期检查结果。"],
  ["费用与权益", "付费套餐、商品、卡密或会员权益以购买页面和订单记录为准。除法律另有规定或服务明确承诺外，已交付的数字权益按对应规则处理；异常支付请通过应用内反馈核查。"],
  ["内容与治理", "用户保留其合法内容的权利，同时授予提供、存储、展示和必要处理该内容所需的有限许可。平台或应用运营方可依据法律和社区规则审核、限制、下架或保全证据。"],
  ["服务中止与账号处理", "存在安全风险、欠费、违法违规、权限失效或持续滥用时，可限制功能或停用账号。账号注销会在身份核验、争议处理和法定保留义务完成后执行，避免不可恢复的误删。"],
  ["责任边界", "我们会以合理措施维护服务安全和可用性，但不承诺永不中断或完全无误。因不可抗力、第三方网络、用户设备、未经授权修改或用户违反条款导致的损失，按适用法律与实际责任承担。"],
  ["条款变更与联系", "条款更新会通过应用公告、版本说明或本页提示。继续使用前应阅读新条款；如不同意，可停止使用并申请注销。咨询或争议请通过应用内反馈提交。"],
] as const;

export default function TermsPage() {
  return (
    <main className="legal-page">
      <header className="legal-header">
        <a className="brand" href="/download-center/">
          <img src="/download-center/logo.svg" alt="" width="38" height="38" />
          <span><strong>易云盈</strong><small>服务条款</small></span>
        </a>
        <a href="/download-center/"><ArrowLeft size={16} aria-hidden="true" />返回官网</a>
      </header>
      <section className="legal-hero terms-hero">
        <Scale aria-hidden="true" />
        <p>TERMS OF SERVICE</p>
        <h1>服务条款</h1>
        <span>更新日期：2026 年 8 月 13 日</span>
      </section>
      <article className="legal-content">
        <div className="legal-intro">
          <ShieldCheck aria-hidden="true" />
          <p>使用易云盈即表示你同意在账号、应用和角色授权范围内使用服务。应用开发者与运营方仍须就其面向最终用户的产品和经营行为承担独立责任。</p>
        </div>
        {SECTIONS.map(([title, body], index) => (
          <section key={title}>
            <span>{String(index + 1).padStart(2, "0")}</span>
            <div><h2>{title}</h2><p>{body}</p></div>
          </section>
        ))}
        <aside className="terms-note">
          <Ban aria-hidden="true" />
          <div><strong>禁止绕过安全边界</strong><p>任何客户端版本、接口路径或已知参数都不构成访问授权；实际权限以服务端对账号、应用、角色、Token 和状态的实时校验为准。</p></div>
        </aside>
      </article>
      <footer className="legal-footer">
        <a href="/download-center/privacy/">隐私政策</a><a href="/download-center/api-docs/">接口文档</a><a href="/download-center/">返回官网</a>
      </footer>
    </main>
  );
}
