package xyz.jjmxg.yiyunying.data.api;

import org.junit.Test;

import java.io.IOException;
import java.net.ConnectException;
import java.net.SocketTimeoutException;

import javax.net.ssl.SSLHandshakeException;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public class RouteFailoverPolicyTest {
    @Test
    public void onlyGetAndHeadAreReplayEligible() {
        assertTrue(RouteFailoverPolicy.isReadOnlyMethod("GET"));
        assertTrue(RouteFailoverPolicy.isReadOnlyMethod("head"));
        assertFalse(RouteFailoverPolicy.isReadOnlyMethod("POST"));
        assertFalse(RouteFailoverPolicy.isReadOnlyMethod("DELETE"));
    }

    @Test
    public void onlyGatewayAvailabilityStatusesAreTransient() {
        assertTrue(RouteFailoverPolicy.isTransientServerStatus(502));
        assertTrue(RouteFailoverPolicy.isTransientServerStatus(503));
        assertTrue(RouteFailoverPolicy.isTransientServerStatus(504));
        assertFalse(RouteFailoverPolicy.isTransientServerStatus(500));
        assertFalse(RouteFailoverPolicy.isTransientServerStatus(501));
        assertFalse(RouteFailoverPolicy.isTransientServerStatus(429));
    }

    @Test
    public void connectionAndTimeoutMayFailOverButTlsAndGenericIoMayNot() {
        assertTrue(RouteFailoverPolicy.isRetryableNetworkFailure(new ConnectException("refused")));
        assertTrue(RouteFailoverPolicy.isRetryableNetworkFailure(new SocketTimeoutException("timeout")));
        assertFalse(RouteFailoverPolicy.isRetryableNetworkFailure(new SSLHandshakeException("bad certificate")));
        assertFalse(RouteFailoverPolicy.isRetryableNetworkFailure(new IOException("protocol failure")));
    }

    @Test
    public void routeBoundsAndMethodAreBothRequired() {
        ApiRequest read = ApiRequest.builder("GET", "/api/health").build();
        ApiRequest write = ApiRequest.builder("POST", "/api/items").build();
        assertTrue(RouteFailoverPolicy.canTryNext(read, 0, 2));
        assertFalse(RouteFailoverPolicy.canTryNext(read, 1, 2));
        assertFalse(RouteFailoverPolicy.canTryNext(write, 0, 2));
    }
}
