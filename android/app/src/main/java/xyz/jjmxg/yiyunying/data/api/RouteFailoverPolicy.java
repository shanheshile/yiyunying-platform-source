package xyz.jjmxg.yiyunying.data.api;

import java.io.IOException;
import java.net.ConnectException;
import java.net.NoRouteToHostException;
import java.net.SocketException;
import java.net.SocketTimeoutException;
import java.net.UnknownHostException;

import javax.net.ssl.SSLException;

/** Conservative cross-endpoint replay policy. */
public final class RouteFailoverPolicy {
    private RouteFailoverPolicy() {
    }

    public static boolean isReadOnlyMethod(String method) {
        return "GET".equalsIgnoreCase(method) || "HEAD".equalsIgnoreCase(method);
    }

    public static boolean isTransientServerStatus(int status) {
        return status == 502 || status == 503 || status == 504;
    }

    public static boolean isRetryableNetworkFailure(IOException exception) {
        Throwable current = exception;
        while (current != null) {
            // Certificate, hostname and TLS protocol failures are security/configuration
            // failures, not an availability signal that should be hidden by failover.
            if (current instanceof SSLException) return false;
            if (current instanceof SocketTimeoutException
                    || current instanceof ConnectException
                    || current instanceof UnknownHostException
                    || current instanceof NoRouteToHostException
                    || current instanceof SocketException) {
                return true;
            }
            current = current.getCause();
        }
        return false;
    }

    public static boolean canTryNext(ApiRequest request, int routeIndex, int routeCount) {
        return request != null
            && isReadOnlyMethod(request.method())
            && routeIndex >= 0
            && routeIndex + 1 < routeCount;
    }
}
