package xyz.jjmxg.yiyunying.ui.bot;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.RectF;
import android.graphics.Shader;
import android.os.SystemClock;
import android.util.AttributeSet;
import android.view.View;

import androidx.annotation.Nullable;

import com.google.gson.JsonArray;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.data.api.Jsons;

public final class WeatherCardView extends View {
    private static final long ANIMATION_DURATION_MS = 8_000L;

    private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG | Paint.DITHER_FLAG);
    private final Paint textPaint = new Paint(Paint.ANTI_ALIAS_FLAG | Paint.SUBPIXEL_TEXT_FLAG);
    private final RectF bounds = new RectF();
    private final Path clipPath = new Path();
    private final Path lightningPath = new Path();
    private final List<ForecastDay> forecast = new ArrayList<>();
    private final List<String> advice = new ArrayList<>();
    private final List<String> fittedAdvice = new ArrayList<>();
    private Shader backgroundShader;
    private int shaderWidth;
    private int shaderHeight;
    private String shaderCondition = "";
    private float fittedAdviceWidth = -1f;
    private boolean hasWeather;
    private boolean animating;
    private long animationStartedAt;
    private float phase;

    private String location = "当前位置";
    private String conditionKey = "sunny";
    private String conditionName = "晴";
    private String source = "";
    private String temperatureText = "--°";
    private String highLowText = "最高 --°  最低 --°";
    private String apparentText = "--°";
    private String humidityText = "--%";
    private String rainText = "--%";
    private String detailText = "";
    private String sunText = "";
    private boolean stale;

    private final Runnable animationFrame = new Runnable() {
        @Override public void run() {
            if (!animating || !isAttachedToWindow() || !isShown() || getWindowVisibility() != VISIBLE) {
                animating = false;
                return;
            }
            long elapsed = SystemClock.uptimeMillis() - animationStartedAt;
            phase = (elapsed % ANIMATION_DURATION_MS) / (float) ANIMATION_DURATION_MS;
            invalidate();
            postOnAnimation(this);
        }
    };

    public WeatherCardView(Context context) {
        this(context, null);
    }

    public WeatherCardView(Context context, @Nullable AttributeSet attrs) {
        super(context, attrs);
        textPaint.setTypeface(android.graphics.Typeface.create("sans", android.graphics.Typeface.NORMAL));
        setImportantForAccessibility(IMPORTANT_FOR_ACCESSIBILITY_YES);
    }

    public void setWeather(JsonObject weather) {
        hasWeather = true;
        location = value(weather, "location_name", "当前位置");
        conditionKey = value(weather, "condition_key", "sunny");
        conditionName = value(weather, "condition_name", "晴");
        source = value(weather, "source", "");
        float temperature = number(weather, "temperature");
        float apparentTemperature = number(weather, "apparent_temperature");
        float high = number(weather, "temperature_max");
        float low = number(weather, "temperature_min");
        float windSpeed = number(weather, "wind_speed");
        float pressure = number(weather, "surface_pressure");
        float uvIndex = number(weather, "uv_index");
        int humidity = Jsons.intValue(weather, "humidity", 0);
        int precipitationProbability = Jsons.intValue(weather, "precipitation_probability", 0);
        String sunrise = shortTime(value(weather, "sunrise", ""));
        String sunset = shortTime(value(weather, "sunset", ""));
        stale = weather.has("stale") && weather.get("stale").getAsBoolean();

        temperatureText = whole(temperature) + "°";
        highLowText = "最高 " + whole(high) + "°  最低 " + whole(low) + "°";
        apparentText = whole(apparentTemperature) + "°";
        humidityText = humidity + "%";
        rainText = precipitationProbability + "%";
        detailText = "风速 " + oneDecimal(windSpeed) + " km/h  ·  气压 " + whole(pressure)
            + " hPa  ·  紫外线 " + oneDecimal(uvIndex);
        sunText = "日出 " + (sunrise.isEmpty() ? "--:--" : sunrise)
            + "  ·  日落 " + (sunset.isEmpty() ? "--:--" : sunset);

        forecast.clear();
        JsonArray rows = Jsons.array(weather, "forecast");
        for (int index = 0; index < rows.size() && index < 3; index++) {
            if (!rows.get(index).isJsonObject()) continue;
            JsonObject item = rows.get(index).getAsJsonObject();
            String date = value(item, "date", "");
            String label = index == 0 ? "今天" : shortDate(date);
            forecast.add(new ForecastDay(
                label,
                value(item, "condition_name", ""),
                whole(number(item, "temperature_max")) + " / " + whole(number(item, "temperature_min")) + "°"
            ));
        }

        advice.clear();
        JsonArray adviceRows = Jsons.array(weather, "advice");
        for (int index = 0; index < adviceRows.size() && index < 3; index++) {
            try {
                String item = adviceRows.get(index).getAsString().trim();
                if (!item.isEmpty()) advice.add(item);
            } catch (RuntimeException ignored) { }
        }
        fittedAdviceWidth = -1f;
        backgroundShader = null;
        setContentDescription(String.format(Locale.CHINA,
            "%s，%s，当前 %.0f 度，最高 %.0f 度，最低 %.0f 度",
            location, conditionName, temperature, high, low));
        invalidate();
        startAnimation();
    }

    @Override protected void onAttachedToWindow() {
        super.onAttachedToWindow();
        startAnimation();
    }

    @Override protected void onDetachedFromWindow() {
        stopAnimation();
        super.onDetachedFromWindow();
    }

    @Override protected void onVisibilityChanged(View changedView, int visibility) {
        super.onVisibilityChanged(changedView, visibility);
        if (visibility == VISIBLE) startAnimation(); else stopAnimation();
    }

    @Override protected void onWindowVisibilityChanged(int visibility) {
        super.onWindowVisibilityChanged(visibility);
        if (visibility == VISIBLE) startAnimation(); else stopAnimation();
    }

    @Override protected void onSizeChanged(int width, int height, int oldWidth, int oldHeight) {
        super.onSizeChanged(width, height, oldWidth, oldHeight);
        backgroundShader = null;
        fittedAdviceWidth = -1f;
    }

    @Override protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        float width = getWidth();
        float height = getHeight();
        if (width <= 0 || height <= 0) return;
        bounds.set(0, 0, width, height);
        float radius = dp(18);
        clipPath.reset();
        clipPath.addRoundRect(bounds, radius, radius, Path.Direction.CW);
        int save = canvas.save();
        canvas.clipPath(clipPath);
        drawBackground(canvas, width, height);
        drawScene(canvas, width, height);
        drawWeatherContent(canvas, width, height);
        canvas.restoreToCount(save);
    }

    private void drawBackground(Canvas canvas, float width, float height) {
        if (backgroundShader == null || shaderWidth != (int) width || shaderHeight != (int) height
            || !shaderCondition.equals(conditionKey)) {
            int top;
            int middle;
            int bottom;
            switch (conditionKey) {
                case "rain":
                case "storm":
                    top = Color.rgb(45, 66, 96);
                    middle = Color.rgb(65, 95, 128);
                    bottom = Color.rgb(104, 132, 157);
                    break;
                case "snow":
                    top = Color.rgb(78, 127, 170);
                    middle = Color.rgb(126, 169, 200);
                    bottom = Color.rgb(207, 226, 239);
                    break;
                case "fog":
                    top = Color.rgb(83, 107, 127);
                    middle = Color.rgb(121, 144, 160);
                    bottom = Color.rgb(185, 199, 207);
                    break;
                case "cloudy":
                case "partly_cloudy":
                    top = Color.rgb(42, 91, 151);
                    middle = Color.rgb(73, 130, 182);
                    bottom = Color.rgb(143, 181, 209);
                    break;
                default:
                    top = Color.rgb(13, 103, 214);
                    middle = Color.rgb(45, 146, 226);
                    bottom = Color.rgb(117, 202, 242);
                    break;
            }
            backgroundShader = new LinearGradient(
                0, 0, width, height,
                new int[]{top, middle, bottom},
                new float[]{0f, 0.56f, 1f},
                Shader.TileMode.CLAMP
            );
            shaderWidth = (int) width;
            shaderHeight = (int) height;
            shaderCondition = conditionKey;
        }
        paint.setShader(backgroundShader);
        canvas.drawRect(0, 0, width, height, paint);
        paint.setShader(null);
        paint.setColor(Color.argb(18, 255, 255, 255));
        canvas.drawCircle(width * 0.88f, height * 0.04f, width * 0.42f, paint);
    }

    private void drawScene(Canvas canvas, float width, float height) {
        boolean sunny = "sunny".equals(conditionKey) || "partly_cloudy".equals(conditionKey);
        boolean cloudy = "cloudy".equals(conditionKey) || "partly_cloudy".equals(conditionKey)
            || "rain".equals(conditionKey) || "storm".equals(conditionKey) || "snow".equals(conditionKey);
        if (sunny) drawSun(canvas, width * 0.82f, height * 0.17f, Math.min(width, height) * 0.085f);
        if (cloudy) {
            float travel = width + dp(180);
            float x = phase * travel - dp(100);
            drawCloud(canvas, x, height * 0.17f, dp(34), 180);
            float second = (x + width * 0.62f) % travel;
            drawCloud(canvas, second, height * 0.11f, dp(25), 126);
        }
        if ("rain".equals(conditionKey) || "storm".equals(conditionKey)) drawRain(canvas, width, height);
        if ("snow".equals(conditionKey)) drawSnow(canvas, width, height);
        if ("fog".equals(conditionKey)) drawFog(canvas, width, height);
        if ("storm".equals(conditionKey) && phase > 0.47f && phase < 0.51f) drawLightning(canvas, width, height);
    }

    private void drawSun(Canvas canvas, float x, float y, float radius) {
        paint.setStyle(Paint.Style.FILL);
        for (int ring = 3; ring >= 1; ring--) {
            paint.setColor(Color.argb(18 + ring * 13, 255, 229, 110));
            canvas.drawCircle(x, y, radius * (1f + ring * 0.30f), paint);
        }
        paint.setColor(Color.rgb(255, 224, 91));
        canvas.drawCircle(x, y, radius, paint);
        paint.setColor(Color.argb(190, 255, 242, 167));
        paint.setStrokeWidth(dp(2));
        paint.setStyle(Paint.Style.STROKE);
        int save = canvas.save();
        canvas.rotate(phase * 360f, x, y);
        for (int index = 0; index < 10; index++) {
            canvas.drawLine(x, y - radius * 1.35f, x, y - radius * 1.70f, paint);
            canvas.rotate(36f, x, y);
        }
        canvas.restoreToCount(save);
        paint.setStyle(Paint.Style.FILL);
    }

    private void drawCloud(Canvas canvas, float x, float y, float size, int alpha) {
        paint.setColor(Color.argb(alpha, 255, 255, 255));
        canvas.drawCircle(x + size * 0.55f, y, size * 0.42f, paint);
        canvas.drawCircle(x + size, y - size * 0.14f, size * 0.58f, paint);
        canvas.drawCircle(x + size * 1.48f, y, size * 0.40f, paint);
        canvas.drawRoundRect(x + size * 0.20f, y, x + size * 1.78f, y + size * 0.54f,
            size * 0.24f, size * 0.24f, paint);
    }

    private void drawRain(Canvas canvas, float width, float height) {
        paint.setColor(Color.argb(168, 207, 238, 255));
        paint.setStrokeWidth(dp(1.5f));
        float span = Math.max(dp(32), width - dp(16));
        for (int index = 0; index < 14; index++) {
            float x = dp(8) + (index * dp(31)) % span;
            float y = ((phase * height * 1.55f + index * dp(29)) % (height * 0.74f)) + height * 0.16f;
            canvas.drawLine(x, y, x - dp(4), y + dp(12), paint);
        }
    }

    private void drawSnow(Canvas canvas, float width, float height) {
        paint.setColor(Color.argb(205, 255, 255, 255));
        for (int index = 0; index < 16; index++) {
            float x = (index * dp(37) + (float) Math.sin(phase * Math.PI * 2 + index) * dp(7)) % width;
            float y = ((phase * height + index * dp(41)) % (height * 0.82f)) + height * 0.12f;
            canvas.drawCircle(x, y, dp(1.5f + index % 3), paint);
        }
    }

    private void drawFog(Canvas canvas, float width, float height) {
        paint.setColor(Color.argb(88, 255, 255, 255));
        paint.setStrokeWidth(dp(4));
        for (int index = 0; index < 4; index++) {
            float shift = (phase * dp(42) + index * dp(11)) % dp(42);
            canvas.drawLine(dp(18) - shift, height * (0.16f + index * 0.09f),
                width - dp(18), height * (0.16f + index * 0.09f), paint);
        }
    }

    private void drawLightning(Canvas canvas, float width, float height) {
        lightningPath.reset();
        lightningPath.moveTo(width * 0.77f, height * 0.17f);
        lightningPath.lineTo(width * 0.68f, height * 0.35f);
        lightningPath.lineTo(width * 0.75f, height * 0.33f);
        lightningPath.lineTo(width * 0.69f, height * 0.48f);
        lightningPath.lineTo(width * 0.84f, height * 0.28f);
        lightningPath.lineTo(width * 0.77f, height * 0.29f);
        lightningPath.close();
        paint.setColor(Color.rgb(255, 231, 92));
        paint.setStyle(Paint.Style.FILL);
        canvas.drawPath(lightningPath, paint);
    }

    private void drawWeatherContent(Canvas canvas, float width, float height) {
        float left = dp(18);
        float right = width - left;
        textPaint.setTextAlign(Paint.Align.LEFT);
        textPaint.setColor(Color.WHITE);
        textPaint.setFakeBoldText(true);
        textPaint.setTextSize(sp(17));
        canvas.drawText(fitText(location, width * 0.60f), left, dp(29), textPaint);

        textPaint.setTextSize(sp(48));
        canvas.drawText(temperatureText, left, dp(91), textPaint);
        textPaint.setTextSize(sp(18));
        canvas.drawText(conditionName, left + dp(122), dp(66), textPaint);
        textPaint.setFakeBoldText(false);
        textPaint.setTextSize(sp(12));
        textPaint.setColor(Color.argb(224, 255, 255, 255));
        canvas.drawText(highLowText, left + dp(122), dp(89), textPaint);

        drawPanel(canvas, left, dp(108), right, dp(168), dp(14), Color.argb(42, 255, 255, 255));
        drawMetric(canvas, left, width, 0, "体感", apparentText);
        drawMetric(canvas, left, width, 1, "湿度", humidityText);
        drawMetric(canvas, left, width, 2, "降雨", rainText);

        drawPanel(canvas, left, dp(180), right, dp(264), dp(14), Color.argb(38, 255, 255, 255));
        if (!forecast.isEmpty()) {
            float cellWidth = (right - left) / forecast.size();
            textPaint.setTextAlign(Paint.Align.CENTER);
            for (int index = 0; index < forecast.size(); index++) {
                ForecastDay item = forecast.get(index);
                float center = left + cellWidth * index + cellWidth / 2f;
                textPaint.setFakeBoldText(true);
                textPaint.setTextSize(sp(12));
                textPaint.setColor(Color.WHITE);
                canvas.drawText(item.label, center, dp(204), textPaint);
                textPaint.setFakeBoldText(false);
                textPaint.setColor(Color.argb(224, 255, 255, 255));
                canvas.drawText(item.condition, center, dp(226), textPaint);
                canvas.drawText(item.temperature, center, dp(249), textPaint);
            }
            textPaint.setTextAlign(Paint.Align.LEFT);
        }

        textPaint.setTextSize(sp(10.5f));
        textPaint.setColor(Color.argb(220, 255, 255, 255));
        canvas.drawText(fitText(detailText, right - left), left, dp(286), textPaint);
        canvas.drawText(sunText, left, dp(304), textPaint);

        float adviceTop = dp(316);
        float adviceBottom = height - dp(22);
        if (!advice.isEmpty() && adviceBottom - adviceTop >= dp(54)) {
            drawPanel(canvas, left, adviceTop, right, adviceBottom, dp(13), Color.argb(45, 255, 255, 255));
            ensureFittedAdvice(right - left - dp(22));
            textPaint.setTextSize(sp(10.5f));
            textPaint.setColor(Color.argb(238, 255, 255, 255));
            int visibleRows = Math.min(fittedAdvice.size(), Math.max(1, (int) ((adviceBottom - adviceTop - dp(14)) / dp(20))));
            for (int index = 0; index < visibleRows; index++) {
                canvas.drawText("• " + fittedAdvice.get(index), left + dp(10), adviceTop + dp(20 + index * 20), textPaint);
            }
        }

        if (stale || !source.isEmpty()) {
            textPaint.setTextSize(sp(9));
            textPaint.setTextAlign(Paint.Align.RIGHT);
            textPaint.setColor(Color.argb(165, 255, 255, 255));
            String footer = stale ? "缓存数据 · " + source : "数据来源 " + source;
            canvas.drawText(footer, width - dp(10), height - dp(7), textPaint);
            textPaint.setTextAlign(Paint.Align.LEFT);
        }
    }

    private void drawMetric(Canvas canvas, float left, float width, int index, String label, String value) {
        float available = width - left * 2f;
        float cellWidth = available / 3f;
        float center = left + cellWidth * index + cellWidth / 2f;
        if (index > 0) {
            paint.setColor(Color.argb(45, 255, 255, 255));
            paint.setStrokeWidth(dp(1));
            float x = left + cellWidth * index;
            canvas.drawLine(x, dp(120), x, dp(156), paint);
        }
        textPaint.setTextAlign(Paint.Align.CENTER);
        textPaint.setFakeBoldText(false);
        textPaint.setTextSize(sp(10));
        textPaint.setColor(Color.argb(190, 255, 255, 255));
        canvas.drawText(label, center, dp(128), textPaint);
        textPaint.setFakeBoldText(true);
        textPaint.setTextSize(sp(17));
        textPaint.setColor(Color.WHITE);
        canvas.drawText(value, center, dp(153), textPaint);
        textPaint.setTextAlign(Paint.Align.LEFT);
        textPaint.setFakeBoldText(false);
    }

    private void drawPanel(Canvas canvas, float left, float top, float right, float bottom, float radius, int color) {
        paint.setShader(null);
        paint.setStyle(Paint.Style.FILL);
        paint.setColor(color);
        canvas.drawRoundRect(left, top, right, bottom, radius, radius, paint);
    }

    private void ensureFittedAdvice(float maxWidth) {
        if (Math.abs(fittedAdviceWidth - maxWidth) < 0.5f && fittedAdvice.size() == advice.size()) return;
        fittedAdviceWidth = maxWidth;
        fittedAdvice.clear();
        for (String item : advice) fittedAdvice.add(fitText(item, maxWidth));
    }

    private void startAnimation() {
        if (!hasWeather || animating || !isAttachedToWindow() || !isShown() || getWindowVisibility() != VISIBLE) return;
        animating = true;
        animationStartedAt = SystemClock.uptimeMillis() - (long) (phase * ANIMATION_DURATION_MS);
        removeCallbacks(animationFrame);
        postOnAnimation(animationFrame);
    }

    private void stopAnimation() {
        animating = false;
        removeCallbacks(animationFrame);
    }

    private float dp(float value) {
        return value * getResources().getDisplayMetrics().density;
    }

    private float sp(float value) {
        return value * getResources().getDisplayMetrics().scaledDensity;
    }

    private static String value(JsonObject object, String key, String fallback) {
        String result = Jsons.string(object, key);
        return result.isEmpty() ? fallback : result;
    }

    private static float number(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return 0f;
        try { return object.get(key).getAsFloat(); } catch (RuntimeException ignored) { return 0f; }
    }

    private static String shortDate(String date) {
        if (date == null || date.length() < 10) return date == null ? "" : date;
        return date.substring(5).replace('-', '/');
    }

    private static String shortTime(String value) {
        if (value == null || value.isEmpty()) return "";
        int separator = value.indexOf('T');
        String time = separator >= 0 ? value.substring(separator + 1) : value;
        return time.length() >= 5 ? time.substring(0, 5) : time;
    }

    private static String whole(float value) {
        return String.format(Locale.CHINA, "%.0f", value);
    }

    private static String oneDecimal(float value) {
        return String.format(Locale.CHINA, "%.1f", value);
    }

    private String fitText(String value, float maxWidth) {
        if (value == null) return "";
        if (textPaint.measureText(value) <= maxWidth) return value;
        String suffix = "…";
        int end = value.length();
        while (end > 0 && textPaint.measureText(value.substring(0, end) + suffix) > maxWidth) end--;
        return value.substring(0, Math.max(0, end)) + suffix;
    }

    private static final class ForecastDay {
        final String label;
        final String condition;
        final String temperature;

        ForecastDay(String label, String condition, String temperature) {
            this.label = label;
            this.condition = condition;
            this.temperature = temperature;
        }
    }
}
