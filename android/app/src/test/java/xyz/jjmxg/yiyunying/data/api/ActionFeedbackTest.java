package xyz.jjmxg.yiyunying.data.api;

import com.google.gson.JsonObject;

import org.junit.Test;

import static org.junit.Assert.assertEquals;

public class ActionFeedbackTest {
    @Test
    public void genericSuccessUsesTheCurrentActionCopy() {
        ApiResult result = ApiResult.response(200, 1, "操作成功", new JsonObject(), "");
        assertEquals("投票已发布", ActionFeedback.success(result, "投票已发布"));
    }

    @Test
    public void specificBusinessMessageIsPreserved() {
        ApiResult result = ApiResult.response(422, 0, "余额不足，请先充值", new JsonObject(), "");
        assertEquals("余额不足，请先充值", ActionFeedback.failure(result, "红包发送失败"));
    }

    @Test
    public void genericFailureUsesTheCurrentActionCopy() {
        ApiResult result = ApiResult.response(400, 0, "", new JsonObject(), "");
        assertEquals("论坛帖子发布失败，请检查内容后重试",
            ActionFeedback.failure(result, "论坛帖子发布失败，请检查内容后重试"));
    }
}
