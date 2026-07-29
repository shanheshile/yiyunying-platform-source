package xyz.jjmxg.yiyunying.ui.widget;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Path;
import android.util.AttributeSet;
import android.view.View;

import androidx.annotation.Nullable;
import androidx.core.content.ContextCompat;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;

public final class DailyChartView extends View {
    private final Paint gridPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint linePaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint pointPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint textPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Path chartPath = new Path();
    private final List<Float> values = new ArrayList<>();
    private String firstDate = "";
    private String lastDate = "";

    public DailyChartView(Context context, @Nullable AttributeSet attrs) {
        super(context, attrs);
        gridPaint.setColor(ContextCompat.getColor(context, R.color.outline));
        gridPaint.setAlpha(55);
        gridPaint.setStrokeWidth(dp(1));
        linePaint.setColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(context));
        linePaint.setStrokeWidth(dp(3));
        linePaint.setStyle(Paint.Style.STROKE);
        linePaint.setStrokeJoin(Paint.Join.ROUND);
        linePaint.setStrokeCap(Paint.Cap.ROUND);
        pointPaint.setColor(ContextCompat.getColor(context, R.color.tertiary));
        textPaint.setColor(ContextCompat.getColor(context, R.color.on_surface_variant));
        textPaint.setTextSize(dp(12));
    }

    public void setData(JsonArray daily) {
        values.clear();
        firstDate = "";
        lastDate = "";
        if (daily != null) {
            for (JsonElement element : daily) {
                if (!element.isJsonObject()) continue;
                JsonObject row = element.getAsJsonObject();
                String date = Jsons.string(row, "stat_date");
                if (firstDate.isEmpty()) firstDate = shortDate(date);
                lastDate = shortDate(date);
                float value = 0f;
                for (Map.Entry<String, JsonElement> entry : row.entrySet()) {
                    if (entry.getKey().contains("date") || !entry.getValue().isJsonPrimitive()
                        || !entry.getValue().getAsJsonPrimitive().isNumber()) continue;
                    value += Math.max(0f, entry.getValue().getAsFloat());
                }
                values.add(value);
            }
        }
        setContentDescription(values.isEmpty() ? "暂无趋势数据" : "近 30 日趋势，共 " + values.size() + " 个数据点");
        invalidate();
    }

    @Override
    protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        float left = dp(16);
        float right = getWidth() - dp(16);
        float top = dp(20);
        float bottom = getHeight() - dp(32);
        for (int row = 0; row <= 4; row++) {
            float y = top + (bottom - top) * row / 4f;
            canvas.drawLine(left, y, right, y, gridPaint);
        }
        if (values.isEmpty()) {
            textPaint.setTextAlign(Paint.Align.CENTER);
            canvas.drawText("暂无趋势数据", getWidth() / 2f, getHeight() / 2f, textPaint);
            return;
        }
        float max = 1f;
        for (float value : values) max = Math.max(max, value);
        chartPath.reset();
        for (int index = 0; index < values.size(); index++) {
            float x = values.size() == 1 ? (left + right) / 2f : left + (right - left) * index / (values.size() - 1f);
            float y = bottom - (bottom - top) * values.get(index) / max;
            if (index == 0) chartPath.moveTo(x, y); else chartPath.lineTo(x, y);
            if (values.size() <= 12 || index == 0 || index == values.size() - 1) canvas.drawCircle(x, y, dp(3.5f), pointPaint);
        }
        canvas.drawPath(chartPath, linePaint);
        textPaint.setTextAlign(Paint.Align.LEFT);
        canvas.drawText(firstDate, left, getHeight() - dp(10), textPaint);
        textPaint.setTextAlign(Paint.Align.RIGHT);
        canvas.drawText(lastDate, right, getHeight() - dp(10), textPaint);
        textPaint.setTextAlign(Paint.Align.LEFT);
        canvas.drawText(format(max), left, top - dp(5), textPaint);
    }

    private String shortDate(String value) {
        return value != null && value.length() >= 10 ? value.substring(5, 10) : value;
    }

    private String format(float value) {
        if (value >= 10000) return String.format(java.util.Locale.CHINA, "%.1f万", value / 10000f);
        return String.valueOf(Math.round(value));
    }

    private float dp(float value) {
        return value * getResources().getDisplayMetrics().density;
    }
}
