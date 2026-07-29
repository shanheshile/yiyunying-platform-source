package xyz.jjmxg.yiyunying.data.session;

import xyz.jjmxg.yiyunying.domain.Role;

public interface SessionProvider {
    String baseUrl();

    String accessToken();

    String refreshToken();

    String appKey();

    Role role();

    default String cacheIdentity() {
        return role().wireName() + "|" + accessToken();
    }

    void updateUserTokens(String accessToken, String refreshToken, String expiresAt, String refreshExpiresAt);
}
