package xyz.jjmxg.yiyunying.domain.api;

public final class ApiRoute {
    private final String method;
    private final String path;
    private final String scope;
    private final String handler;

    public ApiRoute(String method, String path, String scope, String handler) {
        this.method = method;
        this.path = path;
        this.scope = scope;
        this.handler = handler;
    }

    public String method() { return method; }
    public String path() { return path; }
    public String scope() { return scope; }
    public String handler() { return handler; }
    public String label() { return method + "  " + path; }
}
