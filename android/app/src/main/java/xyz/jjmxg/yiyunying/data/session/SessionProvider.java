package xyz.jjmxg.yiyunying.data.session;

import java.util.Collections;
import java.util.List;

import xyz.jjmxg.yiyunying.domain.Role;

public interface SessionProvider {
    String baseUrl();

    /** Ordered, compiled endpoints. Implementations default to the primary only. */
    default List<String> baseUrls() {
        return Collections.singletonList(baseUrl());
    }

    String accessToken();

    String refreshToken();

    String appKey();

    Role role();

    default String cacheIdentity() {
        return role().wireName() + "|" + accessToken();
    }

    void updateUserTokens(String accessToken, String refreshToken, String expiresAt, String refreshExpiresAt);
}
