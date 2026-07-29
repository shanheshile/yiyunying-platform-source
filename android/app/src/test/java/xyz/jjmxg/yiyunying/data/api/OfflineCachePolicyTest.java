package xyz.jjmxg.yiyunying.data.api;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public class OfflineCachePolicyTest {
    @Test
    public void pagedRequestsShareOneResourceFallback() {
        String first = OfflineCachePolicy.resourceKey(
            "user-4|https://example.test",
            "https://example.test/api/user/friends?page=1&per_page=20&group_id=3"
        );
        String second = OfflineCachePolicy.resourceKey(
            "user-4|https://example.test",
            "https://example.test/api/user/friends?page=9&per_page=100&group_id=3"
        );
        assertEquals(first, second);
    }

    @Test
    public void identityFiltersRemainIsolated() {
        String first = OfflineCachePolicy.resourceKey(
            "user-4", "https://example.test/api/user/users/8/messages?page=1"
        );
        String second = OfflineCachePolicy.resourceKey(
            "user-4", "https://example.test/api/user/users/9/messages?page=1"
        );
        assertFalse(first.equals(second));
    }

    @Test
    public void excludesSessionMutationReads() {
        assertFalse(OfflineCachePolicy.isCacheable(ApiRequest.builder("GET", "/api/user/heartbeat").build()));
        assertFalse(OfflineCachePolicy.isCacheable(ApiRequest.builder("GET", "/api/auth/captcha").build()));
        assertTrue(OfflineCachePolicy.isCacheable(ApiRequest.builder("GET", "/api/user/friends").build()));
    }

    @Test
    public void classifiesImportantOfflineModels() {
        assertEquals("个人资料", OfflineCachePolicy.contentKind("/api/user/users/8/profile"));
        assertEquals("联系人与互动", OfflineCachePolicy.contentKind("/api/user/friends/frequent"));
        assertEquals("联系人与互动", OfflineCachePolicy.contentKind("/api/user/users/8/likes"));
        assertEquals("客服会话", OfflineCachePolicy.contentKind("/api/user/customer-service/messages"));
        assertEquals("聊天与群组", OfflineCachePolicy.contentKind("/api/user/conversations"));
        assertEquals("转发与收藏", OfflineCachePolicy.contentKind("/api/user/forwarded-snapshots"));
    }

    @Test
    public void keepsAccountAndBusinessFiltersWhileDroppingOnlyPagingState() {
        String first = OfflineCachePolicy.resourceKey(
            "account-a|app-2",
            "https://example.test/api/user/customer-service/messages?page=1&status=unread"
        );
        String second = OfflineCachePolicy.resourceKey(
            "account-a|app-2",
            "https://example.test/api/user/customer-service/messages?page=8&status=unread"
        );
        String otherStatus = OfflineCachePolicy.resourceKey(
            "account-a|app-2",
            "https://example.test/api/user/customer-service/messages?page=8&status=all"
        );
        String otherAccount = OfflineCachePolicy.resourceKey(
            "account-b|app-2",
            "https://example.test/api/user/customer-service/messages?page=8&status=unread"
        );

        assertEquals(first, second);
        assertFalse(first.equals(otherStatus));
        assertFalse(first.equals(otherAccount));
    }
}
