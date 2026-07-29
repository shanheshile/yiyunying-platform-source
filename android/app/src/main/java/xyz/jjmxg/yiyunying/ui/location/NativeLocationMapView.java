package xyz.jjmxg.yiyunying.ui.location;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.DashPathEffect;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.PointF;
import android.graphics.RectF;
import android.util.AttributeSet;
import android.view.MotionEvent;
import android.view.ScaleGestureDetector;
import android.view.View;

import androidx.annotation.Nullable;
import androidx.core.content.ContextCompat;

import java.util.Locale;

import xyz.jjmxg.yiyunying.R;

/** Lightweight native location preview that remains usable without map tiles or JavaScript. */
public final class NativeLocationMapView extends View {
    public interface OnCenterChangedListener {
        void onCenterChanged(double latitude, double longitude);
    }

    private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint textPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Path path = new Path();
    private final DashPathEffect roadDashEffect;
    private final ScaleGestureDetector scaleDetector;
    private double latitude = 35.86166d;
    private double longitude = 104.195397d;
    private double ownLatitude = Double.NaN;
    private double ownLongitude = Double.NaN;
    private String selectedLabel = "所选位置";
    private OnCenterChangedListener listener;
    private float downX;
    private float downY;
    private double downLatitude;
    private double downLongitude;
    private boolean dragging;
    private boolean previewMode;
    private float zoomLevel = 17f;

    public NativeLocationMapView(Context context) {
        this(context, null);
    }

    public NativeLocationMapView(Context context, @Nullable AttributeSet attrs) {
        this(context, attrs, 0);
    }

    public NativeLocationMapView(Context context, @Nullable AttributeSet attrs, int defStyleAttr) {
        super(context, attrs, defStyleAttr);
        roadDashEffect = new DashPathEffect(new float[] {dp(8), dp(8)}, 0);
        scaleDetector = new ScaleGestureDetector(context,
            new ScaleGestureDetector.SimpleOnScaleGestureListener() {
                @Override public boolean onScale(ScaleGestureDetector detector) {
                    float factor = detector.getScaleFactor();
                    if (!Float.isFinite(factor) || factor <= 0f) return false;
                    setZoom(zoomLevel + (float) (Math.log(factor) / Math.log(1.18d)));
                    return true;
                }
            });
        setFocusable(true);
        setClickable(true);
        textPaint.setTypeface(android.graphics.Typeface.create("sans", android.graphics.Typeface.NORMAL));
        setContentDescription("原生位置地图，可拖动选择位置");
    }

    public void setOnCenterChangedListener(@Nullable OnCenterChangedListener listener) {
        this.listener = listener;
    }

    public void setCenter(double latitude, double longitude, boolean notify) {
        if (!valid(latitude, longitude)) return;
        this.latitude = latitude;
        this.longitude = longitude;
        invalidate();
        if (notify && listener != null) listener.onCenterChanged(latitude, longitude);
    }

    public void setOwnLocation(double latitude, double longitude) {
        ownLatitude = latitude;
        ownLongitude = longitude;
        invalidate();
    }

    public void setZoom(float zoom) {
        zoomLevel = Math.max(12f, Math.min(20f, zoom));
        invalidate();
        setContentDescription((previewMode ? "位置详情" : "原生位置地图，可拖动选择位置")
            + "，缩放 " + Math.round(zoomLevel) + " 级");
    }

    public void zoomBy(float delta) {
        setZoom(zoomLevel + delta);
    }

    public void clearOwnLocation() {
        ownLatitude = Double.NaN;
        ownLongitude = Double.NaN;
        invalidate();
    }

    public void setSelectedLabel(@Nullable String label) {
        selectedLabel = label == null || label.trim().isEmpty() ? "所选位置" : label.trim();
        invalidate();
    }

