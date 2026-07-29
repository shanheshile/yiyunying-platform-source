package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.fragment.app.Fragment;

import com.google.android.material.snackbar.Snackbar;

import java.util.ArrayList;
import java.util.List;

import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.AppContainer;
import xyz.jjmxg.yiyunying.core.AppearanceStyleStore;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.ApiResult;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;

public abstract class BaseFragment extends Fragment {
    private final List<RequestHandle> requests = new ArrayList<>();
    private MainHost host;
    private String appliedAppearanceSignature = "";

    @Override
    public void onAttach(@NonNull Context context) {
        super.onAttach(context);
        if (!(context instanceof MainHost)) {
            throw new IllegalStateException("Host activity must implement MainHost");
        }
        host = (MainHost) context;
    }

    @Override
    public void onViewCreated(@NonNull View view, Bundle savedInstanceState) {
        super.onViewCreated(view, savedInstanceState);
        applyRuntimeAppearance(view, true);
    }

    @Override
    public void onResume() {
        super.onResume();
        applyRuntimeAppearance(getView(), false);
    }

    private void applyRuntimeAppearance(View view, boolean force) {
        if (view == null || getContext() == null) return;
        Context context = requireContext();
        String signature = AppearanceStyleStore.font(context)
            + "|" + RuntimeLanguage.language(context);
        if (!force && signature.equals(appliedAppearanceSignature)) return;
        AppearanceStyleStore.applyFontTree(context, view);
        RuntimeLanguage.applyTree(context, view);
        appliedAppearanceSignature = signature;
    }

    protected AppContainer app() {
        return AppAccess.from(requireContext());
    }

    protected MainHost host() {
        return host;
    }

    protected RequestHandle track(RequestHandle handle) {
        requests.add(handle);
        return handle;
    }

    protected boolean handleFailure(ApiResult result, View anchor) {
        if (result.isSuccessful()) {
            return false;
        }
        if (result.isAuthenticationFailure()) {
            host.onAuthenticationExpired();
            return true;
        }
        String message = result.message().isEmpty() ? "操作失败" : result.message();
        if (!result.traceId().isEmpty()) {
            message += "\n追踪号：" + result.traceId();
        }
        Snackbar.make(anchor, message, Snackbar.LENGTH_LONG).show();
        return true;
    }

    protected void message(View anchor, String text) {
        Snackbar.make(anchor, text, Snackbar.LENGTH_LONG).show();
    }

    @Override
    public void onDestroyView() {
        for (RequestHandle request : requests) {
            request.cancel();
        }
        requests.clear();
        appliedAppearanceSignature = "";
        super.onDestroyView();
    }
}
