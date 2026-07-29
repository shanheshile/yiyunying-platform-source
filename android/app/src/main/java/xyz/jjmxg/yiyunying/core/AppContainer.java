package xyz.jjmxg.yiyunying.core;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;

import java.util.concurrent.Executor;

import okhttp3.OkHttpClient;
import xyz.jjmxg.yiyunying.data.api.ApiClient;
import xyz.jjmxg.yiyunying.data.repository.AuthRepository;
import xyz.jjmxg.yiyunying.data.repository.YiyunyingRepository;
import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.domain.module.ModuleRegistry;

public final class AppContainer {
    private final SessionManager sessionManager;
    private final ApiClient apiClient;
    private final YiyunyingRepository repository;
    private final AuthRepository authRepository;
    private final ModuleRegistry moduleRegistry;

    public AppContainer(Context context) {
        Context appContext = context.getApplicationContext();
        sessionManager = new SessionManager(appContext);
        Handler mainHandler = new Handler(Looper.getMainLooper());
        Executor mainExecutor = mainHandler::post;
        OkHttpClient httpClient = ApiClient.defaultHttpClient(appContext);
        apiClient = new ApiClient(appContext, sessionManager, httpClient, mainExecutor);
        repository = new YiyunyingRepository(apiClient);
        authRepository = new AuthRepository(appContext, sessionManager, repository);
        moduleRegistry = new ModuleRegistry();
    }

    public SessionManager session() {
        return sessionManager;
    }

    public ApiClient api() {
        return apiClient;
    }

    public YiyunyingRepository repository() {
        return repository;
    }

    public AuthRepository auth() {
        return authRepository;
    }

    public ModuleRegistry modules() {
        return moduleRegistry;
    }
}