    public void setPreviewMode(boolean previewMode) {
        this.previewMode = previewMode;
        setContentDescription(previewMode ? "发送位置与我的位置" : "原生位置地图，可拖动选择位置");
        invalidate();
    }

    @Override protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        int width = getWidth();
        int height = getHeight();
        if (width <= 0 || height <= 0) return;

        int surface = color(R.color.primary_container);
        int road = blend(color(R.color.surface), surface, 0.72f);
        int minorRoad = blend(color(R.color.outline_variant), surface, 0.62f);
        int label = color(R.color.on_surface_variant);
        int primary = color(R.color.primary);

        canvas.drawColor(surface);
        paint.setStyle(Paint.Style.STROKE);
        paint.setStrokeCap(Paint.Cap.ROUND);
        paint.setPathEffect(null);

        paint.setColor(minorRoad);
        paint.setStrokeWidth(dp(7));
        for (int i = -1; i < 5; i++) {
            float y = height * (0.17f + i * 0.25f);
            path.reset();
            path.moveTo(-dp(24), y + dp(i % 2 == 0 ? 12 : -8));
            path.cubicTo(width * .27f, y - dp(28), width * .69f, y + dp(24), width + dp(24), y - dp(4));
            canvas.drawPath(path, paint);
        }
        for (int i = 0; i < 5; i++) {
            float x = width * (0.08f + i * 0.24f);
            path.reset();
            path.moveTo(x, -dp(20));
            path.cubicTo(x + dp(36), height * .28f, x - dp(24), height * .72f, x + dp(20), height + dp(20));
            canvas.drawPath(path, paint);
        }

        paint.setColor(road);
        paint.setStrokeWidth(dp(15));
        path.reset();
        path.moveTo(-dp(30), height * .72f);
        path.cubicTo(width * .28f, height * .54f, width * .58f, height * .78f, width + dp(30), height * .42f);
        canvas.drawPath(path, paint);
        paint.setColor(blend(primary, Color.WHITE, 0.72f));
        paint.setStrokeWidth(dp(2));
        paint.setPathEffect(roadDashEffect);
        canvas.drawPath(path, paint);
        paint.setPathEffect(null);

