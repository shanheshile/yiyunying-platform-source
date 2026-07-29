package xyz.jjmxg.yiyunying.ui.bot;

import android.Manifest;
import android.annotation.SuppressLint;
import android.content.Context;
import android.content.pm.PackageManager;
import android.location.Address;
import android.location.Geocoder;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.view.inputmethod.EditorInfo;
import android.widget.LinearLayout;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.core.content.ContextCompat;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsAnimationCompat;
import androidx.core.view.WindowInsetsCompat;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentBotBinding;
import xyz.jjmxg.yiyunying.databinding.ItemBotNewsBinding;
import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.ui.browser.LinkNavigator;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;

public final class BotFragment extends BaseFragment {
    private FragmentBotBinding binding;
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private final ExecutorService geocoderExecutor = Executors.newSingleThreadExecutor();
    private LocationManager locationManager;
    private LocationListener locationListener;
    private Runnable locationTimeout;
    private String pendingWeatherQuestion = "";
    private long conversationId;
    private boolean requestInFlight;
    private int rootPaddingLeft;
    private int rootPaddingTop;
    private int rootPaddingRight;
    private int rootPaddingBottom;
    private final ActivityResultLauncher<String[]> locationPermissionLauncher = registerForActivityResult(
        new ActivityResultContracts.RequestMultiplePermissions(), result -> {
            boolean granted = Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_FINE_LOCATION))
                || Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_COARSE_LOCATION));
            if (granted) loadWeatherForCurrentLocation(pendingWeatherQuestion);
            else finishLoadingWithText("未获得定位权限，无法判断你当前位置的天气。你可以在系统设置中允许定位后重试。");
        }
    );

    public static BotFragment newInstance() { return new BotFragment(); }

    @Nullable
    @Override public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentBotBinding.inflate(inflater, container, false);
        if (state != null) conversationId = state.getLong("conversation_id", 0L);
        host().setPageTitle("机器人问答");
        requireActivity().getWindow().setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE);
        installImeFollower();
        binding.askButton.setOnClickListener(view -> ask());
        binding.questionInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEND) { ask(); return true; }
            return false;
        });
        bindPrompt(binding.weatherPrompt, "北京明天天气怎么样");
        bindPrompt(binding.newsPrompt, "今日快报");
        bindPrompt(binding.travelPrompt, "西安三日游怎么安排");
        bindPrompt(binding.historyPrompt, "介绍一下故宫的历史");
        bindPrompt(binding.helpPrompt, "怎么创建笔记并添加附件");
        return binding.getRoot();
    }

    private void bindPrompt(View prompt, String question) {
        prompt.setOnClickListener(view -> {
            if (binding == null) return;
            binding.questionInput.setText(question);
            binding.questionInput.setSelection(question.length());
            ask();
        });
    }

    private void ask() {
        if (requestInFlight || binding == null) return;
        String question = binding.questionInput.getText() == null ? "" : binding.questionInput.getText().toString().trim();
        if (question.isEmpty()) { message(binding.getRoot(), "请输入问题"); return; }
        if (BotQuestionClassifier.isWeatherQuestion(question)) {
            pendingWeatherQuestion = question;
            beginLoading();
            String requestedLocation = BotQuestionClassifier.extractRequestedLocation(question);
            if (!requestedLocation.isEmpty()) {
                submitQuestion(question, null, requestedLocation, requestedLocation);
                return;
            }
            if (hasLocationPermission()) loadWeatherForCurrentLocation(question);
            else locationPermissionLauncher.launch(new String[]{
                Manifest.permission.ACCESS_COARSE_LOCATION,
                Manifest.permission.ACCESS_FINE_LOCATION,
            });
            return;
        }
        submitQuestion(question, null, "", "");
    }

    private void submitQuestion(
        String question,
        @Nullable Location location,
        String locationName,
        String locationQuery
    ) {
        JsonObject body = new JsonObject();
        body.addProperty("question", question);
        if (conversationId > 0L) body.addProperty("conversation_id", conversationId);
        if (location != null) {
            body.addProperty("latitude", location.getLatitude());
            body.addProperty("longitude", location.getLongitude());
            body.addProperty("location_name", locationName);
        }
        if (locationQuery != null && !locationQuery.trim().isEmpty()) {
            body.addProperty("location_query", locationQuery.trim());
        }
        beginLoading();
        track(app().repository().post("/api/user/bot/ask", body, result -> {
            if (binding == null) return;
            finishLoading();
            if (handleFailure(result, binding.getRoot())) return;
            JsonObject data = result.dataObject();
            long returnedConversationId = Jsons.longValue(data, "conversation_id");
            if (returnedConversationId > 0L) conversationId = returnedConversationId;
            String answer = Jsons.string(data, "answer");
            String title = Jsons.string(data, "title");
            String category = Jsons.string(data, "category");
            String type = Jsons.string(data, "type");
            binding.answerTitle.setText(title.isEmpty()
                ? ("weather".equals(type) ? "天气查询结果" : "回答")
                : title);
            binding.answerCategory.setText(category.isEmpty()
                ? ("weather".equals(type) ? "实时天气" : "智能问答")
                : category);
            LinkNavigator.setTextWithLinks(binding.answerText, answer.isEmpty()
                ? RuntimeLanguage.translate(requireContext(), "暂时没有可展示的回答，请换一种问法后重试。").toString()
                : answer);
            binding.answerText.setAlpha(0f);
            binding.answerText.animate().alpha(1f).setDuration(180L).start();
            JsonObject weather = Jsons.object(data, "weather");
            boolean hasWeather = "weather".equals(type) && weather.size() > 0;
            binding.weatherCard.setVisibility(hasWeather ? View.VISIBLE : View.GONE);
            if (hasWeather) binding.weatherCard.setWeather(weather);
            boolean hasNews = "news".equals(type) && renderNewsItems(Jsons.array(data, "items"));
            binding.answerCard.setVisibility(hasNews ? View.GONE : View.VISIBLE);
            binding.questionInput.setText("");
            binding.questionInput.requestFocus();
        }));
    }

    private boolean renderNewsItems(JsonArray items) {
        if (binding == null) return false;
        binding.newsList.removeAllViews();
        if (items == null || items.isEmpty()) {
            binding.newsList.setVisibility(View.GONE);
            return false;
        }
        int rendered = 0;
        LayoutInflater inflater = LayoutInflater.from(binding.getRoot().getContext());
        for (JsonElement element : items) {
            if (!element.isJsonObject() || rendered >= 10) continue;
            JsonObject item = element.getAsJsonObject();
            String title = Jsons.string(item, "title").trim();
            String source = Jsons.string(item, "source").trim();
            String publishedAt = Jsons.string(item, "published_at").trim();
            String summary = Jsons.string(item, "summary").trim();
            String url = Jsons.string(item, "url").trim();
            if (title.isEmpty()) continue;

            ItemBotNewsBinding row = ItemBotNewsBinding.inflate(inflater, binding.newsList, false);
            row.newsTitle.setText(title);
            String meta = source;
            if (!publishedAt.isEmpty()) meta += (meta.isEmpty() ? "" : " · ") + publishedAt;
            row.newsMeta.setText(meta.isEmpty()
                ? RuntimeLanguage.translate(row.getRoot().getContext(), "新闻来源") : meta);
            boolean hasSummary = !summary.isEmpty() && !summary.equals(title);
            row.newsSummary.setVisibility(hasSummary ? View.VISIBLE : View.GONE);
            if (hasSummary) row.newsSummary.setText(summary);
            row.getRoot().setContentDescription("打开新闻：" + title);
            row.getRoot().setOnClickListener(view -> {
                if (!LinkNavigator.open(view.getContext(), url)) {
                    message(view, "暂时无法打开这条新闻");
                }
            });
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            params.bottomMargin = dp(8);
            row.getRoot().setLayoutParams(params);
            row.getRoot().setAlpha(0f);
            binding.newsList.addView(row.getRoot());
            row.getRoot().animate().alpha(1f).setStartDelay(rendered * 28L).setDuration(150L).start();
            rendered++;
        }
        binding.newsList.setVisibility(rendered > 0 ? View.VISIBLE : View.GONE);
        return rendered > 0;
    }

    private void beginLoading() {
        if (binding == null) return;
        requestInFlight = true;
        binding.newsList.setVisibility(View.GONE);
        binding.progress.setVisibility(View.VISIBLE);
        binding.sendIcon.setVisibility(View.GONE);
        binding.askButton.setContentDescription("正在发送问题");
        binding.sendProgress.setVisibility(View.VISIBLE);
        binding.answerCategory.setText("正在整理");
    }

    private void finishLoading() {
        if (binding == null) return;
        requestInFlight = false;
        binding.progress.setVisibility(View.INVISIBLE);
        binding.sendProgress.setVisibility(View.GONE);
        binding.sendIcon.setVisibility(View.VISIBLE);
        binding.askButton.setContentDescription("发送问题");
    }

    private void installImeFollower() {
        View root = binding.getRoot();
        rootPaddingLeft = root.getPaddingLeft();
        rootPaddingTop = root.getPaddingTop();
        rootPaddingRight = root.getPaddingRight();
        rootPaddingBottom = root.getPaddingBottom();
        ViewCompat.setOnApplyWindowInsetsListener(root, (view, insets) -> {
            applyImePadding(view, insets);
            return insets;
        });
        ViewCompat.setWindowInsetsAnimationCallback(root,
            new WindowInsetsAnimationCompat.Callback(
                WindowInsetsAnimationCompat.Callback.DISPATCH_MODE_CONTINUE_ON_SUBTREE) {
                @NonNull @Override public WindowInsetsCompat onProgress(
                    @NonNull WindowInsetsCompat insets,
                    @NonNull List<WindowInsetsAnimationCompat> runningAnimations
                ) {
                    applyImePadding(root, insets);
                    return insets;
                }
            });
        root.post(() -> ViewCompat.requestApplyInsets(root));
    }

    private void applyImePadding(View root, WindowInsetsCompat insets) {
        Insets ime = insets.getInsets(WindowInsetsCompat.Type.ime());
        Insets navigation = insets.getInsetsIgnoringVisibility(WindowInsetsCompat.Type.navigationBars());
        int keyboardExtra = insets.isVisible(WindowInsetsCompat.Type.ime())
            ? Math.max(0, ime.bottom - navigation.bottom) : 0;
        int targetBottom = rootPaddingBottom + keyboardExtra;
        if (root.getPaddingLeft() != rootPaddingLeft || root.getPaddingTop() != rootPaddingTop
            || root.getPaddingRight() != rootPaddingRight || root.getPaddingBottom() != targetBottom) {
            root.setPadding(rootPaddingLeft, rootPaddingTop, rootPaddingRight, targetBottom);
        }
    }

    private void finishLoadingWithText(String text) {
        finishLoading();
        if (binding == null) return;
        binding.weatherCard.setVisibility(View.GONE);
        binding.newsList.removeAllViews();
        binding.newsList.setVisibility(View.GONE);
        binding.answerCard.setVisibility(View.VISIBLE);
        binding.answerCategory.setText("提示");
        binding.answerTitle.setText("暂时无法完成查询");
        binding.answerText.setText(text);
    }

    private boolean hasLocationPermission() {
        Context context = requireContext();
        return ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
            || ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    @SuppressLint("MissingPermission")
    private void loadWeatherForCurrentLocation(String question) {
        if (binding == null || question == null || question.isEmpty()) return;
        locationManager = (LocationManager) requireContext().getSystemService(Context.LOCATION_SERVICE);
        if (locationManager == null) {
            finishLoadingWithText("当前设备无法提供定位服务，请检查系统定位设置。");
            return;
        }
        Location cached = newestLastKnownLocation(locationManager);
        if (cached != null && System.currentTimeMillis() - cached.getTime() <= 30 * 60 * 1000L) {
            resolveNameAndSubmit(question, cached);
            return;
        }
        List<String> providers = enabledProviders(locationManager);
        if (providers.isEmpty()) {
            if (cached != null) resolveNameAndSubmit(question, cached);
            else finishLoadingWithText("系统定位尚未开启，请开启定位后再查询当地天气。");
            return;
        }
        locationListener = new LocationListener() {
            @Override public void onLocationChanged(@NonNull Location location) {
                clearLocationListener();
                resolveNameAndSubmit(question, location);
            }
            @Override public void onProviderDisabled(@NonNull String provider) { }
            @Override public void onProviderEnabled(@NonNull String provider) { }
            @Deprecated @Override public void onStatusChanged(String provider, int status, Bundle extras) { }
        };
        int requestedProviders = 0;
        try {
            for (String provider : providers) {
                try {
                    locationManager.requestLocationUpdates(provider, 500L, 0f,
                        locationListener, Looper.getMainLooper());
                    requestedProviders++;
                } catch (RuntimeException ignored) { }
            }
            if (requestedProviders == 0) throw new IllegalStateException("没有可用的定位提供方");
            Location fallbackLocation = cached;
            locationTimeout = () -> {
                if (locationListener == null) return;
                clearLocationListener();
                Location fallback = newestLastKnownLocation(locationManager);
                if (fallback == null) fallback = fallbackLocation;
                if (fallback != null) resolveNameAndSubmit(question, fallback);
                else finishLoadingWithText("暂时没有获取到当前位置，请移动到开阔位置或稍后重试。");
            };
            mainHandler.postDelayed(locationTimeout, 6500L);
        } catch (RuntimeException exception) {
            clearLocationListener();
            if (cached != null) resolveNameAndSubmit(question, cached);
            else finishLoadingWithText("当前位置获取失败，请检查定位权限和系统定位开关。");
        }
    }

    @SuppressLint("MissingPermission")
    @Nullable private Location newestLastKnownLocation(LocationManager manager) {
        Location newest = null;
        try {
            for (String provider : manager.getProviders(true)) {
                Location candidate = manager.getLastKnownLocation(provider);
                if (candidate != null && (newest == null || candidate.getTime() > newest.getTime())) newest = candidate;
            }
        } catch (RuntimeException ignored) { }
        return newest;
    }

    private List<String> enabledProviders(LocationManager manager) {
        List<String> providers = new ArrayList<>();
        try {
            List<String> enabled = manager.getProviders(true);
            for (String preferred : new String[]{
                LocationManager.NETWORK_PROVIDER, "fused", LocationManager.GPS_PROVIDER,
                LocationManager.PASSIVE_PROVIDER
            }) {
                if (enabled.contains(preferred) && !providers.contains(preferred)) providers.add(preferred);
            }
            for (String provider : enabled) if (!providers.contains(provider)) providers.add(provider);
        } catch (RuntimeException ignored) { }
        return providers;
    }

    private void resolveNameAndSubmit(String question, Location location) {
        Context appContext = requireContext().getApplicationContext();
        geocoderExecutor.execute(() -> {
            String name = resolveLocationName(appContext, location);
            mainHandler.post(() -> {
                if (binding != null && isAdded()) submitQuestion(question, location, name, "");
            });
        });
    }

    @SuppressWarnings("deprecation")
    private String resolveLocationName(Context context, Location location) {
        if (!Geocoder.isPresent()) return "当前位置";
        try {
            List<Address> addresses = new Geocoder(context, Locale.CHINA)
                .getFromLocation(location.getLatitude(), location.getLongitude(), 1);
            if (addresses == null || addresses.isEmpty()) return "当前位置";
            String displayName = displayName(addresses.get(0));
            return displayName.isEmpty() ? "当前位置" : displayName;
        } catch (Exception ignored) {
            return "当前位置";
        }
    }

    private String displayName(Address address) {
        List<String> parts = new ArrayList<>();
        addDistinct(parts, address.getAdminArea());
        addDistinct(parts, address.getSubAdminArea());
        addDistinct(parts, address.getLocality());
        addDistinct(parts, address.getSubLocality());
        addDistinct(parts, address.getFeatureName());
        return String.join(" ", parts);
    }

    private void addDistinct(List<String> values, String value) {
        if (value == null || value.trim().isEmpty()) return;
        String normalized = value.trim();
        if (!values.contains(normalized)) values.add(normalized);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private void clearLocationListener() {
        if (locationTimeout != null) mainHandler.removeCallbacks(locationTimeout);
        locationTimeout = null;
        if (locationManager != null && locationListener != null) {
            try { locationManager.removeUpdates(locationListener); } catch (RuntimeException ignored) { }
        }
        locationListener = null;
    }

    @Override public void onDestroyView() {
        clearLocationListener();
        requestInFlight = false;
        if (binding != null) {
            View root = binding.getRoot();
            ViewCompat.setOnApplyWindowInsetsListener(root, null);
            ViewCompat.setWindowInsetsAnimationCallback(root, null);
        }
        binding = null;
        super.onDestroyView();
    }

    @Override public void onSaveInstanceState(@NonNull Bundle outState) {
        super.onSaveInstanceState(outState);
        outState.putLong("conversation_id", conversationId);
    }

    @Override public void onDestroy() {
        geocoderExecutor.shutdownNow();
        super.onDestroy();
    }
}
