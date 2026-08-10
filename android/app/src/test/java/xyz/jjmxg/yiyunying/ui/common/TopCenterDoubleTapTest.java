package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.robolectric.Shadows.shadowOf;

import android.content.Context;
import android.os.Looper;
import android.view.ContextThemeWrapper;
import android.view.MotionEvent;
import android.view.View;

import androidx.test.core.app.ApplicationProvider;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import java.time.Duration;
import java.util.concurrent.atomic.AtomicInteger;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class TopCenterDoubleTapTest {
    private Context context;

    @Before public void setUp() {
        context = new ContextThemeWrapper(
            ApplicationProvider.getApplicationContext(), R.style.Theme_Yiyunying);
    }

    @Test public void centerDoubleTapRunsTopActionWithoutRunningSingleClick() {
        View bar = bar();
        AtomicInteger singles = new AtomicInteger();
        AtomicInteger doubles = new AtomicInteger();
        bar.setOnClickListener(view -> singles.incrementAndGet());
        TopCenterDoubleTap.attach(bar, doubles::incrementAndGet);

        tap(bar, 500L, 150f);
        tap(bar, 620L, 150f);
        shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(400));

        assertEquals(1, doubles.get());
        assertEquals(0, singles.get());
    }

    @Test public void centerSingleTapPreservesExistingToolbarClick() {
        View bar = bar();
        AtomicInteger singles = new AtomicInteger();
        bar.setOnClickListener(view -> singles.incrementAndGet());
        TopCenterDoubleTap.attach(bar, () -> { });

        tap(bar, 500L, 150f);
        shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(400));

        assertEquals(1, singles.get());
    }

    @Test public void edgeTapIsLeftToToolbarChildren() {
        View bar = bar();
        AtomicInteger doubles = new AtomicInteger();
        TopCenterDoubleTap.attach(bar, doubles::incrementAndGet);

        tap(bar, 500L, 20f);
        tap(bar, 620L, 20f);
        shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(400));

        assertEquals(0, doubles.get());
    }

    private View bar() {
        View bar = new View(context);
        bar.layout(0, 0, 300, 56);
        return bar;
    }

    private void tap(View view, long downTime, float x) {
        MotionEvent down = MotionEvent.obtain(downTime, downTime,
            MotionEvent.ACTION_DOWN, x, 28f, 0);
        MotionEvent up = MotionEvent.obtain(downTime, downTime + 40L,
            MotionEvent.ACTION_UP, x, 28f, 0);
        view.dispatchTouchEvent(down);
        view.dispatchTouchEvent(up);
        down.recycle();
        up.recycle();
    }
}
