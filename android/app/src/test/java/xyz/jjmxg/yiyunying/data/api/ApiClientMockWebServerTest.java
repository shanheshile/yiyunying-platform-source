package xyz.jjmxg.yiyunying.data.api;

import org.junit.After;
import org.junit.Before;
import org.junit.Test;

import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

import okhttp3.OkHttpClient;
import okhttp3.mockwebserver.MockResponse;
import okhttp3.mockwebserver.MockWebServer;
import okhttp3.mockwebserver.RecordedRequest;
import xyz.jjmxg.yiyunying.data.session.SessionProvider;
import xyz.jjmxg.yiyunying.domain.Role;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

public class ApiClientMockWebServerTest {
    private MockWebServer server;
    private FakeSession session;
    private ApiClient client;

    @Before
    public void setUp() throws Exception {
        server = new MockWebServer();
        server.start();
        session = new FakeSession(server.url("/").toString());
        client = new ApiClient(session, new OkHttpClient(), Runnable::run);
    }

    @After
    public void tearDown() throws Exception {
        server.shutdown();
    }

    @Test
    public void sendsTenantHeadersAndParsesEnvelope() throws Exception {
        server.enqueue(json(200, "{\"code\":1,\"msg\":\"ok\",\"data\":{\"items\":[{\"id\":1}]},\"trace_id\":\"t1\"}"));
        ApiResult result = await(ApiRequest.builder("GET", "/api/user/documents").build());
        assertTrue(result.isSuccessful());
        assertEquals(1, result.items().size());
        assertEquals("t1", result.traceId());
        RecordedRequest request = server.takeRequest(2, TimeUnit.SECONDS);
        assertNotNull(request);
        assertEquals("Bearer access-old", request.getHeader("Authorization"));
        assertEquals("demo-app", request.getHeader("X-App-Key"));
    }

    @Test
    public void refreshesUserTokenAndRetriesOnce() throws Exception {
        server.enqueue(json(401, "{\"code\":401,\"msg\":\"expired\",\"data\":{}}"));
        server.enqueue(json(200, "{\"code\":1,\"msg\":\"refreshed\",\"data\":{\"access_token\":\"access-new\",\"refresh_token\":\"refresh-new\",\"expires_at\":\"later\",\"refresh_expires_at\":\"much-later\"}}"));
        server.enqueue(json(200, "{\"code\":1,\"msg\":\"ok\",\"data\":{\"value\":9}}"));

        ApiResult result = await(ApiRequest.builder("GET", "/api/user/me").build());
        assertTrue(result.isSuccessful());
        assertEquals("access-new", session.accessToken());
        assertEquals("refresh-new", session.refreshToken());
        RecordedRequest first = server.takeRequest(2, TimeUnit.SECONDS);
        RecordedRequest refresh = server.takeRequest(2, TimeUnit.SECONDS);
        RecordedRequest retry = server.takeRequest(2, TimeUnit.SECONDS);
        assertEquals("/api/user/me", first.getPath());
        assertEquals("/api/user/token/refresh", refresh.getPath());
        assertEquals("Bearer access-new", retry.getHeader("Authorization"));
    }

    @Test
    public void sendsIdempotencyKeyOnMutation() throws Exception {
        server.enqueue(json(201, "{\"code\":1,\"msg\":\"created\",\"data\":{}}"));
        ApiRequest request = ApiRequest.builder("POST", "/api/admin/exchanges")
            .idempotencyKey("idem-123").build();
        assertTrue(await(request).isSuccessful());
        assertEquals("idem-123", server.takeRequest(2, TimeUnit.SECONDS).getHeader("Idempotency-Key"));
    }

    @Test
    public void preservesBusinessErrorAndTraceId() throws Exception {
        server.enqueue(json(422, "{\"code\":0,\"msg\":\"quota exceeded\",\"data\":{},\"trace_id\":\"trace-x\"}"));
        ApiResult result = await(ApiRequest.builder("POST", "/api/user/documents").build());
        assertTrue(!result.isSuccessful());
        assertEquals(0, result.code());
        assertEquals("quota exceeded", result.message());
        assertEquals("trace-x", result.traceId());
    }

    @Test
    public void hidesServerImplementationDetailsForHtml404() throws Exception {
        server.enqueue(new MockResponse().setResponseCode(404)
            .setHeader("Content-Type", "text/html; charset=utf-8")
            .setBody("<!doctype html><html><body><h1>404 Not Found</h1><hr>nginx</body></html>"));
        ApiResult result = await(ApiRequest.builder("GET", "/api/health").build());
        assertTrue(!result.isSuccessful());
        assertEquals(404, result.httpCode());
        assertTrue(result.message().contains("不存在"));
        assertTrue(!result.message().contains("HTML"));
        assertTrue(!result.message().contains("public"));
    }

    @Test
    public void parsesJsonWithUtf8Bom() throws Exception {
        server.enqueue(json(200, "\ufeff{\"code\":1,\"msg\":\"ok\",\"data\":{\"value\":7}}"));
        ApiResult result = await(ApiRequest.builder("GET", "/api/health").build());
        assertTrue(result.isSuccessful());
        assertEquals(7, result.dataObject().get("value").getAsInt());
    }

    @Test
    public void rejectsAbsoluteEndpointBeforeSendingCredentials() throws Exception {
        ApiResult result = await(ApiRequest.builder("GET", "https://example.invalid/collect").build());
        assertTrue(!result.isSuccessful());
        assertEquals(0, server.getRequestCount());
    }

    private ApiResult await(ApiRequest request) throws Exception {
        CountDownLatch latch = new CountDownLatch(1);
        AtomicReference<ApiResult> result = new AtomicReference<>();
        client.enqueue(request, value -> { result.set(value); latch.countDown(); });
        assertTrue("request timed out", latch.await(5, TimeUnit.SECONDS));
        return result.get();
    }

    private MockResponse json(int status, String body) {
        return new MockResponse().setResponseCode(status).setHeader("Content-Type", "application/json").setBody(body);
    }

    private static final class FakeSession implements SessionProvider {
        private final String baseUrl;
        private String access = "access-old";
        private String refresh = "refresh-old";

        FakeSession(String baseUrl) { this.baseUrl = baseUrl; }
        @Override public String baseUrl() { return baseUrl; }
        @Override public String accessToken() { return access; }
        @Override public String refreshToken() { return refresh; }
        @Override public String appKey() { return "demo-app"; }
        @Override public Role role() { return Role.USER; }
        @Override public void updateUserTokens(String accessToken, String refreshToken, String expiresAt, String refreshExpiresAt) {
            access = accessToken;
            refresh = refreshToken;
        }
    }
}
