package xyz.jjmxg.yiyunying.ui.location;

import android.Manifest;
import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.location.Address;
import android.location.Geocoder;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.view.inputmethod.EditorInfo;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.core.content.ContextCompat;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.snackbar.Snackbar;

import java.io.IOException;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.databinding.ActivityLocationPickerBinding;
import xyz.jjmxg.yiyunying.ui.common.GlassActionDialog;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

public final class LocationPickerActivity extends SystemInsetActivity {
    public static final String EXTRA_LOCATION_NAME = "location_name";
    public static final String EXTRA_ADDRESS = "address";
    public static final String EXTRA_LATITUDE = "latitude";
    public static final String EXTRA_LONGITUDE = "longitude";
    private static final String EXTRA_VIEW_ONLY = "view_only";

    private ActivityLocationPickerBinding binding;
    private final ExecutorService geocoderExecutor = Executors.newSingleThreadExecutor();
    private final Handler mapHandler = new Handler(Looper.getMainLooper());
    private LocationManager locationManager;
    private Runnable reverseGeocodeTask;
    private LocationListener pendingLocationListener;
    private double latitude = Double.NaN;
    private double longitude = Double.NaN;
    private boolean viewOnly;

    private final ActivityResultLauncher<String[]> locationPermission = registerForActivityResult(
        new ActivityResultContracts.RequestMultiplePermissions(), result -> {
            boolean granted = Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_FINE_LOCATION))
                || Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_COARSE_LOCATION));
            if (granted) readCurrentLocation();
            else showMessage("未获得定位权限，你仍可以搜索或手动填写地点");
        });

    public static Intent pickerIntent(Context context) {
        return new Intent(context, LocationPickerActivity.class);
    }

    public static Intent pickerIntent(Context context, String name, String address,
                                      Double latitude, Double longitude) {
        Intent intent = pickerIntent(context);
        if (name != null && !name.trim().isEmpty()) {
            intent.putExtra(EXTRA_LOCATION_NAME, name.trim());
        }
        if (address != null && !address.trim().isEmpty()) {
            intent.putExtra(EXTRA_ADDRESS, address.trim());
        }
        if (latitude != null && longitude != null
            && !Double.isNaN(latitude) && !Double.isNaN(longitude)
            && latitude >= -90d && latitude <= 90d
            && longitude >= -180d && longitude <= 180d) {
            intent.putExtra(EXTRA_LATITUDE, latitude);
            intent.putExtra(EXTRA_LONGITUDE, longitude);
        }
        return intent;
    }

    public static void openPreview(Context context, String name, String address, double latitude, double longitude) {
        Intent intent = new Intent(context, LocationPickerActivity.class)
            .putExtra(EXTRA_VIEW_ONLY, true)
            .putExtra(EXTRA_LOCATION_NAME, name)
            .putExtra(EXTRA_ADDRESS, address)
            .putExtra(EXTRA_LATITUDE, latitude)
            .putExtra(EXTRA_LONGITUDE, longitude);
        if (!(context instanceof Activity)) intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        context.startActivity(intent);
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityLocationPickerBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        locationManager = (LocationManager) getSystemService(LOCATION_SERVICE);
        viewOnly = getIntent().getBooleanExtra(EXTRA_VIEW_ONLY, false);
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        configureMap();
        binding.openMapButton.setOnClickListener(view -> openMap());
        binding.zoomInButton.setOnClickListener(view -> binding.mapWebView.zoomBy(1f));
        binding.zoomOutButton.setOnClickListener(view -> binding.mapWebView.zoomBy(-1f));
        binding.currentLocationButton.setOnClickListener(view -> requestCurrentLocation());
        binding.searchButton.setOnClickListener(view -> searchLocation());
        binding.searchInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId != EditorInfo.IME_ACTION_SEARCH) return false;
            searchLocation();
            return true;
        });
        binding.confirmButton.setOnClickListener(view -> finishWithSelection());
        if (viewOnly) configurePreview();
        else configureInitialSelection();
    }

    private void configureMap() {
        binding.mapWebView.setOnCenterChangedListener(this::handleMapCenterChanged);
    }

    private void moveMapTo(double lat, double lng, int zoom) {
        if (!validCoordinate(lat, lng) || binding == null) return;
        binding.mapWebView.setZoom(zoom);
        binding.mapWebView.setCenter(lat, lng, false);
    }

    private void handleMapCenterChanged(double lat, double lng) {
        if (binding == null || viewOnly || !validCoordinate(lat, lng)) return;
        latitude = lat;
        longitude = lng;
        binding.coordinateText.setText("坐标：" + coordinate(lat, lng));
        binding.openMapButton.setEnabled(true);
        binding.mapHint.setText("正在识别地图中心");
        if (reverseGeocodeTask != null) mapHandler.removeCallbacks(reverseGeocodeTask);
        reverseGeocodeTask = () -> reverseGeocodeMapCenter(lat, lng);
        mapHandler.postDelayed(reverseGeocodeTask, 520L);
    }

    private void reverseGeocodeMapCenter(double lat, double lng) {
        if (binding == null || viewOnly || !validCoordinate(lat, lng)) return;
        binding.progress.setVisibility(View.VISIBLE);
        geocoderExecutor.execute(() -> {
            Address selected = null;
            try {
                if (Geocoder.isPresent()) {
                    List<Address> values = new Geocoder(getApplicationContext(), Locale.CHINA)
                        .getFromLocation(lat, lng, 1);
                    if (values != null && !values.isEmpty()) selected = values.get(0);
                }
            } catch (IOException | IllegalArgumentException ignored) { }
            Address result = selected;
            runOnUiThread(() -> {
                if (binding == null || isFinishing() || isDestroyed()) return;
                if (Math.abs(latitude - lat) > 0.000001d || Math.abs(longitude - lng) > 0.000001d) return;
                binding.progress.setVisibility(View.INVISIBLE);
                String name = result == null ? "地图选点" : placeName(result);
                String detail = result == null ? coordinate(lat, lng) : fullAddress(result);
                binding.nameInput.setText(name);
                binding.addressInput.setText(detail);
                showSelectedLocation(name, detail, lat, lng);
                binding.mapHint.setText("拖动地图微调位置");
            });
        });
    }
    private void configurePreview() {
        binding.toolbar.setTitle("位置详情");
        binding.mapWebView.setPreviewMode(true);
        binding.pickerActions.setVisibility(View.GONE);
        binding.searchLayout.setVisibility(View.GONE);
        binding.nameLayout.setVisibility(View.GONE);
        binding.addressLayout.setVisibility(View.GONE);
        binding.confirmButton.setVisibility(View.GONE);
        latitude = getIntent().getDoubleExtra(EXTRA_LATITUDE, Double.NaN);
        longitude = getIntent().getDoubleExtra(EXTRA_LONGITUDE, Double.NaN);
        String name = value(getIntent().getStringExtra(EXTRA_LOCATION_NAME), "位置");
        String address = value(getIntent().getStringExtra(EXTRA_ADDRESS), "未提供详细地址");
        showSelectedLocation(name, address, latitude, longitude);
        moveMapTo(latitude, longitude, 17);
        binding.mapHint.setText("发送位置与我的位置");
        if (hasLocationPermission()) {
            Location own = bestLastKnownLocation();
            if (own != null) binding.mapWebView.setOwnLocation(own.getLatitude(), own.getLongitude());
            requestOwnLocationForPreview();
        }
    }

    private void configureInitialSelection() {
        double initialLatitude = getIntent().getDoubleExtra(EXTRA_LATITUDE, Double.NaN);
        double initialLongitude = getIntent().getDoubleExtra(EXTRA_LONGITUDE, Double.NaN);
        if (!validCoordinate(initialLatitude, initialLongitude)) return;
        latitude = initialLatitude;
        longitude = initialLongitude;
        String name = value(getIntent().getStringExtra(EXTRA_LOCATION_NAME), "所选位置");
        String address = value(
            getIntent().getStringExtra(EXTRA_ADDRESS),
            coordinate(latitude, longitude)
        );
        binding.nameInput.setText(name);
        binding.addressInput.setText(address);
        showSelectedLocation(name, address, latitude, longitude);
        moveMapTo(latitude, longitude, 17);
        binding.mapHint.setText("已载入原位置，拖动地图可调整");
    }

    @SuppressWarnings("deprecation")
    private void requestOwnLocationForPreview() {
        if (!viewOnly || !hasLocationPermission() || locationManager == null) return;
        String provider = enabledProvider();
        if (provider == null) return;
        if (pendingLocationListener != null) {
            try { locationManager.removeUpdates(pendingLocationListener); }
            catch (RuntimeException ignored) { }
        }
        pendingLocationListener = location -> {
            if (locationManager != null && pendingLocationListener != null) {
                try { locationManager.removeUpdates(pendingLocationListener); }
                catch (RuntimeException ignored) { }
            }
            pendingLocationListener = null;
            if (binding != null && location != null) {
                binding.mapWebView.setOwnLocation(location.getLatitude(), location.getLongitude());
            }
        };
        try {
            locationManager.requestSingleUpdate(provider, pendingLocationListener, getMainLooper());
        } catch (SecurityException | IllegalArgumentException ignored) {
            pendingLocationListener = null;
        }
    }

    private void requestCurrentLocation() {
        if (hasLocationPermission()) {
            readCurrentLocation();
            return;
        }
        locationPermission.launch(new String[] {
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION
        });
    }

    @SuppressWarnings("deprecation")
    private void readCurrentLocation() {
        if (!hasLocationPermission() || locationManager == null) return;
        setBusy(true);
        Location best = bestLastKnownLocation();
        if (best != null) resolveLocation(best, true);
        String provider = enabledProvider();
        if (provider == null) {
            setBusy(false);
            if (best == null) showMessage("系统定位服务未开启，请开启后重试，或搜索地点");
            return;
        }
        if (pendingLocationListener != null) locationManager.removeUpdates(pendingLocationListener);
        pendingLocationListener = location -> {
            if (locationManager != null && pendingLocationListener != null) {
                locationManager.removeUpdates(pendingLocationListener);
            }
            pendingLocationListener = null;
            resolveLocation(location, false);
        };
        try {
            locationManager.requestSingleUpdate(provider, pendingLocationListener, getMainLooper());
            binding.getRoot().postDelayed(() -> {
                if (pendingLocationListener == null || binding == null) return;
                try { locationManager.removeUpdates(pendingLocationListener); } catch (RuntimeException ignored) { }
                pendingLocationListener = null;
                setBusy(false);
                if (Double.isNaN(latitude)) showMessage("暂时无法取得当前位置，请搜索地点或稍后重试");
            }, 10_000L);
        } catch (SecurityException | IllegalArgumentException exception) {
            pendingLocationListener = null;
            setBusy(false);
            if (best == null) showMessage("当前位置读取失败，请搜索地点或稍后重试");
        }
    }

    private void searchLocation() {
        String query = text(binding.searchInput);
        if (query.isEmpty()) {
            binding.searchLayout.setError("请输入地点、道路或建筑名称");
            return;
        }
        binding.searchLayout.setError(null);
        setBusy(true);
        geocoderExecutor.execute(() -> {
            List<Address> results = new ArrayList<>();
            String error = "没有找到匹配地点，可换一个关键词或手动填写";
            try {
                if (Geocoder.isPresent()) {
                    List<Address> values = new Geocoder(getApplicationContext(), Locale.CHINA)
                        .getFromLocationName(query, 6);
                    if (values != null) results.addAll(values);
                } else error = "当前设备不支持地点搜索，可手动填写地点";
            } catch (IOException | IllegalArgumentException ignored) {
                error = "地点搜索暂时不可用，请检查网络后重试";
            }
            String finalError = error;
            runOnUiThread(() -> {
                if (binding == null || isFinishing() || isDestroyed()) return;
                setBusy(false);
                renderSearchResults(results);
                if (results.isEmpty()) showMessage(finalError);
            });
        });
    }

    private void renderSearchResults(List<Address> results) {
        binding.resultContainer.removeAllViews();
        binding.resultTitle.setVisibility(results.isEmpty() ? View.GONE : View.VISIBLE);
        for (Address address : results) {
            MaterialButton item = new MaterialButton(this, null,
                com.google.android.material.R.attr.materialButtonOutlinedStyle);
            String name = placeName(address);
            String detail = fullAddress(address);
            RuntimeLanguage.protectDynamicText(item);
            item.setText(name + (detail.equals(name) ? "" : "\n" + detail));
            item.setAllCaps(false);
            item.setGravity(android.view.Gravity.START | android.view.Gravity.CENTER_VERTICAL);
            item.setMaxLines(3);
            item.setOnClickListener(view -> selectAddress(address));
            android.widget.LinearLayout.LayoutParams params = new android.widget.LinearLayout.LayoutParams(
                android.view.ViewGroup.LayoutParams.MATCH_PARENT, android.view.ViewGroup.LayoutParams.WRAP_CONTENT);
            params.bottomMargin = dp(6);
            binding.resultContainer.addView(item, params);
        }
    }

    private void selectAddress(Address address) {
        latitude = address.getLatitude();
        longitude = address.getLongitude();
        String name = placeName(address);
        String detail = fullAddress(address);
        binding.nameInput.setText(name);
        binding.addressInput.setText(detail);
        showSelectedLocation(name, detail, latitude, longitude);
        moveMapTo(latitude, longitude, 17);
    }

    private void resolveLocation(Location location, boolean cached) {
        if (location == null) return;
        double resolvedLatitude = location.getLatitude();
        double resolvedLongitude = location.getLongitude();
        latitude = resolvedLatitude;
        longitude = resolvedLongitude;
        showSelectedLocation("当前位置", cached ? "正在更新详细地址" : "正在识别详细地址",
            resolvedLatitude, resolvedLongitude);
        moveMapTo(resolvedLatitude, resolvedLongitude, 17);
        geocoderExecutor.execute(() -> {
            Address address = null;
            try {
                if (Geocoder.isPresent()) {
                    List<Address> values = new Geocoder(getApplicationContext(), Locale.CHINA)
                        .getFromLocation(resolvedLatitude, resolvedLongitude, 1);
                    if (values != null && !values.isEmpty()) address = values.get(0);
                }
            } catch (IOException | IllegalArgumentException ignored) { }
            Address finalAddress = address;
            runOnUiThread(() -> {
                if (binding == null || isFinishing() || isDestroyed()) return;
                if (Math.abs(latitude - resolvedLatitude) > 0.000001d
                    || Math.abs(longitude - resolvedLongitude) > 0.000001d) return;
                setBusy(false);
                String name = finalAddress == null ? "当前位置" : placeName(finalAddress);
                String detail = finalAddress == null
                    ? coordinate(resolvedLatitude, resolvedLongitude) : fullAddress(finalAddress);
                binding.nameInput.setText(name);
                binding.addressInput.setText(detail);
                showSelectedLocation(name, detail, resolvedLatitude, resolvedLongitude);
            });
        });
    }
    private void showSelectedLocation(String name, String address, double lat, double lng) {
        RuntimeLanguage.setDynamicText(binding.locationName, value(name, "位置"));
        RuntimeLanguage.setDynamicText(binding.locationAddress, value(address, "未提供详细地址"));
        binding.coordinateText.setText(validCoordinate(lat, lng) ? "坐标：" + coordinate(lat, lng) : "坐标：未获取");
        binding.openMapButton.setEnabled(validCoordinate(lat, lng));
        binding.mapCard.setEnabled(validCoordinate(lat, lng));
        binding.mapWebView.setSelectedLabel(value(name, "位置"));
    }

    private void finishWithSelection() {
        String name = text(binding.nameInput);
        String address = text(binding.addressInput);
        if (name.isEmpty()) {
            binding.nameLayout.setError("请填写地点名称");
            return;
        }
        binding.nameLayout.setError(null);
        Intent data = new Intent()
            .putExtra(EXTRA_LOCATION_NAME, name)
            .putExtra(EXTRA_ADDRESS, address)
            .putExtra(EXTRA_LATITUDE, latitude)
            .putExtra(EXTRA_LONGITUDE, longitude);
        setResult(RESULT_OK, data);
        finish();
    }

    private void openMap() {
        if (!validCoordinate(latitude, longitude)) {
            showMessage("请先读取当前位置或搜索地点");
            return;
        }
        String name = value(text(binding.locationName), "所选位置");
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        actions.add(new GlassActionDialog.Action("高德地图", R.drawable.ic_location,
            () -> launchMap("amap", name)));
        actions.add(new GlassActionDialog.Action("百度地图", R.drawable.ic_location,
            () -> launchMap("baidu", name)));
        actions.add(new GlassActionDialog.Action("腾讯地图", R.drawable.ic_location,
            () -> launchMap("tencent", name)));
        GlassActionDialog.show(this, "选择地图应用", actions);
    }

    private void launchMap(String provider, String name) {
        String encoded = Uri.encode(value(name, "所选位置"));
        String uri;
        if ("baidu".equals(provider)) {
            uri = String.format(Locale.US,
                "baidumap://map/marker?location=%.7f,%.7f&title=%s&content=%s&coord_type=gcj02&src=yiyunying",
                latitude, longitude, encoded, encoded);
        } else if ("tencent".equals(provider)) {
            uri = String.format(Locale.US,
                "qqmap://map/marker?marker=coord:%.7f,%.7f;title:%s;addr:%s",
                latitude, longitude, encoded, encoded);
        } else {
            uri = String.format(Locale.US,
                "androidamap://viewMap?sourceApplication=yiyunying&poiname=%s&lat=%.7f&lon=%.7f&dev=0",
                encoded, latitude, longitude);
        }
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(uri)));
        } catch (RuntimeException error) {
            try {
                Intent generic = new Intent(Intent.ACTION_VIEW,
                    Uri.parse(String.format(Locale.US, "geo:%.7f,%.7f?q=%.7f,%.7f(%s)",
                        latitude, longitude, latitude, longitude, encoded)));
                startActivity(Intent.createChooser(generic, "选择地图应用"));
            } catch (RuntimeException ignored) {
                showMessage("未找到可用的地图应用，请先安装高德、百度或腾讯地图");
            }
        }
    }

    private boolean hasLocationPermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
            || ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    private Location bestLastKnownLocation() {
        Location best = null;
        for (String provider : locationManager.getProviders(true)) {
            try {
                Location value = locationManager.getLastKnownLocation(provider);
                if (value != null && (best == null || value.getTime() > best.getTime())) best = value;
            } catch (SecurityException | IllegalArgumentException ignored) { }
        }
        return best;
    }

    private String enabledProvider() {
        try {
            if (locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) return LocationManager.NETWORK_PROVIDER;
            if (locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)) return LocationManager.GPS_PROVIDER;
        } catch (RuntimeException ignored) { }
        return null;
    }

    private String placeName(Address address) {
        String[] values = {
            address.getFeatureName(), address.getThoroughfare(), address.getSubLocality(),
            address.getLocality(), address.getSubAdminArea(), address.getAdminArea()
        };
        for (String value : values) if (value != null && !value.trim().isEmpty()) return value.trim();
        return "所选位置";
    }

    private String fullAddress(Address address) {
        String line = address.getMaxAddressLineIndex() >= 0 ? address.getAddressLine(0) : "";
        if (line != null && !line.trim().isEmpty()) return line.trim();
        StringBuilder value = new StringBuilder();
        append(value, address.getAdminArea());
        append(value, address.getLocality());
        append(value, address.getSubLocality());
        append(value, address.getThoroughfare());
        append(value, address.getFeatureName());
        return value.length() == 0 ? coordinate(address.getLatitude(), address.getLongitude()) : value.toString();
    }

    private void append(StringBuilder target, String value) {
        if (value != null && !value.trim().isEmpty() && target.indexOf(value.trim()) < 0) target.append(value.trim());
    }

    private String text(TextView view) {
        return view.getText() == null ? "" : view.getText().toString().trim();
    }

    private String value(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? fallback : value.trim();
    }

    private boolean validCoordinate(double lat, double lng) {
        return !Double.isNaN(lat) && !Double.isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
    }

    private String coordinate(double lat, double lng) {
        return String.format(Locale.US, "%.6f, %.6f", lat, lng);
    }

    private void setBusy(boolean busy) {
        if (binding == null) return;
        binding.progress.setVisibility(busy ? View.VISIBLE : View.INVISIBLE);
        binding.currentLocationButton.setEnabled(!busy);
        binding.searchButton.setEnabled(!busy);
    }

    private void showMessage(String message) {
        if (binding != null) Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_LONG).show();
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    @Override protected void onDestroy() {
        if (locationManager != null && pendingLocationListener != null) {
            try { locationManager.removeUpdates(pendingLocationListener); } catch (RuntimeException ignored) { }
        }
        pendingLocationListener = null;
        if (reverseGeocodeTask != null) mapHandler.removeCallbacks(reverseGeocodeTask);
        reverseGeocodeTask = null;
        geocoderExecutor.shutdownNow();
        binding = null;
        super.onDestroy();
    }
}