        if (previewMode && valid(ownLatitude, ownLongitude)) {
            drawRelationship(canvas, primary, label);
        }
        if (!previewMode) drawCoordinateBadge(canvas, label);
        if (valid(ownLatitude, ownLongitude)) drawOwnMarker(canvas, primary, label);
        drawSelectedMarker(canvas, primary);
    }

    private void drawCoordinateBadge(Canvas canvas, int color) {
        String value = String.format(Locale.CHINA, "%.5f, %.5f", latitude, longitude);
        textPaint.setTextSize(dp(10));
        textPaint.setColor(color);
        float width = textPaint.measureText(value);
        paint.setStyle(Paint.Style.FILL);
        paint.setColor(blend(color(R.color.surface), Color.TRANSPARENT, 0.08f));
        RectF rect = new RectF(dp(10), dp(10), dp(24) + width, dp(34));
        canvas.drawRoundRect(rect, dp(8), dp(8), paint);
        canvas.drawText(value, dp(17), dp(26), textPaint);
    }

    private void drawSelectedMarker(Canvas canvas, int primary) {
        float cx = getWidth() / 2f;
        float cy = getHeight() / 2f - dp(7);
        paint.setStyle(Paint.Style.FILL);
        paint.setColor(0x26000000);
        canvas.drawOval(new RectF(cx - dp(13), cy + dp(22), cx + dp(13), cy + dp(31)), paint);
        paint.setColor(primary);
        canvas.drawCircle(cx, cy, dp(20), paint);
        path.reset();
        path.moveTo(cx - dp(10), cy + dp(14));
        path.lineTo(cx, cy + dp(35));
        path.lineTo(cx + dp(10), cy + dp(14));
        path.close();
        canvas.drawPath(path, paint);
        paint.setColor(color(R.color.on_primary));
        canvas.drawCircle(cx, cy, dp(7), paint);

        textPaint.setTextSize(dp(11));
        textPaint.setColor(color(R.color.on_surface));
        textPaint.setFakeBoldText(true);
        float labelWidth = Math.min(textPaint.measureText(selectedLabel), getWidth() - dp(32));
        float left = Math.max(dp(8), Math.min(getWidth() - labelWidth - dp(24), cx - labelWidth / 2f - dp(12)));
        paint.setColor(color(R.color.glass_surface_strong));
        canvas.drawRoundRect(new RectF(left, cy - dp(52), left + labelWidth + dp(24), cy - dp(24)), dp(9), dp(9), paint);
        canvas.save();
        canvas.clipRect(left + dp(8), cy - dp(52), left + labelWidth + dp(16), cy - dp(24));
        canvas.drawText(selectedLabel, left + dp(12), cy - dp(33), textPaint);
        canvas.restore();
        textPaint.setFakeBoldText(false);
    }

    private void drawOwnMarker(Canvas canvas, int primary, int labelColor) {
        PointF point = ownPoint();
        float x = point.x;
        float y = point.y;
        paint.setStyle(Paint.Style.FILL);
        paint.setColor(color(R.color.surface));
        canvas.drawCircle(x, y, dp(10), paint);
        paint.setColor(primary);
        canvas.drawCircle(x, y, dp(7), paint);
        paint.setStyle(Paint.Style.STROKE);
        paint.setStrokeWidth(dp(2));
        paint.setColor(0x553A7BD5);
        canvas.drawCircle(x, y, dp(14), paint);
        paint.setStyle(Paint.Style.FILL);
        textPaint.setTextSize(dp(9));
        textPaint.setColor(labelColor);
        canvas.drawText("我的位置", Math.min(getWidth() - dp(45), x + dp(13)), y - dp(9), textPaint);
    }

    private PointF ownPoint() {
        double scale = 220000d * Math.pow(1.42d, zoomLevel - 17d);
        double cos = Math.max(.25d, Math.cos(Math.toRadians(latitude)));
        float x = getWidth() / 2f + (float) ((ownLongitude - longitude) * cos * scale);
        float y = getHeight() / 2f - (float) ((ownLatitude - latitude) * scale);
        x = Math.max(dp(18), Math.min(getWidth() - dp(18), x));
        y = Math.max(dp(44), Math.min(getHeight() - dp(18), y));
        return new PointF(x, y);
    }

    private void drawRelationship(Canvas canvas, int primary, int labelColor) {
        PointF own = ownPoint();
        float selectedX = getWidth() / 2f;
        float selectedY = getHeight() / 2f - dp(7);
        paint.setStyle(Paint.Style.STROKE);
        paint.setStrokeWidth(dp(2));
        paint.setColor(blend(primary, color(R.color.surface), 0.76f));
        paint.setPathEffect(new DashPathEffect(new float[] {dp(7), dp(6)}, 0));
        canvas.drawLine(own.x, own.y, selectedX, selectedY, paint);
        paint.setPathEffect(null);

        String distance = distanceText(haversineMeters(ownLatitude, ownLongitude, latitude, longitude));
        textPaint.setTextSize(dp(10));
        textPaint.setColor(labelColor);
        textPaint.setFakeBoldText(true);
        float textWidth = textPaint.measureText(distance);
        float centerX = (own.x + selectedX) / 2f;
        float centerY = (own.y + selectedY) / 2f;
        RectF badge = new RectF(
            Math.max(dp(8), centerX - textWidth / 2f - dp(9)),
            centerY - dp(13),
            Math.min(getWidth() - dp(8), centerX + textWidth / 2f + dp(9)),
            centerY + dp(10)
        );
        paint.setStyle(Paint.Style.FILL);
        paint.setColor(color(R.color.glass_surface_strong));
        canvas.drawRoundRect(badge, dp(9), dp(9), paint);
        canvas.drawText(distance, badge.centerX() - textWidth / 2f, badge.centerY() + dp(4), textPaint);
        textPaint.setFakeBoldText(false);
    }

    private double haversineMeters(double fromLat, double fromLng, double toLat, double toLng) {
        double earthRadius = 6_371_000d;
        double latitudeDelta = Math.toRadians(toLat - fromLat);
        double longitudeDelta = Math.toRadians(toLng - fromLng);
        double start = Math.toRadians(fromLat);
        double end = Math.toRadians(toLat);
        double value = Math.sin(latitudeDelta / 2d) * Math.sin(latitudeDelta / 2d)
            + Math.cos(start) * Math.cos(end)
            * Math.sin(longitudeDelta / 2d) * Math.sin(longitudeDelta / 2d);
        return earthRadius * 2d * Math.atan2(Math.sqrt(value), Math.sqrt(Math.max(0d, 1d - value)));
    }

    private String distanceText(double meters) {
        if (meters < 1000d) return "距我 " + Math.max(1, Math.round(meters)) + " 米";
        return String.format(Locale.CHINA, "距我 %.1f 公里", meters / 1000d);
    }

    @Override public boolean onTouchEvent(MotionEvent event) {
        scaleDetector.onTouchEvent(event);
        if (previewMode) {
            if (event.getActionMasked() == MotionEvent.ACTION_UP) performClick();
            return true;
        }
        if (scaleDetector.isInProgress()) return true;
        switch (event.getActionMasked()) {
            case MotionEvent.ACTION_DOWN:
                downX = event.getX();
                downY = event.getY();
                downLatitude = latitude;
                downLongitude = longitude;
                dragging = false;
                if (getParent() != null) getParent().requestDisallowInterceptTouchEvent(true);
                return true;
            case MotionEvent.ACTION_MOVE:
                float dx = event.getX() - downX;
                float dy = event.getY() - downY;
                if (Math.abs(dx) + Math.abs(dy) > dp(4)) dragging = true;
                double degrees = 0.000018d * Math.pow(2d, 17d - zoomLevel);
                double cos = Math.max(.25d, Math.cos(Math.toRadians(downLatitude)));
                latitude = clamp(downLatitude + dy * degrees, -90d, 90d);
                longitude = clamp(downLongitude - dx * degrees / cos, -180d, 180d);
                invalidate();
                return true;
            case MotionEvent.ACTION_UP:
            case MotionEvent.ACTION_CANCEL:
                if (getParent() != null) getParent().requestDisallowInterceptTouchEvent(false);
                if (event.getActionMasked() == MotionEvent.ACTION_UP && dragging && listener != null) {
                    listener.onCenterChanged(latitude, longitude);
                }
                performClick();
                return true;
            default:
                return super.onTouchEvent(event);
        }
    }

    @Override public boolean performClick() {
        super.performClick();
        return true;
    }

    private int color(int id) {
        return ContextCompat.getColor(getContext(), id);
    }

    private int dp(float value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private boolean valid(double lat, double lng) {
        return !Double.isNaN(lat) && !Double.isNaN(lng) && lat >= -90d && lat <= 90d && lng >= -180d && lng <= 180d;
    }

    private double clamp(double value, double min, double max) {
        return Math.max(min, Math.min(max, value));
    }

    private int blend(int foreground, int background, float ratio) {
        float keep = Math.max(0f, Math.min(1f, ratio));
        float inverse = 1f - keep;
        return Color.argb(
            Math.round(Color.alpha(foreground) * keep + Color.alpha(background) * inverse),
            Math.round(Color.red(foreground) * keep + Color.red(background) * inverse),
            Math.round(Color.green(foreground) * keep + Color.green(background) * inverse),
            Math.round(Color.blue(foreground) * keep + Color.blue(background) * inverse)
        );
    }
}
