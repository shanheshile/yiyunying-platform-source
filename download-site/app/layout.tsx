import type { Metadata, Viewport } from "next";
import "./globals.css";

export const metadata: Metadata = {
  metadataBase: new URL("https://appht.jjmxg.xyz"),
  title: {
    default: "易云盈｜软件授权与运营平台",
    template: "%s｜易云盈",
  },
  description:
    "易云盈为软件开发者提供多应用管理、用户、社交、内容、卡密、商城、安全与版本运营的一站式平台能力。",
  applicationName: "易云盈",
  keywords: [
    "易云盈",
    "软件授权",
    "应用运营",
    "多应用管理",
    "卡密系统",
    "用户管理",
    "论坛社区",
    "Android 客户端",
  ],
  icons: {
    icon: "/download-center/logo.svg",
    shortcut: "/download-center/logo.svg",
    apple: "/download-center/logo.svg",
  },
  manifest: "/download-center/site.webmanifest",
  openGraph: {
    type: "website",
    locale: "zh_CN",
    siteName: "易云盈",
    url: "https://appht.jjmxg.xyz/download-center/",
    title: "易云盈｜软件授权与运营平台",
    description: "一个账号管理多个应用，把授权、用户、内容、社交、交易和安全装进同一套运营中枢。",
    images: [
      {
        url: "https://appht.jjmxg.xyz/download-center/og-card.png",
        width: 1200,
        height: 630,
        alt: "易云盈软件授权与运营平台",
      },
    ],
  },
  twitter: {
    card: "summary_large_image",
    title: "易云盈｜软件授权与运营平台",
    description: "一个账号管理多个应用，把授权、用户、内容、社交、交易和安全装进同一套运营中枢。",
    images: ["https://appht.jjmxg.xyz/download-center/og-card.png"],
  },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: "#0f6a5b",
  colorScheme: "light",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="zh-CN">
      <body>{children}</body>
    </html>
  );
}
