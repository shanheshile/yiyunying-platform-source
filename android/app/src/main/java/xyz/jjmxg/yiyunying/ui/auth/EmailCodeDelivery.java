package xyz.jjmxg.yiyunying.ui.auth;

import com.google.gson.JsonObject;

import xyz.jjmxg.yiyunying.data.api.Jsons;

final class EmailCodeDelivery {
    static final int DEFAULT_RETRY_SECONDS = 60;
    static final int MAX_RETRY_SECONDS = 300;

    private EmailCodeDelivery() {
    }

    static boolean accepted(JsonObject data) {
        String status = Jsons.string(data, "delivery_status");
        return "accepted_unconfirmed".equals(status) || "delivered".equals(status);
    }

    static int retrySeconds(JsonObject data) {
        return Math.max(1, Math.min(
            MAX_RETRY_SECONDS,
            Jsons.intValue(data, "retry_after_seconds", DEFAULT_RETRY_SECONDS)
        ));
    }

    static String fallbackMessage(JsonObject data) {
        return "delivered".equals(Jsons.string(data, "delivery_status"))
            ? "验证码邮件已确认送达"
            : "邮件服务已接收投递请求，最终送达尚未确认，请检查收件箱和垃圾邮件";
    }
}
